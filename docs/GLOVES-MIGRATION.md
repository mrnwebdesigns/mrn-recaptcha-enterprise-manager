# Gloves Online controlled migration — execution gated

Target: **https://gloves-online.com/** only. This document authorizes no production change. No production comment/review was submitted and no notification email was sent by this task.

The owner approved the prepared work on 2026-10-01 and asked whether it uses the new deployment methods. That approval is recorded with the original candidate receipt; it does not complete missing staging/key, backup, or adapter qualification. The subsequent asset-packaging correction has its own source commit and receipt, preserving the originally reviewed archive.

## Deployment method

This is a shared **optional-plugin** release. Use the guarded MainWP optional-plugin update/rollback route and shared release catalog; `mrn fleet update` is for platform-required plugins. The new GitHub Actions site deployment workflow owns child themes and does not ship this plugin. Do not copy code into the Gloves child theme or substitute its adapter.

MRN's new CSS/JS standard applies to shared plugins as well. This candidate builds immutable hashed source/minified JavaScript and a generated manifest. Before promotion, the optional-plugin adapter must independently demonstrate retained public asset URLs, publish-before-HTML ordering, atomic code/manifest activation, request pinning, scoped HTML invalidation, normal-public-URL byte checks and rollback with old/open-browser dependencies still available. Its previously reported private/atomic storage readiness is not proof of that asset contract. This adapter qualification is currently **unverified and blocks asset promotion**. Do not infer compliance from a successful ordinary WordPress plugin update or perform an unqualified live upgrade.

## Verified starting state, 2026-10-01

MainWP `mainwp://status` reports connected to `wpcontrol.mrndev.io`, with 84 abilities. Exact URL resolution returned site 117. A targeted sync timed out; readback showed its timestamp advancing to `2026-10-01T13:23:53+00:00`. Inventory was then reread without retrying the sync.

| Component | Version/state |
| --- | --- |
| Enterprise Manager live | **0.1.1**, active |
| Authoritative plugin main | **0.1.2**, `509a4994693ce1088e27ffbc02f1dea3844f07d4` |
| Registered optional Stack package | **0.1.2**, SHA-256 `ec468d55a9987f7a95704d9580513fc8510b179bb2dda3871acf8c4186a89f95` |
| Candidate | **0.2.0**, dedicated source branch; see release receipt for exact commit/archive hash |
| Advanced Google reCAPTCHA | **5.40**, active |
| WPForms | **2.0.2.1**, active |
| WooCommerce | **11.1.0**, active |
| MRN Comment Management | **1.2.0**, active; no update included |
| WordPress / PHP | **7.1.2 / 8.3.8** |
| Deployment agent | **0.2.5**, schema 2, private/atomic storage ready, no incomplete rollouts |
| Active template / stylesheet | **mrn-base-stack / mrn-base-stack-child**, preservation reported |

The supplied `MRN-sites/gloves/reports/comment-captcha-2026-10-01/live-state.json` records Advanced's guest-comment-only v2 configuration, the displayed “Invalid domain for site key” error, disabled login/registration/reset/checkout flags, and separate WPForms v3 credentials. Reviews are enabled and restricted to logged-in purchasers; Advanced skips logged-in users. **No review submission was tested, and this diagnosis does not establish that reviews are broken.** Comment moderation is enabled. The current Enterprise plugin manages keys/WPForms only.

## Exact intended state

- Upgrade only the already-installed Enterprise Manager to the approved 0.2.0 package, preserving activation.
- Preserve `mrn_recaptcha_enterprise_manager_settings`, all credential constants, `wpforms_settings`, every form's CAPTCHA configuration, theme/child theme, review/rating/moderation options, and notification recipients.
- Set only `mrn_recaptcha_comment_protection`: `blog_enabled=true`, `reviews_enabled=true`, `minimum_score=0.5`, `hostnames=[gloves-online.com,www.gloves-online.com]`, with an explicitly selected production Enterprise SCORE key. Verify whether `www` redirects before finalizing the list; it must be Google-authorized if retained.
- The chosen key must be verified in the configured Google project through the plugin's metadata check and a genuine staging/browser assessment workflow. Neither the broken v2 key nor the existing WPForms key is approved for reuse. The production key ID/project metadata are **not yet verified** and must be attached to the final execution plan by safe identifier/fingerprint, without secrets.
- Turn off only Advanced's comment protection for the cutover; after replacement coverage is verified, deactivate Advanced Google reCAPTCHA. Keep its installed files and original option snapshot for rollback. Do not uninstall or delete keys.
- Leave checkout, login, registration and password-reset protection outside this change.

## Gates before production execution

