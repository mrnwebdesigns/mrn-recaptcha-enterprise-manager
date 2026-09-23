# MRN reCAPTCHA Enterprise Manager

The canonical source lives in the independent `mrnwebdesigns/mrn-recaptcha-enterprise-manager` repository; MRN uses a local checkout symlink for stack integration.

Create Google reCAPTCHA Enterprise keys directly from WordPress and optionally sync generated keys to WPForms.

## What this plugin does

- Stores Google project + service account credentials in plugin settings.
- Creates reCAPTCHA Enterprise website keys via Google API.
- Retrieves the key's legacy secret key for third-party compatibility.
- Optionally syncs the generated site key + legacy secret key to WPForms global CAPTCHA settings.
- Optionally bulk-enables WPForms form-level reCAPTCHA for existing forms.
- Optionally auto-enables WPForms form-level reCAPTCHA for newly created forms.
- Uses a tabbed admin screen (`Credentials` and `Create Key`) with optional MRN sticky toolbar support when available.
- Loads WPForms reCAPTCHA v3 asynchronously while preserving token-before-submit behavior.

## Frontend loading

Enterprise keys synchronized to WPForms use its compatible reCAPTCHA v3
`api.js` integration. Version 0.1.3 makes only that loader asynchronous. Google's
readiness queue protects WPForms initialization; an additional wrapper queues
early token requests until the API is ready. Existing inline scripts, script
attributes, and WordPress nonce filters are preserved.

The loader starts immediately when its existing script tag is reached. This
does not delay protection until interaction or remove any verification. It
leaves v2, invisible v2, hCaptcha, Turnstile, custom API URLs, native
`enterprise.js` integrations, and unrelated scripts unchanged. If WPForms'
expected v3 execution function is absent from its inline output, the original
synchronous tag is retained.

This removes a parser-blocking request; it does not eliminate reCAPTCHA's
download or CPU cost and does not guarantee a PageSpeed score. Qualify each
deployment with repeated measurements and form-token checks. See Google's
[loading guidance](https://developers.google.com/recaptcha/docs/loading).

Focused checks use a WordPress checkout and captured page HTML:

```sh
WP_CORE_DIR=/path/to/wordpress php tests/frontend-contract.php before.html candidate.html
node tests/frontend-ready.mjs candidate.html
php tests/bootstrap-contract.php
```

The first command also generates a candidate page for isolated browser checks;
it does not change a WordPress runtime.

## Requirements

- WordPress admin access (`manage_options`).
- Google Cloud project with reCAPTCHA Enterprise API enabled.
- A service account key JSON (or equivalent service account email + private key).
- Service account role with reCAPTCHA Enterprise key management permissions (typically reCAPTCHA Enterprise Admin).

## Recommended deployment mode (code-locked)

For client websites, do not store service account secrets in the database UI.

Set credentials in `wp-config.php` or server environment variables with these names:

- `MRN_RECAPTCHA_ENTERPRISE_PROJECT_ID`
- `MRN_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL`
- `MRN_RECAPTCHA_ENTERPRISE_PRIVATE_KEY`
- `MRN_RECAPTCHA_ENTERPRISE_ALLOWED_DOMAINS` (comma-separated)
- `MRN_RECAPTCHA_ENTERPRISE_DEFAULT_INTEGRATION_TYPE` (`SCORE` or `CHECKBOX`)

### Example `wp-config.php` constants

```php
define( 'MRN_RECAPTCHA_ENTERPRISE_PROJECT_ID', 'client-prod-project' );
define( 'MRN_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL', 'recaptcha-manager@client-prod-project.iam.gserviceaccount.com' );
define( 'MRN_RECAPTCHA_ENTERPRISE_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n" );
define( 'MRN_RECAPTCHA_ENTERPRISE_ALLOWED_DOMAINS', 'example.com, www.example.com' );
define( 'MRN_RECAPTCHA_ENTERPRISE_DEFAULT_INTEGRATION_TYPE', 'SCORE' );
```

When these values are present, the plugin enters code-locked mode and those fields become read-only in wp-admin.

## Usage flow

1. Activate the plugin.
2. Add the constants above (or same-named env vars) on the server.
3. Open `Settings > reCAPTCHA Enterprise`.
4. Create a key and retrieve legacy secret.
5. Keep "Apply to WPForms" checked to auto-sync to WPForms settings.
6. Leave "Also enable reCAPTCHA on all existing WPForms forms now" checked to roll out form-level toggle to old forms.
7. In `Credentials`, keep "Automatically enable Google reCAPTCHA on newly created WPForms forms" enabled for new forms.

Stack bootstrap can call `MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha()`
through WP-CLI. The method is idempotent: it keeps configured WPForms keys,
reuses one exact Google key for the current hostname and integration type, or
creates a key only when none exists. It fails closed on ambiguous matches and
never returns either key in its result.

## Stack rollout secrets (recommended)

For MRN stack rollouts, keep secrets in stack-managed secret files (gitignored) and let bootstrap inject constants into each new site's `wp-config.php`.

Local paths in this repo:

- `/Users/khofmeyer/Development/MRN/stack/secrets/recaptcha-enterprise-project-id.txt`
- `/Users/khofmeyer/Development/MRN/stack/secrets/recaptcha-enterprise-service-account-email.txt`
- `/Users/khofmeyer/Development/MRN/stack/secrets/recaptcha-enterprise-private-key.pem`
- `/Users/khofmeyer/Development/MRN/stack/secrets/recaptcha-enterprise-allowed-domains.txt` (optional)
- `/Users/khofmeyer/Development/MRN/stack/secrets/recaptcha-enterprise-default-integration-type.txt` (optional: `SCORE` or `CHECKBOX`)

Expected server path (stack manager host):

- `/home/mrndev-stack-manager/stack/secrets/`

Bootstrap script support:

- `/Users/khofmeyer/Development/MRN/stack/scripts/site-bootstrap.sh`

Supported override env vars (optional):

- `STACK_RECAPTCHA_ENTERPRISE_PROJECT_ID`
- `STACK_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL`
- `STACK_RECAPTCHA_ENTERPRISE_PRIVATE_KEY`
- `STACK_RECAPTCHA_ENTERPRISE_ALLOWED_DOMAINS`
- `STACK_RECAPTCHA_ENTERPRISE_DEFAULT_INTEGRATION_TYPE`

## Security notes

- Private key material is stored encrypted with a key derived from `wp_salt( 'auth' )`.
- Creation/sync actions require `manage_options` and a valid nonce.
- In code-locked mode, runtime credentials come from constants/env instead of option storage.
