# ProfitPocket homepage, subscriptions and deployment — 25 October 2026

For the current production-only administrator bootstrap, shared private database
configuration and hosted Brevo activation/test job, use
[HOSTINGER-PRODUCTION-SETUP.md](HOSTINGER-PRODUCTION-SETUP.md). It supersedes the
manual-password/bootstrap and database-loader deployment details below. Production
configuration and actual hosted verification remain separate from local success.

The subsequent [registration payment and QR revision](REGISTRATION-PAYMENTS-REVIEW.md)
adds guest onboarding, protected QR management and two event types (16 total).
Read that review for the current registration flow and next additive migration.

## Current result

The public homepage, ₱100 monthly subscription workflow, private proof uploads,
administrator verification, server-side paid access checks, six subscription
emails, safe email diagnostics and one-time CLI administrator provisioner are
implemented in the existing application.

The subscription migration and Pending Payment policy were explicitly approved
and applied to local XAMPP. Local subscription enforcement is **enabled** using
`C:\xampp\profitpocket-private\upgrade.php`, outside `htdocs`. No historical paid
time was invented; existing owners start Pending Payment. Their business data is
preserved. Existing administrators are exempt.

The public login at `https://profitpocket.online/profitpocket/auth/login.php`
returned HTTP 200 during a read-only check. The application base is therefore
`https://profitpocket.online/profitpocket`, including its subdirectory.

**Local XAMPP email sending is enabled and tested.** The privately configured
sender was confirmed active by Brevo, and one real connection-test email was
accepted for `braceros.gilro@ue.edu.ph`. Brevo's message-specific activity API
confirmed `delivered` at 11:46 pm Manila on 25 October 2026. Its message ID is
`<202610251546.76523787024@smtp-relay.mailin.fr>`. Credentials were loaded only
from the private configuration outside `htdocs` and were not displayed or committed.
Delivery to the recipient server does not establish inbox versus spam placement.
The test recipient is not automatically either bootstrap administrator.

Hostinger production activation remains separate: no production configuration or
database connection was supplied. Normal queued events require the notification
worker to run; schedule it as documented below. The successful direct test did not
install a local scheduler or change Hostinger cron jobs.

No production migration, deployment, administrator creation or GitHub push was
performed. The real designated administrator emails/passwords and real receiving
payment account details must be supplied privately/configured by an administrator.

## Architecture and access

Existing `users`, `roles`, `permissions`, `role_permissions`, `businesses`,
`auth_sessions`, `password_reset_tokens`, `admin_action_logs` and `platform_settings`
are reused. The existing `platform_admin` role already has the required
permissions; no public administrator registration or new role escalation exists.
The imported owner-to-admin protection trigger remains unchanged.

The new subscription ledger is entirely separate from `sale_payments` and the
business financial views. Ordinary customer collections, financial totals,
reports and historical records retain their existing calculations.

| Owner condition | Behavior |
| --- | --- |
| Pending Payment | Sign in, read instructions, upload proof; paid tools restricted |
| Pending Verification | Read submission status/history; paid tools restricted unless a previous subscription is still valid |
| Active | Use existing business modules until the exact expiry timestamp |
| Expired | Renew, manage password/account, contact support and sign out; existing records preserved |
| Rejected | Read reason and submit corrected proof; any existing paid time remains valid |
| Suspended/inactive account | Existing authentication suspension rules apply; payment approval cannot reactivate the account |
| System Administrator | No subscription fee; existing permission checks still apply |

`home_path()` routes owners to their dashboard or subscription page from the
current database state. Every paid endpoint calls the shared business guard;
Dashboard has the same subscription check. This covers page loads, action/AJAX
URLs, receipt access and report exports. Expiry takes effect on the next request
without waiting for cron. Subscription, account settings, password changes,
support and logout remain accessible to authenticated eligible owners.

