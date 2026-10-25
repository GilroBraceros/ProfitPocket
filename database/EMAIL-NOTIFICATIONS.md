# Brevo transactional email implementation — 25 October 2026

The subsequent homepage/subscription upgrade extends this service to 14 event
types and adds private activation/testing commands. Read
[SYSTEM-UPGRADE.md](SYSTEM-UPGRADE.md) for the current rollout/deployment steps.
The original eight types and security/reliability behavior below are preserved.

The original implementation renders eight emails in PHP and submits them through
Brevo API v3 using HTTPS cURL. No Brevo dashboard templates, SMTP, PHPMailer,
Composer packages or `mail()` calls are needed. Sending is disabled by default.
The key shared in chat was not stored or used. Revoke it and create a replacement
privately before enabling production email.

## Existing architecture and data

The implementation reuses `db()` and the existing authentication, Sales, Payments,
Customers and Utang helpers. Account data comes from `users` and `businesses`;
password recovery uses the existing `password_reset_tokens` and `auth_sessions`.
Customer emails come from `customers.email`. Payment amounts, timestamps,
references and collection/refund relationships come from `sale_payments`;
balances and due dates use the existing sale financial view, `sales`,
`customer_credits` and payment classification. No financial formula was replaced.
There was no existing mail transport or scheduled reminder worker.

`database/email-notifications-proposal.sql` adds only:

- `customer_email_preferences`: one row per customer, composite customer/business
  foreign key, separate receipt/reminder permissions, email hash, consent note and
  updating user. Existing customers have no assumed permission.
- `notification_outbox`: unique event hash, entity references, recipient, job state,
  attempt times/count, failure category, Brevo message ID and reset nonce.
  References are deliberately soft so an audit record can survive deletion;
  every send resolves and authorizes the current records again.

The two tables were applied **only to local XAMPP with explicit approval**.
The local database now has 30 tables, 8 views and 27 triggers. Existing financial
tables, views and triggers were unchanged. The original schema export remains a
reference; use the separate migration for these additions. No production database
was accessed or changed.

## Event rules

| Type               | Trigger and recipient                                                                                 |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| `welcome`          | Successful owner registration; once per actual user creation                                          |
| `password_reset`   | Eligible active account requests recovery; 30-minute, single-use link                                 |
| `password_changed` | Saved authenticated password change, admin profile password change, or completed reset; account owner |
| `payment_recorded` | Ordinary initial fully paid sale; consenting customer                                                 |
| `partial_payment`  | Downpayment or collection leaving a positive balance; consenting customer                             |
| `upcoming_due`     | Posted credit with positive balance due in 3 Manila calendar days; consenting customer                |
| `overdue`          | Posted credit with positive balance due 1 Manila calendar day ago; consenting customer                |
| `balance_settled`  | Collection closing an existing positive outstanding balance; consenting customer                      |

Events enqueue inside the original transaction. The CLI worker sends only after
commit, so rolled-back events cannot be emailed. Payment events use one unique
payment key and one classification; retrying a payment cannot create multiple
confirmations. Initial full payment is distinct from settling existing credit.
Voided sales, refunded collections, inactive recipients and obsolete reminders
are skipped. An unsent partial confirmation is skipped if the balance is already
fully settled. Financial data is read when dispatching, not stored in the log.
Payment emails identify the business as the recorder of the payment.

Owners record customer agreement in **Customers → Add/Edit → Email permissions**.
Enabling a permission or changing an opted-in address requires confirmation and
a consent note. Permission is bound to that address, not transferred silently.
Customers can have both permissions off. Existing customers are never bulk
enrolled. Manual reminder buttons remain disabled; reminders use the defined
scheduled stages, not arbitrary daily sends.

## Secure server configuration

Environment variables override a private PHP configuration file. Copy the blank
`config/email.example.php` **outside `public_html`** and fill it privately.
Do not put the real key in Git, JavaScript, HTML, `.htaccess`, a public `.env`,
screenshots or chat. `config/email.php` contains only the loader and safe defaults.

