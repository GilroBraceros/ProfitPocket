# Hosted subscription diagnosis

The existing administrator page now offers an explicitly confirmed setup action.
See [SUBSCRIPTION-WEB-SETUP.md](SUBSCRIPTION-WEB-SETUP.md) for its exact scope and
approval controls. There is no automatic migration on a Git push or page load.

The administrator subscription page now displays safe, read-only setup checks when
an operation fails. It requires the existing `users.manage` permission and does not
expose raw exceptions, credentials, database names, or filesystem paths. The page
shows diagnosis version `subscriptions-20261102-v1` to identify this deployment.

After GitHub auto-deployment, sign in as an administrator and open Subscription
Management. If it fails, report the codes shown in the setup diagnosis card.

- `missing_table` / `missing_columns`: required hosted schema is absent or incomplete.
- `migration_not_recorded`: the subscription completion record is absent, even if
  tables were imported. Do not insert a fabricated completion record to bypass it.
- `database_permissions` / `database_check_failed`: check the existing hosted
  connection, database grants, and database availability.
- `missing_mbstring`: enable PHP mbstring on the hosting account.
- `private_config_invalid`: fix the existing private upgrade configuration.
- `related_schema_mismatch` / `database_collation`: review the related hosted schema.
- `operation_failed`: the basic checks passed; the failed operation needs further
  investigation, including private storage permissions for uploaded payment files.

Git deployment updates files. It does not itself execute SQL or copy local private
configuration to Hostinger. Applying production migrations requires separate
approval under AGENTS.md. The existing CLI runner is `bin/deploy_upgrade.php
--migrate-only`; subscription and registration/payment migrations require their
respective existing authorization settings, in order. Do not run them simply to
remove the warning before checking the hosted database and rollout policy.

This change applies no schema changes, grants no paid time, creates no accounts,
and sends no emails. It is a diagnosis improvement, not proof of a production fix.
