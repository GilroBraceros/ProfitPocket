# Registration payments and administrator QR management

This revision extends the existing ₱100 subscription implementation. GCash,
Bank Transfer and Other are the subscription method choices. Maya is excluded
from those choices and rejected as a new custom provider. Existing historical
records are retained; business customer-payment modules were not rebuilt.

## Administrator workflow

Open **Administration → Subscriptions → Payment methods**.

- Select GCash, Bank Transfer or Other. Bank Transfer requires a bank name;
  Other requires a custom method name.
- Enter the real receiving account name/number and instructions. No receiving
  account, QR code or payment instructions are seeded by the application.
- Optionally upload an actual JPG/JPEG, PNG or WebP QR image up to 2 MB and
  20 million pixels. The browser previews it before saving. PHP validates MIME,
  dimensions and size; safe random filenames replace the original filename.
- Saved methods appear as cards with the account, QR, availability and actual
  payment-history count. Edit, replace/remove QR, activate or deactivate as needed.
- Confirm removal only for an unused method. Methods with recorded payments
  require deactivation so their historical records remain intact.

Changes require both existing `users.manage` and `settings.manage` permissions,
CSRF and a fresh method-form key. Live role/permission checks and locks are repeated
inside the transaction. Duplicate receiving accounts are rejected even when
formatted differently or entered through the Other option as GCash. Different
accounts for the same provider are supported.

QR images are private files, not database blobs or public static uploads. Locally
they use `C:\xampp\profitpocket-private\subscription-proofs\payment-qr`, or
`PP_SUBSCRIPTION_STORAGE/payment-qr` when configured. The image endpoint permits
authorized administrators, authenticated owners, or a valid onboarding session.
Owners/onboarding users see only currently available methods. Replaced images
are deleted when unreferenced; images referenced by historical submission
snapshots remain private and preserved.

## New registration workflow

**Account Details → Subscription Payment → Verification** is one continuous flow:

1. The existing required account/business fields and password rules are validated.
   A successful transaction creates the owner, business, pending subscription
   and expiring onboarding association. It does not issue a normal login session.
2. The same browser immediately opens `auth/registration_payment.php` without a
   sign-in step. Choose an active method from the live administrator-managed
   dropdown. Its receiving account, instructions and optional QR change together.
   Copy Account Number provides success feedback or a manual-copy fallback.
3. Manually send exactly ₱100 using the configured instructions, then upload the
   real JPG/PNG/PDF proof (up to 5 MB). Image previews and a local PDF-preview link
   are available before submission. Preview data stays in the browser.
4. `auth/registration_complete.php` shows the registered email, selected method,
   actual amount/date and current verification status, plus Go to Sign In. It is
   accessible through the bound onboarding session without logging in.
5. An authorized administrator reviews the proof against the actual receiving
   account. Only approval grants access. The original calendar-month, early-renewal,
   expiry, suspension, session and business-authorization rules remain in effect.

The temporary association lasts one hour. The PHP session holds a random raw
token; MySQL stores only its SHA-256 hash. Tokens never appear in URLs, HTML,
logs or email. A unique registration request prevents duplicate account creation;
the account's unique email/username constraints also remain enforced. Session ID
rotation follows successful registration. A recovered browser session can resume
the committed account even if navigation was interrupted after account creation.

Proof ownership is derived from that association, not a posted account ID. CSRF,
submission keys, existing owner locks and proof/reference uniqueness prevent
duplicate submissions or changes to another account. Submission/audit/onboarding
updates are transactional. Failed or replayed uploads remove the new file.
Proof downloads retain the existing authenticated owner/admin protection.

When payment setup is unavailable, the account and pending state are preserved.
The user can return in the same browser while the session is valid, or sign in
later to the restricted subscription page. Expired onboarding does not create a
new account or grant business access.

Rejected users sign in to read the reason and submit corrected proof using their
existing account. Expired subscribers continue the existing login-based renewal
flow. They are never required to register again. The common payment form also
improves that existing subscription page with the dropdown, QR and previews.

## Email behavior

The existing Brevo transport, PHP layout, outbox, duplicate protection and private
configuration are reused. The event whitelist now has 16 types:

| Event | Recipient and behavior |
| --- | --- |
| `registration_payment_submitted` (new) | One combined welcome/pending-verification email to the owner after the first initial proof is saved; subject “Welcome to ProfitPocket — Payment Verification Pending” |
| `subscription_admin_review` (new) | Active administrators with `users.manage`, after each initial-activation proof attempt, including corrected proof before first approval; protected review-page link |
| `subscription_approved` (updated) | “Your ProfitPocket Account Is Now Active!” with actual activation date, expiry and dashboard link |
| `subscription_rejected` (updated) | “Action Required — ProfitPocket Payment Verification” with actual reason and resubmission link |

