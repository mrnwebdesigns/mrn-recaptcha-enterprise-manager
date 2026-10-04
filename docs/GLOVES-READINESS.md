# Gloves release readiness work — 2026-10-01

October 4 source reconciliation: the 0.2.0 candidate now also retains the merged 0.1.4 WPForms loader. Both JavaScript assets share one verified immutable manifest. The operational gates below remain open; the Stack default leaves comment/review protection off and does not deactivate Advanced Google reCAPTCHA.

The 0.2.0 candidate is implemented in the shared plugin repository and tested locally. It is **not yet qualified for production deployment**. The exact source, ZIP and test receipts are retained outside Git in `MRN-release-artifacts/2026-10-01-recaptcha-deployment-readiness/`. Previous approved and tested archives remain intact. PR: https://github.com/mrnwebdesigns/mrn-recaptcha-enterprise-manager/pull/3.

## Outstanding work and acceptance evidence

| Gate | Current evidence | Required completion |
| --- | --- | --- |
| Assessment access | Existing Stack account can inspect the Gloves SCORE key but receives HTTP 403 `IAM_PERMISSION_DENIED` for assessment creation | Reviewed grant below, credential identity readback on the named runtime, successful setup probe and genuine browser assessments |
| Named staging | `gloves.mrndev.io` homepage and product page render; Reviews tab preserves logged-in-purchaser messaging | Resolve its supported connection, verify runtime versions/theme, capture fresh database backup, block outbound email and qualify candidate with synthetic data after explicit submission authorization |
| Keys | Existing WPForms production key has compatible SCORE/domain metadata; no development key found | Select dedicated comment/review keys for production and development, or approve reuse after full compatibility testing; preserve WPForms configuration |
| Optional-plugin adapter | MainWP currently sends `installplugintheme` with `overwrite=yes`; completion checks version/activation only | Implement and pilot the asset contract below in deployment tooling before this release is promoted |
| Settings cutover | No supported narrowly scoped migration operation has been identified in the available MainWP abilities | Qualify a backup-gated settings operation and affected-submission pause; do not substitute an unapproved production route |
| Rollback and production preflight | Live plugin is 0.1.1; registered 0.1.2 is not its exact rollback artifact | Capture exact live files/options, register final source-bound release, run one-site preflight, obtain fresh verified database backup and bind final plan bytes/hash |

Do not replace these gates with another approval of the same plugin scope. The remaining decisions concern specific IAM/key changes and remote test submissions; implementation and local testing remain authorized.

## Exact IAM change proposed for review

Target project: `recaptcha-486314`.

Principal: `serviceAccount:recaptcha-manager@recaptcha-486314.iam.gserviceaccount.com`.

Create or reuse a project custom role **only if its exact permission set matches**:

```yaml
title: MRN reCAPTCHA Assessment Creator
description: Create Enterprise assessments for MRN comment and review protection.
stage: GA
includedPermissions:
  - recaptchaenterprise.assessments.create
```

Proposed role ID: `mrnRecaptchaAssessmentCreator`; full role name `projects/recaptcha-486314/roles/mrnRecaptchaAssessmentCreator`.

Add only that role binding to the principal above, preserving all existing policy bindings and conditions with an etag-checked policy update. Do not overwrite an existing role with a different permission set. Record the prior policy/role privately and a sanitized diff before applying. This grants assessment creation for the project's keys, not just Gloves; the shared scope must be acknowledged before applying. No new credential, owner/editor role, API enablement, billing change, key rotation, or WPForms change is proposed.

Google's reCAPTCHA Enterprise Admin role does not include assessment creation. The predefined Agent role does, but also includes additional permissions; the one-permission custom role is the narrower proposal. See [official role definitions](https://docs.cloud.google.com/iam/docs/roles-permissions/recaptchaenterprise) and [assessment permission contract](https://docs.cloud.google.com/recaptcha/docs/reference/rest/v1/projects.assessments/create).

