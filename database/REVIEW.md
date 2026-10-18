# Existing database review — 9 October 2026

Inspected the imported `profitpocket_db` on the local server at port 3306.
It contains **28 InnoDB tables, eight views, and 27 triggers**. The actual server
is MariaDB 10.4.32 (XAMPP), rather than the MySQL 8 named in the project overview.
The project originally had only empty application files and no SQL reference.
`schema-reference.sql` was exported without data for review only; no database
definitions were created, dropped, or modified.

## Tables and relationships

### Administrator module review — 11 October 2026

The live administrator tables, enums, keys, relationships and relevant trigger guards
were rechecked before implementing the admin panel. It uses:

- users → roles → role_permissions → permissions for active admin authorization;
  users ← businesses for owner directories and derived business account status.
- auth_sessions → users for suspension, role-change and own-profile session revocation.
- support_tickets → users for submitter/assigned admin; ticket_messages → tickets/users
  and ticket_status_history → tickets/users preserve conversations and status changes.
- user_feedback → users for optional attribution and existing new/reviewed/archived states.
- announcements → users for administrator authors; draft/published/archived plus
  published_at support publication without an invented update timestamp.
- admin_action_logs → users for actor/optional target, safe JSON change metadata and
  durable request retries. Actual IP is retained in JSON because no IP column exists.
  Only allowlisted nonfinancial activity_logs metadata is exposed alongside this audit.
- platform_settings → users for existing permitted general settings and their updater;
  backup_jobs → users for read-only existing backup metadata.

There is no business status column, ticket category, announcement updated_at, backup
type or public-setting flag. The application follows owner status, shows ticket
priority, preserves announcement author/creation date and only edits already existing
allowlisted general settings. At review, support_email was the only such setting.
No setting, role, permission, schema or financial data migration was performed.

The existing support_admin can manage tickets/feedback and view aggregate platform
metrics; platform_admin has the additional imported permissions. Business/log screens
use users.manage. Role reassignment requires users.manage + settings.manage and cannot
promote business owners or remove the final active administrator with both permissions.
Prepared statements, transactional live permission checks, role locks, CSRF,
record-bound signatures, stale record checks, plain-text output escaping and safe
audit rendering are implemented in the existing folders. Audit UI has no mutation
endpoint. Financial tables and private event payloads are excluded from admin queries.

Service/database tests and Apache tests passed real administration operations and
rejection cases; service financial fixtures fully rolled back. Hidden Chrome passed
40 layouts and actual modal actions with zero JavaScript errors and cleaned its
disposable nonfinancial accounts. All completed owner service and browser regression
suites and the HTTP authentication checks passed. Final verification retained 28
tables, eight views and 27 triggers, with no administrator test accounts remaining.
The subsequent full-system integration audit connected owner dashboard announcements,
Help & Support tickets/conversations/feedback, general setting consumers and safe
registration/admin-login activity using these same tables. Published-only detail
queries enforce publication date/status; owner support queries enforce submitted_by.
No migration was needed. See INTEGRATION-AUDIT.md for the completed checklist,
changed files and executed validation. Mail delivery, expiry/read tracking and
actual backup execution remain unavailable.

All columns, types, nullability, defaults, indexes, primary keys, foreign keys,
CHECK constraints, and trigger definitions are in `schema-reference.sql`.
The following map explains how the modules connect. Except where listed otherwise,
the primary key is an auto-increment unsigned numeric ID.