Public account creation defers the standalone welcome. The first proof creates
one combined owner notification rather than both welcome and submission emails.
Corrected proofs use the existing submission notification without another welcome.
Administrator recipients and permissions are rechecked before dispatch; revoked
roles and obsolete, already-reviewed submissions are skipped. Support-only
administrators are not sent financial review notices.

Password recovery, customer payment receipts/reminders and subscription renewal
emails retain their existing behavior. Email dispatch remains outside financial
transactions. Failure to resolve/queue a review notice cannot undo a valid proof
submission. Real sending remains enabled in the local private configuration from
the prior successful Brevo test; this revision did not change or expose its key.
Normal delivery still requires the notification worker to run.

## Schema and deployment

The approved local migration is
`database/migrations/20261025_registration_payments.sql`. It adds:

- `subscription_payment_methods.method_category`, `.bank_name`, `.qr_filename`.
- `registration_onboarding`, with unique user/token/request associations, expiry,
  first-submission reference and completion timestamp.

The existing subscription migration is a prerequisite. Existing method names,
receiving details, enabled flags, subscriptions, financial records, views and
triggers are preserved. Existing methods initially receive category Other; GCash
names are recognized by the interface, and administrators can classify older
methods when editing. The local application and repeat-run check succeeded.
The database now has 35 base tables, 8 views and 27 triggers.

For Hostinger:

1. Back up the application/database and private proof/QR directory. Follow
   [SYSTEM-UPGRADE.md](SYSTEM-UPGRADE.md) for any missing prerequisite migrations.
2. Deploy the reviewed files and refreshed `bin/upgrade-release.json` through your
   actual GitHub-to-Hostinger mechanism. A push alone does not prove deployment;
   no production pipeline or hosted database was operated during this revision.
3. Privately set `PP_DEPLOY_MIGRATIONS_APPROVED` to
   `20261025_registration_payments`. Run the existing CLI deployment runner with
   `--migrate-only`, or use its previously configured preauthorized cron process.
   It validates the release and version checksum and skips columns already added
   during an interrupted DDL run. Do not edit the applied migration file.
4. Preserve the private Brevo settings and subscription configuration. Ensure
   `PP_SUBSCRIPTION_STORAGE` remains outside `public_html`, readable/writable by
   the PHP account (typically private directories `0700`, files `0600`). PHP
   Fileinfo and image inspection are required; no GD/Composer package was added.
   Set PHP `upload_max_filesize` to at least 5 MB and `post_max_size` above that
   limit so the existing 5 MB proof allowance is available on the hosted server.
5. Keep `bin/process_notifications.php` scheduled every five minutes. Existing
   customer/subscription reminder cron jobs remain unchanged. Run
   `bin/email_setup_check.php` on Hostinger to obtain the exact server command
   paths; do not assume local Windows paths work on hosting.
6. Configure real GCash/bank receiving details and QR images in the admin module.
   Verify the QR in the actual banking/wallet app before accepting payments.
   Test one controlled registration, review, rejection and approval on deployment.

For a manual CLI invocation from the deployed application directory:

```sh
php bin/deploy_upgrade.php --migrate-only
```

The private authorization must match this reviewed version. Production migration
approval remains separate from local approval. No public installer or page-load
schema change was added. MySQL DDL is not rolled back as an application transaction;
retain additive tables/columns and historical images when reverting code. Use a
maintenance window and known application backup rather than dropping records.

## Verification and limits

Executed locally:

- `tests/registration_test.php`: actual rollback database operations, hashed and
  scoped onboarding, account/proof replay, fixed amount, first-welcome suppression,
  combined owner mail, authorized reviewer mail, permission revocation, corrected
  proof without repeated welcome, audit deduplication, WebP validation and QR
  history/deactivation/deletion rules. Email transport is mocked.
- `tests/http_registration_test.php`: real multipart QR/proof uploads, method
  categories, missing bank-name rejection, provider exclusion, duplicate receiving
  accounts, QR replace/remove/protected reads, guest registration/payment/confirmation,
  malformed proof rejection, tampered owner/amount, approval, rejection/corrected
  proof, no-method setup, expiry and safe sign-in resumption. Its isolated loopback
  PHP process forces email off; all fictional accounts/records/private files are
  cleaned up. It does not alter the real private email configuration.
- `tests/browser_registration_test.ps1`: 30 page/email layouts at 1440, 768 and
  320 pixels, method/QR switching, account-copy feedback, bank/custom fields,
  PNG/PDF previews, loading/duplicate-submit states and circular progress markers.
- Existing subscription browser checks: another 45 layouts. Screenshots were
  inspected; no page overflow or JavaScript exceptions remained.
