# Subscription approval email delivery

The approval event already queued a branded notification, but it depended on a
background worker. Its resolver also suppressed payment-related notices when
subscription enforcement was disabled. Payment decision emails now resolve
independently of the enforcement flag; scheduled expiry reminders remain guarded.

## Hosted behavior after deployment

- New review decisions enqueue in the financial transaction and attempt only that
  event after commit. Delivery failure never reverses a committed payment review.
- The specific-event sender shares the existing cron worker's database lock and
  idempotency key. Accepted and uncertain events are not resent. Delayed retries
  retain their backoff. Other customer emails are not processed by an approval.
- The existing templates, Brevo HTTPS transport and outbox schema are reused.
- Review confirmation distinguishes disabled email, pending delivery, provider
  acceptance, failure, skipped records and unknown outcomes. Brevo acceptance is
  not proof that the message reached the inbox.
- The authorized administrator can inspect **Email notifications** on Subscription
  Management. It exposes only readiness flags and missing setting names, never the
  API key, reset secret, private paths or full configuration.
- For an already approved payment, open **View**. After explicitly confirming the
  registered recipient, **Send approval email** can enqueue an event that was
  absent while sending was disabled. It reuses the original event key and does not
  approve payment again or extend access. Only never-attempted configuration/old
  rollout skips can be safely requeued; failed or uncertain attempts are not reset.

## Remaining server requirements

The code cannot supply a missing production API key or sender. Hostinger must have
the existing private email configuration outside public_html (or equivalent server
environment), a valid Brevo sender, HTTPS base URL, cURL and sending enabled. The
status card identifies missing settings. No local credentials are copied to GitHub.
This revision does not activate or rewrite private configuration.

`bin/process_notifications.php` is still needed as a scheduled PHP CLI job for
deferred retries and other queued event types. Reminder generation uses the existing
`bin/generate_reminders.php` and `bin/subscription_maintenance.php` jobs. This change
does not install cron. A real hosted send and recipient inbox check remain unverified.

## Modified files and validation

- `includes/notifications.php`: one-event delivery after commit, shared worker lock,
  and fixed SQL fragments for result storage instead of collated CASE parameters.
- `includes/subscriptions.php`: return the event ID and allow actual payment notices
  while enforcement is disabled.
- `includes/subscription_email.php`: status, explicit existing-approval email request,
  and safe user-facing result messages.
- `includes/subscription_email_status.php`: private-setting readiness and payment
  notification status, with an explicitly confirmed delivery action.
- `admin/subscriptions.php`: attempt delivery after commit and expose status.
- `tests/subscription_delivery_test.php`: injected transport, commit guard, lock,
  accepted-event replay, retry delay, uncertain state and unrelated-job isolation.
- `tests/subscriptions_test.php`: real rollback fixture approval notice with disabled
  enforcement and mocked transport.
- `tests/http_root_auth_test.php`: protected status and email-action CSRF/confirmation.
- `tests/http_subscriptions_test.php`: real approved-payment status and repeated
  email-request isolation from paid access and duplicate events; owner denial.
- `bin/upgrade-release.json`: refreshed runtime integrity manifest.
- `database/SUBSCRIPTION-EMAIL-DELIVERY.md`: this guide.

No schema migration, real administrator/account creation, live test email, or
production financial changes were performed by the developer for this revision.
