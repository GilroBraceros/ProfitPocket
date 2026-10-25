# Subscription upgrade proposal

Approved and applied to local XAMPP on 25 October 2026, including the Pending
Payment policy for existing owners. Production execution remains a separate step.

The migration `migrations/20261025_subscriptions.sql` creates four additive tables:
`deployment_runs`, `subscription_payment_methods`, `owner_subscriptions`, and
`subscription_payments`. It adds one nullable subscription reference to the existing
notification outbox. Existing users, business financial records, tables, views,
roles, permissions and triggers are preserved.

Subscriptions are separate from customers' sale payments. Each approved proof
adds one calendar month for exactly ₱100. New and existing owners start Pending
Payment when the rollout is explicitly initialized. No historical payment or free
subscription is fabricated. Existing owners' access changes only when the
deployment operator explicitly initializes this policy and enables enforcement;
the deployment must announce this cutover to owners. Admins remain exempt.

Only an active administrator with the existing `users.manage` permission can
approve/reject payments; changing payment instructions also requires
`settings.manage`. No new public role or admin registration is introduced.

Proofs live outside the document root, with authenticated downloads. Owners
retain renewal, account/password, support and logout access while unpaid/expired.
One pending submission per owner is enforced using row locks, plus unique
request/proof hashes. Approval/rejection and administrative audit are atomic.

Local database application requires approval under AGENTS.md. Production is a
separate, explicitly authorized deployment. MySQL DDL cannot be rolled back as a
transaction: take a backup, run the resumable versioned runner, and retain the
tables/rows if application code must be rolled back. Disable the rollout flag
before reverting application code. Do not delete financial or subscription history.

No Hostinger post-deployment hook exists in this checkout. A protected CLI cron
runner will support preauthorized migration and one-time creation of the two
privately configured administrators after files deploy. It will neither promote
an existing owner nor reset an existing administrator's password.
