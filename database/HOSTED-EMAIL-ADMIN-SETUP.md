# Configure hosted email without hPanel

The hosted status confirmed: sending disabled, API key/sender/base URL/reset secret
missing, with notification tables and cURL available. Git deployment does not copy
the local private email file. This revision adds a protected configuration form to
the existing Subscription Management page; it does not recreate the Brevo system.

## Use after auto-deployment

1. Sign in on **https://profitpocket.online** as an active administrator with both
   `users.manage` and `settings.manage`. Open Subscription Management and refresh.
2. Under **Email notifications**, expand **Configure hosted Brevo email**.
3. Enter a current Brevo API key, the sender email already verified in that Brevo
   account, sender name and optional reply-to email. Use a replacement key if an
   older key was shared in chat. Enter the key only in this HTTPS form.
4. Confirm authorization and select **Verify sender and enable email**. A real
   server-side request checks the active sender. Rejection or unavailable Brevo
   leaves settings unactivated.
5. After success, use **Send one hosted test email**, enter an inbox you control,
   and explicitly confirm the single real test. Brevo acceptance and actual inbox
   receipt are separate checks; inspect the inbox and spam folder. Repeating the
   same test inbox uses the existing diagnostic completion record rather than
   blindly sending again after an uncertain result.
6. Open the already approved payment's **View** page and use **Send approval email**
   if the original event was absent or a safe never-attempted skip. This does not
   approve the payment again or extend paid access. Future reviews attempt delivery
   after commit, using the existing templates and transport.

## Private storage and safeguards

The default destination is the existing email loader's location outside the public
website: `/home/ACCOUNT/domains/profitpocket.online/profitpocket-private/email.php`.
An existing `PROFITPOCKET_EMAIL_CONFIG` override is honored only outside public web
paths. The configuration directory is created privately when PHP has permission.
There is no fallback to a public PHP file or GitHub credential.

The form checks the real HTTPS server state and canonical domain, not an untrusted
forwarded header. Configuration/test POSTs require CSRF, administrator permissions
and explicit confirmation. GET never saves or enables settings or sends mail.
The page has no-store caching. The password field is blank after reload and after
errors; neither the API key nor reset secret is rendered, logged, placed in URLs,
returned to JavaScript or stored in database audit payloads.

The HTTPS base URL is derived from the known ProfitPocket origin and installed
base path. A random 32-byte hex reset secret is generated only if none exists.
An existing valid secret and unrelated private settings are preserved. An invalid
existing secret or conflicting server environment override stops the operation.
The sender is verified before an atomic private-file replacement with mode 0600;
the existing email loader then reads BREVO_EMAIL_ENABLED=true. The save does not
automatically send any test or backlog emails. The separate test requires its own
confirmation and uses the existing one-attempt diagnostic mechanism.

If the PHP account cannot write outside public_html, the form reports this. The
hosting owner must grant the required private-directory access; the application
does not weaken file location restrictions. Cron scheduling still requires hosting
access for deferred retries, other queued notifications and reminder generation.
See the existing HOSTINGER-PRODUCTION-SETUP.md guide for the PHP CLI job scripts.

## Modified files and validation

- `includes/email_admin_setup.php`: permission/HTTPS guards, validation, existing
  provider sender check and atomic private configuration save.
- `includes/email_admin_setup_form.php`: secret-free configuration and controlled
  real-test forms, using existing Bootstrap styles.
- `includes/subscription_email_status.php`: include the permission-protected form.
- `admin/subscriptions.php`: POST handling, CSRF, no-store header, key disposal and
  existing diagnostic test dispatch. No payment approval is changed by these actions.
- `tests/email_admin_setup_test.php`: actual temporary private-file persistence,
  provider rejection/mocked verification, HTTPS/permission/consent checks, secret
  preservation, environment precedence, public-path rejection and HTML redaction.
- `tests/http_root_auth_test.php`: protected form, CSRF and local HTTP rejection.
- `bin/upgrade-release.json`: refreshed runtime file hashes.
- `database/HOSTED-EMAIL-ADMIN-SETUP.md`: this procedure.

Tests use a disposable administrator fixture, fabricated keys, a temporary private
directory and mocked provider responses; they remove their fixtures. They do not
change the real XAMPP email configuration or send real email. No schema changes or
production setup were performed by the developer. Hosted activation and actual
provider acceptance/inbox receipt remain unverified until the operator uses the
forms with valid private settings and confirms the result.
