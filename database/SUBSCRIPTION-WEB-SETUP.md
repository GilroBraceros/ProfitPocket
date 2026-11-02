# Subscription setup through the existing administrator page

The hosted diagnosis confirmed four absent tables: deployment_runs,
owner_subscriptions, subscription_payment_methods and subscription_payments.
Git auto-deployment publishes application files but has not applied these tables.

After deploying this revision, an active administrator with both `users.manage`
and `settings.manage` permissions can open Subscription Management. The setup
card lists only missing additions. Opening the page performs read-only checks.
The administrator must approve the displayed additions and Pending Payment policy
with the checkbox, then submit **Apply reviewed subscription setup**. No hPanel,
public installer, new credentials or SSH connection is needed for that action.

## Exact scope for approval

The action uses these existing migration files, in order:

1. `database/email-notifications-proposal.sql`: create customer_email_preferences
   and notification_outbox only if absent. Existing customer permissions are not
   enabled or changed.
2. `database/migrations/20261025_subscriptions.sql`: create deployment_runs,
   owner_subscriptions, subscription_payment_methods and subscription_payments if
   absent; add the nullable notification_outbox.subscription_payment_id column.
   Initialize existing owners without a subscription as Pending Payment, with no
   fabricated paid time. Preserve existing subscription rows.
3. `database/migrations/20261025_registration_payments.sql`: add method_category,
   bank_name and qr_filename to subscription_payment_methods if absent, and create
   registration_onboarding if absent.

The maximum is seven new tables and four new columns. There is no DROP, reset,
financial-record replacement, role/account creation, or changes to existing views
and triggers. Existing receiving methods are preserved; no sample method is added.
An audit event records the administrator who completed setup. Email sending and
subscription enforcement configuration remain unchanged. An already-enabled
subscription enforcement setting will begin using the initialized Pending Payment
records after schema completion, so the approving administrator must review that
existing setting and owner rollout policy.

## Safeguards and limits

The handler stays within the existing authenticated subscription page. It requires
POST, CSRF protection, a session-specific setup key, explicit approval and a matching
hash of the reviewed missing-additions list. It rechecks both permissions, uses the
same per-database advisory lock as the CLI deployment runner, verifies the complete
release manifest, validates existing table columns, and uses the existing checksum
and completion records. No arbitrary SQL or filesystem path comes from a request.

MySQL DDL commits individually. If a statement fails, the action reports that setup
did not complete; it does not claim earlier DDL was rolled back. After resolving the
cause, reload to review the remaining changes and confirm again. Existing columns
are skipped. Incompatible existing tables stop setup before the first DDL.
Insufficient CREATE/ALTER grants still require the hosting account administrator.

After success, the page should load its metrics and payment-method controls. Add a
real receiving account. Payment proof and QR uploads still require writable private
storage outside public_html; they are separate from database setup. This action
does not configure Brevo, send a test email or install cron jobs.

## Validation

The setup test runs the actual migration runner against a PDO double, simulating a
missing hosted schema and interrupted DDL without changing any real database
structure. It checks approval, CSRF, nonce, both permissions, owner denial, stale
plans, advisory locking, replay safety and resuming after interruption. Local root
HTTP checks cover administrator access and rejection of invalid setup requests.
Existing subscription calculations and notification-trigger tests remain in place.

No production schema changes have been applied by the developer. Completion must
be confirmed from the hosted page after the administrator approves and submits.

## Changed files

- `admin/subscriptions.php`: handle the confirmed action and display its review.
- `includes/subscription_setup.php`: fixed-file plan, permission guards and runner.
- `includes/subscription_setup_form.php`: review and explicit approval form.
- `includes/subscription_diagnostics.php`: safe migration failure categories.
- `bin/build_upgrade_manifest.php`: include the existing email prerequisite SQL.
- `bin/upgrade-release.json`: refreshed integrity hashes.
- `tests/subscription_setup_test.php`: simulated schema setup and recovery tests.
- `tests/subscription_diagnostics_test.php`: permission failure diagnosis coverage.
- `tests/http_root_auth_test.php`: HTTP setup CSRF and stale-form rejection.
- `database/SUBSCRIPTION-HOSTED-DIAGNOSIS.md`: link to the setup procedure.
- `database/SUBSCRIPTION-WEB-SETUP.md`: proposal, deployment and verification guide.
