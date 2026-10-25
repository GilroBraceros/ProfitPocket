# ProfitPocket

## Source-authorized test administrators

The two designated test administrators can now be provisioned once during normal
initialization of the complete deployed release, without an extra private bootstrap
configuration or SSH alias. Strict Linux installation, existing configured Hostinger
database identity, schema, permissions and release checks remain in force. XAMPP is
ineligible. This does not configure hosting deployment or supply missing DB/API
credentials. See [database/SOURCE-BOOTSTRAP.md](database/SOURCE-BOOTSTRAP.md).

## Hostinger production setup — 26 October 2026

An explicitly authorized production bootstrap can create the two temporary test
administrators during initialization or a one-time CLI job. XAMPP and mismatched
application/database identities are rejected. A private database loader supports
the same settings in web PHP and cron. The hosted setup job verifies the real Brevo
sender, enables private email settings and records a single controlled test attempt;
a separate read-only checker reports provider delivery.

No Hostinger deployment, account creation or hosted email test has been performed.
See [database/HOSTINGER-PRODUCTION-SETUP.md](database/HOSTINGER-PRODUCTION-SETUP.md)
for the exact private configuration, hPanel jobs, verification and temporary
bootstrap cleanup. No SSH alias or manual administrator SQL inserts are required.

## Registration payment and QR improvements

New owners now complete **Account Details → Subscription Payment → Verification**
without signing in between steps. Administrators manage GCash, Bank Transfer and
Other receiving accounts with private QR uploads; Maya is excluded from subscription
choices. Initial proof submission sends one combined pending-verification welcome
and an authorized administrator review notice. Existing approval and renewal rules
remain in place.

The approved additive migration is applied locally. See
[database/REGISTRATION-PAYMENTS-REVIEW.md](database/REGISTRATION-PAYMENTS-REVIEW.md)
for the complete workflow, changed files, tests and Hostinger deployment steps.

The database connection, authentication module, Business Owner Dashboard,
Sales Management, Expense Management, Customer Management, Payment Management, and
Product Management, Financial Reports and the System Administrator Panel are implemented.

## Homepage, monthly subscriptions and secure deployment — 25 October 2026

Visitors now see the public ProfitPocket homepage. Business owners use a ₱100
manual monthly subscription with private payment proofs, administrator review,
renewals and server-side paid-feature restrictions. Account/password, subscription
and support pages remain accessible while a subscription is pending or expired.
Existing financial modules and calculations are preserved.

The approved migration and Pending Payment policy are applied locally, with local
enforcement enabled. Six subscription emails extend the original eight. Safe
diagnostics, private email activation, a single authorized real-test sender and
an idempotent CLI deployment/admin bootstrap runner are ready. Local Brevo sending
has now been enabled, and one authorized real test was confirmed delivered.
Hostinger email/provisioning still require private settings and deployment
configuration; no production administrator creation was performed.

See [database/SYSTEM-UPGRADE.md](database/SYSTEM-UPGRADE.md) for the full workflow,
changed files, executed tests, actual site URL, migration order, private payment
storage, two-administrator setup, Hostinger cron jobs and remaining production steps.

## Brevo transactional email — 25 October 2026

Eight PHP-rendered notifications now cover registration, secure password recovery,
password changes, customer payments and scheduled credit reminders. Customer
notifications require explicit recorded permission. Sending remains disabled
until a replacement API key, verified sender, private reset secret and actual
HTTPS application URL are configured. The exposed key was not stored or used.

The two notification tables were applied only to local XAMPP with approval;
production migration and deployment remain manual. The complete trigger rules,
configuration variables, Hostinger cron setup, changed-file inventory and testing
results are in [database/EMAIL-NOTIFICATIONS.md](database/EMAIL-NOTIFICATIONS.md).

## Complete administrator frontend overhaul — 11 October 2026

All eight administrator pages now use a separate, scoped design system in
`assets/css/admin.css`: grouped navigation, compact top bar, balanced dashboard,
focused directories, readable ticket conversations, section-based settings and
consistent responsive modals. Original logos and existing backend behavior are
preserved. Business Owner pages retain their established design.

The identified UI problems, complete changed-file inventory, layout decisions,
executed tests and limitations are in
[database/ADMIN-FRONTEND-REVIEW.md](database/ADMIN-FRONTEND-REVIEW.md).

## System integration and admin redesign — 11 October 2026

Business owners now see the three newest published announcements on
`user/dashboard.php`. Read announcement opens the complete text and publication
date. Draft, archived, unpublished and future-dated messages are excluded from
both the dashboard query and direct detail requests. Refresh after publication or
unpublication updates the display. The schema has no expiry or per-owner read
tracking, so there is no invented unread count.

Open **Help & Support** in the owner sidebar (`user/support.php`) to submit a
ticket, read administrator replies, add a follow-up or share feedback. Conversations
and status history are preserved. A follow-up reopens a resolved ticket; closed
tickets require a new ticket. My feedback shows the actual review state. Every
owner query uses the authenticated user ID; no caller-supplied submitter is trusted.
Writes validate CSRF and signed submissions and use transactions with durable
retry protection. Existing general platform settings now appear on this page.

The admin interface now has grouped navigation, four compact dashboard metrics,
recent registrations with View actions, a short real activity list and support
requiring attention. Directories use focused columns, View + menu actions,
collapsible advanced filters and labelled record cards on phones. Settings separates
profile, platform details, roles/permissions and read-only backup history. Original
logos, permissions and working financial modules are preserved. Successful
registrations and administrator logins now record safe actual platform events.

The required checklist, complete changed-file inventory, tests and limitations are
in [database/INTEGRATION-AUDIT.md](database/INTEGRATION-AUDIT.md).

Email and password recovery are now implemented as described in the email section;
they require private server configuration. Announcement expiry/read tracking and
backup execution remain unavailable. Owner business Settings remains explicitly
disabled as Soon. Bootstrap still requires the existing CDN connection. No new
dependency, default account or database structure change was introduced.

## System Administrator Panel

Sign in through the existing auth/login.php. Active administrators are redirected
to admin/dashboard.php; owners continue to user/dashboard.php. Public registration
always creates a business owner. No default account or hardcoded password was added.

The separate administrator navigation reuses both original JPG logos, dashboard
typography, forest green accents and compact View + menu actions. Tables provide
search, applicable status/category filters, date sorting and 15-row pagination.
Bootstrap details and confirmation forms work in modals, with ordinary PHP pages
available when JavaScript is unavailable. Loading/saving states, focus restoration,
keyboard menus and unsaved-change confirmation are included.

| Page | Working functionality |
| --- | --- |
| admin/dashboard.php | Real owner-account totals, active/suspended/inactive statistics, unique businesses, open/in-progress tickets, permitted recent registrations and administrative activity |
| admin/users.php | Owner account directory, details, last login, available account history, confirmed suspension/reactivation with required reasons |
| admin/businesses.php | Business/owner search, category/account status filters and business profile details |
| admin/tickets.php | Existing tickets, descriptions, conversation, administrator replies, assignment, status transitions and complete response/status history |
| admin/feedback.php | Existing anonymous or attributed feedback, category/status filters and new/reviewed/archived state changes |
| admin/announcements.php | Create, view, edit, publish, unpublish and archive through the supported status field; author and original creation time are preserved |
| admin/logs.php | Read-only administrative audit and permitted nonfinancial platform events, search, source/action/date/administrator filters, affected records and recorded IP addresses |
| admin/settings.php | Own profile/password, existing permitted general settings, role/permission display, controlled existing administrator role assignments and latest recorded backup jobs |