| Variable                           | Value                                                                                                     |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `BREVO_API_KEY`                    | YOUR_PRIVATE_BREVO_API_KEY                 |
| `BREVO_SENDER_EMAIL`               | noreply@profitpocket.online                                                                               |
| `BREVO_SENDER_NAME`                | `ProfitPocket`                                                                                            |
| `BREVO_REPLY_TO_EMAIL`             | Monitored support mailbox; sender is the fallback                                                         |
| `BREVO_EMAIL_ENABLED`              | `false` initially; `true` only after setup and controlled testing                                         |
| `PROFITPOCKET_BASE_URL`            | Actual absolute HTTPS app URL, including any subdirectory; no query or fragment                           |
| `PROFITPOCKET_NOTIFICATION_SECRET` | Independent random 32-byte secret encoded as 64 lowercase hex characters                                  |
| `PROFITPOCKET_EMAIL_CONFIG`        | Optional absolute path to the private PHP file when the default location does not fit your hosting layout |

The default private path is a sibling of the actual `public_html` directory:
`profitpocket-private/email.php`. For local XAMPP it is
`C:\xampp\profitpocket-private\email.php`. The loader rejects files under
`public_html`, `htdocs`, `www`, or the detected document root. Use a private
directory readable by your PHP account (typically directory `0700`, file `0600`).
Generate the independent secret with `bin2hex(random_bytes(32))` on your private
server terminal and transfer it directly into that file. Never reuse the API key
as the reset secret. Rotating this secret invalidates queued, unsent reset links;
request fresh links afterward.

Reset bearer tokens are never persisted: the existing reset table stores only
SHA-256 hashes. A random nonce plus the separate private secret derives the
token for the queued email. The nonce is cleared after a terminal attempt.
Reset links use `#token=...`; JavaScript copies the code into the POST form and
removes the fragment. The token is absent from HTTP request URLs and referrers.
Pages use no-store headers; reset pages also use no-referrer. Do not enable
request-body, payload or email-content debugging in production. Restrict access
to Brevo logs/previews, which can contain the email itself.

Password requests show the same response for existing/unknown accounts, limit
requests to 3 per account and 10 per IP in 15 minutes, and serialize creation.
Completion rejects expired/used tokens and revokes existing account sessions.
Changing a password from the account menu requires the current password and
signs out every session. The admin profile password path also sends the alert.

## Hostinger deployment

No deployment pipeline or live domain/account path was verified from this local
checkout. A GitHub push alone must not be treated as deployment. Use your actual
configured pipeline, or upload the changed application files through the
existing SFTP/File Manager workflow, preserving production database configuration
and unrelated applications.

1. Back up the production application/database using your normal process. Keep
   `BREVO_EMAIL_ENABLED=false` during deployment.
2. Upload the runtime files listed below, including `bin/.htaccess` and
   `emails/.htaccess`. Keep tests, generated previews and this database directory
   private. Preserve the existing original logo files and public routes.
3. Apply **only** `database/email-notifications-proposal.sql` manually to the
   intended production database after reviewing/authorizing that production
   migration. It is a one-time migration, not an idempotent installer. If either
   table already exists, inspect its definition first. Do not import the full
   schema dump or reset any records. Local approval did not deploy this migration.
4. Confirm PHP 8+, cURL, OpenSSL, PDO MySQL, mbstring and BCMath are enabled for
   both web and CLI PHP. Existing financial modules already use BCMath. Ensure
   outbound HTTPS to Brevo works and sender/domain verification is complete.
5. Configure the replacement key, verified sender, support mailbox, real HTTPS
   base URL and independent secret privately. Keep the existing `PP_DB_*`
   configuration available to both the website and cron. Do not overwrite a
   working production `config/database.php` with local connection settings.
6. From the actual deployed app directory, run `php bin/email_setup_check.php`.
   It prints safe readiness booleans and **exact absolute commands for that
   server's PHP binary and script locations**, without printing credentials.
   Check `sending_enabled` separately from `configuration_valid`; the diagnostic
   can validate configured settings while sending is off. Confirm the schema and
   secret checks are true before proceeding.
7. Add and test the two cron jobs below. Confirm the reset, dashboard, support
   and logo HTTPS links resolve on the actual domain/subdirectory. Check private
   `bin/`, `emails/`, `config/` and `includes/` URLs return 403.
8. Enable sending for a controlled release, use only your own test account and
   consenting test customer, and verify the checklist below. Existing events
   created while disabled are not queued for retrospective sending. Existing
   customer permissions remain off until explicitly recorded.

### Cron commands and schedule

Use the two command lines printed by `email_setup_check.php` on Hostinger; the
first runs `process_notifications.php`, the second `generate_reminders.php`.
These contain real paths rather than guessed `/home/...` account names. In
hPanel's PHP job mode, use the corresponding absolute script path; in Custom
mode, use the complete printed command. Check the CLI binary matches your PHP
version and sees the same private configuration as the website.

