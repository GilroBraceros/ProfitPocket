-- Approved by the project owner and applied on 18 October 2026.
-- Migration reference: apply once to installations missing provider_name.
-- Adds structured payment provider/bank metadata without changing existing rows,
-- relationships, financial views, audit triggers or payment amounts.
USE profitpocket_db;

ALTER TABLE sale_payments
    ADD COLUMN provider_name VARCHAR(80) NULL AFTER payment_method_id;

-- One shared method entry for named wallets other than GCash and Maya.
-- Existing methods and historical payments are preserved.
INSERT INTO payment_methods (method_code,method_name,is_active)
SELECT 'wallet_other','Other Digital Wallet',1
WHERE NOT EXISTS (
    SELECT 1 FROM payment_methods WHERE method_code='wallet_other'
);