### Authorization and account behavior

Every page and action endpoint checks the authenticated session, live account
status, admin role and actual role_permissions records. Mutations repeat permission
checks inside their transaction rather than trusting request or cached role values.

| Existing role | Authorized areas |
| --- | --- |
| business_owner | Existing owner modules; all administrator URLs are denied |
| support_admin | Dashboard aggregates, support tickets, feedback and own profile; recent owner contact details are omitted |
| platform_admin | All administrator pages, within existing assigned permissions |

Business profiles and logs use users.manage because the imported permissions have
no separate business/log permission. Tickets/feedback use support.manage. General
settings require settings.manage; announcements require announcements.manage;
backup metadata requires backups.manage. Own profile is available to any active
admin. Role reassignment requires both users.manage and settings.manage, permits
only existing admin accounts and admin roles within the actor's permissions, and
protects the last active administrator with both permissions. Definitions are
read-only; no owner-to-admin promotion or public administrator provisioning was added.
Administrator-role row locks serialize administrative mutations and role changes.

Suspension changes only an active owner's account_status to suspended and revokes
every existing auth_sessions record for that user. Reactivation requires a separate
confirmation and reason, changes suspended to active and leaves old sessions revoked.
The owner must sign in again. Inactive/deactivated accounts remain visible without
inventing a lifecycle for them. Business status is the linked owner's account status.
Sales, expenses, customer payments, debts and business financial history are neither
shown nor changed by the admin panel.

Administrator profile changes require the current password. Optional new passwords
must match, contain 12–72 bytes and use password_hash(); saving ends all of that
administrator's sessions. Role changes also end the target administrator's sessions.

### Stored actions, audit and integration

All writes use prepared statements, CSRF tokens, action/record-bound signed submission
keys and database transactions. Record revisions reject stale edits. Durable audit
submission keys prevent duplicate replies/announcements/status changes when a request
is retried. Profile retries are rejected rather than logging password-derived data.
Input is length/status validated; output is escaped as plain text. Database failures
produce sanitized messages rather than exposing SQL or connection credentials.

Replies are stored in ticket_messages with the real administrator and timestamp.
Status changes append ticket_status_history in the same transaction and maintain
assignment/resolved_at. No email is sent. Support tickets have priority rather than
a category field, so the table displays priority.

Significant changes append admin_action_logs with actor, target where applicable,
safe before/after metadata and required account/role reasons. The actual REMOTE_ADDR
is stored in audit JSON because there is no dedicated IP column. Existing records
without it display "Not recorded." Audit screens offer no edit/delete endpoint and
exclude financial activity, failed-login identifiers, password hashes, tokens and
internal submission metadata. Ticket/announcement bodies remain in their own
records rather than being copied to audit descriptions.

Published announcements use includes/platform.php, shared by the owner dashboard
and the administrator helper. The owner details endpoint repeats the publication
check. Help & Support now connects ticket creation, administrator replies, owner
follow-ups and feedback review states through the existing tables.

Only existing platform_name, platform_description, support_email and support_phone
settings are eligible for editing. At review time, only support_email was configured.
Missing keys are not inserted, and credentials/internal settings are never exposed.
Changing the support address does not configure email delivery. Backup monitoring is
read-only metadata for the latest 20 backup_jobs; it does not create, download,
restore or claim a backup. The schema has no backup type or announcement updated_at.
No database structure, role definitions or financial views/triggers were changed.

### Files created or modified for administration

| Files | Change |
| --- | --- |
| admin/dashboard.php, users.php, businesses.php, tickets.php, announcements.php, logs.php, settings.php | Replace authorized placeholders with the shared working controller |
| admin/feedback.php, admin/action.php | Schema-backed feedback page and authorized processing/details endpoint |
| includes/admin.php | Actual queries, filters, permissions, transactions, account/session actions, audit and published announcement helper |
| includes/admin_page.php, admin_navbar.php, admin_content.php | Shared page, distinct admin shell and reusable details/action forms |
| assets/js/admin.js, assets/css/style.css | Modal/loading/validation/focus behavior and scoped responsive admin styling |
| includes/record_actions.php, assets/js/record-actions.js, includes/footer.php | Integrate the existing compact action menus and load admin scripts only on admin pages |
| tests/admin_test.php, http_admin_test.php | Rollback service/database tests and actual Apache authentication/action tests |
| tests/admin_browser_fixture.php, browser_admin_test.ps1 | Disposable browser accounts, guarded cleanup and responsive/interactive tests |
| tests/http_auth_test.php | Expect the completed administrator user page to return 200 |
| README.md, database/REVIEW.md | Features, schema relationships, file inventory, permissions and executed checks |

### Administration checks actually executed

- Rollback database/service tests passed suspension and revoked-session behavior,
  reactivation, retained posted financial history, permissions/role spoofing,
  durable retries, ticket responses/status history, feedback, announcements,
  author/publication preservation, safe settings, controlled roles, last privileged
  admin protection, current-password confirmation, password hashing and audit redaction.
- Actual Apache tests passed guest redirects, owner rejection, permitted platform
  and support administrator pages, unauthorized POST/CSRF/record binding rejection,
  suspension/session loss/reactivation, ticket responses, feedback and announcement
  create/edit/publish/unpublish/retries. Controlled existing admin role changes
  ended the target session; a fresh sign-in applied the updated permissions.
  Profile changes ended sessions and the new password worked on a fresh sign-in.
  HTML input displayed as escaped text.
- Hidden Chrome passed all eight pages at 1440/1024/768/390/320px (40 layouts),
  navigation, original logos, mobile offcanvas, ticket forms at desktop/tablet/phone,
  keyboard menus, duplicate-submit prevention, retained filters, dirty-form
  confirmation and actual ticket/feedback/account/announcement actions. Zero
  JavaScript errors were recorded. Desktop and narrow phone screenshots were
  visually reviewed; results are in tests/http-results/admin-browser-checks.json.
- Existing authentication, dashboard, sales, expenses, customers, utang, products,
  reports and HTTP authentication/admin regression suites passed.
- The combined owner browser regression also passed Dashboard, Sales, Expenses,
  Customers, Utang, Products and Reports, including phone navigation and working
  forms; zero JavaScript errors were recorded.
- Database fixtures were rolled back; Apache/browser fixtures used random disposable
  accounts and were removed by guarded cleanup. No default testing credentials
  were persisted in source.
- Final PHP syntax checks passed all 87 PHP files; JavaScript syntax checks passed
  all 11 scripts. Final database verification retained 28 tables, eight views and
  27 triggers, with zero remaining administrator test accounts.

## Financial Reports

