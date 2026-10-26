-- ============================================================================
-- G-SMaRT BIR Integration System
-- Database schema (MySQL 5.5 compatible: InnoDB, utf8, no JSON type,
-- no DATETIME defaults, no generated columns, no window functions)
-- ============================================================================

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Tenancy and access
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS organizations;
CREATE TABLE organizations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  org_type VARCHAR(20) NOT NULL DEFAULT 'firm',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  retention_years TINYINT UNSIGNED NOT NULL DEFAULT 10,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at DATETIME NULL,
  mfa_secret VARCHAR(255) NULL,
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS user_tokens;
CREATE TABLE user_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(20) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_tokens_hash (token_hash),
  KEY ix_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS login_attempts;
CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_attempts_email (email, created_at),
  KEY ix_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  code VARCHAR(30) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  rank_order INT NOT NULL DEFAULT 0,
  is_privileged TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
  code VARCHAR(50) NOT NULL,
  name VARCHAR(120) NOT NULL,
  group_name VARCHAR(60) NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS role_permissions;
CREATE TABLE role_permissions (
  role_code VARCHAR(30) NOT NULL,
  permission_code VARCHAR(50) NOT NULL,
  PRIMARY KEY (role_code, permission_code),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_code) REFERENCES roles (code) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS memberships;
