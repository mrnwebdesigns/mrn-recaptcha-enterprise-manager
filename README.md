# MRN reCAPTCHA Enterprise Manager

Create Google reCAPTCHA Enterprise keys directly from WordPress and optionally sync generated keys to WPForms.

## What this plugin does

- Stores Google project + service account credentials in plugin settings.
- Creates reCAPTCHA Enterprise website keys via Google API.
- Retrieves the key's legacy secret key for third-party compatibility.
- Optionally syncs the generated site key + legacy secret key to WPForms global CAPTCHA settings.
- Uses a tabbed admin screen (`Credentials` and `Create Key`) with optional MRN sticky toolbar support when available.

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
