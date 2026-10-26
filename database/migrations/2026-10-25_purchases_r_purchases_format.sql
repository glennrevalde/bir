-- ============================================================================
-- G-SMaRT BIR Integration System: purchases in the R_Purchases workbook format
-- Run once on an existing installation (MySQL 5.5 or newer), for example in
-- phpMyAdmin's SQL tab with the gsmart_bir database selected, or:
--   mysql -u root -p gsmart_bir < database/migrations/2026-10-25_purchases_r_purchases_format.sql
-- New installations from gsmart_bir_clean.sql or gsmart_bir_demo.sql already have it.
-- ============================================================================

-- Purchases are kept per month; the invoice date becomes optional.
-- Each purchase keeps its own VAT rate (the vatRate column of R_Purchases).
ALTER TABLE purchase_entries
  MODIFY invoice_date DATE NULL,
  ADD COLUMN vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000 AFTER other_goods_amount;

-- Existing purchases that carried no input VAT on taxable amounts were at 0%.
UPDATE purchase_entries
   SET vat_rate = 0
 WHERE input_vat = 0
   AND (services_amount + capital_goods_amount + other_goods_amount) <> 0;
