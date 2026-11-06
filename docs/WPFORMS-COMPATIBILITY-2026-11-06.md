# WPForms 2.0.2.2 and 2.0.4 qualification

The MRN Web Designs SiteGround site has WPForms 2.0.4, and its final Stack
migration clone has WPForms 2.0.2.2. Both currently have reCAPTCHA Manager 0.1.1,
which has no frontend form-aware adapter. Neither vendor version was in the
0.2.3 adapter's explicit compatibility list.

The 0.2.4 candidate adds exactly those two vendor versions to the existing
default-off adapter. Existing versions remain supported, and unlisted versions
retain native loading. No loader runtime behavior, server validation, provider,
keys, thresholds, comment/review defaults or notification settings changed.

The site opt-in is separate and restricted to Contact form 687 on the Contact
page. It is inert with the old manager. Reuse that one site profile on both
environments after their individual readiness and rollback checks; do not enable
the adapter globally or bypass its multiple-form/provider/version guards.

## Source and frontend checks

- Eleven Node tests pass, including four supported-version and six unsupported-
  version PHP-adapter processes, lifecycle failure/retry and immutable builds.
- The real WPForms frontend scripts from each exact runtime were exported
  read-only. The public native CAPTCHA inline contracts match the adapter's
  supported classic-v3 structure.
- The isolated frontend fixture passes 46 assertions across 1280px/390px for
  both vendor versions: required-field validation, early submission waiting for
  readiness, one native AJAX continuation with a fresh token, rejection of an
  empty token, button/field recovery and a newly requested token on retry.
- Native WPForms nonce refresh uses a loopback stub. No real form submission,
  external telemetry, mail, database write or Google scoring request is made.
- PHP lint, security PHPCS, WordPress standards, compatibility and PHPStan pass.
  The initial static-only run deliberately skipped API/runtime/browser rows;
  named WordPress/package and deployment checks remain separate acceptance.

Private vendor bundles/settings and full fixture outputs stay outside Git.
The report retains vendor hashes and coverage, without exporting credentials.
Use the exact generated minified asset when qualifying the final package.

## Deployment acceptance

This evidence is not an installation receipt or a real Google scoring result.
Before production enablement, verify the built package and original rollback,
fresh backup, active WPForms version/provider/single-form contract, installed
asset hashes and no change to CAPTCHA settings. Exercise the protected form's
real token/server path under an explicitly approved controlled test. Measure
public performance after installation, separately from these local fixtures.

The owner target is performance 95+ on mobile and desktop and 100 for the other
Lighthouse categories. This compatibility change does not itself establish
those scores. It enables use of the already implemented safer loading contract
and must be measured together with the remaining site-owned issues.