Open user/reports.php from Reports in the sidebar. The page keeps the existing
logos, dashboard shell, compact cards and readable tables. Sales, Expense, Profit
and Customer Receivables tabs show one report at a time. Dates use Asia/Manila: Today, Monday-based
This Week, This Month (default), This Year and Custom Date Range. Presets end today;
custom ranges accept up to 366 days. Invalid dates/filters return a friendly 400,
rather than quietly exporting a different period.

Four period-wide cards show sales after discounts, operating expenses, gross
profit and estimated net profit. Existing local Chart.js shows sales, expenses
and net profit by day (up to 31 days), week (up to 120 days) or month. An accessible
data table and empty/failure states remain available. Search/status/category
filters affect only the selected table, CSV and print rows; overview cards,
charts and report facts retain their explicitly stated scope.

- Sales lists posted transactions with current payment status, transaction and
  paid/partial/unpaid counts, discounts, customer/reference search and status
  filtering. Drafts/voids are excluded.
- Expenses lists posted operating expenses with category, description, amount
  and payment method. Expense count, highest category, expandable category
  distribution, description/category search and category filters are included.
  Inventory purchases and other cash outflows are not operating expenses.
- Profit explains sales before discounts, discounts once, net sales, historical
  product costs, gross profit, operating expenses and estimated net profit.
  Net cash received by payment date is separate. Zero recorded costs produce a
  warning because profit may be overstated.
- Utang shows current outstanding/overdue debt across all sale dates, distinct
  customers with balances and credit collections less refunds by selected payment
  dates. The all-date table includes unpaid/partial/settled credits, original net
  sale, net paid, balance, due date and text status. Settled debt cannot be overdue;
  archived customers retain debt. Current balances/statuses are not historical
  figures as of the reporting period end.

Profit cards reuse dashboard_totals() and v_daily_financials. Revenue comes from
posted v_sale_financials, not collections. Historical rounded item costs form
COGS, subtracted once. Top-five product insights use sale snapshots. Transaction
net sales are allocated proportionally to rounded item values; largest remainders
receive leftover centavos. All product contributions reconcile to net sales/gross
profit before selecting the top five. Products are grouped by ID and display their
latest sold snapshot name. Catalog price/name/archive changes preserve history.

CSV downloads include report, business, dates, timezone, currency and filter
metadata. Every matching row is exported independently of pagination, with
two-decimal amounts and spreadsheet-formula protection. Profit CSV includes its
breakdown and top products. Authorization uses the signed-in business; supplied
business IDs cannot switch tenants.

Print opens an all-matching-row view with business name, report, period and
generation time. Report-only print styles hide navigation/filters/actions,
preserve table headers and expand category distribution. Browser Print can save
as PDF; no dedicated PDF export library or new dependency was introduced.
Page filters use reliable ordinary GET requests. Transaction details reuse scoped
Sale/Expense/Utang fragments in a read-only modal with restored keyboard focus.

Queries use prepared statements and the authenticated business ID. Summary,
chart and table reads share one transaction snapshot. Reports adds no financial
write, schema migration, duplicate ledger or stored summary.

### Files created or modified for Reports

| Files | Change |
| --- | --- |
| user/reports.php | Report page, tabs, filters, CSV and all-row print view |
| includes/reports.php | Dates/filters, financial queries, product allocation and CSV helpers |
| assets/js/reports.js, assets/css/style.css | Chart, loading/details/focus behavior and scoped responsive/print styles |
| includes/header.php, includes/navbar.php, includes/footer.php | Report-only body class, navigation and existing Chart.js/module scripts |
| tests/reports_test.php | Rollback financial, date, allocation, CSV and Dashboard reconciliation tests |
| tests/http_report_checks.php, tests/http_auth_test.php | Apache report/download/print and access-control checks |
| tests/browser_report_checks.ps1, tests/browser_dashboard_test.ps1 | Reports browser checks (-Reports) and completed-module regressions |
| README.md, database/REVIEW.md | Features, file inventory, calculations, schema usage and executed checks |

### Reports checks actually executed

- PHP and JavaScript syntax checks passed.
- Rollback tests passed empty, sales-only/expense-only, fully/partially/unpaid
  sales, subsequent repayments, multiple items/debts, archived customers,
  overdue/settled status, fixed/percent discounts, historical costs, zero-cost
  warnings, voids/refunds, losses, all periods and daily/weekly/monthly charts.
  The requested 20,000 sales / 8,000 costs / 4,000 expenses example yields
  12,000 gross profit and 8,000 net profit; later 2,000 collection changes cash
  and debt only. Cards reconcile with Dashboard for the same dates.
- Tests checked exact residual-centavo allocation, search/status/category,
  pagination, all-row print/CSV, filtered CSV amounts/status, formula protection,
  business isolation and complete fixture rollback.
- Apache tests passed all tabs/CSV/print views, period presets/custom dates,
  download headers, input/XSS handling, read-only requests, guest redirects,
  admin rejection and foreign-business export isolation.
- Hidden Chrome passed all tabs at 1440/1024/768/390/320px without page overflow,
  four cards/three chart series, chart/card reconciliation, custom dates, empty
  states, CSV and all three detail modals with restored focus. Print media hid
  navigation/actions and showed business/period. Chrome produced nonempty print
  PDFs for all four tabs. Desktop, phone and print screenshots were visually
  reviewed. No physical printer was tested.
- Existing auth/Dashboard/Sales/Expenses/Customers/Utang/Products backend suites,
  Utang concurrency tests, Apache tests and combined Chrome checks with
  -Reports -Products -Utang -Customers -Expenses -Sales passed with zero JavaScript
  errors. Report browser checks made no financial writes.

Financial fixtures and trigger audits were rolled back. Existing HTTP/browser
fixtures used disposable businesses and their guarded cleanup routines.
Protected screenshots/results/print PDFs are in ignored tests/http-results/.
The database remains 28 base tables, eight views and 27 triggers. Reports reflects
current posted/void states and collections; it is not an immutable historical
closing statement or tax report.

## Product Management

Open `user/products.php` from the enabled sidebar. Total Products includes archived
records; Active Products counts the active catalog. These cards cover the whole
business independently of list filters. Search names, filter active/archived/all,
sort names or selling prices, and browse 15-row pages. The existing View + three-dot
actions open details, edit, archive and restore modals. Add Product uses the same
modal system, with an ordinary form fallback if JavaScript is unavailable.

Products use the existing name, optional SKU/description, default selling price,
default unit cost and active status. Prices accept zero and up to two decimal
places. Exact centavo previews show estimated unit gross profit before discounts
and operating expenses. Below-cost prices are allowed with a visible warning.
Blank SKUs are NULL; nonblank SKUs must be unique within the business, including
archived products. Duplicate names are allowed. Descriptions are limited to 4,000
characters and SKUs to 60. Output is escaped and inline errors retain input.

Owner authorization, business-scoped queries, CSRF, signed submission keys,
transactions and business/product locks protect writes. Revision checks reject
stale edits. Existing activity logs store before/after values and durable request
keys for create/edit/archive/restore replay; retain these audits. Product records
are archived rather than deleted. No database definition was modified.