| Table | Primary key | Relationships and purpose |
| --- | --- | --- |
| users | user_id | role_id → roles; unique username and email; password_hash and account_status control authentication |
| roles | role_id | unique role_code; role_type is owner or admin |
| permissions | permission_id | unique permission_code |
| role_permissions | role_id + permission_id | many-to-many roles ↔ permissions |
| auth_sessions | session_id | user_id → users; unique token_hash; expiry and revocation |
| password_reset_tokens | reset_id | user_id → users; unique token_hash; expiry and used_at |
| businesses | business_id | owner_user_id → users, unique (one business per owner); business_category_id → business_categories |
| business_categories | business_category_id | unique category_name; required when registering a business |
| customers | customer_id | business_id → businesses; reusable same-business customer pair |
| products | product_id | business_id → businesses; SKU unique within a business; default selling price and unit cost |
| sales | sale_id | business_id → businesses; customer_id + business_id → customers; creator/void actor constrained to the business owner; sale number unique within a business |
| sale_items | sale_item_id | sale_id + business_id → sales; product_id + business_id → products; quantities, price and cost snapshots |
| customer_credits | sale_id (not auto-increment) | one-to-one sale_id → sales; due date and credit notes; customer is inherited through the sale |
| sale_payments | payment_id | sale_id + business_id → sales; payment method; entered_by constrained to owner; refund refers to a collection in the same sale/business; optional idempotency key unique per business |
| payment_methods | payment_method_id | shared methods; unique method_code and is_active |
| expense_categories | expense_category_id | business_id → businesses; category name unique per business |
| recurring_expenses | recurring_expense_id | business, same-business expense category and payment method; frequency and next due date |
| expenses | expense_id | business, same-business category/recurrence and payment method; entered_by/voided_by constrained to owner; recurring occurrence unique |
| expense_receipts | receipt_id | expense_id + business_id → expenses; uploaded_by constrained to owner; storage metadata maps to uploads/receipts/ |
| activity_logs | activity_id | optional actor_user_id → users and business_id → businesses; financial triggers automatically record several events |
| admin_action_logs | admin_action_id | admin_user_id and optional target_user_id → users; admin trigger guard |
| announcements | announcement_id | author_admin_id → users; draft/published/archived |
| platform_settings | setting_key (string) | updated_by → users; administrator guard |
| backup_jobs | backup_job_id | optional initiated_by → users; administrator guard |
| support_tickets | ticket_id | submitted_by → users; optional assigned_admin_id → users; administrator assignment guard |
| ticket_messages | message_id | ticket_id → support_tickets; sender_user_id → users |
| ticket_status_history | history_id | ticket_id → support_tickets; changed_by → users |
| user_feedback | feedback_id | optional submitted_by → users |

## Financial calculations and views

- `v_sale_item_totals`: subtotal from rounded quantity × unit price; COGS from
  rounded quantity × unit cost.
- `v_payment_totals`: collections less refunds.
- `v_sale_financials`: items, discounts, payments, and remaining balance.
- `v_credit_balances`: credit sale details, amount paid, balance, and credit status.
- `v_business_credit_totals`: outstanding utang grouped by business.
- `v_daily_financial_events`: posted revenue/COGS, posted expenses, and collections/
  refunds for posted or void sales, using their respective activity dates.
- `v_daily_financials`: gross profit = net sales − COGS; net profit subtracts only
  operating expenses; cash flow = cash received − cash spent.
- `v_dashboard_overview`: business aggregates and outstanding utang.

Profit and cash flow are separate: credit sales can earn revenue before collection,
and inventory purchases are cash outflows rather than operating expenses. Future
reports must use the existing views and business filters consistently.

Triggers require priced items and a positive total before posting, full payment
for immediate sales, and credit details for credit sales. They constrain excess
collections/refunds, require refunding before voiding paid sales, preserve payment,
credit and expense histories, and prevent editing many finalized financial fields.
Implement financial writes in transactions with parent sale row locks.

## Dependencies and inconsistencies

- PHP 8.2.12, PDO MySQL, mbstring, BCMath, sessions, Apache, and the database are available.
  No Composer or npm packages are needed.
- Imported role/category records exist. Roles are `business_owner`, `support_admin`,
  and `platform_admin`. Registration must use `business_owner`, never a supplied
  role ID. Categories come from the actual database.
- Support admins have support management and platform metric permissions; platform
  admins additionally manage users, announcements, settings, and backups. There is
  no dedicated business/log permission, so current guards use `users.manage`.
- SQL views/triggers have a `root@localhost` definer. Moving servers requires
  reviewing definer availability and privileges; no changes were made here.
- `v_credit_balances` includes draft sales, and business credit totals therefore
  can include draft credit balances. Before implementing utang/report screens,
  decide whether to filter to posted sales in application queries. Changing the
  view itself requires approval.
- Schema guards are not a replacement for authorization in PHP. Some admin
  trigger checks are INSERT-only, and owners must be scoped in every module query.
- Mail delivery and a trusted application origin are missing for password recovery.
- Bootstrap uses an external CDN; offline styling would need locally stored assets.
- At the initial authentication stage, backup and feedback screens were absent.
  The administrator module now provides feedback management and backup history
  inside System Settings, using these existing tables.

## Development plan using the existing folders

1. **Connection/authentication (current step):** PDO in config/, session/CSRF/role
   helpers in includes/, forms in auth/, shared layouts in includes/, styling and
   password toggles in assets/. Owner registration creates the required business
   atomically. Existing module entry points receive access guards only.
2. **Business setup:** products and customers in user/, followed by expense
   categories/settings. Take business_id from the authenticated owner.
