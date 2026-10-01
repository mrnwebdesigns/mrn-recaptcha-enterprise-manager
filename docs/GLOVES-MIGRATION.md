# Gloves Online controlled migration — approval required

Target: **https://gloves-online.com/** only. This document authorizes no production change. No production comment/review was submitted and no notification email was sent by this task.

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

## Gates before production approval/execution

1. Review the exact candidate commit, local test evidence, ZIP SHA-256 and limitations. Merge/publish/register promotion is separate from local artifact preparation; this task does not alter the Stack catalog or a live package URL.
2. Resolve a named staging environment with Gloves' actual child theme, WordPress/PHP/WooCommerce/WPForms versions and form templates. Block outbound email before any test; use fresh synthetic content/accounts/orders. Obtain explicit authorization for any staging submissions if the staging environment is shared or externally accessible. No production test submissions are authorized.
3. Verify actual Google SCORE metadata and real token/assessment behavior, including assessment permission, score threshold, exact hostname/action, blocked consent/CSP, expired/replayed tokens, multiple forms, WPForms on the same page, native customer/purchaser/rating behavior and recipient-routing parity. The local mock results do not replace this gate.
4. Reconfirm Advanced still protects only comments. Identify any new integrations or drift before deactivation. Archive sanitized setting fingerprints and encrypted/private backups, not secrets in reports.
5. Resolve the exact target again via MainWP and sync/read back. Run the supported optional-plugin preflight for **only** Enterprise Manager against the approved immutable release; require `would_install=false`, preserved activation and exact old version/hash. Inspect agent readiness and supported settings-operation capabilities. Safe mode or missing capability is a blocker; do not disable it or silently switch to SSH/browser/other credentials.
6. Capture a fresh successful database backup receipt using the verified hosting/backup route, plus an exact restorable copy/hash of the **live 0.1.1** plugin files, Advanced 5.40 files/options, new-option prior absence/value, and relevant WPForms/moderation/review/recipient configuration. The registered 0.1.2 archive is **not** the live rollback artifact. A pending job is not a receipt. Verify retention and restore access.
7. Present the final plan bytes/hash, selected verified key identity, staging results, live preflight, backup receipt, prior package hash and bounded cutover window for explicit approval. This is the production approval checkpoint. If the selected route lacks a supported settings migration action, report it and obtain approval for the specific alternative route before using it.

## Approved cutover sequence

1. Through the approved deployment route, upgrade only Enterprise Manager. Both protections remain off. Verify file/version hashes, HTTP/REST health, existing WPForms key/configuration fingerprints and unchanged review/comment settings. Do not run WPForms bootstrap or bulk-enable forms.
2. Use a short, approved submission pause at the edge or application maintenance boundary for affected comment/review POST routes while leaving storefront GET/checkout behavior in scope as agreed. Establish the pause before changing overlapping providers. If a sufficiently narrow, verified pause mechanism is unavailable, stop and agree on a maintenance window; do not improvise custom production code.
3. Within that pause, disable only Advanced's `captcha_show_wp_comment`, preserving the rest of its option array. Enable and verify the new option values through the plugin's protected settings flow. If Google verification fails or readback differs, immediately restore the original Advanced option and stop.
4. Purge relevant page/CDN caches and verify guest blog and authenticated purchaser product forms contain one MRN field, correct action/key, one MRN handler, and no legacy Advanced widget for these forms. Check that WPForms' separate configuration and rendered integration remain unchanged. Confirm administrator replies retain their normal workflow. These reads do not prove a successful live save.
5. Keep the submission pause until the approved nonpersisting verification checks pass. Missing/invalid-token production POST probes, any valid production save, and notification tests require separately explicit authorization. Without it, stop with staging proof and read-only live checks, and let the owner choose whether to resume normal customer traffic or authorize a bounded test. Never claim live end-to-end verification without evidence.
6. After replacement coverage is verified at the approved level and no other Advanced protections are enabled, deactivate Advanced, retaining files/options. Purge caches and read back plugin status and form markup. Lift the pause only after the approved verification/rollback decision. Do not schedule monitoring unless requested.

There must be no interval where submissions are open with both comment providers disabled, nor a user-visible form requiring two competing providers. Retire Advanced only after replacement verification; staging validates the intended replacement before any production switch, and the final deactivation follows live readback.

## Rollback

Triggers include unexpected key/domain rejection, valid customer false negatives, Google/IAM outage, purchaser/rating/moderation/routing drift, duplicate CAPTCHA, WPForms regression, server errors or changed scope.

1. Pause affected submissions again if already reopened. Retain evidence without tokens or customer text.
2. Reactivate Advanced 5.40 if deactivated, restoring its exact original options with comment protection enabled and the originally disabled protections still disabled.
3. Set both new protection flags off (this does not need Google), restoring the prior option/absence as recorded. Purge affected caches and verify only the original CAPTCHA renders.
4. If code rollback is needed, restore the backed-up exact live **0.1.1** plugin directory through the approved rollback route and required fresh backup gate. Verify checksums/activation and configuration hashes. A full database restore is a last resort because it can discard new WooCommerce orders; prefer narrow option/file restoration.
5. Confirm WPForms, review purchaser/rating/moderation settings, recipient routing and HTTP/REST are unchanged. Record the reverted state and leave the old package and backup receipts retained.

Rollback restores the original known v2 invalid-domain condition; it is not a repair of that condition. If acceptable comment protection cannot be restored, keep affected submissions paused and escalate to the owner. Do not leave both protections disabled on an open public submission route.

## Approval scope

Approve the tested source/package independently from production execution. Production execution requires the completed staging/key/preflight/backup fields above and a concrete supported route. No permission to publish live test comments/reviews or send emails is implied by approving the plugin upgrade.
