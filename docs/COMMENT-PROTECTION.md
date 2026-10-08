# Comment and review protection — 0.2.2 candidate

This release adds two opt-in protections under **Settings → Comment reCAPTCHA**:

| Setting | Default | Scope |
| --- | --- | --- |
| Protect WordPress comments | Off | Comments on posts, pages, attachments and all other non-product post types |
| Protect WooCommerce product reviews | Off | Submissions on `product` objects while WooCommerce is loaded |
| Enterprise site key | Empty | An explicitly selected Enterprise **SCORE** website key |
| Exact accepted hostnames | Empty | Includes the canonical `home_url()` hostname and every public submitting alias |
| Minimum score | 0.5 | Shared by both protections; permitted range 0.1–1.0 |

These settings live only in `mrn_recaptcha_comment_protection`. They do not modify the manager credential option, WPForms global keys, form-level toggles, or existing WPForms provisioning behavior. There is no automatic activation on upgrade and no automatic key reuse. CHECKBOX/v2, WAF, unrestricted-domain, and test keys are rejected by the new setup flow.

The stored `blog_enabled` setting and Google action `mrn_blog_comment` retain their compatibility names. Version 0.2.2 broadens that setting to every non-product WordPress comment target. Product reviews remain independently controlled. The explicit deployment pause covers every existing comment target even while both protection settings are off. Before disabling a legacy comment provider, the migration agent requires the `all-non-product-types` coverage marker and verified public serving of this release.

## Key preparation

1. Establish the site's canonical hostname and redirects. Use a separate staging key for staging.
2. Inspect the intended key in the configured Google Cloud project. Prefer a dedicated production SCORE key for comments/reviews. Do not infer compatibility from a WPForms “v3” label or a visible key suffix.
3. Grant the configured service account permission to inspect keys (`recaptchaenterprise.keys.get`) and create assessments (`recaptchaenterprise.assessments.create`). The reCAPTCHA Enterprise Admin role covers key management but does not include assessment creation; do not infer access from successful provisioning. Add only the required permission through a reviewed custom role, or explicitly approve the broader reCAPTCHA Enterprise Agent role. Existing provisioning credentials must be checked for assessment permission. Key creation is a separate explicitly approved operation.
4. Ensure domain verification is enabled. Google allows subdomains of listed parent domains, but this plugin accepts assessment hostnames only by exact match against its own configured list. Do not add development domains to a production key.
5. Save an enabled protection on the isolated staging site. The settings handler fetches key metadata through an authenticated, redirect-disabled GET scoped to the configured project and exact key, then verifies the returned key identity, type and domains before saving. Google may canonicalize a text project ID to its numeric project number in the resource name; that response is accepted from the scoped GET. Different text projects, different configured numeric projects, malformed names and different key IDs are rejected. After metadata passes, setup sends a synthetic invalid token with action `mrn_setup_probe` to the assessment API. The authenticated request must succeed and return `tokenProperties.valid === false`; denied access, network errors, malformed responses and unexpectedly valid tokens preserve the previous configuration. This check can create an invalid Google assessment and consume assessment quota; it sends no comment, IP address, customer data or email. It runs only during explicit enabled-settings verification, not page views. A fingerprint binds verification to project/key/hostname settings. A changed canonical hostname or project fails closed until reverified.
6. Exercise a real frontend token against Google's assessment service on staging. The setup probe proves only current API access and invalid-token rejection. It does not prove genuine browser-token validity, hostname/action binding, score suitability, consent compatibility or real traffic behavior.

Both checkboxes off is an emergency disable: it preserves existing key settings and does not contact Google. To configure a new key without turning on public protection, use a nonpublic staging runtime. There is deliberately no hidden “test mode” or fail-open production setting.

## Submission behavior

| Actor/operation | Behavior |
| --- | --- |
| Guest WordPress commenter, including page and attachment comments | CAPTCHA required when WordPress comment protection is on |
| Subscriber/customer, including verified purchasers | CAPTCHA required on enabled targets |
| Administrator with `manage_options` | CAPTCHA exempt, including administrative replies; native WordPress/WooCommerce rules still apply |
| Editor/moderator/shop manager without `manage_options` | Core wp-admin AJAX replies require edit permission and the valid core reply nonce; other protected creations need a token |
| WP-CLI and WordPress cron | Trusted server operations exempt |
| Editing/moderating existing comments | No CAPTCHA added |
| Trusted imports using `wp_insert_comment()` directly | Unchanged; low-level insertion is not a public submission API |
| Blog pingbacks/trackbacks via their actual server endpoints | Unchanged |
| Arbitrary claimed comment type, user ID or REST body ID | Cannot grant an exemption |
| Public comments on custom post types | CAPTCHA required when WordPress comment protection is on |
| Privileged WooCommerce order notes and trusted imports | Existing low-level insertion and trusted server exemptions remain unchanged |

