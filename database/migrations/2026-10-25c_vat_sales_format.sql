-- ============================================================================
-- G-SMaRT BIR Integration System: sales in the VAT sales workbook format
-- Run once on an existing installation (MySQL 5.5 or newer), after the two
-- other 2026-10-25 files, for example in phpMyAdmin's SQL tab with the
-- gsmart_bir database selected, or:
--   mysql -u root -p gsmart_bir < database/migrations/2026-10-25c_vat_sales_format.sql
-- The application also applies it by itself when it can. New installations from
-- gsmart_bir_clean.sql or gsmart_bir_demo.sql already have it.
-- ============================================================================

-- Each sale keeps its own VAT rate (the VAT RATE column). GROSS TAXABLE is not
-- stored: it is always taxable sales + output VAT.
ALTER TABLE sales_entries
  ADD COLUMN vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000 AFTER taxable_amount;

-- Existing sales that carried no output VAT on taxable amounts were at 0%.
UPDATE sales_entries
   SET vat_rate = 0
 WHERE output_vat = 0
   AND taxable_amount <> 0;

-- Record the schema version so the application does not repeat this step.
INSERT INTO system_config (k, v) VALUES ('schema_version', '4') ON DUPLICATE KEY UPDATE v = '4';