New Sales selections load current catalog selling prices and costs. Archived
products are excluded from new selections; existing draft items may retain their
linked products. Historical item name/cost/price snapshots and financial totals
are preserved when the catalog changes. The existing Sales manual price override
remains available. The empty Sales catalog link opens Add Product.

The database has no product category field or category table, so category controls
were omitted. Stock tracking, inventory adjustments and email integration are not
part of this module. Existing logos and completed module styles are preserved.

### Files created or modified for Products

| Files | Change |
| --- | --- |
| `user/products.php`, `user/product_action.php` | Catalog page and scoped HTML/JSON actions |
| `includes/products.php`, `includes/product_content.php` | Queries, validation, audited transactional writes and modal content |
| `assets/js/products.js`, `assets/css/style.css` | Exact profit preview, loading/validation/dirty guards and responsive layouts |
| `includes/navbar.php`, `includes/footer.php` | Enabled navigation and module scripts |
| `includes/record_actions.php`, `assets/js/record-actions.js` | Product action routing and keyboard focus support |
| `includes/sale_form_content.php` | Empty-catalog link to Add Product |
| `tests/products_test.php` | Rollback catalog, financial snapshot and integration tests |
| `tests/http_product_checks.php`, `tests/http_auth_test.php` | Actual HTTP CRUD, replay and authorization/validation checks |
| `tests/browser_product_checks.ps1`, `tests/browser_dashboard_test.ps1` | Product browser checks (`-Products`) and shared menu verification |
| `README.md`, `database/REVIEW.md` | File inventory, schema usage and test results |

### Product validation actually executed

- PHP syntax checks passed. Product rollback tests passed creation/edit/status,
  nullable/duplicate SKUs, zero/below-cost prices, exact calculations, altered
  retries, stale edits, isolation, search/sort/pagination and transaction rollback.
- Actual posted sales in rollback fixtures verified old snapshots and Dashboard
  totals remain unchanged after catalog edits, while future sales use current
  prices/costs. Archived products are rejected for new items; linked draft
  snapshots remain intact. No financial fixtures were committed.
- Live Apache HTTP checks passed create/edit/archive/restore, repeated requests,
  forged CSRF/signatures, input errors, escaping, foreign-business rejection,
  read-only details, and current/archived Sales options.
- Hidden Chrome passed catalog and modal layouts at desktop, tablet and narrow
  phone widths (320–1440px), exact positive/negative profit previews, below-cost
  warnings, keyboard menus/focus, input retention, duplicate-click prevention,
  filter preservation and actual product CRUD. Selecting a product in Sales
  automatically loaded its current price and cost. Zero JavaScript errors.
- Existing auth, Dashboard, Sales, Expenses, Customers and Utang backend suites,
  Utang concurrency checks, HTTP checks and combined browser regressions passed.

HTTP/browser CRUD used disposable businesses, verified they had no posted financial
history, and removed their catalog/audit fixtures. Browser tests did not post sales.
Protected screenshots and results are in ignored `tests/http-results/`.

## Payment Management

Open `user/payments.php` from the owner sidebar. **Payment History** includes
valid full payments, downpayments and subsequent partial/final payments across
Cash, Digital Wallet (GCash, Maya or named Other) and Bank Transfer. **Outstanding
Balances** preserves unpaid/partial sales, due dates, overdue monitoring and
audited due-date editing. Both views use compact actions, responsive records and
Bootstrap dialogs.

Add Sale uses the same method controls and stores the initial payment, provider,
reference and optional payment notes in the existing ledger. Later collections
reduce the balance without adding sales revenue. Customer totals/history,
Dashboard collections/current balances and Financial Reports remain connected.

The owner approved and applied `database/payment-provider-proposal.sql`: one
nullable `sale_payments.provider_name` column and one Other Digital Wallet method.
Existing financial rows, views, relationships and audit triggers were preserved.
The SQL file is a migration reference; do not reapply it to this updated database.

`user/utang.php` redirects to the new balance view. Existing action/token and
idempotency identifiers remain compatible. All collection totals are based on
currently unreversed payments on valid sales and their payment dates; current
balances explicitly cover all sale dates. Original payment/refund histories remain
read-only and traceable.

The full changed-file inventory, database relationships, financial rules,
executed tests and limitations are in
[database/PAYMENT-MANAGEMENT-REVIEW.md](database/PAYMENT-MANAGEMENT-REVIEW.md).

## Run with XAMPP

1. Start Apache and the existing database server.
2. Open http://localhost/profitpocket/ .
3. Register an owner using an existing business category, then sign in using the
   username or email. Existing administrator accounts use the same sign-in form.
   Public registration cannot create administrators.

The connection defaults to the local XAMPP database server and `profitpocket_db`.
For a different server, configure `PP_DB_HOST`, `PP_DB_PORT`, `PP_DB_NAME`,
`PP_DB_USER`, and `PP_DB_PASSWORD` in Apache's environment, then restart Apache.
Use a dedicated application database account outside local development.
`PP_BASE_PATH` defaults to `/profitpocket`; set it to `/` for a site at the web root.
Do not commit credentials. PHP errors are logged rather than shown to visitors.

Apache must honor `.htaccess` (`AllowOverride` enabled). Protected folders are
`config/`, `includes/`, `database/`, `tests/`, and `uploads/receipts/`; directory listings and sensitive
file types and hidden directories are blocked. The root rules use Apache's
`mod_rewrite`, which is enabled in this XAMPP installation. Equivalent rules are
required with another web server.
Bootstrap 5.3.3 is loaded from jsDelivr with integrity checks and requires internet
access; the app's own CSS and JavaScript are local.

## Authentication behavior

- Owner registration inserts `users` and `businesses` in one transaction.
- Passwords use `password_hash()` and `password_verify()`, with rehashing at login.
- Forms use CSRF tokens. Logout requires POST, revokes the database session, and
  destroys the PHP session cookie.
- Successful login rotates the PHP session ID. Only a hash of the authentication
  token is stored in `auth_sessions`. Sessions expire after eight hours, or after
  30 minutes of inactivity in the PHP session.
- Account status, role, and business are read from the database on each protected
  request. Suspended accounts and revoked sessions lose access immediately.
- Ten failed sign-ins from an IP or for an identifier in 15 minutes temporarily
  block sign-in. Failure logs use hashed IP/identifier values. This uses the
  existing `activity_logs` table; log retention and more scalable rate limiting
  can be addressed as deployment traffic grows.
- Admin modules enforce existing `role_permissions`. Businesses and logs use
  `users.manage`, since there are no separate permissions for those modules yet.
- Business modules take their business ID from `require_business()` rather than
  request parameters. Every future data query must still filter by that ID.

Password recovery now uses single-use, expiring hashed tokens and the notification
outbox; sending requires private configuration as described in the email guide.
The recovery form always displays a generic response. The owner dashboard uses
actual financial records. The administrator management modules are now implemented
with the existing permissions, as described above.

## Business dashboard

`user/dashboard.php` uses the existing owner session and linked business. It shows
net sales, operating expenses, estimated net profit, current outstanding balances,
financial activity, and the latest ten sales/expenses/credit collections or refunds.
The reusable dashboard layout is in `includes/navbar.php`; query/date/currency
helpers are in `includes/dashboard.php`. Authentication and connection code were
preserved. Original JPG logos are displayed without altering or cropping them.