The new filter returns the previous approval value after validation. It does not approve, publish, trash, change ratings, alter recipient filters, or send mail. WooCommerce's native verified-owner check remains; the new layer also enforces that rule on alternate `wp_allow_comment()` paths. Administrative CAPTCHA exemption does not override WooCommerce's public purchaser requirement.

`pre_comment_approved` validates before `wp_new_comment()` insertion and core REST approval. `rest_pre_insert_comment` also validates creations that core allows to skip approval. Repeated validation within the same insertion uses an in-memory result; `wp_insert_comment` clears it. Subsequent submissions must obtain another token. Google also rejects reused tokens. There is no token, comment text, private-key, or provider-body logging.

The assessment uses `mrn_blog_comment` or `mrn_product_review`, the configured site key and a server-derived expected action. It requires `tokenProperties.valid === true`, exact action, an allowed hostname, a numeric score above the threshold, and a creation time within 120 seconds (with at most 10 seconds future clock skew). Missing, malformed, expired, mismatched, replayed and low-score tokens are rejected before save. Google/OAuth/network failures fail closed with a retry error. OAuth tokens are credential-bound and cached for five minutes; assessment results are never shared across requests.

## Browser and integration contract

The standard WordPress `comment_form` hook emits one field inside each guest or logged-in form using that form's explicit post ID. WooCommerce's standard review template uses these hooks. The small local script is enqueued only when an enabled form is rendered, once per page. Enterprise JavaScript is loaded on the first submission, shared across forms, and reused if already present. No checkbox widgets are created. Legacy `api.js` and WPForms APIs are left alone.

The browser generates a token at submission, reruns native validation and submit handlers, preserves the original submit button, and clears tokens after submission or history restoration. Each retry generates a new token. Capture-phase delegation handles multiple forms, moved reply forms and inserted forms on pages where the handler is already loaded. Loading/verification timeout and errors retain the text and focus a live status message. With JavaScript disabled, the form explains the requirement and the server still rejects an unverified submission. A server-side error uses WordPress's error/back-link page; returning to the form depends on the browser's normal form-history behavior.

Core REST integrations can send `X-MRN-Recaptcha-Token`; the standard form uses `mrn_recaptcha_token`. New custom AJAX/REST endpoints and custom form templates must be qualified explicitly. Endpoints that call `wp_insert_comment()` directly bypass WordPress submission validation and must add their own validation before insertion. Do not expose those as public review endpoints. Custom review APIs must retain purchaser/rating checks. This release does not modify privileged WooCommerce management APIs or import code.

Do not enable overlapping CAPTCHA providers on the same form. The plugin does not disable another plugin, mutate its settings, or silently remove its hooks. A production cutover must follow the migration runbook after replacement coverage has been verified on staging. Qualify pages that contain WPForms and comments together with actual Google scripts, including privacy/consent tools and CSP rules. The local mock tests prove namespace isolation but do not certify the external Google script combination.

## Local tests

### Immutable asset package

Use the Node version in `.node-version` and the locked esbuild dependency. On a clean committed checkout:

```bash
npm ci --ignore-scripts --no-fund --no-audit
npm test
python3 tools/build-release.py /absolute/new-release-directory
```

The builder exports the exact commit, builds the source and minified JavaScript together, verifies both, and packages full-SHA-256 filenames plus `assets/manifest.json`. The receipt binds the package and manifest to the source commit and toolchain. Run it twice into separate empty directories and compare archive hashes. Existing artifacts cannot be overwritten. Tests reject stale output, missing files, altered bytes, and immutable-name collisions; a behavior change produces a new URL without relying on the plugin version.

Install the generated ZIP in the disposable runtime before browser tests. A source checkout alone has no generated manifest. The runtime resolves the manifest once per request, selects the hashed source with `SCRIPT_DEBUG` or minified output otherwise, and supplies no query version. Missing manifest entries/files produce visible unavailable feedback and no unversioned fallback. Release verification checks hashes; runtime requests do not rehash files.

The package satisfies the build portion of MRN's asset standard. It does **not** qualify a deployment adapter: the serving environment must publish and retain immutable assets before HTML references them, atomically activate code plus manifest, pin each request to one release, invalidate only affected HTML, and prove public asset checksums and rollback/open-tab behavior. WordPress's ordinary plugin-directory replacement cannot be assumed to retain old asset URLs. Keep promotion blocked until the optional-plugin route demonstrates these guarantees. New child-theme GitHub Actions deployment does not deploy this shared plugin.

Use a disposable WordPress runtime, separate database, fresh local content and blocked outbound mail. The fixture refuses to load unless `MRN_RECAPTCHA_ISOLATED_TEST=true`, environment type is `local`, and `WP_HOME` is exactly `http://127.0.0.1:8765`. Never copy fixtures to a managed site or include them in a release ZIP.