3. **Sales:** draft sale and item management, safe discount calculations, payment
   recording and posting in user/sales.php, matching the schema triggers.
4. **Expenses:** categories, expense posting/voiding and receipt validation in
   user/expenses.php with protected file serving and transaction handling.
5. **Utang:** customer-linked credit sales, due dates and partial collections in
   user/utang.php, using existing payment records and calculated balances.
6. **Dashboard/reports:** user/dashboard.php and user/reports.php using the existing
   views, distinct profit/cash flow, date ranges and owner filters.
7. **Administration:** implement the existing admin/ files one at a time with
   role_permissions and audit trails. Implement password recovery once mail and
   origin configuration are available.

Step 1, Customer Management in step 2, Sales in step 3, Expenses in step 4, and the Business Owner Dashboard portion of step 6 have now been implemented.
The dashboard uses the existing views, current credit filtered to posted sales,
owner-scoped recent activities, and reporting dates. It preserves the authentication
module, database structure, and original JPG logos. Sales uses existing products,
customers, item cost/name/price snapshots, initial collections, and one credit
record per credit sale. Posted financial fields remain immutable; notes-only
edits and unpaid draft edits are audited. Paid voids require explicit confirmation
of returned funds and record refunds linked to each unrefunded original collection.
Credits and payments retain their history. Sales summaries/outstanding queries
filter to posted sales, avoiding the draft-credit view inconsistency without
altering the views. Revenue continues to come from sales rather than collections.

