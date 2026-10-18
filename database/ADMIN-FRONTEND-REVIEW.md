# Administrator frontend overhaul

Implemented and verified on 11 October 2026. This review covers the latest
frontend overhaul; the earlier integration work is documented separately in
[INTEGRATION-AUDIT.md](INTEGRATION-AUDIT.md).

## Problems identified

- Small body, table and supporting text made records and support conversations
  difficult to scan. Headings, controls and secondary information competed.
- Navigation lacked the requested Overview, Management, Support and Platform
  grouping. Repeated account information crowded the top bar.
- Dashboard sections had uneven proportions. Administrative activity occupied a
  narrow area rather than a readable section beneath registrations and tickets.
- Directories had crowded secondary information, inconsistent column proportions
  and search controls that consumed too much horizontal space.
- Settings needed section navigation and a clear separation between profile,
  platform contacts, permissions and backup metadata.
- Forms needed deliberate grouping, clearer confirmation and reply areas, and
  more readable detail and conversation layouts.
- Administrator rules were mixed into the owner stylesheet, making consistency
  and future maintenance harder.

Existing PHP templates, administrative handlers, shared components, rendered admin
and owner pages, SQL references and both original JPG logos were inspected before
the rewrite. The JPG artwork and its existing white margins were retained.

## Design system and shared components

`assets/css/admin.css` is loaded only for administrator pages. All its rules are
scoped to `.admin-layout`. It defines the forest green, neutral background, text
and border colors, alongside navigation, headers, panels, filters, tables,
badges, buttons, forms, details, settings and modal layouts.

- Primary green `#164B35`, accent `#22A06B`, background `#F8FAF9`, white panels,
  text `#1F2937`, secondary text `#6B7280` and border `#E5E7EB`.
- Inter with Segoe UI/system sans-serif fallbacks; no font dependency was added.
  Body/table text is 14px, headings 22–24px, section titles 15–16px, labels 13px,
  supporting text 12–13px and KPI values 26–28px.
- Spacing follows the requested scale, with 20px panel interiors where appropriate.
  Panels use 8px corners, controls 6px corners and thin borders without heavy shadows.
- Sidebar is 236px, or 76px when collapsed; top bar is 64px desktop and 60px phone.
  Group labels are uppercase, with aligned 18px icons and a green active indicator.
- Desktop buttons are approximately 36px high, inputs 40px and phone modal actions
  44px. Visible keyboard focus, existing loading/disabled states and validation
  messages are retained.
- Directories use focused columns, subtle text badges, View plus a three-dot menu
  when secondary actions exist, and labelled record cards below 768px.
- Modals share compact headers, grouped fields, readable details and consistent
  footers. Ticket dialogs are wider, with a distinct reply area; phone dialogs
  use Bootstrap fullscreen behavior.

No new library, fake notification control, decorative chart, recreated logo or
invented backend action was introduced. Table widths are CSS classes, compatible
with the unchanged security policy that disallows inline styles.

## Layout changes and page coverage

All eight existing administrator routes receive the new shared layout and styling.
Their thin route wrappers continue to use the reusable includes.

| Page | Implemented interface |
| --- | --- |
| `admin/dashboard.php` | Exact compact subtitle; four equal KPI cards; wider recent-registration table and narrower open-ticket list; full-width actual activity below |
| `admin/users.php` | Search/status toolbar, compact identity and business columns, details and suspension/reactivation modals |
| `admin/businesses.php` | Business/owner directory, adjacent category/status filters and structured profile details |
| `admin/tickets.php` | Reference, subject, submitter, date, status and actions; readable chronological conversation, separate reply/status area and same-modal View-to-Reply navigation |
| `admin/feedback.php` | User, short feedback preview, date, status and actions; full message and review form in a modal |
| `admin/announcements.php` | One “+ New Announcement” primary action, author as secondary text, clear Draft/Published badges and simple create/edit/confirmation forms |
| `admin/logs.php` | Read-only table with readable activity names, search and visible date/activity filters; structured log details |
| `admin/settings.php` | Secondary section navigation; separate profile, platform settings, roles/permissions and read-only backup information |

Desktop navigation is grouped and collapsible. Tablet sections stack where space
is limited, while the KPI grid becomes two columns. Phones use offcanvas navigation,
wrapped filters, labelled records and full-width modal forms. Original logos retain
their proportions. Businesses and logs keep View alone because they have no
existing secondary mutation; unavailable actions were not invented.

“Total Users” continues to count business-owner accounts, as the preserved backend
already did. Its supporting label makes that scope explicit.

## Changed files

