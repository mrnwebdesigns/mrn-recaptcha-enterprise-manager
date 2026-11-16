# Optional WPForms v3 loading

Version 0.1.4 added an opt-in loader for a single protected WPForms form on a
page. Version 0.2.4 qualifies WPForms 2.0.1.1, 2.0.2.1, 2.0.2.2 and 2.0.4.
Existing sites retain native WPForms loading by default.
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
announces a retry message with an explicit retry control; it never proceeds
without a nonempty token. The error is inserted immediately before the submit
controls and does not alter field validation state.

## Availability configuration

The default browser API host is `www.google.com`. A site may select the only
other supported official host when Google is unavailable to its visitors:

```php
add_filter( 'mrn_recaptcha_form_aware_api_host', static function( $host, $form_id ) {
    return 687 === $form_id ? 'www.recaptcha.net' : $host;
}, 10, 2 );
```

The host is allowlisted; arbitrary hosts are rejected. The site must also allow
the selected host and its required Google resources in CSP before enabling it.
This is not an automatic bypass for blockers and does not permit submission
without a valid server-verified token.

Optionally add a site-owned fallback contact route to the failure UI. Only
`https`, `mailto`, and `tel` URLs are accepted:

```php
add_filter( 'mrn_recaptcha_form_aware_help_url', static function( $url, $form_id ) {
    return 687 === $form_id ? 'tel:+19193241563' : $url;
}, 10, 2 );
```

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
Run `php tests/adapter.php VERSION` against that output for every supported
version and unsupported 2.0.3, 2.0.5 and 2.1.0 controls. The explicit version
list is intentional; an unlisted vendor release retains native loading until
its integration is qualified.
`python3 tools/build-release.py /absolute/output-directory` packages a clean
commit with content-hashed source/minified pairs and a checksum manifest.
This command never deploys. Named-site runtime, failure/retry and token checks
are required separately; unit stubs do not establish Google's real scoring.

The two added vendor versions also have a private-input browser fixture:
`tests/browser-wpforms-compat.cjs`. Set `MRN_WPFORMS_COMPAT_INPUT` to the
checksum-locked public frontend inputs and `MRN_RECAPTCHA_REPORT_DIR` to a
private output directory, with the MRN QA Engine's Playwright dependencies on
`NODE_PATH`. It exercises the real vendor validation and AJAX continuation at
1280px and 390px, including delayed Google readiness, empty tokens, native
button recovery and a fresh-token retry. Google and transport are fixture
stubs; only loopback requests are allowed. It does not send mail, create a real
lead, verify Google scoring, or replace the named-site acceptance checks.

Use `MRN_RECAPTCHA_LOADER_ASSET` for the exact generated minified asset when
qualifying a built package. Keep vendor files private; retain their hashes and
the fixture report rather than committing third-party plugin bundles.

References: https://developers.google.com/recaptcha/docs/loading and
https://developers.google.com/recaptcha/docs/v3.
