# Registration payment and QR proposal

Approved and applied to local XAMPP during this task. The repeated application
check passed. Production execution remains separate.

The additive `migrations/20261025_registration_payments.sql` adds three columns
to `subscription_payment_methods`: category, bank name and a private QR filename.
Categories are GCash, Bank Transfer and Other. Existing names, receiving accounts,
enabled states, subscriptions, payment records, views and triggers are preserved.

One new `registration_onboarding` table binds a freshly created owner to a
hashed random token, expiry and unique registration request. Only the browser's
PHP session contains the raw token. No password or bearer token appears in URLs,
HTML, logs or this table. The short-lived session permits initial payment and
confirmation only; it never grants access to business features. Expired sessions
resume safely through ordinary sign-in to the existing restricted subscription page.

QR images use private storage beside existing payment proofs. Replacement and
removal preserve any image referenced by historical submission snapshots.
Methods with payment history can be deactivated, while unused methods may be
removed after confirmation. No subscription record or financial history is deleted.

This local migration was approved under AGENTS.md. Production application
remains separate. The versioned runner checks prior migration state and checksum;
it does not reset or rebuild existing tables. Back up the database and private
files before production rollout. Retain additive tables and historical references
when reverting application code; do not reset the database.
