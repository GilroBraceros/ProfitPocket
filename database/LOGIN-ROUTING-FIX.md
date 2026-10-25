# Hosted login routing and connection compatibility

## Verified symptoms, 26 October 2026

The two supplied temporary passwords were checked against their deployed-source
hashes locally; both matched. One authorized HTTPS sign-in attempt for each account
at `/profitpocket/auth/login.php` returned the generic credentials-rejected message.
No passwords, cookies, tokens or database credentials were printed by those checks.

Further live checks found two application paths:

- `/auth/login.php` rendered a form posting to `/profitpocket/auth/login.php`.
- `/auth/register.php` returned HTTP 503 with the generic database-connection
  failure page, while `/profitpocket/auth/register.php` still rendered the older
  registration page.

The earlier missing-static-file checks alone did not establish that no deployment
occurred. The root installation was routing into the older subfolder copy, and
the root registration request could not connect to its database.

## Fix

`config/paths.php` derives the application path from PHP's script location.
Root deployments use `/auth/...`, `/admin/...`, `/assets/...` and a root session
cookie. Subfolder deployments retain `/profitpocket/...`. An explicit `PP_BASE_PATH`
override remains supported, including an empty value for the domain root.
Invalid or external base paths are rejected.

Both designated Hostinger installation locations are eligible for the already
approved one-time administrator bootstrap: the domain's `public_html` root or
its `profitpocket` subfolder. The existing database-identity, role, permissions,
schema, local-exclusion and complete-release checks remain in force.

When the new root loader has **no explicit private database configuration or
database environment settings**, it may reuse the existing connection include at:

```text
/home/ACCOUNT/domains/profitpocket.online/public_html/profitpocket/config/database.php
```

This is a narrow compatibility path to the already-running older copy of this
same application. It is enabled only for the exact designated domain root on Linux.
Canonical path checks exclude symlinks to other locations. The older include must
declare only the expected `db` function; a second modern loader is rejected.
The connection function's actual source file is verified. Existing private/env
configuration always takes precedence. Nothing is imported locally, from another
site, from an upload or from a URL. Credential values are never copied, displayed,
logged, extracted into source or changed. The existing connection code is reused.

If the old connection depends on hosting settings unavailable to the new root,
or the old file is not present/eligible, this compatibility path cannot invent
credentials. Hosting access would still be necessary to correct that configuration.

Responses using the corrected authentication include the public release identifier
`X-ProfitPocket-Release: 20261026-root-auth-v1`. This exposes no account existence,
database identity, server paths, credentials or bootstrap diagnostics. It allows
deployment verification independently of static-file caches.

## Checks and limits

- All 170 PHP files passed syntax checks.
- Nine affected suites passed: deployment routing, root HTTP authentication,
  production setup, HTTP auth, auth, email, subscriptions, HTTP subscriptions,
  and HTTP email.
- The new HTTP test actually signed in a separate temporary administrator at the
  domain root, changed its initial password, signed in again and opened both the
  dashboard and subscription module. Sending was disabled; the fixture was removed.
- Existing subfolder routes and authentication continued to pass.
- The existing-include check ran in isolated PHP processes with fabricated includes,
  verifying helper collision rejection and output suppression.
- No schema, existing password, financial record or private email setting changed.
  The selected live administrator identities remain absent from local XAMPP.

Local success does not prove that the hosted compatibility include is present or
that live provisioning completed. Confirm the new response header on the hosted
installation and authenticate there before claiming the live issue resolved.

Changed runtime files: `config/paths.php`, `config/database_bootstrap.php`,
`config/database.php`, `includes/auth.php`, `includes/production_release.php`,
`includes/production.php`, `bin/build_upgrade_manifest.php` and the refreshed
156-file `bin/upgrade-release.json`. Added tests: `tests/deployment_routing_test.php`
and `tests/http_root_auth_test.php`; extended `tests/production_setup_test.php`.
Documentation and README point to this correction.
