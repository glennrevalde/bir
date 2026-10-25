# G-SMaRT BIR Integration System

A web platform for preparing Philippine BIR electronic attachments: it turns Excel workbooks and records kept in the database into DAT files for the BIR validation modules, then tracks validator evidence, approvals, filing and certificates. It is built for accounting firms that handle several client companies, and for companies that file for themselves.

Plain PHP, MySQL, HTML, CSS and JavaScript. No framework, no Composer, no build step, no external CDN.

## What it covers

| Area | Forms and outputs |
|---|---|
| VAT relief | Summary lists of sales (SLS), purchases (SLP) and importations (SLI) |
| Withholding | 1601-EQ QAP, 1601-FQ QAP, 1600-VT and 1600-PT MAP, SAWT (generic and 1702-Q shape) |
| Annual alphalists | 1604-C Schedules 1 and 2, 1604-E Schedules 3 and 4 (with QAP consolidation), 1604-F Schedules 4 to 6 |
| Other | Semestral List of Regular Suppliers (SRS) consolidation, reconciliation views |
| Certificates | BIR Form 2307, 2306 and 2316 as PDF, single or batch, with void and reissue |
| Operations | Excel and CSV import with column mapping, direct entry, spreadsheet-style grid entry, period locks, maker-checker approvals, hash-chained audit trail, encrypted backups, token API |

Every DAT is written by one serializer from a versioned layout registry, whether the rows came from Excel or from the database. Layouts that could not be confirmed from a BIR source are shipped as drafts and labelled as such. See [docs/COMPLIANCE.md](docs/COMPLIANCE.md) before using any output for a real filing.

## Requirements

- PHP 7.2 to 8.3 with these extensions: pdo_mysql, zip, openssl, mbstring, iconv, xmlreader, simplexml, zlib, json
- MySQL 5.5 or newer (MariaDB 10.x also works). Set `max_allowed_packet = 16M` under `[mysqld]` in `my.ini`: MySQL 5.5 defaults to 1M, which large alphalists and backups can exceed
- Apache with `.htaccess` enabled (XAMPP is fine), or Nginx with the rules in [docs/SECURITY.md](docs/SECURITY.md)

## Install on XAMPP (Windows)

1. Copy the `gsmart-bir` folder to `C:\xampp\htdocs\gsmart-bir`.
2. In phpMyAdmin, create a database named `gsmart_bir` with collation `utf8_general_ci`.
3. Import one SQL file from the `database` folder into it:
   - `gsmart_bir_demo.sql` for a working demo firm with two clients and nine months of records, or
   - `gsmart_bir_clean.sql` for an empty installation (reference data and one superadmin only).
4. Copy `config.sample.php` to `config.php` and set:
   - the database name, user and password;
   - `app_key`: 64 hexadecimal characters. Generate one with `php -r "echo bin2hex(random_bytes(32));"` from `C:\xampp\php`. Keep it safe: MFA enrolments and encrypted backups depend on it.
   - `storage_path`: ideally a folder outside `htdocs`, such as `C:/gsmart-bir-storage`.
5. Open `http://localhost/gsmart-bir/check.php` on the server. Fix anything marked Missing (on XAMPP, enable `extension=zip` in `php.ini` if needed and restart Apache).
6. Open `http://localhost/gsmart-bir/` and sign in. Delete `check.php` when everything works.

From the command line you can install instead of importing SQL:

```
php tools/install.php            # clean
php tools/install.php --demo     # with demo data
php tools/install.php --force --demo --admin=you@firm.ph --password=YourPassw0rd!
```

## Sign-in accounts

| Account | Password | Notes |
|---|---|---|
| admin@gsmart.local | ChangeMe@2026 | Superadmin. A new password is required at first sign-in. In both SQL files. |
| owner@demo.local | Demo@123456 | Firm owner, Bayanihan Accounting Partners (demo only) |
| preparer@demo.local | Demo@123456 | Preparer |
| reviewer@demo.local | Demo@123456 | Reviewer and approver |
| encoder@demo.local | Demo@123456 | Encoder |
| auditor@demo.local | Demo@123456 | Read-only auditor |
| clientadmin@demo.local | Demo@123456 | Client administrator limited to Banwa Agri-Ventures |
| signatory@demo.local | Demo@123456 | Signatory limited to Kalamansi Trading |
| company@demo.local | Demo@123456 | Owner of a second tenant, Puerto Digital Solutions Inc (a company filing for itself) |
| payroll@demo.local | Demo@123456 | Preparer at Puerto Digital Solutions |

reviewer@demo.local belongs to both tenants, so it shows the organization switcher. Sign in as owner@demo.local to see the demo firm; the superadmin account manages the platform and has no clients of its own.

What the demo contains (all names and TINs invented, except the public validator sample noted below):

