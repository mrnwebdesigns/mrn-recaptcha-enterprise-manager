# WPForms v3 asynchronous loading qualification

September 23, 2026. Candidate plugin version: 0.1.3. Target: https://gloves-online.com/.

## Scope

The Enterprise Manager provisions Enterprise keys and synchronizes their legacy-compatible credentials to WPForms. WPForms owns the page's classic v3 `api.js` loader. This change makes that specific loader asynchronous, installs Google's readiness queue before it, and wraps WPForms' existing token execution function so an early submission waits for readiness. It leaves verification, action names, credentials, provider settings, form markup, and other CAPTCHA modes unchanged.

## Functional evidence

- PHP contract tests use the real WordPress HTML parser and script-tag helpers. They cover nonce filters, preserved inline scripts, one loader, scope exclusions, and fallback when the expected WPForms function is absent.
- JavaScript tests execute the generated candidate's actual WPForms inline code. Slow, already-loaded, and failed loader cases preserve token-before-submit behavior. Failed loading never releases the queued submission callback.
- Isolated browsers using the live origin and candidate HTML acquired tokens normally and with a forced two-second loader delay. Both forms received tokens and the requested callback ran once, with no JavaScript exceptions.
- Server-side Google verification accepted the tokens for hostname `gloves-online.com` and action `wpforms`. The automated browser received score 0; this proves token validity, not acceptance of a human form submission. Existing spam thresholds remain in force. No newsletter submission or customer notification was sent.
- Candidate UI checks at 412, 768, and 1366 CSS pixels passed: consent defaults/rejection persistence, fixed banner, keyboard menu behavior, mobile account/favorite links, and no horizontal overflow. Axe WCAG A/AA scans found no violations.
- Full-plugin MRN QA passed PHP lint, WordPress/security coding checks, PHP compatibility, PHPStan, secrets/debug scans, static API audit, and whitespace checks. Engine runtime rows were deliberately excluded from this source pass; candidate browser, accessibility, and performance checks ran separately. This is component qualification, not a Fleet release signoff.

## Performance evidence and limits

Matched local comparisons use Chrome 153.0.8010.36, Lighthouse 13.5.0, identical captured HTML apart from the loader change, Brotli document delivery, and the same first-party asset cache. Runs were serial. These scores are not Google PSI scores.

| Mode | Baseline scores | Candidate scores |
| --- | --- | --- |
| Simulated mobile | 86, 84, 88 | 88, 86, 89 |
| Actual DevTools mobile throttling | 62, 60 | 66, 53 |

Simulated median score improved from 86 to 88; median blocking time decreased from 341.5 to 266.5 ms. Median FCP changed from 2.039 to 2.010 s, while median LCP changed from 2.867 to 3.005 s. Actual-throttle results are mixed, so a meaningful speed improvement is not established.

A fresh, unchanged [Google baseline](https://pagespeed.web.dev/analysis/https-gloves-online-com/zptvamjgr1?form_factor=mobile) scored 70 mobile / 63 desktop, compared with an earlier 57 / 88. The newer mobile run paints earlier but exposes much more blocking time (739 ms). These baseline changes must not be credited to this candidate. The reCAPTCHA payload and execution cost remain.

The candidate follows [Google's asynchronous loading guidance](https://developers.google.com/recaptcha/docs/loading), but this is not proof of green performance scores. Any live trial must preserve an exact rollback and compare fresh reports before wider promotion.

## Approved live trial and rollback

The owner approved an explicit Liquid Web SSH exception after MainWP safe mode
blocked the install preview. The exact `f7f00d1` package was installed only on
Gloves Online at 16:26:24 UTC, after verified remote database backup
`459125ce8fdc`. File hashes, hook registration, active version, and unchanged
CAPTCHA settings were verified. Other plugin files were preserved.

| Google PSI run | Mobile | Desktop |
| --- | ---: | ---: |
| Immediate unchanged baseline | 70 | 63 |
| [Trial 1](https://pagespeed.web.dev/analysis/https-gloves-online-com/cbgxogxtkp?form_factor=mobile) | 86 | 97 |
| [Trial 2](https://pagespeed.web.dev/analysis/https-gloves-online-com/x6i27sod5g?form_factor=mobile) | 57 | 92 |
| [Trial 3](https://pagespeed.web.dev/analysis/https-gloves-online-com/7cgbeup4fv?form_factor=mobile) | 55 | 98 |

The mobile improvement did not hold. In the two slow candidate runs, observed
FCP and LCP were identical (2.360 s and 2.479 s), even though load completed
earlier (1.265 s and 1.841 s). Google then modeled LCP at approximately 11.56 s.
The baseline mobile CPU benchmark was 321.5; candidate benchmarks were 1039.5,
872, and 801. The large benchmark variation limits causal score comparisons.
Do not claim that asynchronous loading solved the mobile paint delay.

Live token checks and UI/axe checks passed. Google's token verification returned
the expected hostname/action; automated scores were 0, so these checks do not
claim a successful human newsletter submission. No customer messages were sent.
The MRN runtime API check passed. The generic smoke check flagged
`requestStorageAccess: Permission denied.` from Google's reCAPTCHA iframe;
the identical message appears in both baseline PSI reports. A repeat smoke
against the homepage and product page passed when only that exact third-party
warning was excluded. The unfiltered warning remains documented; this is not
an unqualified release-QA pass.

Under the agreed rollback condition, the original active 0.1.1 plugin was
restored at 16:33:38 UTC after a second verified remote database backup,
`0d13740a72d9`. Every original plugin file hash was verified, the new files and
frontend hook were removed, settings stayed unchanged, and page cache was
cleared. The candidate remains an unmerged draft; it is not a Stack/Fleet
release or a deployed optimization.

The [restored-plugin control](https://pagespeed.web.dev/analysis/https-gloves-online-com/qtvs9sqdmp?form_factor=mobile)
scored 56 mobile / 87 desktop. Its token acquisition and Google verification
passed again, and the public HTML contains the original synchronous loader with
no trial shims. The low mobile result persists with either implementation.
