# Automatic test administrators from the deployed source

The project owner explicitly authorized a source-based one-time bootstrap on
26 October 2026 because they do not have Hostinger credentials. The additional
private `upgrade.php` authorization is no longer required for the two designated
test accounts. The previous credential handoff remains valid; no new password
was generated and no plaintext password is in the repository.

After Hostinger actually deploys the complete release, the first ordinary PHP
application initialization can create the two accounts automatically. A GitHub
push alone does not execute PHP on Hostinger. No public installer, query parameter,
special registration form, separate login or website cron URL is introduced.

## Exact eligibility

- Linux only; the real application directory must be
  `/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket`.
- The application must already have its working hosted database configuration.
  The configured database/user must use the same `uNUMBER_` Hostinger account
  prefix; local `profitpocket_db`/root defaults are rejected.
- `SELECT DATABASE()` and `CURRENT_USER()` must match that existing configured
  database and account exactly. Request Host headers cannot authorize anything.
- The expected existing ProfitPocket authentication, roles/permissions, businesses
  and activity-log columns must exist. The actual `platform_admin` role and all
  required module permissions are verified again under role locks.
- All files must match the reviewed release manifest, with LF/CRLF normalization.

The source calls the existing PDO connection. It never guesses credentials,
switches databases, touches another site's files or sends the local connection
to Hostinger. If deployment overwrites previously hardcoded hosting credentials,
the hosting owner must preserve them using the existing private database loader
or environment. Source code cannot recover missing server credentials.

## Records and first login

Both inserts are transactional, Active and assigned the actual existing System
Administrator role. Administrators remain exempt from owner subscriptions. Any
existing designated email stops the transaction; no existing password or role
is overwritten. The database's existing unique constraints remain in force.

The existing activity log records `DEPLOY_ADMIN_PROVISIONED`,
`DEPLOY_ADMIN_PASSWORD_REQUIRED` and one `DEPLOY_BOOTSTRAP_COMPLETED` entry keyed
by `bootstrap:hostinger-test-admins:v1`. Shared role locks serialize concurrent
initializations. A completed marker prevents future requests/deployments from
recreating accounts or resetting passwords, even after a password change.
Earlier completion records in `deployment_runs` are also respected.

This path works with the original authentication schema: it does not require the
new subscription migration and never executes DDL. New subscription/email modules
still require their separately reviewed production migrations. They are not
silently installed or enabled by creating administrators.

Sign in at **https://profitpocket.online/profitpocket/auth/login.php**, using the
two previously supplied emails/passwords. They are not local XAMPP accounts.
Change each password at the existing mandatory first-login screen, then sign in
again. Reserved test emails are login identifiers, not inboxes; change profiles
to controlled real addresses before expecting administrator email delivery.

After both accounts are verified, set `PRODUCTION_RELEASE_ADMINS_ENABLED=false`
in `includes/production_release.php`, remove `includes/production_test_accounts.php`,
rebuild the release manifest locally and deploy that cleanup. Keep completion
and audit records. Even before cleanup, the durable completion marker disables
further inserts automatically.

## Verified deployment limit

The live HTTPS root and registration page responded with HTTP 200, but the new
subscription CSS and JavaScript both returned HTTP 404; registration also lacked
the new progress flow. This is evidence the observed deployment was not the
reviewed GitHub release. Hostinger's repository/branch/path/auto-deployment settings
cannot be configured through a source push alone. Someone with hosting access
must fix or trigger deployment if it is not already connected.

No live account creation, hosted authentication or hosted Brevo delivery has been
verified. An accessible login form does not prove the new code or schema is deployed.

## Brevo is separate

Brevo still uses the existing private server configuration and real HTTPS cURL
transport. The key must not be committed to GitHub. If the private key/sender and
worker jobs are not configured on Hostinger, someone with authorized hosting access
must supply them using [the existing setup guide](HOSTINGER-PRODUCTION-SETUP.md).
This administrator bootstrap does not invent an API key or claim email delivery.

## Changes and local checks

`includes/production_release.php` adds the narrow source authorization and runtime
guards. `includes/production.php` invokes it when the optional private bootstrap
is not configured. `includes/deployment.php` reuses the hashed-account provisioning
with the original activity log as a durable ledger and password-change gate;
`includes/auth.php` retains the existing initialization hook. Tests cover local/
unrelated installation rejection, exact database identity, same-account checks,
base-schema provisioning, first-password change and repeat protection. Test accounts
are separate fictional rollback fixtures; the selected live identities remain
absent from XAMPP. No real messages are sent by those checks.

Executed locally: all 166 PHP files passed syntax checks, nine affected unit/HTTP
suites passed, and the complete release contains 154 runtime hashes. The empty
receiving-method HTTP scenario was skipped to preserve the existing real method.
No schema structure, real financial record or private email setting was changed.