- Today, Monday-to-today week, month-to-date (default), and custom ranges up to 366
  days are supported, in Asia/Manila. Cards, chart, and recent transactions use the
  selected dates. Comparisons use the immediately preceding equally long period.
- `v_daily_financials` provides net sales, historical item costs and operating
  expenses. Estimated net profit = sales − COGS − operating expenses. Inventory
  purchases are displayed in transaction history but excluded from operating
  expense and profit deductions, since item cost snapshots already supply COGS.
- Outstanding balances use `v_sale_financials` for **posted** sales, excluding drafts and
  voids. It is the current recorded balance across all dates, independent of the
  reporting filter. Partial collections reduce credit without duplicating revenue.
- `v_sale_financials`, `expenses`, `sale_payments`, `sales`, and same-business
  `customers` supply recent activity. Collections/refunds for voided credit sales
  remain historical cash activities; voided sales themselves are excluded.
- Read queries share a transaction snapshot on this server's REPEATABLE-READ
  isolation level. Prepared statements take business_id only from the signed-in
  user; request parameters cannot override it. No schema changes or totals storage.
- Chart.js 4.4.8 and a Bootstrap Icons 1.11.3 SVG subset are local assets. The
  existing Bootstrap CSS/JS still require CDN access. Chart data has an accessible
  table and a fallback if Chart.js is unavailable. Zero-record businesses display
  formatted zeros and empty states; unlinked owners get an explanatory message.
- Sales navigation and Add Sale open the sales form; Record Payment opens Payment Management.
  Expenses, Customers, Payments, Products, Reports and Help & Support are working
  sidebar destinations. The dashboard shortcuts link to completed forms.
  Owner business Settings is still explicitly disabled as Soon.
- Profit uses recorded item costs; zero-cost sold items display a warning because
  those costs can understate COGS. This is an estimated financial overview.

## Sales Management

Open **Sales** in the owner sidebar at `user/sales.php`. The list has actual sales,
summary totals, reference/customer search, reporting dates, record/payment status,
oldest/newest sorting and 15-record pages. Revenue and completed/pending counts
use the current filters and exclude drafts/voids. Outstanding balance is current
across all dates. Due dates show a separate overdue label.

**Modal workflow:** New Sale, View, Edit and Void actions open a single shared
Bootstrap modal on the sales page. Entry has Customer, Items Purchased and Payment
Details sections, with optional date/draft/notes under More options. New sales
default to Walk-in Customer. The due date appears only while a balance remains.
Details are read-only and completed-sale edits expose notes only. Void confirmation
shows the reference, customer and total, with the existing returned-money policy.
Forms, details and edits use fullscreen dialogs below 576px; void confirmation
stays compact. Headers and Cancel/Save actions stay visible while the body scrolls.

Forms submit to the existing PHP controllers with `?modal=1` for JSON responses.
Financial write helpers are unchanged. Server/native validation appears beside
fields and in a clear alert; errors retain entered values and the submission key.
Loading indicators and disabled controls prevent accidental repeated submissions.
Closing a changed form or leaving the page warns before discarding it. Focus enters
the dialog and returns to its opening action; dialogs are never nested. Successful
saves refresh only the list/summary region, preserving filters, URL and scroll.
The dedicated pages remain usable as fallbacks when modal JavaScript is unavailable.

- `user/sale_form.php` creates or edits an eligible draft. Select real products
  and optional existing customers, add up to 50 items, and specify quantities and
  selling prices. Names and unit costs are captured from the product records;
  existing item snapshots are preserved on draft edits. There is no stock logic.
- Fully paid walk-in sales are supported. Partial/unpaid sales require a customer
  and create one `customer_credits` row linked to the original sale. Due dates are
  optional. Credit balances come from the existing views, rather than copied
  balance columns. Later collections are recorded through the completed Utang module.
- PHP uses **BCMath** exact decimals; JavaScript uses scaled **BigInt** values.
  Quantity supports three decimal places; money and discount values support two.
  Each quantity x price line rounds half up to centavos, as in the SQL view.
  Fixed discounts subtract from the subtotal; percentages round the final total
  to centavos. Positive totals, discount limits, payment limits, product/customer
  ownership, active methods, timestamps and due dates are validated on the server.
- Payment choices come from active `payment_methods` (currently Cash, GCash,
  Maya and Bank Transfer). Initial collections are stored once in `sale_payments`
  at the sale timestamp. Signed submission keys, existing unique references and
  payment idempotency keys protect refresh/replay. No payment gateway is added.
- Drafts contribute no revenue and record no payments. Posted sales allow notes
  edits only, matching existing database triggers. Drafts with any payment history
  cannot receive financial edits. Edits are audited; stale forms are rejected.
- `user/sale_view.php` shows actual customer information, historical items,
  discounts, due dates, notes and chronological collections/refunds. Voiding
  requires a reason and preserves all history. Collected money must be returned
  and explicitly confirmed first; linked refunds reverse only each original
  collection's unrefunded amount, using its original method. This records returned
  money; it does not send a bank or wallet refund. Voiding cannot be undone.
- `user/sale_product.php` adds only a product name, default price and default cost
  reference when needed. It is not full Product Management or inventory tracking.
  Customer selection uses the Customer Management records and excludes archived
  customers for new sales. Already-linked sales keep their customer reference.
- All writes share a database transaction and business lock. Reads/writes use the
  session's business ID. CSRF and action/record signatures protect mutation forms.
  Original logos and owner layout are reused; dashboard queries already reflect
  posted sales, repayments and voids on refresh and required no changes.

PHP must have PDO MySQL, mbstring and BCMath; all are available in this XAMPP
installation. No Composer/npm framework or database migration is required.

## Checks

From PowerShell in this folder:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
& C:\xampp\php\php.exe tests/auth_test.php
& C:\xampp\php\php.exe tests/http_auth_test.php
& C:\xampp\php\php.exe tests/dashboard_test.php
& C:\xampp\php\php.exe tests/sales_test.php
& C:\xampp\php\php.exe tests/expenses_test.php
& C:\xampp\php\php.exe tests/customers_test.php
```

`auth_test.php` uses one transaction and rolls back its temporary accounts,
businesses, session tokens, and log entries. It never changes existing users or
database definitions. MySQL/MariaDB may consume auto-increment numbers even when
the transaction rolls back. Do not expose CLI tests through HTTP.

`http_auth_test.php` requires Apache at `http://localhost/profitpocket`. It creates
temporary owner/platform-admin/support-admin fixtures, tests real forms and cookie
sessions, and removes only those fixtures in its `finally` block. It does not use
or change existing account passwords. It reports a fixture suffix if cleanup fails.
It also checks sales route authorization, CSRF/signatures, product references,
draft creation/replay/details/edit/search, escaping and stale requests. HTTP sale
fixtures are restricted to immediate drafts without credit/payment history;
cleanup verifies that condition before removing only the test business's records.

