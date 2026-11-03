# Customer purchase emails, reminders and logos — 3 November 2026

## Findings

The purchase hook ran only when the initial payment was greater than zero. It
queued a payment receipt, not a purchase confirmation. Unpaid purchases had no
notification event at all. Customer notifications also waited for the CLI worker;
without an operating Hostinger cron job, the event could stay pending. Explicit
customer communication permission and valid private Brevo settings are additional
requirements and have not been bypassed.

Manual customer reminders did not exist. The existing consent table already has
separate receipt and reminder permissions, and the outbox and activity log already
provide the tracking needed for this feature. No new database structure is needed.

The live application is currently under `/profitpocket`. Read-only HTTPS checks
returned 200 image/jpeg for both original logo files there; the equivalent domain
root URLs returned 404. The original image files were downloaded for inspection:
both are valid JPEGs with substantial white space around the artwork. This is not
evidence that the current subdirectory login page referenced a missing image: its
existing logo URL was correct. Specific authenticated-page or email-client failures
were not independently reproduced on Hostinger. The changes make image serving
consistent across supported installation paths and retain a text fallback.

## Implemented behavior

1. A posted sale creates exactly one purchase event for Fully Paid, Unpaid, or
   Partially Paid/Installment. Drafts, voids and failed saves do not send purchase
   emails. Initial receipts are replaced by the appropriate purchase confirmation,
   avoiding a second notification for the same initial payment.
2. Sale ID/number, customer, business, original item snapshots, quantities, prices,
   subtotal/discount/net amount, initial payment, balance and optional due date come
   from the existing database and financial helpers. Missing due dates are omitted.
   Item lines are identified as before discount when applicable.
3. A controller attempts only that sale's event after commit, sharing the existing
   worker's advisory lock, provider transport, templates and idempotency rules.
   Email failure never rolls back a committed sale. Page retries do not send a
   second accepted email. Older purchase notices are skipped if later payments
   would make the original unpaid/partial message contradictory.
4. Additional collections keep the current payment calculations. Notifications
   contain the payment date, transaction number/reference, prior balance, actual
   payment and new balance. A payment that settles an existing unpaid/credit sale
   produces a settlement confirmation, including when it is the first collection.
5. **Send Reminder** appears inside **View → Customer email notifications** in
   Sales and the transaction/history details in Payment Management. It uses the
   existing shared Bootstrap modal and compact View/action controls. A normal-page
   fallback is available without the modal loader.
6. The reminder shows the registered customer and email, business, sale number,
   totals, optional due date and last reminder. It offers a sandboxed preview,
   optional message (500 characters), recipient confirmation and a Send action.
   Preview is read-only. Recipient addresses cannot be overridden in a request;
   a contact revision rejects forms whose displayed recipient has changed.
7. The server rechecks ownership, active customer, outstanding posted balance,
   saved reminder permission, CSRF and signed transaction token. It serializes
   requests using the existing business lock and limits requests to one per sale
   in 24 hours and three per customer in 24 hours. The UI explains the cooldown.
8. The existing outbox records the owner/customer/sale, frozen recipient reference,
   notification type, timestamps, attempts, provider message ID, status and safe
   failure code. The existing activity log records the owner's optional message
   linked to that event. A same-key retry reuses the event; a changed message with
   the same key is rejected. Settled or cancelled sales cannot send a reminder.
9. Details show purchase status and expandable email/reminder history. Brevo
   acceptance is explicitly distinguished from inbox delivery. Missing email,
   missing permission, disabled sending, pending, failed, skipped and uncertain
   outcomes have separate messages.
10. The public branding endpoint serves only the two original JPEG logos from
    fixed application asset locations, including the known nested asset fallback.
    It checks image format, disallows arbitrary paths/symlinks, sets image/jpeg and
    ETag/cache headers, and loads no private configuration or database connection.
    Website image references use app_url; emails use the configured absolute HTTPS
    base. No logo artwork was replaced or regenerated. The login page now also
    shows the icon alongside its existing brand text.
11. Actual email HTML retains inline styles for email clients. The sandboxed
    browser preview uses a local stylesheet instead, so the application's strict
    Content Security Policy remains unchanged. The original ProfitPocket text
    wordmark remains visible if a mail client blocks remote images.

## Existing schema reused

| Source | Keys and relationship used |
| --- | --- |
| sales | sale_id primary key; business and customer association; stored sale_number/status/sold_at |
| sale_items | sale_item_id primary key; sale_id/business_id references sales; original product-name/price/quantity snapshots |
| sale_payments | payment_id primary key; sale/business scope, entered_by and durable idempotency key; immutable collection/refund records |
| customer_credits | sale_id primary/foreign key; actual nullable due date; protected history |
| v_sale_financials | existing net sale, paid amount and balance calculations; unchanged |
| customers, businesses, users | actual scoped recipient, business identity and active owner/customer checks |
| customer_email_preferences | customer_id primary key and customer/business relationship; receipt/reminder flags tied to the email hash |
| notification_outbox | notification_id primary key, unique event_key and existing logical user/business/customer/sale/payment references; VARCHAR notification_type already supports the four new names |
| activity_logs | activity_id primary key, owner/business references and existing JSON after_data for the reminder message/event link |

## Validation and actual limits

- PHP syntax passed for 195 files. Twelve PHP suites passed: customer_email_test,
  email_test, http_auth_test, logo_test, sales_test, payments_test, utang_test,
  subscriptions_test, subscription_delivery_test, production_setup_test,
  email_admin_setup_test and http_root_auth_test.
