# ProfitPocket integration audit — 11 October 2026

Completed a review of the project folders, AGENTS.md, database connection, live
schema, authentication/permissions, owner/admin pages, shared PHP components,
service queries, form handlers, navigation, CSS and JavaScript before redesigning
the frontend. The checklist below records the initial problems and completed fixes.

## Integration checklist

| Feature | Admin Side | Business Owner Side | Database Connection | Problem | Fix |
| --- | --- | --- | --- | --- | --- |
| Announcements | Existing create/edit/publish/unpublish retained | Recent dashboard section and details modal now work | announcements.author_admin_id → users; announcement_status + published_at | Published content was never displayed to owners | Added latest three published messages and a published-only details endpoint; draft/archived/future/unpublished requests denied. Publish → refresh → read → unpublish tested |
| Support tickets | Existing replies, assignment and status/history retained | New Help & Support submission, conversation and follow-up | support_tickets.submitted_by/assigned_admin_id → users; ticket_messages and ticket_status_history → tickets/users | Owner interface was absent | Added authenticated owner scoping, signed forms, CSRF, transactional saves/retries and follow-ups. Resolved tickets reopen; closed tickets require new tickets. Full conversation tested |
| Feedback | Existing category/status review retained | New submission and own review-state history | user_feedback.submitted_by → users | No owner entry point or visible review state | Added category/message form and My feedback. Submit → review → owner status tested; other-owner entries excluded |
| General settings | Existing stored setting edits and audit retained | Supported values now appear in Help & Support | platform_settings.setting_key PK; updated_by → users | Saved contact/general values had no consumer | Shared allowlist consumes platform_name/description/support_email/phone. Only existing keys editable; all four consumers tested inside rollback |
| Registration/business directory | Reads real owners/businesses | Atomic public owner/business registration retained | businesses.owner_user_id unique FK → users; category FK | Required end-to-end verification | Two actual HTTP registrations appeared in directory and accurate dashboard totals. Existing profile queries read current business data; account status derives from owner |
| Suspension/reactivation | Confirmed state changes and actual audit retained | Fresh status/session checks on every protected request | users.account_status + auth_sessions revocation | Required verification across integrated and financial pages | Suspended owner direct URLs blocked; old session stays revoked after reactivation; fresh login succeeds. Posted financial history preserved in rollback tests |
| Registration/admin-login activity | Audit/dashboard now include permitted actual events | Real registration appends safe platform event | activity_logs.actor_user_id → users; admin_action_logs actor/target FKs | Successful registration/admin login were absent from readable platform activity | Added USER_REGISTERED and AUTH_ADMIN_LOGIN with IDs and safe descriptions; no password/authentication token/message payload exposed. Real event counts tested |
| Administrative actions/audit | Existing suspension/reactivation, announcement, response and settings events retained | Applicable owner outcomes tested | admin_action_logs; restricted nonfinancial activity_logs | AJAX saves previously lost success notice on reload | Preserve success flash for modal mutations. Logs remain read-only and exclude financial/private payloads; existing redaction tests pass |
| Roles/permissions | Live page and transactional action checks retained | Owner admin routes denied | users → roles → role_permissions → permissions | No missing backend permission connection found | Existing support/platform permission tests pass, including forged roles, unauthorized actions, session revocation on role changes and last privileged administrator protection |
| Backup history | Latest actual backup_jobs, read-only | No owner access needed | backup_jobs → users | Backup execution does not exist | Clearly labelled monitoring only; no fake jobs, backup button or download/restore action |
| Sales/customers/products/utang | Private financial records remain excluded | Scoped services, transactions, historical snapshots retained | Same-business FKs, sales/items/payments/credits and financial views | No confirmed query/relationship defect found | Existing calculation, archival, repayment, retry, owner-isolation, HTTP and browser suites passed |
| Dashboard/expenses/reports | Platform-only metrics retained | Revenue, historic costs, operating expenses, repayments and report totals retained | Existing views and authenticated business filters | No confirmed calculation defect found | Exact financial suites reconciled totals; drafts/voids/refunds, stock purchases, historical cost, date limits, receipts and CSV isolation passed |
| Owner navigation/shortcuts | Not applicable | Existing links preserved; Help & Support added | Existing real handlers | Caption called completed actions upcoming | Corrected caption and removed outdated sidebar note; owner Settings remains visibly Soon |
| Admin interface | Substantial frontend redesign over existing backend | Owner theme and original logos preserved | Existing services retained | Flat navigation, oversized/repeated sections, wide tables and crowded settings/filters | Grouped reusable navigation, four compact metrics, recent registrations/View, support snapshot, short activity, focused tables/mobile cards, View + menu, advanced filter disclosure and organized settings |

## Behavior and boundaries

Owners find announcements on user/dashboard.php and tickets/feedback on
user/support.php through Help & Support in the sidebar. No existing notification
bell existed, so only the dashboard announcement section was added.

The schema has no announcement expiry or per-owner read tracking. There is no
invented unread badge, read-state write or expiry control. Publishing/unpublishing
takes effect on refresh/revisit; no push delivery is implied.

Support remains in-app. A stored support address is contact information and does
not configure SMTP or an email API. No email delivery, password recovery mail,
SDK or background email process was added. Backup history only monitors recorded
jobs. Owner business profile Settings is still explicitly disabled as Soon; a
profile editor is outside this integration repair. The existing Bootstrap CDN
connection is still required for Bootstrap styling and modal behavior.

## Changed files

All changes use the existing folders. No database definition, imported role,
permission, financial view/trigger, logo or uploaded receipt was changed.