After the grant, rerun one synthetic invalid-token assessment through the intended runtime credentials. Expect a successful authenticated response with `tokenProperties.valid=false`; a denial or unexpected response keeps enablement blocked. Then qualify genuine browser tokens on the named staging hostname. Never describe the setup probe alone as successful comment/review protection.

IAM rollback: remove only the newly added exact principal/role binding using current policy etag after protection has been disabled or restored to its prior provider. Delete a newly created custom role only after proving no other bindings depend on it. Do not revoke pre-existing grants. Retain the receipts. No IAM write has been performed by this task.

## Proposed key and staging test scope

After explicit approval, create two production SCORE website keys in the same project: display name `Gloves comments and reviews` restricted to `gloves-online.com` and `www.gloves-online.com`, and display name `Gloves comments and reviews development` restricted to `gloves.mrndev.io`. Require domain verification, no testing/WAF settings, and no unrestricted domains. Inventory first to avoid duplicate creation. These are proposed new keys; the current WPForms and Advanced keys remain unchanged. Record returned resource names and public-key fingerprints in the execution receipt.

Remote staging submissions are a separate authorization: only `https://gloves.mrndev.io/`, synthetic unpublished test content where supported, synthetic customer/nonpurchaser accounts and a synthetic completed order, outbound mail blocked before any fixture creation or save. Exclude real orders, customers and existing comments/reviews. Retain exact fixture IDs for cleanup; never use bulk deletion or touch production. The named environment is externally accessible, so no test comments/reviews have been created there.

Required matrix: valid guest blog save; valid logged-in purchaser review with rating; missing/invalid/expired/replayed/wrong-host/wrong-action/wrong-key/low-score rejection before save; direct native/REST submission bypass rejection; nonpurchaser rejection; administrator and core moderator replies; disabled surfaces; multiple forms; retry after outage; expired browser tab; blocked script/consent/CSP; WPForms coexistence; moderation and notification-recipient calculation with actual delivery suppressed. Compare configuration fingerprints before/after and perform rollback. Genuine negative cases must be distinguished from locally mocked cases in the receipt.

## Deployment adapter acceptance contract

This belongs to the shared deployment workflow; do not implement a replacement transport inside this plugin or modify the Gloves child theme. The current source boundary keeps this release's edits within the plugin repository.

The optional-plugin route must expose a separately qualified capability with these properties:

1. Receive an exact allowlisted plugin, source commit, ZIP/file/manifest hashes, prior release, expected active state, site identity, backup receipt and confirmation-bound plan.
2. Verify and stage the complete release before selection. Publish immutable public assets before new HTML can refer to them. Keep prior public URLs through the cache, open-tab and rollback windows.
3. Atomically select code and manifest together, pinning each request to one release. A sequence that deletes/replaces the plugin directory is insufficient. Define OPcache and concurrent request behavior explicitly.
4. Retain a restorable prior release privately and preserve plugin activation, unrelated files/options, WPForms, child theme and other Stack components. Unknown outcomes retain a lock and require reconciliation.
5. Invalidate only the approved form HTML URLs/cache tags. No global origin/CDN purge or asset deletion.
6. Verify normal public asset URLs, expected MIME types and exact manifest hashes on first and warm requests, plus a returning browser. Prove old/open-tab URLs continue to work after activation and rollback. Run the existing Gloves stale-asset regression against this optional-plugin adapter, not merely the child-theme adapter.
7. Produce non-secret activation, verification and rollback receipts bound to the exact plan. Pilot on named staging before making the capability available to the Gloves production plan.

The new GitHub Actions site workflow is a child-theme route. `mrn fleet update` is the platform-required-plugin route. Neither substitutes for the optional-plugin capability above. Deployment-agent 0.2.5 reporting private/atomic storage ready does not qualify the existing overwrite installer.

Once these gates pass, follow [the exact cutover and rollback sequence](GLOVES-MIGRATION.md). Advanced Google reCAPTCHA remains active until replacement coverage is verified; login, registration, password reset and checkout remain outside this change.