The test run used WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.6, and an isolated SQLite database. Google metadata/OAuth/assessments are deterministic mocked responses; all other PHP HTTP is blocked. Browser Google calls are intercepted. Static compatibility checks cover PHP 7.4–8.3. Production PHP 8.3 and the Gloves theme/cache/consent stack still require staging qualification.

Setup outline (substitute a disposable path; never an existing site): download WordPress and the WordPress Performance Team SQLite Database Integration test dependency, copy its `db.copy` to `wp-content/db.php`, create a local config with the constants above, copy `tests/fixtures/local-only.php` into local `mu-plugins`, install WordPress with `--skip-email`, and activate this candidate plus the exact WooCommerce code version. Create an empty `.mrn-recaptcha-fixture` marker at the root. No production database copy or credentials are needed.

```bash
php tests/bootstrap-contract.php
MRN_RECAPTCHA_TEST_ROOT=/tmp/mrn-recaptcha-qa-20261001/site php tests/integration.php
MRN_RECAPTCHA_TEST_ROOT=/tmp/mrn-recaptcha-qa-20261001/site php -S 127.0.0.1:8765 \
  -t /tmp/mrn-recaptcha-qa-20261001/site tests/fixtures/router.php
# In another terminal, with Playwright and axe installed in the QA engine:
NODE_PATH=/Users/khofmeyer/Development/MRN-qa-engine/node_modules node tests/browser.cjs
```

MRN QA must inspect the candidate's staged snapshot or committed SHA, never a baseline HEAD when changes are uncommitted. Run the commit proof gate before committing, then full-source release QA on the clean candidate. Runtime QA uses the explicitly named loopback test site, serially with isolated reports. Scope the sample path to `/qa/product/`; full runtime Google qualification is a separate staging gate.

## Adoption on other Stack sites

Inventory exact versions, active theme, native comment/review forms, custom submission endpoints, current CAPTCHA providers, purchaser/moderation/rating rules and notification routing. Install only the approved checksum-qualified package through the site's qualified asset deployment route and backup gates. Keep both features off initially; verify staging keys and genuine assessments; approve separate enablement per surface; switch existing coverage without an unprotected or duplicate-provider interval; invalidate only affected form HTML caches. Verify WPForms independently before and after. Never automatically apply this to a fleet or add login, registration, reset or checkout protection.

## Reference contracts

- [Google SCORE browser integration](https://docs.cloud.google.com/recaptcha/docs/instrument-web-pages)
- [Google assessment IAM roles](https://docs.cloud.google.com/iam/docs/roles-permissions/recaptchaenterprise)
- [Google website assessments](https://docs.cloud.google.com/recaptcha/docs/create-assessment-website)
- [Google key metadata](https://docs.cloud.google.com/recaptcha/docs/reference/rest/v1/projects.keys)
- WordPress 7.1.2 `wp-includes/comment.php` and core REST comments controller, inspected locally.
- WooCommerce 11.1.0 `includes/class-wc-comments.php` and native review template, inspected locally.

### Sites without WooCommerce or WPForms

Neither plugin is a dependency of WordPress comment protection. Qualify the
exact installed CAPTCHA ZIP in a separate WordPress database with both plugins
absent. The reproducible runner checks package/runtime parity and every packaged
asset checksum, then runs native PHP submission/REST checks, desktop/mobile axe
scans, real browser submission, multiple forms, provider failure and
JavaScript-disabled rejection. It also checks the native settings screens,
subscriber/admin behavior, moderation, explicit cutover pause and emergency
disable. The review switch remains inert on `product` objects without
WooCommerce; it does not turn them into protected WooCommerce reviews.

Use the pinned WordPress 7.1.2 and SQLite Database Integration 3.0.2 archives.
Supply the approved package checksum and a new report directory; the runner
refuses a checksum mismatch or occupied loopback port and removes its runtime
on exit. `--mrn-qa` adds the full plugin release suite against that same runtime.

```bash
NODE_PATH=/Users/khofmeyer/Development/MRN-qa-engine/node_modules \
python3 tests/run-no-commerce.py \
  --wordpress /absolute/wordpress-7.1.2.zip \
  --sqlite /absolute/sqlite-database-integration.3.0.2.zip \
  --package /absolute/mrn-recaptcha-enterprise-manager-0.2.2.zip \
  --package-sha256 <approved-sha256> \
  --reports /absolute/new-qualification-directory --mrn-qa
```

Google OAuth, key metadata and assessments are intercepted, and outbound mail
is blocked. This proves optional-plugin independence and the local integration
contract. Genuine Google browser tokens, exact hosted immutable-asset retention
and site-specific consent/cache behavior still require their separate approved
qualification before enabling protection remotely. These tests do not advance
the retained Fleet default or remove any site's legacy protection.
