-- ============================================================================
-- G-SMaRT BIR Integration System: taxes withheld on us in the SAWT layout
-- Run once on an existing installation (MySQL 5.5 or newer), after
-- 2026-10-25_purchases_r_purchases_format.sql, for example in phpMyAdmin's SQL
-- tab with the gsmart_bir database selected, or:
--   mysql -u root -p gsmart_bir < database/migrations/2026-10-25b_sawt_layout.sql
-- The application also applies it by itself when it can. New installations from
-- gsmart_bir_clean.sql or gsmart_bir_demo.sql already have it.
-- ============================================================================

-- A SAWT line belongs to a month and usually has no payment date.
-- The BIR's nature-of-payment descriptions run past 150 characters.
ALTER TABLE withholding_entries
  MODIFY payment_date DATE NULL,
  MODIFY nature_of_payment VARCHAR(255) NULL;

-- Record the schema version so the application does not repeat this step.
INSERT INTO system_config (k, v) VALUES ('schema_version', '3') ON DUPLICATE KEY UPDATE v = '3';
