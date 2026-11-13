-- ============================================================================
-- G-SMaRT BIR Integration System: expanded withholding codes for the SAWT templates
-- Run once on an existing installation (MySQL 5.5 or newer), after
-- 2026-11-06_client_upload.sql, for example in phpMyAdmin's SQL tab with the
-- gsmart_bir database selected, or:
--   mysql -u root -p gsmart_bir < database/migrations/2026-11-14_sawt_atc_codes.sql
-- The application also applies it by itself when it can. New installations from
-- gsmart_bir_clean.sql or gsmart_bir_demo.sql already have it.
-- ============================================================================

-- Every expanded withholding code the SAWT entry of the BIR Alphalist module v7.4
-- offers (its ATC table, type WE), added for review like the codes seeded at install.
-- A code the installation already has is left as it is.
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC020', 'EWT', 'corporate', 'Professional entertainers such as, but not limited to actors and actresses, singers, lyricists, composers, emcees - If gross income for the current year did not exceed P 720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC020' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC021', 'EWT', 'corporate', 'Professional entertainers such as, but not limited to actors and actresses, singers, lyricists, composers, emcees - If gross income exceeds P 720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC021' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC030', 'EWT', 'corporate', 'Professional athletes including basketball players, pelotaris and jockeys - If gross income for the current year did not exceed P 720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC030' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC031', 'EWT', 'corporate', 'Professional athletes including basketball players, pelotaris and jockeys - If gross income exceeds P 720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC031' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC040', 'EWT', 'corporate', 'All directors and producers involved in movies, stage, radio, television and musical productions - If gross income for the current year did not exceed P 720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC040' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC041', 'EWT', 'corporate', 'All directors and producers involved in movies, stage, radio, television and musical productions - If gross income exceeds P 720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC041' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC050', 'EWT', 'corporate', 'Management and technical consultants paid to juridical person - If the current years gross income is P 720,000 and below', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC050' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC051', 'EWT', 'corporate', 'Management and technical consultants paid to juridical person - If the currents years gross income exceeds P720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC051' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC060', 'EWT', 'corporate', 'Business and bookkeeping agents and agencies - If gross income for the current year did not exceed P 720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC060' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC061', 'EWT', 'corporate', 'Business and bookkeeping agents and agencies - If gross income exceeds P 720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC061' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC070', 'EWT', 'corporate', 'Insurance agents and insurance adjusters - If gross income for the current year did not exceed P 720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC070' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC071', 'EWT', 'corporate', 'Insurance agents and insurance adjusters - If gross income exceeds P 720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC071' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC103', 'EWT', 'corporate', 'Gross commission or service fees of custom, insurance, stock, real estate, immigration and commercial brokers & fees of agents of professional entertainers - if gross income did not exceed 720,000- Corporate', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC103' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC110', 'EWT', 'corporate', 'Cinematographic film rentals - Corporate', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC110' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC139', 'EWT', 'corporate', 'Gross commission or service fees of custom, insurance, stock, real estate, immigration and commercial brokers & fees of agents of professional entertainers - if gross income for the current year did not exceed P720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC139' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC140', 'EWT', 'corporate', 'Gross commission or service fees of custom, insurance, stock, real estate, immigration and commercial brokers & fees of agents of professional entertainers - if gross income exceeds 720,000- Corporate', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC140' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC150', 'EWT', 'corporate', 'Professional fees paid to medical practitioners (inc.doctors of medicine doctors of veterinary sciences & dentists) by hospitals & clinics or paid directly by Health Maintenance Org (HMOs) &/or similar est - If gross income exceeds P720K', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC150' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC151', 'EWT', 'corporate', 'Professional fees paid to medical practitioners (inc.doctors of medicine doctors of veterinary sciences & dentists) by hospitals & clinics or paid directly by Health Maintenance Org (HMOs) &/or similar est - If gross income for the current yr < P720K', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC151' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC156', 'EWT', 'corporate', 'Payments made by credit card companies - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC156' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC161', 'EWT', 'corporate', 'From bank deposits - Savings/Time - Corporate', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC161' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC162', 'EWT', 'corporate', 'From Treasury Bills and Other Government Securities - Corporate', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC162' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC163', 'EWT', 'corporate', 'From deposit substitutes, trusts funds and other similar arrangements - Corporate', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC163' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC170', 'EWT', 'corporate', 'On foreign currency deposits Resident - Corporate', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC170' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC390', 'EWT', 'corporate', 'On interest payments to taxpayers enjoying preferential tax rates (i.e. PEZA Registered Enterprises)', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC390' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC440', 'EWT', 'corporate', 'On interest income from long-term deposit in the form of savings trust funds, deposit substitutes which was pre-terminated by the holder before the 5th year - corporate', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC440' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC516', 'EWT', 'corporate', 'Commission, rebates, discounts & other similar considerations pd/granted to indepndnt & exclusive sales reps & mkting agents & sub-agents of multi-level mrktng co. inc. multilevel mrkting co.-if gross income exceeds P720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC516' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC535', 'EWT', 'corporate', 'Payments made by pre-need companies to funeral parlors - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC535' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC540', 'EWT', 'corporate', 'Tolling fee paid to refineries - Corporate', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC540' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC555', 'EWT', 'corporate', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of P500,000 or less - Corporation', 1.5000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC555' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC556', 'EWT', 'corporate', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of more than P500,000 but not more than P2M - Corporation', 3.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC556' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC557', 'EWT', 'corporate', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of more than P2M - Corporation', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC557' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC558', 'EWT', 'corporate', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is not habitually engaged in real estate business - Corporation', 6.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC558' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC610', 'EWT', 'corporate', 'Income payments made to suppliers of Agricultural products - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC610' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC630', 'EWT', 'corporate', 'Income payments on purchases of minerals, mineral products & quarry resources - Corporate', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC630' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC632', 'EWT', 'corporate', 'Income payments on purchases of gold by Bangko Sentral ng Pilipinas (BSP) from gold miners/suppliers under PD 1899, as amended by R.A. No. 7076- Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC632' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC650', 'EWT', 'corporate', 'On gross amount of refund given by Meralco to customers with active contracts as classified by Meralco - Corporate', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC650' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC651', 'EWT', 'corporate', 'On gross amount of refund given by Meralco to customers with terminated contracts as classified by Meralco - Corporate', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC651' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC660', 'EWT', 'corporate', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETHER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - RESIDENTIAL AND GENERAL SERVICE CUSTOMERS WHOSE MONTHLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS C', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC660' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC661', 'EWT', 'corporate', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETHER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - NON - RESIDENTIAL CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS CLASSIFIED BY ME', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC661' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC662', 'EWT', 'corporate', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETHEER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - RESIDENTIAL AND GENERAL SERVICE CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS C', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC662' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC663', 'EWT', 'corporate', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETHER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED CUSTOMER''S BILLING - NON - RESIDENTIAL CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS CLASSIFIED BY OTHER ELEC', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC663' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC680', 'EWT', 'corporate', 'INCOME PAYMENTS MADE BY POLITICAL PARTIES AND CANDIDATES OF LOCAL AND NATIONAL ELECTIONS OF ALL THEIR CAMPAIGN EXPENDITURES, AND INCOME PAYMENTS MADE BY INDIVIDUALS OR JURIDICAL PERSONS FOR THEIR PURCHASES OF GOODS AND SERVICES INTENDED TO BE GIVEN A', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC680' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC690', 'EWT', 'corporate', 'Income payments received by Real Estate Investment Trust (REIT)', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC690' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC710', 'EWT', 'corporate', 'INTEREST INCOME DERIVED FROM ANY OTHER DEBT INSTRUMENTS NOT WITHIN THE COVERAGE OF DEPOSIT SUBSTITUTES', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC710' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC720', 'EWT', 'corporate', 'Income payments on locally produced raw sugar - Corporation', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC720' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC770', 'EWT', 'corporate', 'Income payments made by joint ventures, whether incorporated or not, taxable or non-taxable, to their local/resident supplier of goods. - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC770' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC780', 'EWT', 'corporate', 'Income payments made by joint ventures, whether incorporated or not, taxable or non-taxable, to their local/resident supplier of services. - Corporate', 2.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC780' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC790', 'EWT', 'corporate', 'On the share of each co-venturer/member from the net income of the joint venture/consortium not taxable as corporation prior to actual or constructive distribution thereof.', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC790' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC820', 'EWT', 'corporate', 'On one-half (1/2) of the gross remittances by e-marketplace operators to the sellers/merchants for the goods or services sold/paid through their platform/facility - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC820' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WC830', 'EWT', 'corporate', 'On one-half (1/2) of the gross remittances by digital financial services providers to the sellers/merchants for the goods or services sold/paid through their platform/facility - Corporate', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WC830' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI020', 'EWT', 'individual', 'Professional entertainers, such as, but not limited to, actors and actresses, singers, lyricist, composers, emcees - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI020' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI021', 'EWT', 'individual', 'Professional entertainers such as, but not limited to actors & actresses, singers, lyricists, composers, emcees - If gross Income is more than P3M or VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI021' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI030', 'EWT', 'individual', 'Professional athletes, including basketball players, pelotaris and jockeys - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI030' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI031', 'EWT', 'individual', 'Professional athletes including basketball players, pelotaris and jockeys - If gross income is more than P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI031' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI040', 'EWT', 'individual', 'All directors and producers involved in movies, stage, radio, television and musical productions - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI040' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI041', 'EWT', 'individual', 'All directors and producers involved in movies, stage, radio, television and musical productions - If gross income is more than P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI041' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI050', 'EWT', 'individual', 'Management and technical consultants - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI050' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI051', 'EWT', 'individual', 'Management and technical consultants - If gross income is more than P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI051' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI060', 'EWT', 'individual', 'Business and bookkeeping agents and agencies - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI060' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI061', 'EWT', 'individual', 'Business and bookkeeping agents and agencies - If gross income is more that P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI061' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI070', 'EWT', 'individual', 'Insurance agents and insurance adjusters - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI070' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI071', 'EWT', 'individual', 'Insurance agents and insurance adjusters - If gross income is more than P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI071' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI080', 'EWT', 'individual', 'Other recipients of talent fees - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI080' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI081', 'EWT', 'individual', 'Other recipients of talent fees - If gross income is more than P3M or VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI081' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI090', 'EWT', 'individual', 'Fees of directors who are not employees of the company - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI090' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI091', 'EWT', 'individual', 'Fees of directors who are not employees of the company - If gross income is more than P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI091' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI110', 'EWT', 'individual', 'Cinematographic film rentals - Individual', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI110' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI130', 'EWT', 'individual', 'Income distribution to beneficiaries of estates and trusts', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI130' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI139', 'EWT', 'individual', 'Gross commission or service fees of custom, insurance, stock, real estate, immigration and commercial brokers & fees of agents of professional entertainers - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI139' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI140', 'EWT', 'individual', 'Gross commission or service fees of custom, insurance, stock, real estate, immigration and commercial brokers & fees of agents of professional entertainers - If gross income is more than P3M or VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI140' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI150', 'EWT', 'individual', 'Payments for medical/dental/veterinary services thru Hospitals/ Clinics/Health Maintenance Organizations, including direct payments to service providers - If gross income > P3M of VAT Registered regardless of amount', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI150' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI151', 'EWT', 'individual', 'Payments for medical/dental/veterinary services thru Hospitals/ Clinics/Health Maintenance Organizations, including direct payments to service providers - If gross income for the current year did not exceed P3M', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI151' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI152', 'EWT', 'individual', 'Payment by the general professional partnerships (GPP) to its partners - If gross income for the current year did not exceed P720,000', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI152' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI153', 'EWT', 'individual', 'Payment by the General Professional Partnerships (GPPs) to its partners - If gross income exceeds P720,000', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI153' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI156', 'EWT', 'individual', 'Payments made by credit card companies - Individual', 0.5000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI156' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI159', 'EWT', 'individual', 'Additional payments to government personnel from importers, shipping and airline companies or their agents for overtime services', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI159' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI161', 'EWT', 'individual', 'From bank deposits - Savings/Time - Individual', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI161' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI162', 'EWT', 'individual', 'From Treasury Bills and Other Government Securities - Individual', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI162' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI163', 'EWT', 'individual', 'From deposit substitutes, trusts funds and other similar arrangements - Individual', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI163' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI170', 'EWT', 'individual', 'On foreign currency deposits Resident - Individual', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI170' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI440', 'EWT', 'individual', 'On interest income from long-term deposit in the form of savings trust funds, deposit substitutes which was pre-terminated by the holder before the 5th year - individual (Less than 3 years)', 20.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI440' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI441', 'EWT', 'individual', 'On interest income from long-term deposit in the form of savings trust funds, deposit substitutes which was pre-terminated by the holder before the 5th year - individual (3 years to less than 4 years)', 12.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI441' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI442', 'EWT', 'individual', 'On interest income from long-term deposit in the form of savings trust funds, deposit substitutes which was pre-terminated by the holder before the 5th year - individual ( 4 years to less than 5 years)', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI442' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI516', 'EWT', 'individual', 'Commission, rebates, discounts & other similar considerations pd/granted to indepndnt & exclusive sales reps & mkting agents & sub-agents of multi-level mktng co. inc. multilevel mrkting co.-if gross income > P3M or VAT Registered regardless of amt', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI516' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI530', 'EWT', 'individual', 'Gross payments to embalmers by funeral parlors', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI530' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI535', 'EWT', 'individual', 'Payments made by pre-need companies to funeral parlors - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI535' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI540', 'EWT', 'individual', 'Tolling fee paid to refineries - Individual', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI540' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI555', 'EWT', 'individual', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of P500,000 or less - Individual', 1.5000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI555' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI556', 'EWT', 'individual', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of more than P500,000 but not more than P2M - Individual', 3.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI556' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI557', 'EWT', 'individual', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is habitually engaged in real estate business with a selling price of more than P2M - Individual', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI557' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI558', 'EWT', 'individual', 'Sale, exchange or transfer of real property classified as ordinary assets where the seller/transferor is not habitually engaged in real estate business - Individual', 6.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI558' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI610', 'EWT', 'individual', 'Income payments made to suppliers of Agricultural products - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI610' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI630', 'EWT', 'individual', 'Income payments on purchases of minerals, mineral products & quarry resources - Individual', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI630' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI632', 'EWT', 'individual', 'Income payments on purchases of gold by Bangko Sentral ng Pilipinas (BSP) from gold miners/suppliers under PD 1899, as amended by R.A. No. 7076- Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI632' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI650', 'EWT', 'individual', 'On gross amount of refund given by Meralco to customers with active contracts as classified by Meralco - Individual', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI650' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI651', 'EWT', 'individual', 'On gross amount of refund given by Meralco to customers with terminated contracts as classified by Meralco - Individual', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI651' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI660', 'EWT', 'individual', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETHER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - RESIDENTIAL AND GENERAL SERVICE CUSTOMERS WHOSE MONTHLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS C', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI660' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI661', 'EWT', 'individual', 'WITHHOLDING ON GROSS AMOUNT OF INTERST ONT HE REFIND OF METER DEPOSIT WHETER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - RESIDENTIAL AND GENERA; SERVICE CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS200 KWH AS CLASS', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI661' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI662', 'EWT', 'individual', 'WITHHOLDING ON GROSS AMOUNT OF INTERESTO N THE REFUND OF METER DEPOSIT WHETHER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - RESIDENTIAL AND GENERAL SERVICE CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS CL', 10.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI662' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI663', 'EWT', 'individual', 'WITHHOLDING ON GROSS AMOUNT OF INTEREST ON THE REFUND OF METER DEPOSIT WHETER PAID DIRECTLY TO THE CUSTOMERS OR APPLIED AGAINST CUSTOMER''S BILLING - NON - RESIDENTIAL CUSTOMERS WHOSE MONTLY ELECTRICITY CONSUMPTION EXCEEDS 200 KWH AS CLASSIFIED BY OTH', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI663' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI680', 'EWT', 'individual', 'INCOME PAYMENTS MADE BY POLITICAL PARTIES AND CANDIDATES OF LOCAL AND NATIONAL ELECTIONS OF ALL THEIR CAMPAIGN EXPENDITURES, AND INCOME PAYMENTS MADE BY INDIVIDUALS OR JURIDICAL PERSONS FOR THEIR PURCHASES OF GOODS AND SERVICES INTENDED TO BE GIVEN', 5.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI680' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI710', 'EWT', 'individual', 'INTEREST INCOME DERIVED FROM ANY OTHER DEBT INSTRUMENTS NOT WITHIN THE COVERAGE OF DEPOSIT SUBSTITUTES', 15.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI710' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI720', 'EWT', 'individual', 'Income payments on locally produced raw sugar - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI720' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI770', 'EWT', 'individual', 'Income payments made by joint ventures, whether incorporated or not, taxable or non-taxable, to their local/resident supplier of goods. - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI770' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI780', 'EWT', 'individual', 'Income payments made by joint ventures, whether incorporated or not, taxable or non-taxable, to their local/resident supplier of services. - Individual', 2.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI780' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI820', 'EWT', 'individual', 'On one-half (1/2) of the gross remittances by e-marketplace operators to the sellers/merchants for the goods or services sold/paid through their platform/facility - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI820' AND tax_kind = 'EWT');
INSERT INTO tax_codes (atc_code, tax_kind, payee_type, description, rate, effective_from, version_tag, source_ref, status, notes, created_at)
  SELECT 'WI830', 'EWT', 'individual', 'On one-half (1/2) of the gross remittances by digital financial services providers to the sellers/merchants for the goods or services sold/paid through their platform/facility - Individual', 1.0000, '2018-01-01', 'BIR-v7.4-ATC', 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)', 'pending_review',
    'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM tax_codes WHERE atc_code = 'WI830' AND tax_kind = 'EWT');

-- The module's description for the rentals codes, where the seeded placeholder is still in place.
UPDATE tax_codes SET description = 'Rentals- real/personal properties, poles, satellites & transmission facilities, billboards - Individual', source_ref = 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)'
  WHERE atc_code = 'WI100' AND tax_kind = 'EWT' AND description LIKE '%verify description against the current ATC table%';
UPDATE tax_codes SET description = 'Rentals- real/personal properties, poles, satellites & transmission facilities, billboards - Corporate', source_ref = 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)'
  WHERE atc_code = 'WC100' AND tax_kind = 'EWT' AND description LIKE '%verify description against the current ATC table%';

-- Record the schema version so the application does not repeat this step.
INSERT INTO system_config (k, v) VALUES ('schema_version', '9') ON DUPLICATE KEY UPDATE v = '9';
