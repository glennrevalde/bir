-- ============================================================================
-- G-SMaRT BIR Integration System: uploading new clients from a CSV file
-- Run once on an existing installation (MySQL 5.5 or newer), after the
-- 2026-10-25 files, for example in phpMyAdmin's SQL tab with the gsmart_bir
-- database selected, or:
--   mysql -u root -p gsmart_bir < database/migrations/2026-11-06_client_upload.sql
-- The application also applies it by itself when it can. New installations from
-- gsmart_bir_clean.sql or gsmart_bir_demo.sql already have it.
-- ============================================================================

-- One uploaded CSV file of new clients, held for review until it is saved.
CREATE TABLE IF NOT EXISTS client_import_batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  original_filename VARCHAR(200) NOT NULL,
  stored_path VARCHAR(255) NULL,
  file_sha256 CHAR(64) NULL,
  ignored_columns VARCHAR(500) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'review',
  total_rows INT NOT NULL DEFAULT 0,
  saved_rows INT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  saved_by INT UNSIGNED NULL,
  saved_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_cib_org (organization_id, status),
  CONSTRAINT fk_cib_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Its rows, each with the problems found, until the clients are saved.
CREATE TABLE IF NOT EXISTS client_import_rows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id INT UNSIGNED NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  data_json TEXT NOT NULL,
  errors_json TEXT NULL,
  warnings_json TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'error',
  edited TINYINT(1) NOT NULL DEFAULT 0,
  client_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_cir_batch (batch_id, row_no),
  CONSTRAINT fk_cir_batch FOREIGN KEY (batch_id) REFERENCES client_import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Record the schema version so the application does not repeat this step.
INSERT INTO system_config (k, v) VALUES ('schema_version', '8') ON DUPLICATE KEY UPDATE v = '8';