The early Sales implementation used a minimal product reference form. Product,
Utang collection, Reports and administrator management have since been completed.
No schema changes were made. Completed features read business records. One
erroneous browser-test sale (#108) was corrected by an audited void with its
recorded collection reversed; its retained history is excluded from revenue and
debt. Financial
integration fixtures roll back, including trigger logs. See README.md for current
behavior, modified files and test scope.

The modal redesign reuses these same financial helpers and constraints. New,
details, edit and void actions return presentation fragments; mutation responses
can return JSON after the existing transaction completes. Original dedicated pages
remain available. Browser saves now use only disposable immediate drafts with no
payment or credit history; cleanup verifies that restriction before deleting only
the test business's rows.

Expense Management now uses the existing expense/category/receipt/recurrence
relationships. `trg_expense_update` prohibits changing financial columns, so edits
update only description, notes and receipt attachments; correcting money/date/type
requires voiding and recording another expense. `trg_expense_delete` forbids deleting
expense history. Voids require the owner, timestamp and reason; trigger and application
audit rows remain. No trigger, view, key, column or table definition was changed.

Receipt replacements keep earlier metadata/files; direct storage access is denied
and images are served through an owner-scoped endpoint. Validated images are limited
to 2 MB and 16 million pixels. Fileinfo/image inspection are available; GD is absent
but not required by this storage approach. Keep Apache directory protection enabled.

Recurring templates do not enter totals. Explicit paid confirmation inserts one
actual expense for `(recurring_expense_id, recurrence_due_on)` and advances its
cursor transactionally. The unique occurrence key prevents duplicate payments;
daily/weekly intervals and anchored month/year dates are supported. Completed
schedules cannot be resumed past their end date. A voided occurrence stays reserved.
No automatic scheduler was introduced.

Business row locks serialize expense writes. Signed submission keys are recorded
in existing `activity_logs.after_data` for replay without new columns; retain these
audit rows. Existing dashboard views already exclude voids and deduct only operating
expenses, keeping inventory cash purchases separate from COGS. Rollback database
tests, HTTP validation/security checks and guarded responsive browser checks cover
the module; README.md records the exact scope and limitations.

Customer Management uses the existing `customer_name`, `phone`, `email`, `address`,
`notes`, timestamps and `is_active` fields. Archive/restore changes only the active
flag; customers and linked financial records are never deleted by the module.
Customer purchase totals sum posted `v_sale_financials.net_sale`. Outstanding utang
sums that view's remaining balances only for posted credit sales joined to
`customer_credits`, with overdue dates checked in Manila time. This avoids the
known draft-credit inconsistency without changing a view. Aggregate payment/item
views prevent repeated collections from multiplying revenue. Archived customers'
debt stays in summary totals. Existing Sales selectors/details read current names
and active status, preserving composite customer/business relationships.

Duplicate normalized mobile numbers produce a shared-number confirmation; same
names are allowed. Transactions, business locks, CSRF, signed request keys,
contact/status revisions and existing activity logs protect writes. Creation/edit/
archive/restore add customer audit actions; those events must be retained for replay.
Customer changes preserve dashboard financial figures. The shared Sales/Expense/
Customer action controls are presentation-only changes. No schema or financial
helper changes were needed. README.md lists all files and executed test coverage.

Utang Management uses existing posted credit `sales` joined one-to-one to
`customer_credits.sale_id`. Customer and sale/payment composite business foreign
keys retain tenant isolation. `sale_payments` is the only collection/refund ledger;
`v_sale_financials` derives net paid and remaining balances from aggregate views,
so repeated payments cannot multiply sales revenue. Draft/void debt is excluded,
archived debt retained, and overdue requires an earlier Manila due date plus a
positive balance. A settled/no-due credit cannot be overdue.

The fourth summary card sums collections less refunds by payment date, including
initial credit payments and real refunds on later voided sales. It is cash received
in the chosen period, separate from current debt and sales/profit. Sale-date list
filters and payment-date collection filters have explicitly labeled scopes.

Business/parent locks and current locking ledger reads recheck balances before
collection. The existing unique business/idempotency key with an `utang:` prefix
prevents repeat submissions; the existing insert trigger audits payments. Immutable
payment update/delete triggers remain unchanged. Due-date edits are permitted by
the existing credit update trigger and audited in `activity_logs` under entity
`credit`, including before/after dates and durable request keys. No new schema or
second ledger was introduced. Email delivery remains unavailable and independent
of financial operations. README.md records file inventory, executed rollback,
concurrency/HTTP/browser/regression checks and their limitations.

Product Management uses the existing `products` primary key `product_id` and
`business_id` foreign key. Its fields are `product_name`, nullable `sku` and
`description`, `default_selling_price`, `default_unit_cost`, `is_active`,
`created_at` and `updated_at`. Prices use DECIMAL(13,2). The existing unique
`(business_id, sku)` key also covers archived products; multiple NULL codes are
permitted. There is no category relationship or stock field to expose.

`sale_items` references products through `(product_id, business_id)` and preserves
`product_name_snapshot`, `unit_price` and `unit_cost`. Catalog edits update only
the product row. Existing Sales code retains item snapshots and reads current
catalog defaults for new selections. Archive/restore toggles `is_active` and
preserves product references and sale history. New items require active products;
already-linked draft items may retain an archived reference.

Writes lock the owned active business and relevant product within a transaction.
Revisions reject stale changes; scoped SKU validation complements the existing
unique key. Existing `activity_logs` records PRODUCT_CREATED, PRODUCT_UPDATED,
PRODUCT_ARCHIVED and PRODUCT_RESTORED with owner/business identity, before/after
values, request key and payload hash. Retain these audit rows for durable replay.
No category table, inventory ledger, column, index, view or trigger was introduced.
README.md records the files, rollback snapshot/calculation checks, actual isolated
HTTP/browser CRUD, responsive checks and completed-module regression results.

Financial Reports is read-only and introduces no structure or summary ledger.
dashboard_totals() reads v_daily_financials for the same net sales, operating
expenses, COGS and net profit used by Dashboard. Charts group daily values into
day/week/month buckets. Zero-cost warnings read historical sale_items.

v_sale_financials aggregates item and payment totals before exposing one row per
sale. Reports joins only the same-business customer and one-to-one credit row,
avoiding multiplied revenue from multiple items/payments/debts. Posted sales use
sold_at; posted operating expenses use expense_date and join their same-business
category and payment method. Inventory purchases do not become a second product
cost. Discounts are subtotal minus net sale, deducted once.

Current credit balances use posted credit sales with customer_credits, including
archived customers. Overdue requires a positive balance and a due date before the
Manila date. Distinct customers with positive balances are counted once. Credit
collections use sale_payments.paid_at less refunds, including real cash refunds
for later voided sales. Overall net cash received uses the existing daily view.
Neither collection measure is treated as revenue.

Product insights stream posted sale items with historical names/prices/costs.
Rounded item values receive proportionally allocated transaction net sales using
BCMath division and largest-remainder centavo allocation. Product totals reconcile
to sales/COGS/gross profit before choosing the top five. Catalog edits/archive
cannot rewrite snapshots; the most recent sold name labels each product ID.

Owner authentication determines report/export business scope. Prepared queries,
validated dates/filter whitelists, escaping and CSV formula protection apply.
Print/CSV include all matching rows, independent of pagination. Overview reads
share one transaction snapshot. README.md records rollback, HTTP, responsive/
print/browser and completed-module checks. Current statuses/balances are labeled;
no historical closing statement is inferred from the current views.