- Customer tests exercise real MySQL records inside rollback transactions: all
  three purchase types, multiple items, discounts, balances, missing due dates,
  consent, fixed recipient, stale contact, replay/cooldowns, installments,
  settlement, foreign ownership, missing email and simulated Brevo failure.
- HTTP checks exercise preview JSON, escaping, signed forms, CSRF, foreign-sale
  rejection, and the prohibition on sending for drafts. These committed HTTP
  fixtures are restricted to deletable drafts and are cleaned up.
- Chrome checks cover 15 layouts (1440, 768 and 320 pixels), original logo loading,
  preview updates, sandboxing, keyboard focus, loading/disabled controls and error
  recovery. Test snapshots are local, ignored, and based on rollback fixtures.
- Local email configuration reports enabled, valid sending settings, cURL and
  available queue schema. Provider sender authentication and inbox receipt were
  not reverified with a real send in this revision. Tests use injected/mock
  transports; production continues to use real Brevo API v3 requests.
- Live original logo URLs were checked independently. The new branding endpoint
  has passed local HTTP 200/image/jpeg, exact original-byte, ETag/304 and invalid-path
  checks. Its hosted response needs checking after publication.
- No production email or financial-record mutation was performed here. Production
  purchase/reminder acceptance and inbox receipt remain unverified because no
  authenticated production test session was used.

One initial local post-commit test created a fictional credit fixture before its
cleanup hit the database's unconditional credit-history deletion guard. No trigger
was disabled or changed. The normal void workflow was used, and the fixture owner,
customers and products were deactivated. Local user 1000/business 482 retains one
voided test sale with no money collected, explicitly labelled as fictional. Its
mock notification was removed. This local audit history is the only retained
financial fixture from this revision; subsequent financial tests are rollback-only.
The deployed application does not use these fixtures as demonstration data.

## Deployment and hosted verification

No migration is required. Preserve all private Hostinger configuration files.
Deploy the complete reviewed source and updated release manifest together through
the existing Git deployment. The operator explicitly authorized pushing this release
to main on 3 November 2026. Confirm deployment from the new public branding endpoint;
the final handoff records the push and any hosted HTTP verification results.

On the hosted administrator's Subscription Management page, verify **Email
notifications** shows Enabled, required sending settings present, queue tables and
cURL available. The existing protected **Configure hosted Brevo email** form can
set the key and verified sender privately and derive the correct URL. The current
public app is **https://profitpocket.online/profitpocket**, not the domain root.
Use the separate explicitly authorized test-email button to test transport without
creating financial records, and check the recipient inbox/spam separately.

For customer tests, use a designated staging/test business and an inbox the operator
controls, with explicit receipt/reminder permission. Never test against unrelated
actual customers. Posted credit/payment histories are protected and can require
voiding rather than deletion; no production test transaction was created here.

Record a permitted paid/unpaid/partial purchase, inspect the notification status,
then open **View → Send Reminder** for an outstanding purchase. Preview, confirm
and send to the fixed registered inbox. Verify the outbox status/provider ID and
actual receipt separately. Record an additional payment and settlement; confirm
the correct receipt and disabled reminder state. Check the logo URL used by the
hosted page and authorized email returns HTTP 200 image/jpeg.

Immediate purchase/payment/manual-reminder attempts do not require cron. Deferred
retries, other notification types and scheduled reminders still use the existing
Hostinger PHP CLI jobs: process_notifications.php, generate_reminders.php and
subscription_maintenance.php in bin/. Configure the worker at least every minute
and reminder generators daily, using the actual Hostinger PHP binary and absolute
application path. The scripts set Asia/Manila; confirm the hosting cron scheduler's
timezone before choosing a wall-clock schedule. No cron was installed or changed.
See HOSTED-EMAIL-ADMIN-SETUP.md and HOSTINGER-PRODUCTION-SETUP.md for private settings
and existing job instructions. API credentials must not be sent in chat or GitHub.

## Changed files

- assets/branding.php
- assets/css/email-preview.css
- assets/css/style.css
- assets/js/customer-email.js
- assets/js/sales.js
- assets/js/utang.js
- auth/login.php
- auth/register.php
- auth/registration_complete.php
- auth/registration_payment.php
- bin/build_upgrade_manifest.php
- bin/preview_emails.php
- bin/upgrade-release.json
- database/CUSTOMER-EMAIL-REVIEW.md
- emails/layout.php
- emails/templates/balance_settled.php
- emails/templates/manual_reminder.php
- emails/templates/partial_payment.php
- emails/templates/purchase_installment.php
- emails/templates/purchase_paid.php
- emails/templates/purchase_unpaid.php
- includes/admin_navbar.php
- includes/customer_content.php
- includes/customer_email.php
- includes/customer_email_status.php
- includes/customer_reminder_form.php
- includes/email_templates.php
- includes/footer.php
- includes/header.php
- includes/navbar.php
- includes/notifications.php
- includes/payment_content.php
- includes/sale_details_content.php
- includes/sales.php
- includes/subscription_navigation.php
- index.php
- tests/browser_customer_email_test.ps1
- tests/customer_email_test.php
- tests/customer_email_visual_router.php
- tests/email_test.php
- tests/http_auth_test.php
- tests/logo_test.php
- user/payments_action.php
- user/sale_email.php
- user/sale_form.php
- user/sale_view.php
