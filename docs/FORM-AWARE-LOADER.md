# Optional WPForms v3 loading

Version 0.1.4 adds an opt-in loader for a single protected WPForms 2.0.2.1
form on a page. Existing sites retain native WPForms loading by default.
The intended Gloves scope is the homepage newsletter, form 68. Site code may
enable it with `mrn_recaptcha_form_aware_loading` (arguments: false, form ID).

Google's script starts when the form is within 1,000 pixels of the viewport,
on first focus or pointer intent, or when WPForms requests a submission token.
Browsers without IntersectionObserver load immediately. No user-agent checks,
Lighthouse checks, or arbitrary performance-test delays are used.

The adapter preserves the `wpforms` action, generates a new token for each
validated submission, and continues through WPForms' native submission path.
It does not change CAPTCHA settings, secrets, server verification, score
thresholds, or form notifications. Loading and token execution have 15-second
failure bounds. Failure clears the token, restores the submit button, and
announces a retry message; it never proceeds without a nonempty token.

Google recommends early loading for more behavioral context. Starting near the
form preserves more context than submission-only loading but does not prove
identical fraud classification. Review real submission failures/spam after
deployment; do not lower score thresholds to hide failures.

## Compatibility and rollback

Only classic reCAPTCHA v3 with the unmodified Google API URL is supported.
Other providers, versions, multiple initial forms, Customizer/admin requests,
or missing/corrupt generated assets keep native WPForms loading. Dynamically
inserting additional protected forms into an opted-in page is unsupported.
The site must not enable this adapter on such pages.

Remove the site opt-in and invalidate the affected page HTML to restore native
loading. Keep the prior plugin archive for normal guarded package rollback.
Never edit vendor WPForms files or disable its CAPTCHA option.

## Build and verification

`npm ci --ignore-scripts`, `npm test`, and full MRN plugin QA validate source.
Build generated assets from a committed source with `tools/build-assets.mjs`;
run `php tests/adapter.php` and `php tests/adapter.php 2.1.0` against that output.
`python3 tools/build-release.py /absolute/output-directory` packages a clean
commit with content-hashed source/minified pairs and a checksum manifest.
This command never deploys. Named-site runtime, failure/retry and token checks
are required separately; unit stubs do not establish Google's real scoring.

References: https://developers.google.com/recaptcha/docs/loading and
https://developers.google.com/recaptcha/docs/v3.