`sales_test.php` exercises the actual financial write helpers and imported
triggers/views in one rollback transaction: one/multiple items, fractional/half-cent
rounding, discounts, full/partial/unpaid payments, credits/due dates, validation,
cross-business restrictions, replay, draft/snapshot/notes edits, stale revisions,
draft posting, explicit refunds/voids, existing refund limits, audit events,
filters/pagination and dashboard updates. Original table counts are checked after
rollback. Financial fixtures are never committed or retained in production data.

The Chrome check supports `-Sales` alongside `-Username` and `-Password` arguments
(supply your local owner credentials). It checks desktop and 768/390/320-pixel sales
pages/modals, multiple-item calculations, scrolling without horizontal overflow,
visible footer actions, adjacent validation, error/input retention, submission
loading/locking, unsaved-change cancellation, focus restoration and the void dialog.
Successful AJAX draft saves and list/filter/scroll preservation are tested in a
temporary business created by `browser_sales_fixture.php`; cleanup removes only
that exact business and its immediate drafts after checking for payment/credit
history. The test blocks financial browser writes. Paid/partial/unpaid/void writes
are tested through the rollback integration tests, and draft saves through HTTP.
Screenshots/results stay in the ignored, HTTP-protected `tests/http-results/`.

During the initial modal browser check, an incorrect CSRF selector inadvertently
saved test sale **#108** for Carlo. It was corrected with the supported audited
void and a linked reversal of its recorded collection; no actual money was
collected. The void reason records the testing error. Its history remains visible
under Voided/All records, but contributes no posted revenue or customer debt.
Carlo's original posted sales and outstanding debt remain PHP600 each. The browser
test now guards against unintended writes and uses a disposable business for saves.

## Customer Management and shared table actions

Open `user/customers.php` from the sidebar or the dashboard's Add Customer shortcut.
The page shows active customer count, customers with outstanding credit, total utang,
and a customer directory. Purchase and credit cards read the actual business records.
Search works by name or mobile number, including full +63-format numbers. Balance
filters cover all/utang/clear/overdue; the directory supports active/archived/all,
name/recent-activity sorting and 15-row pages. Summary cards cover the whole business,
independent of directory filters. Debt totals include archived customers.

Customer entry/edit uses a shared Bootstrap modal with name, optional Philippine
mobile number, email, address and notes. Names are limited to 150 characters, address
to 255, email to 191 bytes and notes to 4,000 characters. Mobile numbers accept local
09 and +639/639 formats with spaces/hyphens/parentheses and are stored as 09 numbers.
Blank optional fields use NULL; invalid values produce adjacent field errors. Equal
names are allowed. A matching normalized phone number in the same business produces
a warning and requires explicit shared-number confirmation, including matches to
archived customers. Identical signed submissions replay their existing result.

Details display contact information, date added, notes, purchase totals/count,
the latest 10 posted purchases, outstanding utang, unpaid credit count, and up to
10 posted credit transactions with due dates, unpaid entries first. These are
read-only summaries; the Utang module now handles collections and due corrections.
Email reminders remain unavailable until email is configured.

Purchases sum `v_sale_financials.net_sale` for posted sales belonging to that business
and customer. Credits join `customer_credits` by its sale primary key; utang sums
remaining `balance_due` for posted credit sales only. The view already aggregates
items and collections/refunds, avoiding join multiplication and treating repayments
as payments rather than additional purchases. Draft and void sales are excluded;
settled credit has zero outstanding balance. Overdue means a positive balance with
a due date before the current Manila date. Recent activity includes customer edits,
posted purchases and their latest payment date. Money uses the existing DECIMAL,
BCMath and centavo display helpers.

Archive and Restore use explicit confirmation modals and the existing `is_active`
column. Archiving hides the customer from the active directory and the new-sale
selector while preserving sales, credits, payments and outstanding amounts. Editing
an archived customer's contact information keeps them archived. Restore makes them
available again. The existing Sales catalog and views already read current customer
names/status, so no Sales financial helpers or queries were changed. Contact/status
changes preserve dashboard revenue, expenses, profit and debt.

Every customer query is business-scoped. Transactions, owner business locks, prepared
statements, CSRF, signed submission keys, revision checks and `activity_logs` audit
entries protect changes. Revision checks cover contact/status fields rather than
changing credit totals. Keep customer audit events to preserve durable submission
replay. No table, view, trigger, foreign key or original customer record was changed.

Sales, Expenses and Customers now share a compact bordered **View** link and an
accessible three-dot **More actions** button. Secondary menus use clear labels and
distinct danger text; void/archive actions retain Bootstrap confirmation modals.
The small shared component receives each module's existing URLs. Open menus are
temporarily placed outside scrolling table containers to avoid clipping, with
arrow/Home/End/Escape/Tab navigation and focus returned to the More actions button
after secondary-action modals close. The existing Bootstrap icon library and logos
are preserved; no icon package or framework was introduced. Sales/Expense financial
write logic, calculations and database operations were unchanged for this redesign.

Tables used: `customers`, `businesses`, `sales`, `sale_items`, `sale_payments`,
`customer_credits`, `activity_logs` and the existing authentication tables. Financial
totals use `v_sale_financials`, whose item/payment aggregate views already preserve
one row per sale. Composite customer/business foreign keys keep sales references
within the owning business.

Checks executed locally:

- `tests/customers_test.php` performs actual writes in rollback transactions:
  creation/replay, optional fields, normalized/shared phones, matching names, input
  validation, cross-business access, edit/replay/stale requests, the PHP700 debt
  example, multiple repayments, discounts, overdue/settled credit, draft/void/refund
  exclusions, history/search/filters/pagination, archive/replay/restore and Sales
  selection. It verifies financial totals are preserved by contact/status changes.
- `tests/http_auth_test.php` includes `tests/http_customer_checks.php`: real customer
  create/edit/archive/restore/replay, duplicate-phone confirmation, validation,
  escaping, foreign access, CSRF, stale edits, read-only details, phone search and
  new/archived/restored Sales selector checks. Disposable unlinked customer references
  are removed after verifying the test business has no posted financial history.
- `tests/browser_dashboard_test.ps1 -Customers -Expenses -Sales` checks desktop and
  768/390/320-pixel pages/modals, real customer saves/edits/archive/restore in a
  disposable business, filters and counts after AJAX refresh, Sales selection,
  validation/input retention, duplicate-submit locking, in-modal discard prompts,
  action menus outside tables, keyboard/focus behavior and existing module
  regressions. Screenshots were inspected and no JavaScript exceptions occurred.
  Supply your local owner credentials with `-Username` and `-Password`.

Financial integration fixtures roll back; HTTP/browser contact-only fixtures are
removed after guard checks. Original customers and financial records were preserved.
Auto-increment counters may advance. No phone/email delivery service is added;
contact validation verifies format, not ownership. Bootstrap continues to require
the existing CDN connection.

| Files created | Purpose |
| --- | --- |
| `includes/customers.php`, `includes/customer_content.php` | Scoped customer/financial queries, writes and reusable modal content |
| `user/customer_action.php`, `assets/js/customers.js` | Transactional routes, AJAX updates, validation and unsaved-form handling |
| `includes/record_actions.php`, `assets/js/record-actions.js` | Shared action controls, unclipped menus and keyboard/focus support |
| `tests/customers_test.php`, `tests/http_customer_checks.php`, `tests/browser_customer_checks.ps1` | Rollback financial, real HTTP and disposable browser coverage |