| Job                         | Cron schedule (UTC) | Purpose                                       |
| --------------------------- | ------------------- | --------------------------------------------- |
| `process_notifications.php` | `*/5 * * * *`       | Process up to 30 queued notifications per run |
| `generate_reminders.php`    | `0 1 * * *`         | Queue due stages once daily at 09:00 Manila   |

Hostinger's cron schedules use UTC; application date arithmetic uses Manila.
The sender may run up to five minutes after queuing. A global MySQL advisory
lock prevents overlapping workers. Repeated reminder runs are deduplicated by
sale, actual due date and stage. No cron URL or public test-sending endpoint exists.
See [Hostinger's cron guide](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

## Reliability and operational checks

The service posts PHP-rendered `htmlContent` and `textContent` with the `api-key`
header to the [official send endpoint](https://developers.brevo.com/reference/send-transac-email).
TLS verification stays enabled; network requests have bounded connect/total
timeouts. The log records safe failure categories rather than provider response
bodies or cURL diagnostics containing secrets.

| Outbox state | Meaning                                                                                                           |
| ------------ | ----------------------------------------------------------------------------------------------------------------- |
| `pending`    | Waiting for the worker or a permitted retry                                                                       |
| `processing` | Claimed for one API attempt                                                                                       |
| `accepted`   | HTTP 201 with a Brevo message ID; delivery is not proven                                                          |
| `failed`     | Rejected/configuration failure or retry limit reached                                                             |
| `skipped`    | Missing consent/data, invalid recipient, obsolete event or incomplete configuration                               |
| `uncertain`  | Timeout/interrupted attempt/duplicate key/unknown acceptance; inspect provider logs before deciding what happened |

HTTP 429, server failures and known preconnection failures can retry, at most
three attempts within ten minutes of the first attempt, reusing the same UUID
`idempotencyKey`. This is deliberately shorter than Brevo's documented
[idempotency window](https://developers.brevo.com/docs/heterogenous-versions-batch-emails).
Unknown outcomes stop automatic resends. Do not manually reset an uncertain job
or replay an expired provider key without reviewing Brevo first. A crashed
processing job becomes uncertain on the next worker run.

Queue savepoints isolate ordinary notification insert errors from financial
transactions. Network requests occur only in the worker after commit, so an API
failure cannot undo a payment. Do not delete outbox/audit keys casually: they are
the application's durable duplicate protection. Review outbox state, failure
category, attempt times and message ID using authorized database administration.
There is no added public mail-management UI or delivery webhook. Confirm actual
delivery in Brevo and the recipient inbox; API acceptance alone is not delivery.

## Local testing and results

Run against local XAMPP only, with Apache/MySQL running and real sending disabled:

```powershell
C:\xampp\php\php.exe tests\email_test.php
C:\xampp\php\php.exe tests\http_email_test.php
C:\xampp\php\php.exe bin\preview_emails.php
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\browser_email_test.ps1
C:\xampp\php\php.exe bin\email_setup_check.php
```

`email_test.php` uses a fabricated credential and injected mock transport, with
reserved fake addresses. Financial fixtures run inside rollback transactions;
the disposable password-test account and its metadata are cleaned up. It tests
all eight notifications, exact balances, escaping/formatting, consent, missing
recipient, missing credentials, API rejection/429/5xx/timeout, malformed acceptance,
payment replay, repeat reminders, bounded retries, precommit-send prevention,
an actual queue insertion failure isolated from valid financial rows, hashed
tokens, expiration/single use, password changes and rate limits. The intentional
queue-failure test produces the sanitized `queue_unavailable` log message.

`http_email_test.php` verifies private/CLI routes, guest restrictions, generic
reset responses, security headers, CSRF, actual password changes, revocation of
an existing authenticated session and a fresh login. Its temporary nonfinancial
account is deleted afterward. Preview generation writes eight fake-data HTML
files under protected, ignored `tests/http-results/email-previews`; it does not
load credentials or invoke a transport. Reserved test domains are rejected by
the real transport even if a preview address is accidentally reused.

The Chrome check passed all eight email layouts at 1440, 768 and 320 pixels
(24 layouts), reset form widths, fragment removal, generic recovery and zero
JavaScript exceptions. Screenshots were inspected. HTTPS CTA markup was checked;
the fabricated preview host is not a live deployment. The local preview browser
loads the original JPG from localhost solely to inspect branding.

Existing Auth, Sales, Expenses, Customers, Payments, Utang, Products, Reports,
Dashboard, Admin, Integration and their HTTP regression suites passed. All 121 PHP
files passed syntax checks and all 13 application JavaScript files passed Node syntax checks.
No real Brevo request or email delivery was performed. Test auto-increment
counters may advance even when fixture data is rolled back/cleaned up. Final local
inspection found zero temporary email-test accounts, outbox rows or customer
permission rows, with sending still disabled.

### Controlled inbox checklist after private configuration

- Register your own new owner account: one welcome with the real dashboard URL.
- Request recovery: one reset email, correct HTTPS fragment link, expiry and
  single use; finish the reset and receive one password-change alert.
- Change a password from the account menu and the admin profile: verify alerts
  and session revocation, never a password in the email.
- Use a consenting test customer: initial full payment → Payment Recorded;
  downpayment/partial collection → Partial Payment; final collection against
  credit → Balance Settled. Verify actual references and peso balances.
- In a staging database only, create real test credit due in three days and
  credit due yesterday; run the reminder generator twice and worker once.
  Verify one reminder per eligible stage and no email after settlement/void.
- Check no-address and opted-out customers are skipped, and repeat payment
  submissions do not create extra notifications.
- Inspect Gmail and Outlook inboxes at desktop/mobile widths, including blocked
  images, peso glyphs, logo fallback, support link and reset-link fragment survival
  through any provider click tracking. Review Brevo's message ID and delivery log.

These inbox/provider/production checks were not executed locally. No verified
sender, production URL, private replacement key or actual hosting paths were
provided. The existing account email is format-validated; ownership verification
is not implemented by this module and `email_verified_at` is not falsely set.
There is no catch-up policy for days when the daily reminder job was offline.
No support, announcement, feedback or marketing email category was added.

## Files created and modified

| Created files                                                                                                                                                                         | Purpose                                                                            |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `config/email.php`, `config/email.example.php`                                                                                                                                        | Environment/private-file configuration, disabled defaults and readiness validation |
| `includes/email_transport.php`, `includes/email_templates.php`                                                                                                                        | HTTPS Brevo adapter and safe reusable PHP renderer                                 |
| `includes/notifications.php`, `includes/passwords.php`                                                                                                                                | Outbox, consent, event resolution, worker/reminder and password security helpers   |
| `emails/layout.php`, `emails/.htaccess`                                                                                                                                               | Inline/table-based branded layout and HTTP protection                              |
| `emails/templates/welcome.php`, `password_reset.php`, `password_changed.php`, `payment_recorded.php`, `partial_payment.php`, `upcoming_due.php`, `overdue.php`, `balance_settled.php` | The eight application-owned templates (all under `emails/templates/`)              |
| `auth/reset_password.php`, `auth/change_password.php`, `auth/contact_support.php`, `assets/js/reset-password.js`                                                                      | Working secure password forms and public configured security-contact page          |
| `bin/process_notifications.php`, `bin/generate_reminders.php`, `bin/preview_emails.php`, `bin/email_setup_check.php`, `bin/.htaccess`                                                 | Protected CLI jobs, safe preview and actual-server setup diagnostics               |
| `database/email-notifications-proposal.sql`, `database/EMAIL-NOTIFICATIONS.md`                                                                                                        | Approved local additive migration and this deployment/testing guide                |
| `tests/email_test.php`, `tests/http_email_test.php`, `tests/browser_email_test.ps1`                                                                                                   | Mock transport, rollback integration, HTTP and browser verification                |

| Modified files                                            | Change                                                                                 |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `includes/auth.php`                                       | Registration event hook                                                                |
| `includes/sales.php`, `includes/payments.php`             | Initial and subsequent payment hooks; preserve payment ID before enqueueing            |
| `includes/customers.php`, `includes/customer_content.php` | Scoped customer permissions and consent form                                           |
| `includes/admin.php`                                      | Alert after saved profile password change; old mailbox snapshot when email changes too |
| `includes/navbar.php`                                     | Account-menu password change link                                                      |
| `includes/payment_content.php`                            | Accurate conditional payment-notification explanation                                  |
| `auth/forgot_password.php`                                | Real generic, rate-limited reset requests                                              |
| `includes/admin_content.php`                              | Clarify support settings do not configure transactional mail                           |
| `.gitignore`, `README.md`                                 | Exclude local secrets/generated artifacts and document the implementation              |

Database connection, existing financial definitions, original logos and page
styles were preserved. Generated test HTML/screenshots are not runtime changes.
