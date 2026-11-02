# Payment approval collation fix

The hosted database setup completed, but the administrator reported error 1267
(`database_collation`) after **Approve and activate**. The failing hosted SQL text
is not available, so its exact statement has not been independently confirmed.

The review update included `CASE WHEN ?='rejected' THEN NULL ELSE reference_key
END`. A regression test reproduces error 1267 with that expression against the
actual local subscription table by using different connection collations when
preparing and executing it. The replacement update succeeds under the same test.
This models the reported failure without modifying database structure.

## Changes

- `includes/subscriptions.php`: choose the fixed rejection-only reference clearing
  fragment in PHP after validating the decision. Approval retains the existing
  reference; rejection clears it. Other values remain bound parameters, and review
  locks, transactions, audit events and replay protection remain intact.
- `config/database_session.php`: set the application's connection charset and
  collation explicitly to utf8mb4/utf8mb4_unicode_ci, matching the reviewed schema,
  and retain the +08:00 session time zone. This is SET NAMES on the connection, not
  ALTER TABLE or ALTER DATABASE.
- `config/database.php`: initialize those settings on the normal connection and
  the reused existing Hostinger connection.
- `admin/subscriptions.php`: stop suggesting database setup when migration records
  already exist and a different operation failed. Diagnostic version is now v2.
- `includes/subscription_diagnostics.php`: describe the query/connection problem
  without prescribing a database structure change.
- `bin/build_upgrade_manifest.php`, `bin/upgrade-release.json`: include the session
  helper and refresh the release integrity hashes.
- `tests/subscription_collation_test.php`: real native SQL reproduces the old
  failure, verifies the replacement approval and rejection, payment-reference
  retention/release, existing paid access and idempotent replay. Fictional account
  and payment rows are rolled back; actual email sending is disabled in this test.
- `database/SUBSCRIPTION-HOSTED-DIAGNOSIS.md`: update the diagnostic version.
- `database/SUBSCRIPTION-COLLATION-FIX.md`: this review and retry procedure.

MySQL documents how prepared parameters derive collations from the connection and
their expression context: [PREPARE statement reference](https://dev.mysql.com/doc/refman/8.0/en/prepare.html).

## Hosted verification

After auto-deployment, refresh Subscription Management and reopen the pending
submission. Confirm the actual payment in the receiving account before retrying
approval. Check the payment status, the owner's access end date and one approval
audit event. Do not run database setup again merely to resolve a collation error.

The developer has not approved a real payment or changed production schema. Hosted
verification still requires the administrator to retry the real review action.
