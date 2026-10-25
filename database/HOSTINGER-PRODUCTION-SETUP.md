# Hostinger production email and two temporary administrators

## Actual status — 26 October 2026, Manila

The production-compatible changes are implemented locally. No authorized Hostinger
filesystem/database/session or deployment connection was available. No GitHub push,
Hostinger deployment, production account creation, cron installation or hosted Brevo
test has been performed. The live database name and hosting account directory have
not been observed; the placeholders below must use your actual hPanel values.

Local Brevo delivery was verified in the previous revision. That private XAMPP file
is outside this repository and is not copied by GitHub. Live readiness cannot be
inferred from that local success. The existing loader defaults to sending disabled
when the hosted private file/environment is missing. Missing hosted credentials,
different web/CLI environment settings, absent schema or workers, inactive senders,
and PHP cURL/TLS configuration are possible causes, not a confirmed live diagnosis.

No schema migration is introduced by this revision. The existing notification and
subscription migrations must already be applied to the actual hosted database.
The bootstrap never runs DDL or modifies a financial record. If prerequisites are
missing, review the existing [upgrade guide](SYSTEM-UPGRADE.md) before separately
authorizing production migrations; local approvals are not production approvals.

## 1. Create or merge three private files in hPanel

In **Websites → profitpocket.online → Dashboard → File Manager**, use access to all
hosting files so you can reach the domain directory above `public_html`.
See [Hostinger's File Manager guide](https://www.hostinger.com/support/4548688-basic-actions-in-the-file-manager-in-hostinger/).

For the supplied live URL, the application is expected at:

```text
/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket
```

The default private directory is its domain's sibling of `public_html`:

```text
/home/ACCOUNT/domains/profitpocket.online/profitpocket-private/
    database.php
    email.php
    upgrade.php
    subscription-proofs/      # existing protected proofs; preserve them
        payment-qr/
```

Replace `ACCOUNT` with the actual hosting account shown in hPanel. The bootstrap
intentionally stops if this actual directory does not match the privately configured
installation. It does not guess production from an HTTP Host header. Use private
directory permissions `0700` and file permissions `0600` where supported and ensure
the website and cron PHP account can read them. No unrelated site's files are used.

**Preserve your existing hosted database connection before deploying.** If the
current server has credentials embedded in `config/database.php`, transfer those
same values into the private file below first. Do not replace them with local
XAMPP settings. The newly deployed `config/database.php` is a credential-free loader
included in the release manifest. Server environment variables override file values;
remove stale conflicting overrides or make them consistent for web PHP and cron.

Private `database.php` (use the existing ProfitPocket database and user from hPanel,
not a new database and not your thesis database):

```php
<?php
return [
    'PP_DB_HOST' => 'localhost', // or the actual existing database host
    'PP_DB_PORT' => '3306',
    'PP_DB_NAME' => 'YOUR_HOSTINGER_PROFITPOCKET_DATABASE',
    'PP_DB_USER' => 'YOUR_HOSTINGER_PROFITPOCKET_DATABASE_USER',
    'PP_DB_PASSWORD' => 'YOUR_EXISTING_DATABASE_PASSWORD',
];
```

Private `email.php` (merge into an existing file; preserve its working reset secret):

```php
<?php
return [
    'BREVO_API_KEY' => 'YOUR_PRIVATE_BREVO_API_KEY',
    'BREVO_SENDER_EMAIL' => 'YOUR_VERIFIED_BREVO_SENDER',
    'BREVO_SENDER_NAME' => 'ProfitPocket',
    'BREVO_REPLY_TO_EMAIL' => 'YOUR_SUPPORT_EMAIL',
    'BREVO_EMAIL_ENABLED' => false, // the setup job validates then enables it
    'PROFITPOCKET_BASE_URL' => 'https://profitpocket.online/profitpocket',
    'PROFITPOCKET_NOTIFICATION_SECRET' => 'INDEPENDENT_RANDOM_64_LOWERCASE_HEX_CHARACTERS',
];
```

Use your actual registered, active Brevo sender. Enter the API key only in this
private Hostinger file. Do not put it in the public loader, GitHub, JavaScript,
cron arguments or conversation. Keep cURL HTTPS certificate verification enabled;
if it fails, correct the hosting PHP CA/extension configuration rather than bypassing
TLS. Password-reset links require the independent persistent notification secret;
rotating it invalidates previously generated reset links.

Private `upgrade.php`: merge these keys into your existing array, preserving the
current subscription enforcement, storage directory and rollout settings:

```php
<?php
return [
    'PP_ENVIRONMENT' => 'production',
    'PP_PRODUCTION_APP_ROOT' => '/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket',
    'PP_PRODUCTION_DB_NAME' => 'YOUR_HOSTINGER_PROFITPOCKET_DATABASE',
    'PP_PRODUCTION_DB_USER' => 'YOUR_HOSTINGER_PROFITPOCKET_DATABASE_USER',
    'PP_PRODUCTION_BASE_URL' => 'https://profitpocket.online/profitpocket',
    'PP_PROVISION_TEST_ADMINS' => true,
    'PP_PROVISION_TOKEN' => 'ANOTHER_INDEPENDENT_RANDOM_64_LOWERCASE_HEX_SECRET',
    'PP_ACTIVATE_EMAIL' => true,
    'PP_EMAIL_TEST_AUTHORIZED' => true,
    'PP_EMAIL_TEST_ADDRESS' => 'braceros.gilro@ue.edu.ph',
    // Preserve your current values for all other existing upgrade.php keys.
];
```

Generate each 64-character lowercase hex secret privately using a password manager
or a cryptographically secure generator. The test inbox above was explicitly
authorized in this conversation. Do not replace it with customers or a mailing list.
Do not copy the example array over an existing file without retaining its other keys.

Existing optional location overrides remain supported:
`PROFITPOCKET_DATABASE_CONFIG`, `PROFITPOCKET_EMAIL_CONFIG` and
`PROFITPOCKET_UPGRADE_CONFIG`. Their files must resolve outside web roots.
The default paths above avoid relying on environment variables that may differ
between website PHP and cron. Ordinary XAMPP behavior and its private files remain
unchanged; `PP_ENVIRONMENT` defaults to development.

## 2. Deploy and authorize one setup cron job

The GitHub remote exists in this checkout, but there is no `.github` deployment
workflow or verified Hostinger hook here. In **Advanced → Git**, inspect the
configured repository, branch, target directory and auto-deployment status. Use
`public_html/profitpocket` as the target only if it matches your actual installation.
See [Hostinger Git deployment](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/).
A GitHub push is not evidence that deployment completed or that accounts exist.

Deploy this reviewed release with `bin/upgrade-release.json`. It contains 153
runtime hashes and no private configuration. The database loader is now included.
Release hashes normalize CRLF to LF so Git's Windows-to-Linux text conversion does
not reject the same source. Content changes still fail verification; existing
SQL migration checksum/completion rules are unchanged.
Do not rebuild this manifest on Hostinger to suppress a partial or unexpected upload.

When the complete release is installed and the private authorization above is set,
the next normal application initialization can provision the two test accounts.
There is no installer URL, administrator registration form, request token or password
in an HTTP response. The request only invokes the narrow, privately authorized
bootstrap; it neither logs in the visitor nor activates email or sends test mail.

For the email activation and single hosted test, open **Dashboard → Cron Jobs**.
Add a **PHP** job for:

```text
/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket/bin/production_setup.php
```

Use every five minutes (`*/5 * * * *`) temporarily. With PHP job mode, select the
actual script under your hosting account using hPanel's path prefix; do not duplicate
the `/home/ACCOUNT/` prefix if hPanel already supplies it. This executes using CLI,
not an HTTP URL. The private flags authorize its operations; no command arguments
or secrets are needed. It also provisions the accounts if no page initialized yet.

The job verifies Linux, explicit production configuration, the exact real application
path, `SELECT DATABASE()` and the authenticated `CURRENT_USER()` account. It rejects
XAMPP, the local database/root user and mismatched database identities before any
insert. The complete manifest and existing migration marker are prerequisites.
There is no fallback that silently targets another database.

The job checks the real Brevo sender over HTTPS, validates required settings and
schema, and atomically writes `BREVO_EMAIL_ENABLED=true` to the existing private
email file. A persistent environment override of `false` must be corrected first.
It refuses email links that do not match the actual production base URL.

It sends at most one connection-test attempt to the privately authorized inbox.
The attempt is recorded before contacting the API, and a database lock serializes
concurrent tests. API acceptance and a saved message ID are reported separately
from provider delivery and inbox receipt. An uncertain result is never resent
automatically. A hosted-only test marker prevents an imported local test marker
from being mistaken for a hosted send.

Inspect **Cron Jobs → View output**. Success should report administrators
`completed` or `already_completed`, and email `accepted` with a message ID.
`setup_already_attempted` means inspect the prior output/Brevo logs; it does not
prove the first attempt succeeded. No password or API key is printed.
Remove this temporary cron after inspecting the result. Its completion record
also disables further automatic setup attempts if you leave the job temporarily.

All files under `bin/`, `config/` and `includes/` remain denied over HTTP.
The CLI entry points additionally reject non-CLI execution, independent of Apache.

## 3. Verify delivery, login and ongoing notifications

The test uses the existing ProfitPocket HTML layout and live HTTPS buttons; it
does not fabricate a payment, reset or registration event. Brevo API acceptance
alone is not delivery. Check the authorized inbox, including spam, and the matching
message in [Brevo transactional logs](https://help.brevo.com/hc/en-us/articles/360021533839-Manage-your-transactional-logs-and-email-previews).

For an automated read-only provider check, temporarily add a PHP cron for:

```text
/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket/bin/check_test_email.php
```

Inspect its output after the first run and remove it. It fetches events for only the
saved test message ID and authorized recipient. `provider_delivery: delivered`
confirms Brevo reported delivery to the recipient server; `inbox_delivery` remains
`not_verified` because only you can confirm inbox receipt. Unrelated message events
cannot satisfy the check. See [Brevo's event API](https://developers.brevo.com/reference/get-email-event-report).

To inspect account role/status, permissions and first-password markers without
printing secrets, a **Custom** cron/authorized shell can run:

```sh
YOUR_PHP8_CLI /home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket/bin/production_setup.php --check --verify-sender
```

Use the server's actual PHP binary, not a Windows path. Running `bin/email_setup_check.php`
as a PHP cron prints safe readiness and the exact server CLI command paths, including
the checker above. Remove temporary diagnostic jobs once their output is reviewed.

Sign in at **https://profitpocket.online/profitpocket/auth/login.php** using the two
temporary credentials from the local handoff. The generated `@example.invalid`
addresses are unique test login identities, not inboxes. Email delivery to those
addresses is deliberately suppressed. For ongoing real administrator notifications,
change each profile to an actual controlled email after the first password change.

Both accounts use the existing `platform_admin` role and its current permissions;
no role IDs are assumed or permission grants fabricated. They are Active and exempt
from Business Owner subscription rules. A unique durable completion record and
role locks prevent duplicate provisioning. An existing matching email stops the
transaction rather than changing its password or promoting its role. Other existing
administrators are preserved; the application creates exactly these two additional
designated test accounts, not a forced total of two administrators.

The existing first-login gate requires each temporary password to be changed before
opening administrator pages. Saving the new password revokes sessions; sign in
again and verify Dashboard, Subscription Management, Payment Methods, Announcements,
Support and Settings as authorized by the existing role. Verify a Business Owner
receives access denied on administrator URLs. Those hosted checks remain unperformed
until you execute them; local tests are not substituted for hosted authentication.

For ongoing automated mail, inspect existing cron jobs first and add only missing
entries. Use PHP mode with the same application prefix above:

| Script | Schedule, UTC | Manila purpose |
| --- | --- | --- |
| `bin/process_notifications.php` | `*/5 * * * *` | Deliver queued messages through real Brevo HTTPS requests |
| `bin/generate_reminders.php` | `0 1 * * *` | Customer due reminders at 09:00 Manila |
| `bin/subscription_maintenance.php` | `5 1 * * *` | Subscription reminders at 09:05 Manila |

The application uses `Asia/Manila` and its MySQL session uses `+08:00`. Existing event
keys, notification outbox constraints, live recipient/permission checks, customer
consent and financial transaction isolation remain in place. No customer emails are
sent without the existing eligibility rules. Do not add duplicate worker/reminder
jobs or public cron URLs. See [Hostinger cron setup](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

All existing 16 notification types and templates are retained: welcome; reset;
password changed; payment recorded; partial payment; upcoming/overdue reminders;
balance settled; subscription submitted/approved/rejected/expiring/expired/renewed;
combined registration/payment welcome; authorized administrator review notice.
Public registration still defers welcome until the first proof, avoiding duplicates.

On hosting, use one controlled account to test actual registration/proof submission,
password reset and password change, followed by administrator approval/rejection.
Check the resulting outbox status and Brevo message logs; do not use customers for
tests. A successful connection test proves connectivity, not every hosted trigger.
For failures, inspect hPanel's PHP/cron error logs for sanitized configuration or
queue error categories. Do not publish logs or remove TLS/authorization checks.

## 4. Disable temporary provisioning after success

In private `upgrade.php`, set `PP_PROVISION_TEST_ADMINS=false`,
`PP_ACTIVATE_EMAIL=false` and `PP_EMAIL_TEST_AUTHORIZED=false`; remove the
`PP_PROVISION_TOKEN`. Keep the email file's `BREVO_EMAIL_ENABLED=true`, verified
sender/key, permanent reset secret, private database configuration and existing
subscription/storage settings. Normal notifications do not require bootstrap flags.

Remove `includes/production_test_accounts.php` from source and deploy that deletion
with a locally refreshed manifest (`php bin/build_upgrade_manifest.php`). Only hashes
were committed, and no plaintext credential needs to be scrubbed from source.
Do not delete `deployment_runs` completion/audit/password-change records. The marker
prevents future deploys from recreating deleted test accounts or resetting passwords.
No self-deleting PHP script or public credential-retrieval mechanism was added.

## Local verification and changed files

Eleven affected suites passed: production setup, auth, subscriptions, email,
registration, admin, HTTP auth, HTTP subscriptions, HTTP email, HTTP registration,
and integration. These used rollback/disposable fixtures and mocked or disabled
Brevo sending. The selected two production identities were verified absent locally.
The empty-payment-method HTTP scenario was skipped because a real receiving method
is configured; that method was preserved. No new real email was sent.

The new production test checks environment/path/DB guards, Host-header non-authorization,
private file loading and DSN validation, two hashed fixture identities, existing
authentication/session behavior, password-change gate, role/subscription exemption,
collision/repeat protection, mock activation in disposable private files, one-send
and timeout protection, correct links and scoped provider-event interpretation.
Both production CLI commands were also actually run locally and refused XAMPP.
LF/CRLF release equivalence and content-tamper rejection were also verified.

All 165 PHP files passed syntax checks; the reviewed manifest contains 153 runtime
files and its hashes match. No CSS, JavaScript, logos or email layouts were changed.

Created:

- `config/database.example.php`: private database file model.
- `includes/production.php`: explicit environment guard, one-time bootstrap and status.
- `includes/production_test_accounts.php`: temporary test emails/names and password hashes.
- `bin/production_setup.php`: private-authorized production setup/readiness job.
- `bin/check_test_email.php`: read-only message-specific hosted delivery check.
- `tests/production_setup_test.php`: guards, hashed provisioning, private activation and mocked delivery tests.
- This document.

Modified:

- `config/database.php`: shared private-file database loader with environment precedence.
- `config/upgrade.php`, `config/upgrade.example.php`: explicit production identity/authorization keys.
- `includes/auth.php`: optional Linux production initialization; no local provisioning.
- `includes/deployment.php`: reuse bootstrap with prehashed identities, strict collisions and separate completion key.
- `includes/email_diagnostics.php`: reusable real activation, controlled send and scoped delivery check.
- `bin/activate_email.php`, `bin/send_test_email.php`: reuse those helpers.
- `bin/email_setup_check.php`: print actual hosted job/check command paths.
- `bin/build_upgrade_manifest.php`, `bin/upgrade-release.json`: include loader/new runtime files.
- `tests/http_subscriptions_test.php`: deny new private routes over HTTP.
- `tests/http_registration_test.php`: preserve real configured methods during empty-state testing.
- `README.md`, `database/SYSTEM-UPGRADE.md`: link current hosting instructions.

Production database identity, sender verification, actual SMTP API response,
recipient inbox receipt, both hosted logins, hosted trigger processing and cron/Git
configuration remain **unverified**, not claimed successful. No private API key,
database password or temporary plaintext administrator password is in this document.