| File | Change |
| --- | --- |
| `assets/css/admin.css` | New scoped administrator design system and responsive components |
| `assets/css/style.css` | Removed obsolete administrator rules; retained shared owner announcement/support and working owner styles |
| `assets/js/admin.js` | Ticket modal sizing and settings section selection; preserved existing submission and modal lifecycle logic |
| `includes/header.php` | Conditionally loads the admin stylesheet with a file-version cache key |
| `includes/admin_navbar.php` | Grouped sidebar, original logos, compact breadcrumb/account menu and profile/logout placement |
| `includes/admin_page.php` | Requested dashboard subtitle and announcement action label |
| `includes/admin_dashboard_content.php` | Rebuilt KPI, registration, ticket and activity layout |
| `includes/admin_directory.php` | Rebuilt directory toolbars, columns, responsive labels and record presentation |
| `includes/admin_settings.php` | Section navigation and reorganized settings presentation |
| `includes/admin_content.php` | Rebuilt details, forms, confirmations and ticket conversation presentation |
| `tests/browser_admin_test.ps1` | Extended responsive, typography/alignment, modal, navigation and profile-save verification; improved screenshot capture |
| `tests/admin_browser_fixture.php` | Exposes fixture administrator/business IDs to the browser tests; existing guarded cleanup retained |
| `README.md` | Documents the overhaul and links this review |
| `database/ADMIN-FRONTEND-REVIEW.md` | This implementation, file inventory, verification and limitations report |

Generated screenshots and test results are under the existing protected
`tests/http-results/` directory. They are local verification artifacts, not application
records or public routes. Screenshots taken during automated tests include clearly
named temporary test records that were subsequently removed.

## Preservation and completed verification

The authentication implementation, administrative mutation handlers, financial
calculations, queries and owner templates were preserved. SHA-256 comparison
confirmed 29 protected backend, authentication, configuration, owner and shared
script files unchanged. No SQL structure changes or new database dependencies
were introduced.

Tests were executed against the existing XAMPP application and database:

- PHP syntax checks passed all 87 PHP files; Node syntax checks passed all 11
  JavaScript files.
- The 13 existing PHP/HTTP suites passed: authentication, dashboard, sales,
  expenses, customers, utang, products, reports, admin, integration, HTTP auth,
  HTTP admin and HTTP integration. Tests use transaction rollback or guarded
  cleanup for their temporary data.
- Hidden Chrome passed all eight admin pages at 1440, 1024, 768, 390 and 320px:
  40 page layouts, with no horizontal page overflow, missing logos, overlapping
  action strips or unreadable body font sizes.
- Thirty admin detail/form layouts passed at 1440, 768 and 320px: ticket reply,
  ticket details, user details, business details, suspension, feedback review,
  announcement creation, profile, role and platform setting.
- Actual announcement creation/edit/publication/unpublication, ticket reply and
  history, feedback review, suspension/reactivation and profile saving passed.
  Profile saving ended the administrator session; a fresh login displayed the
  updated profile.
- Desktop collapse, mobile offcanvas, settings section navigation, View-to-Reply
  within one ticket modal, keyboard menu navigation/focus restoration, unsaved
  changes, duplicate submission protection, escaped output and retained filters
  passed. No JavaScript exceptions were recorded.
- Owner integration browser tests passed 15 page layouts and six form layouts.
  Published announcements could be read on the owner dashboard and disappeared
  after unpublication. Ticket replies, follow-ups/reopening, feedback review,
  validation retention and duplicate protection passed with zero JavaScript
  exceptions.
- Rendered desktop pages for all eight routes, tablet layouts, narrow phone
  records and representative detail/form dialogs were visually reviewed. A
  column-width issue caused by inline styles blocked by CSP was corrected using
  CSS classes and the affected layouts were retested.
- Read-only final schema inspection retained 28 tables, eight views and 27
  triggers, with zero administrator/integration/browser fixture accounts remaining.
  The temporary inspection script was removed.

Results: `admin-browser-checks.json`, `integration-browser-checks.json`,
`admin-overhaul-regression.txt` and page/form PNGs in `tests/http-results/`.
These reports contain no login passwords or database credentials.

## Remaining issues and limits

No unresolved application failure or page overflow was observed in the final
executed checks. Physical-device, screen-reader and non-Chrome browser testing
were not performed; Chrome viewport emulation, keyboard checks and visual review
were performed.

Email sending and backup execution remain unavailable. Support contact settings
are displayed without implying email delivery; backup information remains read-only.
The current database has no recorded backup jobs, so a nonempty backup-history
layout and actual backup execution were not tested. Bootstrap retains its existing
CDN requirement. These existing limitations do not require a database change to
use the redesigned administrator pages.