`PP_SUBSCRIPTIONS_ENABLED` is a private deployment control, not a user setting.
Its source default is false for staging the cutover; enable it on production only
after the approved migration/policy and receiving accounts are ready. With the
flag true, a missing schema or missing/expired subscription fails closed into the
subscription page. Local enforcement is already true. Do not treat the staged
default as a completed production rollout.

## Registration, proof and verification

Registration always creates an owner and linked business through the existing
transaction. After the approved migration, a Pending Payment subscription row is
created in that transaction. The welcome email explains activation when the
rollout is enabled. Signing in does not grant paid access by itself.

The owner page shows the fixed ₱100 price, actual period/status, configured
payment recipients, an optional reference, private proof upload and recent
submission history. There is no payment gateway or automatic recurring charge.

Proofs allow actual JPG/PNG/PDF MIME types, up to 5 MB. Images have dimension
limits and PDFs require a PDF header. Original filenames are ignored; random
`.bin` names are stored outside the web root with restrictive permissions. The
protected download endpoint checks the current owner/admin and serves attachments
with no-store, nosniff and sandbox headers. Proofs cannot execute as web scripts.

CSRF tokens, random submission keys, unique proof hashes, optional reference
hashes and owner row locks prevent repeat submissions. Only one submission may
be pending for an owner. A rejected reference can be used with corrected proof;
an already submitted file or an approved/pending reference cannot be reused.
Uploads are limited to ten submissions per account per day. Failed/replayed
requests clean up newly stored files.

Administrators with `users.manage` see real overview counts, verified revenue,
search/status filters, pagination, proof details and decision forms. Approval
requires both confirmation and explicit acknowledgment that the actual ₱100 was
verified in the receiving account. A screenshot alone is never approval.
Rejection requires a reason. Both decisions lock the owner/submission, save the
reviewer/time, update any paid period, record an administrative audit and enqueue
the appropriate email atomically. Concurrent approvals grant just one period.

Payment instructions are managed under **Administration → Subscriptions →
Configure receiving payment accounts**. Editing them requires both `users.manage`
and `settings.manage`. Enter the real method name (for example your configured
wallet or bank), recipient, account identifier and instructions. No merchant
accounts or numbers were seeded. Methods can be disabled; existing submissions
retain a snapshot of the instructions used when submitted.

## Calendar-month policy

An approved first subscription starts at its actual approval timestamp in
`Asia/Manila`. Its expiry is one calendar month later, preserving the time of day
and clamping the day to the next month's last valid day.

- 31 January 2026 → 28 February 2026; 31 January 2028 → 29 February 2028.
- Early renewal starts at the current expiry, preserving already paid time.
- The original billing-day anchor is retained across uninterrupted renewals:
  January 31 → February 28 → March 31.
- An expired renewal restarts at approval and establishes a new billing anchor.
- Each approved submission retains its own actual added period and decision.

There is no automatic charge and no deletion or resetting of business records.

## Email activation and test

The original eight transaction/security emails are preserved, with six new types:

| Type | Trigger |
| --- | --- |
| `subscription_submitted` | Owner submits a valid proof; confirmation that review is pending |
| `subscription_approved` | First approved subscription |
| `subscription_rejected` | Rejected proof, including reason and resubmission link |
| `subscription_expiring` | Three Manila calendar days before expiry |
| `subscription_expired` | First maintenance check after expiry; once per actual period |
| `subscription_renewed` | Approved renewal, including the actual new expiry |

The outbox, HTTPS cURL transport, branded PHP layout, escaping, peso/time formats,
bounded retries and duplicate protection are reused. Account subscription notices
go to the stored owner email; customer mail still requires its separate explicit
customer permissions. Customer payment/reminder emails are skipped when that
owner's paid subscription is inactive. Subscription notices are skipped when the
rollout is disabled. Superseded reminder dates are checked again at dispatch.
No ticket, feedback or promotional mail was added.

Configure the existing private `email.php` outside `public_html`, or the
persistent hosting environment, using `config/email.example.php` as a blank model:

| Setting | Required production value |
| --- | --- |
| `BREVO_API_KEY` | Replacement key, created/stored privately; revoke the exposed key |
| `BREVO_SENDER_EMAIL` | Actual registered/verified Brevo sender |
| `BREVO_SENDER_NAME` | `ProfitPocket` |
| `BREVO_REPLY_TO_EMAIL` | Monitored support address, or the sender fallback |
| `PROFITPOCKET_BASE_URL` | `https://profitpocket.online/profitpocket` |
| `PROFITPOCKET_NOTIFICATION_SECRET` | Independent random 32-byte secret encoded as 64 lowercase hex characters |
| `BREVO_EMAIL_ENABLED` | `true` after readiness and sender checks |

Keep secrets out of source, GitHub, logs, JavaScript, public PHP and chat.
`PROFITPOCKET_EMAIL_CONFIG` can specify an absolute private config path. The
default private directory is a sibling of the actual `public_html` directory.
Environment variables take precedence and must be shared by web PHP and cron.

From the deployed app directory:

```sh
php bin/email_setup_check.php --verify-sender
php bin/activate_email.php --confirm
php bin/send_test_email.php --confirm
```

The diagnostic prints booleans and missing variable names, never their values.
`--verify-sender` makes a real read-only API request and reports whether the
configured sender is active; it does not prove transactional authorization,
quota or delivery. The activation command checks the schema, URL, independent
secret and sender before atomically enabling the existing private file. If a
persistent environment variable overrides the file with false, it explicitly
reports that hosting variable must be changed to true instead.

For the one real test, set these in the private **upgrade** configuration first:

```php
'PP_EMAIL_TEST_AUTHORIZED' => true,
'PP_EMAIL_TEST_ADDRESS' => 'braceros.gilro@ue.edu.ph',
```

The test sends one clearly labelled connection-test email using the shared layout
and actual Brevo API. It does not pretend a payment or password change occurred.
A durable release/recipient marker is saved before the attempt; repeated runs
cannot silently send again. Review an uncertain attempt in Brevo before changing
that marker. The returned message ID proves acceptance only. Verify inbox/spam
delivery separately. Turn off test authorization afterward.