| Client | What is in it |
|---|---|
| Kalamansi Trading Corp, 111-222-333 (Cebu, two branches, top withholding agent) | Sales, purchases and withholding for January to September 2026, one importation, 8 employees with TY2025 compensation, QAP files for each month, SLS/SLP/SLI files, a 1604-C, 2307 and 2316 certificates, a corrected sale with its revision, a voided duplicate purchase, and January to March locked by the reviewer |
| Banwa Agri-Ventures Inc, 333-444-555 (Iloilo and Bacolod) | January to June sales loaded through a committed Excel import with a saved column mapping, July to September encoded directly, purchases and withholding all year, QAP and SLS/SLP files, 2307s |
| Juan Dela Cruz, 222-333-444 | Taxes withheld by his clients and a SAWT |
| Tala Bakeshop (Clarissa Mendoza), 444-555-666 | Non-VAT; taxes withheld by three customers and SAWT files for Q1 to Q3 |
| Validator Sample Employer, 684-785-510 | The public 1604-C sample from the test fixtures. Its file reproduces the validator-passed bytes, the genuine validator report is recorded and accepted, and approval of the file and of the 1604-C layout wait in the approvals queue for reviewer@demo.local |
| Puerto Digital Solutions Inc, 555-666-777 (second tenant, Makati) | 10 employees with TY2025 compensation and 2316s, a 1604-C, 2025 and 2026 withholding with a 1604-E Schedule 4, July to September QAP and SLS/SLP files |

Every other demo file stays at "pending BIR validation" because no real validator report exists for it. Filing records are left empty for the same reason. The RDO list comes pre-loaded in both SQL files.

A demo API token is included for Kalamansi Trading (scopes `records:read`, `files:read`):
`gsb_0123456789abcdef0123456789abcdef0123456789abcdef`. Revoke it under Admin, API tokens before going live.

Change or remove the demo accounts before putting real taxpayer data on the server.

## How a filing moves through the system

1. **Records.** Encode, grid-enter, import from Excel or CSV, or post through the API. TINs are kept as text so leading zeros survive; Excel cells in scientific notation are rejected.
2. **Generate.** Choose the form, schedule and period. The system runs Level 1 data checks, writes the DAT from the layout registry, and immediately parses it back with an independent reader for Level 2 structure checks (round trip, control totals, filename, line endings).
3. **Validate with BIR.** Run the official BIR validation module on your own machine. Upload its report (the .TXT, or a screenshot) and optionally the DAT you validated so the bytes can be matched by SHA-256. This is Level 3, and only a person can record it.
4. **Review and approve.** A different user reviews the evidence and approves release. Approval locks the months the file covers.
5. **File and certify.** Record the eFPS/eBIRForms/eSubmission reference and acknowledgment, then issue 2307, 2306 or 2316 certificates.

The three levels are always reported separately. The system never marks a file as passing BIR validation on its own.

## Folder layout

```
index.php            front controller (index.php?p=<page>)
api.php              token API, see docs/API.md
check.php            requirements check (delete after install)
config.sample.php    copy to config.php
app/
  bootstrap.php      configuration, error handling, sessions, security headers
  lib/               domain classes: DatWriter, DatReader, Specs, Records, Imports, Reports,
                     Checks, Evidence, Workflow, Certificates, Backup, Audit, Auth, Crypto, Xlsx, Pdf ...
  pages/             one PHP file per page
  views/             shared templates
  specs/             seeded layouts (layouts.php) and reference data (reference.php)
  help/              the built-in manual, one article per page
assets/              CSS, JavaScript, fonts (bundled, OFL) and the logo mark
database/            schema.sql, gsmart_bir_clean.sql, gsmart_bir_demo.sql
docs/                API, compliance matrix and sources, security and privacy notes
storage/             uploads, generated DAT and PDF files, evidence, backups, logs (not web-readable)
tests/               automated tests and the validator-passed 1604-C fixture
tools/               installer, demo loader, SQL file builder
```

## Tests and tools

```
php tests/run.php        # 87 tests in a separate <db>_test database; exit code 0 when all pass
php tools/build_sql.php  # rebuild database/gsmart_bir_clean.sql and gsmart_bir_demo.sql
```

The tests include a byte-for-byte check that the 1604-C writer reproduces a file the BIR validator accepted, a check that Excel import and direct entry give identical DAT bytes, tenant isolation, maker-checker rules, period locks, audit tamper detection and a backup round trip.

## User's Manual

The built-in User's Manual (Overview, User's Manual, or your name menu) has nine chapters and 53 sections: getting started, setting up, keeping records, importing, generating files, validation and approval, certificates, administration, and troubleshooting with a glossary. It has step-by-step instructions with screenshots, a search, previous and next links, and a "Go to this page" button.

- Every page's **Help** button opens that page's section in a side panel.
- **Print or save as PDF** opens the whole manual as one document with a cover and contents, ready for the browser's Print or Save as PDF.
- The text lives in `app/help/manual.php` and the screenshots in `assets/img/manual/`. The screenshots show demo data only.

## Known limitations

- Most layouts are drafts or pending verification. Only the 1604-C Schedule 1 layout reproduces a validator-passed sample. Approve each layout only after your own validator run accepts a file made with it. See [docs/COMPLIANCE.md](docs/COMPLIANCE.md).
- Legacy `.xls` workbooks are not read; save them as `.xlsx` first.
- Certificate PDFs are system-drawn replicas of the January 2018 (ENCS) forms. Check each form's current revision on the BIR website before issuing.
- SRS submission may no longer be required; confirm before relying on it.
- The system does not submit anything to BIR. Validation and submission happen in BIR's own tools.
- Outgoing mail (verification, password reset, approval notices) is kept in an outbox on the System page. Switch the mail mode there to send through PHP `mail()` once the server has a mail transport.
#   b i r  
 