| Files modified | Purpose |
| --- | --- |
| `user/customers.php`, `assets/css/style.css` | Working customer directory, modals and shared responsive action styling |
| `user/sales.php`, `user/expenses.php` | Shared action controls; original financial queries/actions retained |
| `includes/navbar.php`, `includes/footer.php`, `user/dashboard.php` | Customer navigation, page scripts and Add Customer shortcut |
| `tests/http_auth_test.php`, `tests/browser_dashboard_test.ps1`, `tests/browser_expenses_checks.ps1`, `tests/browser_sales_fixture.php` | Customer coverage, menu assertions and guarded contact fixture cleanup |
| `README.md`, `database/REVIEW.md` | Current implementation, relationships, checks and limitations |

## Expense Management

Open `user/expenses.php` from the sidebar or the dashboard's Add Expense shortcut.
The page has three compact summaries, real expense history, description/category
search, category/date/status/type filters, newest/oldest sorting, and 15-row pages.
Add, view, edit and void actions use one Bootstrap modal with dedicated-page
fallbacks. Phone history uses stacked rows; entry forms fill the phone screen with
scrollable content and visible Cancel/Save actions. Errors preserve entered data,
and dirty forms prompt before being discarded.

Required fields are an owned active expense category, description, positive amount,
past/current Manila date, active payment method and expense type. A minimal category
form reuses existing names in that business and can reactivate a matching inactive
category. Payment methods come from the existing table; no example categories or
payment methods were inserted into existing businesses.

Amounts use validated decimal strings, BCMath and the existing DECIMAL columns.
Total Expenses and Highest Expense Category use posted **operating** expenses
matching the selected period/filters. Expenses This Month uses the full current
calendar month and all categories, independent of the history filters. Stock
purchases and other cash payments are retained in history but excluded from these
operating totals. Net profit remains net sales minus COGS minus operating expenses.
The existing `v_daily_financials` and dashboard queries already implement these
rules, so refreshing the dashboard updates its cards, chart and recent transactions
without changes to financial query code. Voided records contribute no active cost.

**Editing follows the imported database trigger.** Amount, date, category, payment
method and classification are finalized even for ordinary expenses. Posted expenses
allow description/notes edits and receipt replacement; financial corrections use
Void with a required reason, then a new expense. Voids preserve history and audit
records and do not record a supplier refund. Voided expenses cannot be edited.
Retrying the same signed void submission returns its existing result without
creating another void event. A fresh void request remains rejected.

Receipts support JPG/PNG/WebP up to 2 MB and 16 million pixels. PHP upload admission,
Fileinfo MIME checks and image dimensions validate files before storage. Random
`.bin` filenames go into `uploads/receipts/`, whose `.htaccess` denies direct HTTP
access. Only the owner-scoped `user/expense_receipt.php` endpoint serves images or
downloads with safe headers. Replacements retain previous attachments. Failed
transactions remove newly stored files, including destinations tracked before a
failed move. A forced receipt foreign-key failure verifies cleanup after storage.
Fileinfo and image inspection are available
in XAMPP; no GD, Composer or npm dependency was added. Apache must honor the receipt
directory's `.htaccess` rules and be able to write there.

Recurring schedules use the existing daily/weekly/monthly/yearly fields. Schedules
can be paused/resumed and have an optional end date. A due occurrence becomes an
actual expense only when the owner clicks Record paid expense. Its category,
payment method, classification and amount follow the schedule. Confirmation advances
the cursor once, preserves month-end/leap-day anchors, and stops after the end date.
The existing unique recurrence/due-date key prevents duplicate actual payments.
There is no background scheduler; templates never affect totals. Voiding a paid
occurrence keeps its occurrence reserved; use an ordinary corrected expense if needed.

Owner/business locks, prepared statements, CSRF, signed submission keys and stale
revision checks protect changes. Transactions cover expenses, receipt metadata,
recurrence advancement and audit records. Submission replay uses request keys in
the existing `activity_logs`; retain these financial audit records to preserve
deduplication. The database structure and original financial records were unchanged.

Tables used: `expenses`, `expense_categories`, `expense_receipts`,
`recurring_expenses`, `payment_methods`, `activity_logs`, `businesses` and the
existing authenticated users/sessions, with `v_daily_financials` for integration.

Expense validation executed locally:

- `tests/expenses_test.php`: actual database writes inside rollback transactions:
  create/replay, multiple categories, amounts, search/date/type/category filters,
  pagination, totals/profit, description/notes edits, stale requests, void/replay,
  JPG/PNG/WebP validation, receipt storage/replacement/read isolation, invalid files,
  recurring confirmation/deduplication, future/paused/ended schedules and date anchors.
- `tests/http_auth_test.php`: actual HTTP page/modal/security checks, category and
  schedule save/replay, positive multipart JPEG admission with an invalid amount,
  fake-image rejection, CSRF failures, foreign expense/category access, forbidden
  admin access, missing receipts and direct-storage denial. Its fixture cleanup
  verifies that no financial expense was committed before removing reference rows.
- `tests/browser_dashboard_test.ps1 -Expenses -Sales`: Chrome desktop and
  768/390/320-pixel pages/modals, empty states, action menus, validation, loading,
  duplicate-submit blocking, error/input retention, discard prompts, focus restoration
  and the dashboard shortcut, plus existing Dashboard/Sales checks. Screenshots were
  inspected; there were no JavaScript exceptions. Supply your local owner credentials
  using `-Username` and `-Password`.

Browser expense writes are restricted to one rejected-CSRF request. Financial
create/edit/void and receipt persistence are verified in rollback integration
tests, rather than leaving expense test records that the database forbids deleting.
A successful committed expense submission and image download were not exercised
through the browser/HTTP suite. All financial test fixtures and receipt files were
removed by rollback/cleanup; auto-increment counters can still advance.

| Files created | Purpose |
| --- | --- |
| `includes/expenses.php`, `includes/expense_content.php` | Scoped financial helpers and reusable forms/details |
| `user/expense_action.php`, `user/expense_receipt.php` | Transactional actions and private receipt delivery |
| `assets/js/expenses.js`, `uploads/receipts/.htaccess` | Modal behavior and receipt storage protection |
| `tests/expenses_test.php`, `tests/browser_expenses_checks.ps1` | Rollback financial checks and guarded browser checks |

| Files modified | Purpose |
| --- | --- |
| `user/expenses.php`, `assets/css/style.css` | Working expense page and responsive styles |
| `includes/navbar.php`, `includes/footer.php`, `user/dashboard.php` | Expense navigation, page script and Add Expense shortcut |
| `.gitignore` | Keep receipt protection tracked while excluding private images |
| `tests/http_auth_test.php`, `tests/browser_dashboard_test.ps1` | Expense HTTP/browser coverage alongside existing tests |
| `README.md`, `database/REVIEW.md` | Module behavior, schema restrictions and verification scope |

