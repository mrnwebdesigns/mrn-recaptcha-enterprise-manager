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