1. Bind the owner's approval to the final candidate commit, local test evidence, ZIP SHA-256 and limitations. Complete the asset adapter qualification above, including the Gloves stale-asset regression and rollback. Merge/publish/register promotion is separate from local artifact preparation; this task does not alter the Stack catalog or a live package URL. Source promotion requires the registered commit to match clean `origin/main` under the optional-plugin planner contract.
2. Resolve a named staging environment with Gloves' actual child theme, WordPress/PHP/WooCommerce/WPForms versions and form templates. Block outbound email before any test; use fresh synthetic content/accounts/orders. Obtain explicit authorization for any staging submissions if the staging environment is shared or externally accessible. No production test submissions are authorized.
3. Verify actual Google SCORE metadata and real token/assessment behavior, including assessment permission, score threshold, exact hostname/action, blocked consent/CSP, expired/replayed tokens, multiple forms, WPForms on the same page, native customer/purchaser/rating behavior and recipient-routing parity. The local mock results do not replace this gate.
4. Reconfirm Advanced still protects only comments. Identify any new integrations or drift before deactivation. Archive sanitized setting fingerprints and encrypted/private backups, not secrets in reports.
5. Resolve the exact target again via MainWP and sync/read back. Run the supported optional-plugin preflight for **only** Enterprise Manager against the approved immutable release; require `would_install=false`, preserved activation and exact old version/hash. Inspect agent readiness and supported settings-operation capabilities. Safe mode or missing capability is a blocker; do not disable it or silently switch to SSH/browser/other credentials.
6. Capture a fresh successful database backup receipt using the verified hosting/backup route, plus an exact restorable copy/hash of the **live 0.1.1** plugin files, Advanced 5.40 files/options, new-option prior absence/value, and relevant WPForms/moderation/review/recipient configuration. The registered 0.1.2 archive is **not** the live rollback artifact. A pending job is not a receipt. Verify retention and restore access.
7. Present the final plan bytes/hash, selected verified key identity, staging results, live preflight, backup receipt, prior package hash, exact affected HTML URL/cache-tag set and bounded cutover window. The owner's existing approval persists for the approved scope; obtain a new decision only for material changes or a specific alternative route not already authorized. If the selected route lacks a supported settings migration action, report that blocker before considering an alternative.

## Approved cutover sequence

1. Through the qualified deployment route, stage the exact archive, verify it, publish immutable assets with prior assets retained, then atomically activate Enterprise Manager code and manifest together. Both protections remain off. Verify file/version/manifest hashes, HTTP/REST health, existing WPForms key/configuration fingerprints and unchanged review/comment settings. Do not run WPForms bootstrap or bulk-enable forms.
2. Use a short, approved submission pause at the edge or application maintenance boundary for affected comment/review POST routes while leaving storefront GET/checkout behavior in scope as agreed. Establish the pause before changing overlapping providers. If a sufficiently narrow, verified pause mechanism is unavailable, stop and agree on a maintenance window; do not improvise custom production code.
3. Within that pause, disable only Advanced's `captcha_show_wp_comment`, preserving the rest of its option array. Enable and verify the new option values through the plugin's protected settings flow. If Google verification fails or readback differs, immediately restore the original Advanced option and stop.
4. Invalidate only the recorded affected blog/product HTML URLs or cache tags at origin and edge. Preserve cart/account/checkout exclusions, unrelated object/transient/media caches and old static assets; no global purge. Verify guest blog and authenticated purchaser product forms contain one MRN field, correct action/key, one manifest-resolved MRN handler, and no legacy Advanced widget for these forms. Fetch exact asset URLs from normal public HTML, require JavaScript content type and manifest SHA-256 equality, then repeat with warm cache and a returning browser. Prove old asset URLs remain valid. Check WPForms' separate configuration and rendered integration remain unchanged. Confirm administrator replies retain their normal workflow. These reads do not prove a successful live save.
5. Keep the submission pause until the approved nonpersisting verification checks pass. Missing/invalid-token production POST probes, any valid production save, and notification tests require separately explicit authorization. Without it, stop with staging proof and read-only live checks, and let the owner choose whether to resume normal customer traffic or authorize a bounded test. Never claim live end-to-end verification without evidence.
6. After replacement coverage is verified at the approved level and no other Advanced protections are enabled, deactivate Advanced, retaining files/options. Refresh affected HTML only and read back plugin status and form markup. Lift the pause only after the approved verification/rollback decision. Do not schedule monitoring unless requested.

There must be no interval where submissions are open with both comment providers disabled, nor a user-visible form requiring two competing providers. Retire Advanced only after replacement verification; staging validates the intended replacement before any production switch, and the final deactivation follows live readback.

## Rollback

Triggers include unexpected key/domain rejection, valid customer false negatives, Google/IAM outage, purchaser/rating/moderation/routing drift, duplicate CAPTCHA, WPForms regression, server errors or changed scope.

1. Pause affected submissions again if already reopened. Retain evidence without tokens or customer text.
2. Reactivate Advanced 5.40 if deactivated, restoring its exact original options with comment protection enabled and the originally disabled protections still disabled.
3. Set both new protection flags off (this does not need Google), restoring the prior option/absence as recorded. Invalidate only affected HTML and verify only the original CAPTCHA renders.
4. If code rollback is needed, atomically restore the backed-up exact live **0.1.1** release through the qualified rollback route and required fresh backup gate. Retain the new immutable assets so cached pages/open tabs do not encounter missing dependencies. Verify checksums/activation and configuration hashes. A full database restore is a last resort because it can discard new WooCommerce orders; prefer narrow option/file restoration.
5. Confirm WPForms, review purchaser/rating/moderation settings, recipient routing and HTTP/REST are unchanged. Record the reverted state and leave the old package and backup receipts retained.

Rollback restores the original known v2 invalid-domain condition; it is not a repair of that condition. If acceptable comment protection cannot be restored, keep affected submissions paused and escalate to the owner. Do not leave both protections disabled on an open public submission route.

## Approval scope

Approve the tested source/package independently from production execution. Production execution requires the completed staging/key/preflight/backup fields above and a concrete supported route. No permission to publish live test comments/reviews or send emails is implied by approving the plugin upgrade.