## Files changed for the modal interface

| Files | Change |
| --- | --- |
| `includes/sales_ui.php` (new) | HTML/JSON presentation responses and field-error mapping |
| `includes/sale_form_content.php`, `includes/sale_details_content.php`, `includes/sale_void_content.php`, `includes/sale_modal_error.php` (new) | Reusable form content, read-only details, compact void confirmation and safe load errors |
| `user/sales.php`, `user/sale_form.php`, `user/sale_view.php` | Modal actions/shell and optional fragment/JSON responses; dedicated-page fallbacks |
| `assets/js/sales.js`, `assets/css/style.css` | Shared modal lifecycle, AJAX refresh, focus, loading, unsaved guards, validation and responsive styling |
| `tests/browser_sales_fixture.php` (new), `tests/browser_dashboard_test.ps1`, `tests/http_auth_test.php` | Guarded browser draft fixtures, modal behavior and actual HTTP fragment/JSON tests |
| `README.md`, `database/REVIEW.md` | Current UI behavior, checks and the corrected browser-test record |

No financial helpers, database definitions, authentication code, logos or dashboard
layout were rebuilt for the modal redesign.

## Files changed for Sales Management

| Files | Change |
| --- | --- |
| `includes/sales.php` (new) | Scoped queries, exact calculations, validation, transactions, snapshots, audit, edits, refund/void and reference helpers |
| `user/sales.php` | Working sales history, summaries, search, filters and pagination |
| `user/sale_form.php`, `user/sale_view.php`, `user/sale_product.php` (new) | Sale/draft entry, details and explicit void confirmation; minimal product references |
| `assets/js/sales.js` (new), `assets/css/style.css` | Live exact previews, multiple items, option searches and responsive sales styles |
| `includes/navbar.php`, `includes/footer.php` | Active Sales navigation and route-specific scripts; shared owner layout |
| `user/dashboard.php` | Enable Add Sale and Record Utang links; financial queries preserved |
| `tests/sales_test.php` (new), `tests/http_auth_test.php`, `tests/browser_dashboard_test.ps1` | Rollback financial checks, real HTTP drafts/security and browser sales checks |
| `README.md`, `database/REVIEW.md` | Current module behavior, schema restrictions, validation and limitations |

Database tables used are `sales`, `sale_items`, `sale_payments`,
`customer_credits`, `products`, `customers`, `payment_methods`, `activity_logs`,
`businesses`, `users` and `roles`, plus the existing financial views. Composite
sale/product/customer foreign keys preserve business relationships; credit uses
its sale primary key and refunds reference the original collection. Database
definitions, existing financial records, authentication/connection code and JPG
logos were preserved. Product, Utang, Reports and administrator management have
since been completed; see their current module sections above.

Verified locally: PHP syntax for every PHP file; rollback integration tests; HTTP
registration, username/email login, session rotation, logout/revocation, input
validation, escaping, CSRF, guest/role/permission boundaries, account suspension,
and private file protection. `dashboard_test.php` verifies fully/partially paid
sales, repayments, fixed/percent discounts, historical cost snapshots, operating
versus inventory expenses, drafts/voids/refunds, negative profit, dates and empty
states. Financial fixtures and trigger-generated logs are all rolled back.

The built-in browser runtime failed to start. Headless Chrome checked the actual
authenticated dashboard at 1440, 768, 390 and 320 pixels. Logos, chart rendering,
active navigation, desktop collapse, mobile menu, custom date controls, account
dropdown and logout passed, with no JavaScript exceptions or horizontal page
overflow. Screenshots and results are under the protected, ignored
`tests/http-results/` directory. The reusable browser script takes `-Username` and
`-Password` as parameters; it does not store login credentials in project files.
This initial dashboard verification did not cover financial writes. Subsequent
module sections record their implemented CRUD and executed checks.

See `database/schema-reference.sql` for all columns, indexes, keys, checks,
triggers, and views, and `database/REVIEW.md` for the module relationships and plan.
The schema export is a **reference only**. Do not import it over your existing
database. No migration or database initialization is run by this application.

## Files changed for the dashboard

| Files | Change |
| --- | --- |
| `user/dashboard.php` | Financial overview, filters, current credit, chart/table, recent activity and honest pending actions |
| `includes/dashboard.php` (new) | Dashboard query, date, grouping, currency, comparison and icon helpers |
| `includes/header.php`, `includes/navbar.php`, `includes/footer.php` | Conditional owner layout, sidebar/topbar, original JPG favicon and dashboard assets; existing layouts preserved |
| `assets/css/style.css`, `assets/js/script.js` | Scoped dashboard styles, responsive controls, sidebar collapse, custom date inputs and Chart.js rendering |
| `assets/js/chart.umd.min.js`, `assets/images/bootstrap-icons.svg`, `assets/THIRD-PARTY-LICENSES.txt` (new) | Local pinned chart dependency, selected icon symbols and licenses |
| `tests/dashboard_test.php`, `tests/browser_dashboard_test.ps1` (new), `tests/http_auth_test.php` | Financial fixtures with rollback, actual browser checks, dashboard validation and identity-tampering checks |
| `README.md`, `database/REVIEW.md` | Current implementation, validation, scope and file inventory |

No existing authentication, connection, logo, administrator, or other management
module files were modified for the dashboard. Database definitions are unchanged.

## Files changed in the initial authentication step

| Files | Change |
| --- | --- |
| `config/database.php` | PDO connection with environment configuration and safe errors |
| `auth/login.php`, `auth/register.php`, `auth/logout.php`, `auth/forgot_password.php` | Working sign-in, owner registration and logout; honest sign-in help page |
| `includes/auth.php` | Session, CSRF, validation, role/permission and business ownership helpers |
| `includes/header.php`, `includes/navbar.php`, `includes/footer.php` | Reusable Bootstrap page layout and sign-out form |
| `includes/module_pending.php` | Shared explicit pending page for later modules |
| `index.php` | Routes visitors to sign-in or their authorized account page |
| `user/dashboard.php`, `admin/dashboard.php` | Actual account/business/permission information |
| `user/customers.php`, `user/expenses.php`, `user/products.php`, `user/reports.php`, `user/sales.php`, `user/settings.php`, `user/utang.php` | Authorization guards and pending state only |
| `admin/announcements.php`, `admin/businesses.php`, `admin/logs.php`, `admin/settings.php`, `admin/tickets.php`, `admin/users.php` | Role/permission guards and pending state only |
| `assets/css/style.css`, `assets/js/script.js` | Forest-green responsive styles and accessible password visibility buttons |
| `.htaccess`, `config/.htaccess`, `includes/.htaccess`, `database/.htaccess`, `tests/.htaccess`, `.gitignore` | Private file/directory protection and exclusions |
| `database/schema-reference.sql`, `database/REVIEW.md` | Schema-only reference, relationships, issues and development plan |
| `tests/auth_test.php`, `tests/http_auth_test.php` | Database and HTTP integration checks with test fixture cleanup |
| `README.md` | Setup, behavior, validation, limitations, and this file inventory |

`AGENTS.md`, editor/Codex configuration, and existing uploaded files were unchanged.