| File | Status | Purpose |
| --- | --- | --- |
| includes/platform.php | New | Shared eligible general settings and published/date-filtered announcement queries |
| includes/support.php | New | Owner ticket/feedback queries, validation, transactions, fresh account checks and durable retry protection |
| user/support.php | New | Help & Support, own tickets/feedback, filters and pagination |
| user/support_action.php | New | Authorized read/detail and CSRF/signed form processing; no-JavaScript fallback |
| includes/support_content.php | New | Reusable ticket, follow-up, feedback and announcement content/forms |
| includes/support_modal.php | New | One responsive Bootstrap dialog |
| includes/announcements.php | New | Compact real dashboard announcement section |
| assets/js/support.js | New | Loading/saving, same-dialog navigation, duplicate guard, validation and focus/dirty behavior |
| includes/admin_dashboard_content.php | New | Compact dashboard, recent registrations, activity and support |
| includes/admin_directory.php | New | Focused directory tables, mobile labels and compact/advanced filters |
| includes/admin_settings.php | New | Organized profile, eligible settings, roles and read-only backups |
| includes/auth.php | Modified | Real safe successful registration/admin-login events |
| user/dashboard.php | Modified | Published announcement integration and accurate shortcut caption |
| includes/navbar.php | Modified | Working owner Help & Support navigation |
| includes/header.php | Modified | Scoped admin layout class |
| includes/footer.php | Modified | Load owner support script only on relevant pages |
| includes/admin.php | Modified | Shared platform queries, safe readable activity and real compact dashboard data |
| includes/admin_page.php | Modified | Shared controller retained; dispatch redesigned templates |
| includes/admin_navbar.php | Modified | Permission-aware grouped sidebar/topbar with profile/logout |
| includes/admin_content.php | Modified | Accurate publication explanation and IP placement in audit details |
| admin/action.php | Modified | Retain success flash after AJAX mutations |
| assets/css/style.css | Modified | Scoped forest-green admin redesign and owner announcement/support styles |
| assets/js/admin.js | Modified | Preserve original focus target, guard dialog switching and disable all controls while saving |
| tests/integration_test.php | New | Rollback setting-consumer, pagination, scoping, retry and suspension tests |
| tests/http_integration_test.php | New | Actual Apache owner/admin workflow and rejection tests |
| tests/browser_integration_test.ps1 | New | Responsive owner pages/forms and actual cross-role browser workflows |
| tests/http_auth_test.php | Modified | Clean safe registration activity for exact disposable accounts |
| tests/admin_browser_fixture.php | Modified | Return disposable owner username for cross-role integration tests |
| README.md | Modified | Current integration, behavior and corrected outdated module captions |
| database/REVIEW.md | Modified | Current schema-backed integrations and limits |
| database/INTEGRATION-AUDIT.md | New | Required checklist, completed fixes, inventory and validation |

## Validation executed

- All 13 PHP suites passed after implementation: auth_test, dashboard_test,
  sales_test, expenses_test, customers_test, utang_test, products_test, reports_test,
  admin_test, integration_test, http_auth_test, http_admin_test and
  http_integration_test.
- Service tests use real SQL with rollback. Financial totals, private receipts,
  immutable snapshots/history, business isolation and preserved posted financial
  history passed. General-setting consumer tests restored every original setting.
- Apache integration used separate random administrator, owner, other-owner and
  guest sessions. Actual registration/counts, draft/publish/read/future/unpublish,
  ticket submission/admin response/owner follow-up/reopening/status history,
  feedback review, CSRF/role/direct-URL rejection, owner isolation, audit events
  and suspension/reactivation passed.
- Hidden Chrome passed all eight admin pages at 1440/1024/768/390/320px
  (40 layouts). Original logos, active navigation, mobile offcanvas, responsive
  forms, unclipped keyboard menus, dirty forms, duplicate submissions, retained
  filters and actual account/ticket/feedback/announcement mutations passed.
- Hidden Chrome passed dashboard, owner ticket and feedback pages at the same five
  widths (15 layouts), plus ticket/feedback forms at 1440/768/320px (six forms).
  Actual publish/read/unpublish, ticket/admin response/owner follow-up, feedback
  review, escaped HTML text, validation retention and duplicate guarding passed.
  No JavaScript errors were recorded.
- Desktop and phone admin dashboard/directory/settings and owner support/detail/form
  screenshots were visually reviewed. Evidence is saved under protected, ignored
  tests/http-results/; admin-browser-checks.json and integration-browser-checks.json
  contain no passwords.
- HTTP/browser fixtures are random isolated test accounts with guarded exact cleanup.
  Production accounts were not reset and no financial fixtures were committed.
- The combined owner browser regression passed Dashboard, Sales, Expenses,
  Customers, Utang, Products and Reports, including the existing chart, forms,
  filters, navigation, keyboard/focus behavior and zero JavaScript errors.
- Initial browser harness attempts failed because of an incorrect test action URL,
  an omitted publication confirmation and an empty test account used for a
  chart-specific test. Corrected test inputs passed; no unresolved application
  failure remains in the final runs.
- Physical-device and screen-reader testing were not performed. Browser viewport
  emulation, keyboard checks and visual review were performed. Email delivery and
  backup execution cannot be tested because these features remain unavailable.
- Final syntax checks passed all 87 PHP files and 11 JavaScript scripts. Final
  read-only database verification retained 28 tables, eight views and 27 triggers.
  Only the original support_email general setting remains configured; rollback
  tests did not leave additional keys. Zero integration/admin/browser fixture
  accounts remained. The temporary schema inspection script was removed.