- Existing Auth, Sales, Expenses, Customers, Payments, Utang, Products, Reports,
  Dashboard, Admin, Integration, HTTP and email suites passed. One pre-existing
  Utang test used a calendar week that excluded its two-day-old fixture on Mondays;
  its test period now explicitly covers those fixture dates. Application financial
  calculations were not changed.
- Syntax checks passed for 159 PHP files and 14 JavaScript files. The complete
  release manifest covers 148 runtime files. The administrator HTTP test now
  removes its own queued password-change alert when cleaning up its disposable
  account, including when real sending is enabled locally.
- Final database/file checks found zero retained registration fixtures, subscription
  submissions, payment methods, queued emails or private proof/QR files. Local
  email sending remains enabled and the reviewed release manifest matches.

Run locally with XAMPP MySQL available:

```powershell
C:\xampp\php\php.exe tests\registration_test.php
C:\xampp\php\php.exe tests\http_registration_test.php
C:\xampp\php\php.exe bin\preview_emails.php
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\browser_registration_test.ps1
```

The new browser suite uses actual cleaned-up HTTP snapshots through a read-only
loopback server. Captured QR images are disposable upload fixtures, not real
receiving accounts or scannable-payment verification. No real new registration,
payment or administrator-review emails were sent by these tests. Live inbox
delivery of the new message variants, real QR scanning, payment receipt and the
Hostinger rollout require controlled deployment checks. API sending was previously
confirmed with a separate authorized connection-test email.

Test HTML/screenshots remain in protected, ignored `tests/http-results/`. Fixture
data is not seeded into the completed backend. Auto-increment counters can advance.
The existing application does not verify email ownership; no verification flag
or identity claim was fabricated by this revision.

## Files created for this revision

| File | Purpose |
| --- | --- |
| `includes/registration.php` | Token-bound registration, initial-payment association and notification resolution |
| `includes/subscription_methods.php` | Method/category validation, duplicate checks, private QR storage and lifecycle |
| `includes/registration_progress.php` | Accessible three-step progress component |
| `includes/subscription_payment_form.php` | Shared real method/QR selection, account copy and proof form |
| `includes/subscription_method_management.php` | Administrator cards and method/QR editor |
| `auth/registration_payment.php`, `auth/registration_complete.php` | Guest payment and confirmation pages |
| `user/subscription_qr.php` | Protected QR-image delivery |
| `emails/templates/registration_payment_submitted.php`, `emails/templates/subscription_admin_review.php` | Combined owner welcome and authorized administrator notice |
| `database/migrations/20261025_registration_payments.sql`, `database/REGISTRATION-PAYMENTS-PROPOSAL.md`, this review | Additive migration, approval scope and deployment/testing record |
| `tests/registration_test.php`, `tests/http_registration_test.php`, `tests/onboarding_http_router.php`, `tests/registration_visual_router.php`, `tests/browser_registration_test.ps1` | Rollback, isolated HTTP and responsive browser verification |

## Existing files modified for this revision

| File | Change |
| --- | --- |
| `auth/register.php` | Continue directly into bound payment flow, resumable/duplicate-safe registration and new progress UI |
| `includes/auth.php` | Optional deferred welcome when creating the public onboarding account |
| `includes/subscriptions.php` | Shared upload processing, bank/QR snapshots, submission audit and initial-payment notification integration |
| `admin/subscriptions.php`, `user/subscription.php` | New reusable administrator controls and owner payment selection |
| `includes/notifications.php`, `includes/email_templates.php` | Two new event types and secure recipient resolution |
| `emails/templates/subscription_approved.php`, `emails/templates/subscription_rejected.php` | Requested subjects and actual activation date |
| `assets/css/subscriptions.css`, `assets/js/subscriptions.js` | Scoped responsive styling, conditional fields, copy feedback and private local-file previews |
| `includes/deployment.php`, `bin/build_upgrade_manifest.php`, `bin/upgrade-release.json` | Authorized repeatable migration and refreshed complete-release detection |
| `bin/preview_emails.php`, `tests/email_test.php` | Sixteen template fixtures and rendering checks |
| `tests/http_auth_test.php`, `tests/http_integration_test.php` | New registration nonce and deliberate separation of onboarding/sign-in fixture sessions |
| `tests/http_admin_test.php` | Clean up queued notifications belonging to its disposable administrator fixture |
| `tests/browser_subscriptions_test.ps1`, `tests/utang_test.php` | Dropdown adaptation and date-independent revenue fixture range |
| `index.php`, `.gitignore`, `README.md`, `database/SYSTEM-UPGRADE.md` | Current onboarding copy, generated-artifact exclusions and documentation |

Original logos, private credentials, existing subscription calculation rules and
business financial definitions were preserved.