CREATE TABLE memberships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  role_code VARCHAR(30) NOT NULL,
  all_clients TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  invited_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_membership (user_id, organization_id),
  KEY ix_membership_org (organization_id),
  CONSTRAINT fk_mem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_mem_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_mem_role FOREIGN KEY (role_code) REFERENCES roles (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS client_entities;
CREATE TABLE client_entities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  tin CHAR(9) NOT NULL,
  taxpayer_type VARCHAR(20) NOT NULL DEFAULT 'non_individual',
  registered_name VARCHAR(150) NULL,
  last_name VARCHAR(60) NULL,
  first_name VARCHAR(60) NULL,
  middle_name VARCHAR(60) NULL,
  trade_name VARCHAR(150) NULL,
  classification VARCHAR(30) NOT NULL DEFAULT 'regular',
  vat_status VARCHAR(10) NOT NULL DEFAULT 'vat',
  rdo_code VARCHAR(5) NULL,
  address1 VARCHAR(150) NULL,
  address2 VARCHAR(150) NULL,
  zip_code VARCHAR(6) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  reporting_cycle VARCHAR(10) NOT NULL DEFAULT 'calendar',
  fiscal_month_end TINYINT UNSIGNED NOT NULL DEFAULT 12,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_tin (organization_id, tin),
  KEY ix_client_org (organization_id, status),
  CONSTRAINT fk_client_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS branches;
CREATE TABLE branches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_code VARCHAR(5) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_head_office TINYINT(1) NOT NULL DEFAULT 0,
  rdo_code VARCHAR(5) NULL,
  address1 VARCHAR(150) NULL,
  address2 VARCHAR(150) NULL,
  zip_code VARCHAR(6) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch (client_id, branch_code),
  KEY ix_branch_org (organization_id),
  CONSTRAINT fk_branch_client FOREIGN KEY (client_id) REFERENCES client_entities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS membership_scopes;
CREATE TABLE membership_scopes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_scope_membership (membership_id),
  CONSTRAINT fk_scope_mem FOREIGN KEY (membership_id) REFERENCES memberships (id) ON DELETE CASCADE,
  CONSTRAINT fk_scope_client FOREIGN KEY (client_id) REFERENCES client_entities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS support_grants;
CREATE TABLE support_grants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  granted_by INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_support_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS signatories;
CREATE TABLE signatories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  title VARCHAR(100) NULL,
  tin VARCHAR(20) NULL,
  user_id INT UNSIGNED NULL,
  signature_path VARCHAR(255) NULL,
  signature_approved_by INT UNSIGNED NULL,
  signature_approved_at DATETIME NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_sign_client (client_id),
  CONSTRAINT fk_sign_client FOREIGN KEY (client_id) REFERENCES client_entities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Master data
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS counterparties;
CREATE TABLE counterparties (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  tin CHAR(9) NULL,
  branch_code VARCHAR(5) NOT NULL DEFAULT '0000',
  entity_type VARCHAR(20) NOT NULL DEFAULT 'non_individual',
  registered_name VARCHAR(150) NULL,
  last_name VARCHAR(60) NULL,
  first_name VARCHAR(60) NULL,
  middle_name VARCHAR(60) NULL,
  address1 VARCHAR(150) NULL,
  address2 VARCHAR(150) NULL,
  zip_code VARCHAR(6) NULL,
  foreign_address VARCHAR(200) NULL,
  email VARCHAR(190) NULL,
  is_supplier TINYINT(1) NOT NULL DEFAULT 0,
  is_customer TINYINT(1) NOT NULL DEFAULT 0,
  is_payee TINYINT(1) NOT NULL DEFAULT 0,
  is_payor TINYINT(1) NOT NULL DEFAULT 0,
  default_atc VARCHAR(10) NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cp_tin (client_id, tin, branch_code),
  KEY ix_cp_name (client_id, registered_name),
  KEY ix_cp_last (client_id, last_name),
  CONSTRAINT fk_cp_client FOREIGN KEY (client_id) REFERENCES client_entities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS employees;
CREATE TABLE employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  employee_no VARCHAR(30) NULL,
  tin CHAR(9) NULL,
  tin_branch VARCHAR(5) NOT NULL DEFAULT '0000',
  last_name VARCHAR(60) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  middle_name VARCHAR(60) NULL,
  birth_date DATE NULL,
  address VARCHAR(200) NULL,
  zip_code VARCHAR(6) NULL,
  contact_no VARCHAR(40) NULL,
  region_code VARCHAR(10) NULL,
  nationality VARCHAR(40) NOT NULL DEFAULT 'FILIPINO',
  employment_status VARCHAR(5) NOT NULL DEFAULT 'R',
  is_mwe TINYINT(1) NOT NULL DEFAULT 0,
  hired_on DATE NULL,
  separated_on DATE NULL,
  separation_reason VARCHAR(5) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_emp_client (client_id, last_name),
  KEY ix_emp_tin (client_id, tin),
  CONSTRAINT fk_emp_client FOREIGN KEY (client_id) REFERENCES client_entities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Business records (all amounts DECIMAL, all with provenance)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS sales_entries;
CREATE TABLE sales_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  counterparty_id INT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NULL,
  invoice_date DATE NOT NULL,
  period_month CHAR(7) NOT NULL,
  exempt_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  zero_rated_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  taxable_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000,
  output_vat DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'posted',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  import_job_id INT UNSIGNED NULL,
  row_hash CHAR(40) NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  voided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_sales_period (client_id, period_month, status),
  KEY ix_sales_cp (client_id, counterparty_id),
  KEY ix_sales_hash (client_id, row_hash),
  KEY ix_sales_job (import_job_id),
  CONSTRAINT fk_sales_client FOREIGN KEY (client_id) REFERENCES client_entities (id),
  CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_sales_cp FOREIGN KEY (counterparty_id) REFERENCES counterparties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS purchase_entries;
CREATE TABLE purchase_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  counterparty_id INT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NULL,
  invoice_date DATE NULL,
  period_month CHAR(7) NOT NULL,
  exempt_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  zero_rated_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  services_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  capital_goods_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  other_goods_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000,
  input_vat DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  non_creditable_vat DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'posted',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  import_job_id INT UNSIGNED NULL,
  row_hash CHAR(40) NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  voided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_pur_period (client_id, period_month, status),
  KEY ix_pur_cp (client_id, counterparty_id),
  KEY ix_pur_hash (client_id, row_hash),
  KEY ix_pur_job (import_job_id),
  CONSTRAINT fk_pur_client FOREIGN KEY (client_id) REFERENCES client_entities (id),
  CONSTRAINT fk_pur_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_pur_cp FOREIGN KEY (counterparty_id) REFERENCES counterparties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS importation_entries;
CREATE TABLE importation_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  counterparty_id INT UNSIGNED NOT NULL,
  import_entry_no VARCHAR(40) NOT NULL,
  assessment_date DATE NULL,
  importation_date DATE NOT NULL,
  period_month CHAR(7) NOT NULL,
  country_origin VARCHAR(60) NULL,
  landed_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  other_charges DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  exempt_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  taxable_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  or_no VARCHAR(40) NULL,
  vat_payment_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'posted',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  import_job_id INT UNSIGNED NULL,
  row_hash CHAR(40) NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  voided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_imp_period (client_id, period_month, status),
  KEY ix_imp_hash (client_id, row_hash),
  KEY ix_imp_job (import_job_id),
  CONSTRAINT fk_imp_client FOREIGN KEY (client_id) REFERENCES client_entities (id),
  CONSTRAINT fk_imp_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_imp_cp FOREIGN KEY (counterparty_id) REFERENCES counterparties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- direction: 'agent' = client withheld from a payee (QAP, MAP, 1604-E/F, 2307/2306 issued)
--            'payee' = a payor withheld from the client (SAWT source, 2307 received)
-- tax_kind:  EWT (expanded/creditable), FWT (final), GVAT / GPT (government withholding of VAT / percentage tax)
DROP TABLE IF EXISTS withholding_entries;
CREATE TABLE withholding_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  counterparty_id INT UNSIGNED NOT NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'agent',
  tax_kind VARCHAR(5) NOT NULL DEFAULT 'EWT',
  atc_code VARCHAR(10) NOT NULL,
  nature_of_payment VARCHAR(255) NULL,
  payment_date DATE NULL,
  period_month CHAR(7) NOT NULL,
  income_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  tax_withheld DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  rate_exception_note VARCHAR(255) NULL,
  schedule_tag CHAR(1) NOT NULL DEFAULT 'A',
  reference_no VARCHAR(40) NULL,
  certificate_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'posted',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  import_job_id INT UNSIGNED NULL,
  row_hash CHAR(40) NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  voided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_wh_period (client_id, direction, tax_kind, period_month, status),
  KEY ix_wh_cp (client_id, counterparty_id),
  KEY ix_wh_atc (client_id, atc_code),
  KEY ix_wh_hash (client_id, row_hash),
  KEY ix_wh_job (import_job_id),
  CONSTRAINT fk_wh_client FOREIGN KEY (client_id) REFERENCES client_entities (id),
  CONSTRAINT fk_wh_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_wh_cp FOREIGN KEY (counterparty_id) REFERENCES counterparties (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Annual compensation per employee (1604-C / 2316). Column groups follow the
-- 1604-C Schedule 1 D1 record observed in a validator-passed v7.4 sample.
DROP TABLE IF EXISTS compensation_records;
CREATE TABLE compensation_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  tax_year SMALLINT UNSIGNED NOT NULL,
  schedule_code VARCHAR(5) NOT NULL DEFAULT 'S1',
  region_code VARCHAR(10) NULL,
  employment_from DATE NULL,
  employment_to DATE NULL,
  has_previous_employer TINYINT(1) NOT NULL DEFAULT 0,
  prev_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_smw DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_13th DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_deminimis DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_contrib DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_other DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_nt_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_tx_basic DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_tx_13th DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_tx_other DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_tx_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_smw DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_13th DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_deminimis DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_contrib DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_other DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_nt_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_tx_basic DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_tx_13th DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_tx_other DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_tx_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  taxable_comp_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  net_taxable_comp DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_due DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  prev_tax_withheld DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_withheld_jan_nov DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pres_withheld_dec DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  over_withheld_refunded DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_withheld_adjusted DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  pera_credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  smw_per_day DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  smw_per_month DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  smw_per_year DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  factor_days SMALLINT UNSIGNED NULL,
  mwe_holiday_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  mwe_overtime_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  mwe_night_diff DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  mwe_hazard_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  nationality VARCHAR(40) NOT NULL DEFAULT 'FILIPINO',
  employment_status VARCHAR(5) NOT NULL DEFAULT 'R',
  separation_reason VARCHAR(5) NOT NULL DEFAULT 'NA',
  substituted_filing CHAR(1) NOT NULL DEFAULT 'Y',
  status VARCHAR(20) NOT NULL DEFAULT 'posted',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  import_job_id INT UNSIGNED NULL,
  row_hash CHAR(40) NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  voided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_comp_year (client_id, tax_year, status),
  KEY ix_comp_emp (employee_id),
  KEY ix_comp_hash (client_id, row_hash),
  CONSTRAINT fk_comp_client FOREIGN KEY (client_id) REFERENCES client_entities (id),
  CONSTRAINT fk_comp_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_comp_emp FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS revisions;
CREATE TABLE revisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  subject_type VARCHAR(40) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  revision_no INT UNSIGNED NOT NULL,
  data_json LONGTEXT NOT NULL,
  changed_by INT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  changed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_rev_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS period_locks;
CREATE TABLE period_locks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  period_month CHAR(7) NOT NULL,
  scope VARCHAR(20) NOT NULL DEFAULT 'all',
  reason VARCHAR(255) NULL,
  locked_by INT UNSIGNED NOT NULL,
  locked_at DATETIME NOT NULL,
  unlocked_by INT UNSIGNED NULL,
  unlocked_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_lock (client_id, period_month, unlocked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Tax reference data (versioned, approval-controlled)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  atc_code VARCHAR(10) NOT NULL,
  tax_kind VARCHAR(5) NOT NULL,
  payee_type VARCHAR(20) NOT NULL DEFAULT 'any',
  description VARCHAR(255) NOT NULL,
  rate DECIMAL(7,4) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  version_tag VARCHAR(40) NULL,
  source_ref VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
  notes VARCHAR(255) NULL,
  submitted_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_atc_from (atc_code, effective_from),
  KEY ix_atc_kind (tax_kind, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS rdo_codes;
CREATE TABLE rdo_codes (
  code VARCHAR(5) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS form_types;
CREATE TABLE form_types (
  code VARCHAR(12) NOT NULL,
  family VARCHAR(12) NOT NULL,
  name VARCHAR(150) NOT NULL,
  frequency VARCHAR(12) NOT NULL,
  output_format VARCHAR(5) NOT NULL DEFAULT 'dat',
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS report_schedules;
CREATE TABLE report_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_code VARCHAR(12) NOT NULL,
  schedule_code VARCHAR(5) NOT NULL,
  name VARCHAR(200) NOT NULL,
  dataset_key VARCHAR(40) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sched (form_code, schedule_code),
  CONSTRAINT fk_sched_form FOREIGN KEY (form_code) REFERENCES form_types (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS deadline_rules;
CREATE TABLE deadline_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_code VARCHAR(12) NOT NULL,
  rule_text VARCHAR(200) NOT NULL,
  months_after_period TINYINT UNSIGNED NOT NULL DEFAULT 1,
  due_day TINYINT UNSIGNED NOT NULL DEFAULT 31,
  source_ref VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- File layout registry. Every byte the serializer writes comes from these rows.
DROP TABLE IF EXISTS layout_specs;
CREATE TABLE layout_specs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_code VARCHAR(12) NOT NULL,
  schedule_code VARCHAR(5) NULL,
  variant_key VARCHAR(12) NULL,
  version_label VARCHAR(40) NOT NULL,
  validator_version VARCHAR(20) NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  status VARCHAR(25) NOT NULL DEFAULT 'draft',
  confidence VARCHAR(20) NOT NULL DEFAULT 'low',
  filename_pattern VARCHAR(120) NOT NULL,
  delimiter VARCHAR(3) NOT NULL DEFAULT ',',
  line_ending VARCHAR(4) NOT NULL DEFAULT 'CRLF',
  trailing_newline TINYINT(1) NOT NULL DEFAULT 1,
  encoding VARCHAR(20) NOT NULL DEFAULT 'Windows-1252',
  column_header_row TINYINT(1) NOT NULL DEFAULT 0,
  text_policy VARCHAR(30) NOT NULL DEFAULT 'bir_upper',
  source_ref TEXT NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  approval_evidence_id INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_spec_form (form_code, schedule_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS spec_records;
CREATE TABLE spec_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  spec_id INT UNSIGNED NOT NULL,
  record_code VARCHAR(12) NOT NULL,
  role VARCHAR(10) NOT NULL,
  label VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_srec_spec (spec_id),
  CONSTRAINT fk_srec_spec FOREIGN KEY (spec_id) REFERENCES layout_specs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS spec_fields;
CREATE TABLE spec_fields (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  spec_record_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  field_key VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  source_expr VARCHAR(120) NOT NULL,
  data_type VARCHAR(12) NOT NULL DEFAULT 'text',
  max_len SMALLINT UNSIGNED NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  quote_mode VARCHAR(10) NOT NULL DEFAULT 'never',
  fmt VARCHAR(20) NULL,
  confidence VARCHAR(20) NOT NULL DEFAULT 'low',
  notes VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfield_pos (spec_record_id, position),
  CONSTRAINT fk_sfield_rec FOREIGN KEY (spec_record_id) REFERENCES spec_records (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Imports (staging)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS import_jobs;
CREATE TABLE import_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  import_type VARCHAR(30) NOT NULL,
  template_version VARCHAR(20) NULL,
  original_filename VARCHAR(200) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  sheet_name VARCHAR(100) NULL,
  header_row INT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
  mapping_json TEXT NULL,
  options_json TEXT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  processed_rows INT NOT NULL DEFAULT 0,
  valid_rows INT NOT NULL DEFAULT 0,
  error_rows INT NOT NULL DEFAULT 0,
  duplicate_rows INT NOT NULL DEFAULT 0,
  committed_rows INT NOT NULL DEFAULT 0,
  message VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  committed_by INT UNSIGNED NULL,
  committed_at DATETIME NULL,
  rolled_back_by INT UNSIGNED NULL,
  rolled_back_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_job_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS import_rows;
CREATE TABLE import_rows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id INT UNSIGNED NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  raw_json TEXT NOT NULL,
  normalized_json TEXT NULL,
  errors_json TEXT NULL,
  warnings_json TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  row_hash CHAR(40) NULL,
  target_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_irow_job (job_id, status),
  CONSTRAINT fk_irow_job FOREIGN KEY (job_id) REFERENCES import_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS column_mappings;
CREATE TABLE column_mappings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  import_type VARCHAR(30) NOT NULL,
  name VARCHAR(100) NOT NULL,
  mapping_json TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_map_org (organization_id, import_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS upload_sessions;
CREATE TABLE upload_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(32) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  original_filename VARCHAR(200) NOT NULL,
  total_bytes BIGINT NOT NULL DEFAULT 0,
  received_bytes BIGINT NOT NULL DEFAULT 0,
  temp_path VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_upload_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Reporting lifecycle
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS report_runs;
CREATE TABLE report_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  form_code VARCHAR(12) NOT NULL,
  schedule_code VARCHAR(5) NULL,
  variant_key VARCHAR(12) NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  period_label VARCHAR(30) NOT NULL,
  amended TINYINT(1) NOT NULL DEFAULT 0,
  source_mode VARCHAR(20) NOT NULL DEFAULT 'database',
  spec_id INT UNSIGNED NULL,
  status VARCHAR(25) NOT NULL DEFAULT 'prepared',
  row_count INT NOT NULL DEFAULT 0,
  total_base DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  total_tax DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  dq_errors INT NOT NULL DEFAULT 0,
  dq_warnings INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  prepared_by INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_run_client (client_id, form_code, period_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS report_run_items;
CREATE TABLE report_run_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id INT UNSIGNED NOT NULL,
  item_no INT UNSIGNED NOT NULL,
  payload_json TEXT NOT NULL,
  source_refs VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_item_run (run_id),
  CONSTRAINT fk_item_run FOREIGN KEY (run_id) REFERENCES report_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS data_snapshots;
CREATE TABLE data_snapshots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id INT UNSIGNED NOT NULL,
  snapshot_gz LONGTEXT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_snap_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS dat_files;
CREATE TABLE dat_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  run_id INT UNSIGNED NULL,
  form_code VARCHAR(12) NOT NULL,
  schedule_code VARCHAR(5) NULL,
  variant_key VARCHAR(12) NULL,
  period_label VARCHAR(30) NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  filename VARCHAR(120) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  spec_id INT UNSIGNED NULL,
  origin VARCHAR(12) NOT NULL DEFAULT 'generated',
  amended TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(25) NOT NULL DEFAULT 'prepared',
  file_label VARCHAR(25) NOT NULL DEFAULT 'unvalidated_draft',
  record_count INT NOT NULL DEFAULT 0,
  total_base DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  total_tax DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  supersedes_id INT UNSIGNED NULL,
  superseded_by INT UNSIGNED NULL,
  parse_summary TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_dat_client (client_id, form_code, period_from),
  KEY ix_dat_status (client_id, status),
  KEY ix_dat_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS validation_runs;
CREATE TABLE validation_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  dat_file_id INT UNSIGNED NULL,
  run_id INT UNSIGNED NULL,
  level TINYINT UNSIGNED NOT NULL,
  result VARCHAR(10) NOT NULL,
  error_count INT NOT NULL DEFAULT 0,
  warning_count INT NOT NULL DEFAULT 0,
  summary VARCHAR(255) NULL,
  executed_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_vr_file (dat_file_id, level),
  KEY ix_vr_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS validation_errors;
CREATE TABLE validation_errors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  validation_run_id INT UNSIGNED NOT NULL,
  severity VARCHAR(10) NOT NULL,
  code VARCHAR(40) NOT NULL,
  record_no INT NULL,
  field_key VARCHAR(40) NULL,
  message VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ve_run (validation_run_id),
  CONSTRAINT fk_ve_run FOREIGN KEY (validation_run_id) REFERENCES validation_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS official_validation_evidence;
CREATE TABLE official_validation_evidence (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  dat_file_id INT UNSIGNED NOT NULL,
  validation_run_id INT UNSIGNED NULL,
  validator_version VARCHAR(30) NOT NULL,
  patch_date DATE NULL,
  report_type VARCHAR(40) NULL,
  executed_at DATETIME NOT NULL,
  outcome VARCHAR(10) NOT NULL,
  dat_sha256_validated CHAR(64) NULL,
  checksum_match TINYINT(1) NOT NULL DEFAULT 0,
  evidence_filename VARCHAR(200) NULL,
  evidence_path VARCHAR(255) NULL,
  evidence_sha256 CHAR(64) NULL,
  parsed_tin VARCHAR(20) NULL,
  parsed_form VARCHAR(20) NULL,
  parsed_period VARCHAR(20) NULL,
  parsed_summary TEXT NULL,
  notes VARCHAR(255) NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_decision VARCHAR(10) NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_ove_file (dat_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------------
-- Governance
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS approval_requests;
CREATE TABLE approval_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NULL,
  subject_type VARCHAR(30) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  reason VARCHAR(255) NULL,
  payload_json TEXT NULL,
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL,
  decision VARCHAR(10) NOT NULL DEFAULT 'pending',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_appr_org (organization_id, decision),
  KEY ix_appr_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS filing_records;
CREATE TABLE filing_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  dat_file_id INT UNSIGNED NOT NULL,
  channel VARCHAR(30) NOT NULL,
  filed_at DATETIME NOT NULL,
  reference_no VARCHAR(80) NULL,
  ack_filename VARCHAR(200) NULL,
  ack_path VARCHAR(255) NULL,
  ack_sha256 CHAR(64) NULL,
  notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_filing_file (dat_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS certificates;
CREATE TABLE certificates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  form_code VARCHAR(5) NOT NULL,
  cert_no VARCHAR(30) NOT NULL,
  counterparty_id INT UNSIGNED NULL,
  employee_id INT UNSIGNED NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  total_income DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  total_tax DECIMAL(17,2) NOT NULL DEFAULT 0.00,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  reissue_of INT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'issued',
  signatory_id INT UNSIGNED NULL,
  use_signature TINYINT(1) NOT NULL DEFAULT 0,
  paper_size VARCHAR(10) NOT NULL DEFAULT 'A4',
  template_revision VARCHAR(40) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  pdf_path VARCHAR(255) NULL,
  sha256 CHAR(64) NULL,
  void_reason VARCHAR(255) NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  generated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cert_no (client_id, form_code, cert_no, revision),
  KEY ix_cert_period (client_id, form_code, period_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS attachments;
CREATE TABLE attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NULL,
  subject_type VARCHAR(30) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  filename VARCHAR(200) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_att_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Append-only, hash-chained event log
DROP TABLE IF EXISTS audit_events;
CREATE TABLE audit_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  subject_type VARCHAR(40) NULL,
  subject_id INT UNSIGNED NULL,
  summary VARCHAR(255) NOT NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  reason VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  prev_hash CHAR(64) NULL,
  event_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_org (organization_id, created_at),
  KEY ix_audit_client (client_id, created_at),
  KEY ix_audit_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(255) NULL,
  link VARCHAR(255) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_notif_user (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS backups;
CREATE TABLE backups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  scope VARCHAR(10) NOT NULL,
  filename VARCHAR(200) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  encrypted TINYINT(1) NOT NULL DEFAULT 1,
  record_count INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NULL,
  last_restored_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_backup_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS api_tokens;
CREATE TABLE api_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_hint VARCHAR(12) NOT NULL,
  scopes VARCHAR(255) NOT NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS mail_outbox;
CREATE TABLE mail_outbox (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  sent_ok TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS system_config;
CREATE TABLE system_config (
  k VARCHAR(60) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

SET FOREIGN_KEY_CHECKS = 1;