The actual requests use [Brevo's send endpoint](https://developers.brevo.com/reference/send-transac-email);
sender diagnostics use [Brevo's sender endpoint](https://developers.brevo.com/reference/get-senders).
The original [email guide](EMAIL-NOTIFICATIONS.md) explains reset-token protection
and outbox states. A provider failure cannot roll back a committed financial or
subscription operation.

## Migration order, backup and cutover

1. Back up the intended production database, application files and private proof
   directory. Announce the approved Pending Payment transition to existing owners.
2. Preserve production `config/database.php` and existing `PP_DB_*` connection
   settings. No Hostinger credentials or production account path were available
   locally. The CLI and website must use the same intended database and PHP version.
3. If the prior email tables are missing, first review/apply the existing
   `database/email-notifications-proposal.sql` once. If they already exist, inspect
   their schema and do not import over them. The new runner refuses to proceed
   without this prerequisite.
4. Review `database/migrations/20261025_subscriptions.sql`. It creates
   `deployment_runs`, `subscription_payment_methods`, `owner_subscriptions` and
   `subscription_payments`, then adds the nullable `subscription_payment_id`
   outbox reference. No financial table, view, trigger, role or permission changes.
5. In private `upgrade.php`, authorize only this reviewed migration:
   `PP_DEPLOY_MIGRATIONS_APPROVED='20261025_subscriptions'` and
   `PP_EXISTING_OWNER_POLICY='pending-payment'`. This is separate from local approval.
   Keep `PP_SUBSCRIPTIONS_ENABLED=false` during the staged upload/migration.
6. Deploy the reviewed runtime files and the generated `bin/upgrade-release.json`.
   Run `php bin/deploy_upgrade.php --migrate-only`, or configure the preauthorized
   cron runner below. The version/checksum marker, conditional table creation and
   explicit existing-column check make reruns safe and interrupted DDL resumable.
   MySQL DDL is not transactionally reversible; never edit an applied version's SQL.
7. Configure real receiving accounts in the admin module, private proof storage,
   email settings and the two administrator settings below. Set
   `PP_SUBSCRIPTIONS_ENABLED=true` to perform the announced cutover. Missing owner
   rows receive no paid access; no historical paid subscription is fabricated.
8. Execute controlled real inbox/payment review tests using authorized test
   accounts. Keep production financial operations separate from test fixtures.

The local schema now has 34 base tables, 8 views and 27 triggers. The original
schema export remains a base reference; these additive migrations document the
new definitions. No full dump import, reset or existing financial record rewrite
is part of this upgrade.

For code rollback, use a maintenance window and the known working application
backup. Preserve new tables, audit/outbox records and private proof files so paid
periods/decisions are not lost. Retain the cutover configuration for the corrected
release; do not reopen the old ungated application to unpaid users. Restore a
database backup only as a separately planned recovery action, not as routine
rollback. The application never drops the new tables automatically.

## Two designated administrators and deployment automation

The checkout contains the GitHub remote
`https://github.com/GilroBraceros/ProfitPocket.git`, with no `.github` workflow or
configured server post-deployment command. Hostinger's actual Git settings were
not accessible. A push has not been claimed to create accounts automatically.

Use `config/upgrade.example.php` as a blank model for a private `upgrade.php`
outside `public_html`. `PROFITPOCKET_UPGRADE_CONFIG` may specify its absolute path.
Configure once on Hostinger:

| Setting | Meaning |
| --- | --- |
| `PP_SUBSCRIPTIONS_ENABLED` | True after migration and announced owner cutover |
| `PP_SUBSCRIPTION_STORAGE` | Absolute writable private proof directory outside all document roots |
| `PP_DEPLOY_MIGRATIONS_APPROVED` | Exact reviewed version `20261025_subscriptions` |
| `PP_EXISTING_OWNER_POLICY` | Explicit `pending-payment` policy |
| `PP_BOOTSTRAP_AUTHORIZED` | True only for the authorized initial provisioning |
| `PP_BOOTSTRAP_TOKEN` | Independent random 64-character lowercase hex authorization secret |
| `PP_BOOTSTRAP_ADMIN1_EMAIL`, `PP_BOOTSTRAP_ADMIN2_EMAIL` | Two distinct real emails, supplied privately |
| `PP_BOOTSTRAP_ADMIN1_PASSWORD`, `PP_BOOTSTRAP_ADMIN2_PASSWORD` | Distinct strong initial passwords, 16–72 bytes, supplied privately |
| `PP_BOOTSTRAP_ADMIN1_NAME`, `PP_BOOTSTRAP_ADMIN2_NAME` | Actual administrator names, supplied privately |

Use a private directory/file readable by the PHP account (typically `0700`/`0600`).
Never place initial passwords or keys in GitHub Actions output or a public installer.

`bin/deploy_upgrade.php` is CLI-only and denied over HTTP. With the exact private
authorizations, it checks the release manifest, acquires a database deployment
lock, applies the approved version and provisions both accounts in one transaction.
It locates the existing `platform_admin` role and verifies its existing required
permissions. It hashes passwords, never changes an existing administrator's
password, refuses owner/incompatible-account collisions and records completion.
Newly provisioned accounts must change the initial password before opening admin
pages, including direct URLs. Password changes revoke existing sessions.

The durable completion marker prevents future deploys from duplicating/recreating
accounts or resetting credentials. After successful provisioning/sign-in/password
changes, set `PP_BOOTSTRAP_AUTHORIZED=false` and remove the initial passwords and
bootstrap token from private configuration. Retain completion/audit records.

Build `bin/upgrade-release.json` locally using `php bin/build_upgrade_manifest.php`
after final changes/tests, then deploy it with that release. The cron runner waits
until all listed runtime files match, so it does not bootstrap a partially uploaded
release. The production database connection file/private settings are excluded.
Do not rebuild the manifest on the server merely to suppress an unexpected mismatch.

### Hostinger cron jobs

Run `php bin/email_setup_check.php` **on Hostinger**. It prints exact quoted
commands using that server's actual `PHP_BINARY` and absolute installed script
paths. Use the printed command in a Custom job, or its PHP script path in hPanel's
PHP job mode. A hosting username/absolute path was not guessed from the public URL.

| Script | Cron schedule (UTC) | Purpose |
| --- | --- | --- |
| `bin/deploy_upgrade.php` | `*/5 * * * *` | Detect a complete deployed release; run only preauthorized migration/bootstrap |
| `bin/process_notifications.php` | `*/5 * * * *` | Send queued emails through the real API |
| `bin/generate_reminders.php` | `0 1 * * *` | Original customer due reminders at 09:00 Manila |
| `bin/subscription_maintenance.php` | `5 1 * * *` | Subscription expiry reminders at 09:05 Manila |

Hostinger schedules use UTC, while all subscription dates are calculated in
Manila. See the [Hostinger cron documentation](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).
If a secure post-deployment shell hook is available in your actual setup, invoke
the same CLI runner there instead of the bootstrap cron. See
[Hostinger Git deployment](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/).
No public bootstrap URL, first-visitor installation or page-load provisioning exists.

## Verification

Executed against local XAMPP with fictional payment details/test accounts:

- `tests/subscriptions_test.php`: pending/active/expired/rejected states, receipt
  confirmation, exact price, month-end/leap-year anchors, early/expired renewal,
  proof/reference/request replay, six mocked email triggers, repeat reminders,
  admin exemption and atomic two-admin bootstrap/idempotence. All fixtures roll back.
- `tests/http_subscriptions_test.php`: actual PNG/PDF/JPG multipart uploads,
  authorized private downloads, cross-owner/admin permission checks, CSRF,
  actual review/rejection/resubmission, approval replay, first-login admin password
  change, all 18 paid page/action/export guards for unpaid and expired sessions,
  restored paid access, and simultaneous reviews by two administrators. Temporary
  accounts, records and private proof files are removed by exact IDs/filenames.
- `tests/browser_subscriptions_test.ps1`: 45 layouts at 1440/768/320 pixels for the
  homepage, owner/admin states and six new emails, plus mobile navigation,
  keyboard focus, loading/disabled states and preserved Approve submitter values.
  It uses actual HTTP snapshots after fixture cleanup through a read-only loopback
  server; it cannot execute mutations. Screenshots were inspected; no JavaScript
  exceptions or horizontal page overflow occurred.
- All existing Auth, Sales, Expenses, Customers, Payments, Utang, Products, Reports,
  Dashboard, Admin, Integration and corresponding HTTP/email suites passed with
  enforcement enabled. Positive legacy HTTP fixtures explicitly receive active
  test-only periods through a helper that refuses non-reserved real email addresses.
- Existing 8-email tests and the new 6-email tests use mock transport; production
  continues to use real HTTPS cURL whenever privately enabled. The safe activation
  command refused incomplete configuration as expected.
- Final syntax checks passed for all 145 PHP files and 14 JavaScript files. The
  reviewed release manifest matches all 137 runtime files. Final inspection found
  no retained upgrade-test accounts, subscription payments, test payment methods,
  queued emails or proof files. Only the approved migration completion marker and
  real owners' pending subscription rows remain; local enforcement is enabled.

Run locally with Apache/MySQL and private proof storage ready:

```powershell
C:\xampp\php\php.exe tests\subscriptions_test.php
C:\xampp\php\php.exe tests\http_subscriptions_test.php
C:\xampp\php\php.exe tests\email_test.php
C:\xampp\php\php.exe bin\preview_emails.php
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\browser_subscriptions_test.ps1
```

These scripts create only reserved fictional accounts, but use real database
transactions/HTTP uploads. Never run the fixture suites on production. Disposable
auto-increment IDs may advance. Preview HTML/screenshots remain under protected,
ignored `tests/http-results/`.

Outstanding production checks: actual private settings, verified sender and
transactional authorization/quota, one accepted real test email and separate inbox
delivery, Gmail/Outlook rendering, real receiving account details, designated
administrator provisioning, configured Hostinger deployment/cron jobs and the
announced existing-owner cutover. None were falsely marked complete. Delivery
webhooks and email ownership verification remain outside this upgrade.

## File inventory for this upgrade

| Created | Purpose |
| --- | --- |
| `admin/subscriptions.php` | Real metrics, directory, payment review and receiving-account configuration |
| `user/subscription.php`, `user/subscription_proof.php` | Activation/renewal/history and protected proof downloads |
| `includes/subscriptions.php`, `includes/subscription_navigation.php` | Shared policy, transactions, private storage, reminders and account navigation |
| `includes/deployment.php`, `includes/email_diagnostics.php` | Versioned deployment/bootstrap and safe real-email diagnostics |
| `config/upgrade.php`, `config/upgrade.example.php` | Environment/private-file upgrade configuration |
| `assets/css/subscriptions.css`, `assets/js/subscriptions.js` | Scoped responsive design and duplicate-submit/loading behavior |
| Six `emails/templates/subscription_*.php` files | Branded subscription lifecycle emails |
| `bin/deploy_upgrade.php`, `bin/subscription_maintenance.php` | Authorized CLI deployment/bootstrap and scheduled reminders |
| `bin/activate_email.php`, `bin/send_test_email.php` | Verified private enablement and one authorized real connection test |
| `bin/build_upgrade_manifest.php`, `bin/upgrade-release.json` | Complete-release detection without public provisioning |
| `database/migrations/20261025_subscriptions.sql`, `database/SUBSCRIPTION-PROPOSAL.md`, `database/SYSTEM-UPGRADE.md` | Reviewed schema, cutover policy and this guide |
| `tests/subscriptions_test.php`, `tests/http_subscriptions_test.php`, `tests/subscription_test_helpers.php`, `tests/subscription_visual_router.php`, `tests/browser_subscriptions_test.ps1` | Rollback, HTTP/concurrency, paid fixtures and responsive verification |

| Modified | Change |
| --- | --- |
| `index.php` | Public homepage with requested sections and authenticated routing |
| `includes/auth.php`, `includes/passwords.php`, `auth/register.php` | Owner enrollment/routing, paid guards and first-login password handling |
| `user/dashboard.php`, `user/settings.php` | Dashboard enforcement and accessible real account/security information |
| `includes/admin.php`, `includes/admin_navbar.php`, `includes/admin_dashboard_content.php` | Subscription sidebar entry and accurate account-status wording |
| `includes/header.php`, `includes/footer.php`, `includes/navbar.php` | Scoped assets, homepage footer and subscription/account links |
| `includes/notifications.php`, `includes/email_templates.php`, `emails/layout.php`, `emails/templates/welcome.php` | Six additional types, current subscription eligibility and activation-aware welcome |
| `config/email.php`, `config/email.example.php`, `bin/email_setup_check.php`, `bin/preview_emails.php` | Private activation path, known HTTPS base, safe diagnostics and 14 previews |
| `tests/auth_test.php`, `tests/http_auth_test.php`, `tests/http_admin_test.php`, `tests/http_integration_test.php`, `tests/email_test.php` | Update positive fixtures/expectations for paid access and 14 templates |
| `.gitignore`, `README.md`, `database/EMAIL-NOTIFICATIONS.md` | Exclude private/generated artifacts and link current deployment instructions |

The previous email-module files remain in this working tree; their original
inventory is in `EMAIL-NOTIFICATIONS.md`. They are preserved, not overwritten.
The local private `C:\xampp\profitpocket-private\upgrade.php` is outside the repo
and contains only enforcement/storage configuration, no API key or admin passwords.
