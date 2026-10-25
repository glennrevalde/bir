<?php
// key => array(title, group, html)
return array(
    'dashboard' => array('Dashboard', 'Getting around', '
<p>The dashboard shows the active client. Switch clients with the selector in the top bar; the boxes next to it show the client TIN and head-office branch code.</p>
<ul>
<li><strong>Filing calendar</strong> shows each month of the year and the newest file for each family (RELIEF, QAP, MAP, SAWT). Hover a mark to read its status.</li>
<li><strong>Work queue</strong> lists approvals waiting for you, files pending official BIR validation, and imports with errors.</li>
<li><strong>Quarter totals</strong> summarise saved records so you can spot missing months before generating files.</li>
</ul>
<p>Deadlines appear only when an administrator has added deadline rules with their BIR source. The system does not guess due dates.</p>'),

    'search' => array('Search', 'Getting around', '<p>Search by TIN (with or without dashes), name, invoice number or DAT filename. Results are limited to the active client.</p>'),
    'notifications' => array('Notifications', 'Getting around', '<p>Approval requests, validator evidence to review and decisions on your requests appear here. Opening one marks it read.</p>'),

    'sales' => array('Sales', 'Records', '
<p>Record invoices and receipts issued. Each entry belongs to a branch and a customer from the directory.</p>
<ul><li>Taxable sales are net of VAT. Output VAT is checked against 12% of taxable sales during data quality checks; a difference is a warning, not a block.</li>
<li>The Summary List of Sales consolidates all branches of the TIN for the month and groups rows per customer TIN.</li>
<li>Edits keep a revision; voiding keeps the record with a reason. Locked periods cannot be changed.</li></ul>'),
    'purchases' => array('Purchases', 'Records', '<p>Supplier invoices for the Summary List of Purchases. Split the amount into services, capital goods and other goods. Non-creditable input VAT reduces creditable input tax in the RELIEF header.</p>'),
    'importations' => array('Importations', 'Records', '<p>One row per import entry. The Summary List of Importations writes each entry separately (no grouping).</p>'),
    'withholding' => array('Taxes we withheld', 'Records', '
<p>Income payments where this client is the withholding agent. These records feed QAP (1601-EQ, 1601-FQ), MAP (1600-VT, 1600-PT), the 1604-E and 1604-F annual schedules, and certificates 2307 and 2306.</p>
<ul>
<li>Pick the kind of withholding first; the ATC list narrows to codes registered for that kind on the payment date.</li>
<li>The rate fills from the ATC registry and tax withheld is computed exactly (no rounding drift). Keep a different rate or amount only with a written reason.</li>
<li>Individual ATCs (WI...) need an individual payee and corporate ATCs (WC...) a non-individual payee.</li>
<li><strong>Schedule placement</strong> decides which QAP and annual schedule the payment lands in. Most payments are placement A.</li>
</ul>'),
    'withheld_on_us' => array('Taxes withheld on us', 'Records', '<p>Enter Form 2307 certificates received from customers. These rows build the SAWT attached to the selected return. Keep the certificate reference so the reconciliation report can match them.</p>'),
    'compensation' => array('Compensation', 'Records', '
<p>One record per employee per year and schedule. Schedule 1 covers employees other than minimum wage earners; Schedule 2 covers MWEs.</p>
<p>Use <em>Compute totals</em> to fill totals from their parts. Data quality checks block the 1604-C file when totals do not add up to the centavo, because the validator rejects inconsistent rows.</p>'),
    'grid' => array('Grid entry', 'Records', '<p>Spreadsheet-style entry for withholding, sales and purchases. Type a TIN or name to pick a directory entry. Enter moves down, Tab moves right, Ctrl+Enter saves all rows. Rows with errors stay on screen with the message beside them; saved rows turn green.</p>'),
    'directory' => array('Customers, suppliers, payees', 'Records', '<p>The directory is shared by all record types. TIN plus branch code must be unique per client. Names are stored as typed; the DAT writer converts them to upper case and reports every other change (for example N-tilde to N) in the data quality report.</p>'),
    'employees' => array('Employees', 'Records', '<p>Employees used by compensation records and Form 2316. Keep the TIN as 9 digits; the TIN branch is usually 0000.</p>'),
    'periods' => array('Period locks', 'Records', '<p>A locked month cannot be edited, imported into or voided. Approving a file locks the months it covers automatically. Unlocking requires a request approved by a second person.</p>'),

    'imports' => array('Import a spreadsheet', 'Imports', '
<ol><li>Choose the import type and branch, then upload .xlsx or .csv. Legacy .xls must be saved as .xlsx first.</li>
<li>If the headers match a G-SMaRT template the columns map automatically; otherwise map each field to a column and optionally save the mapping.</li>
<li>Rows are staged and checked in batches. Nothing touches your records yet.</li>
<li>Review errors row by row, download the corrections workbook, or commit valid rows. Identical rows already saved are skipped, so re-importing the same file is safe.</li>
<li>A committed import can be rolled back while its periods are unlocked.</li></ol>
<p>TINs stored as numbers in Excel are rejected when digits may have been lost (8 digits or scientific notation). Format TIN columns as Text.</p>'),
    'import_map' => array('Map columns', 'Imports', '<p>Each G-SMaRT field can take one source column. Required fields are marked. Choose the date order used in the file if dates are written as numbers with slashes.</p>'),
    'import_review' => array('Review an import', 'Imports', '<p>Valid rows are ready to commit; duplicate rows match something already saved; error rows need fixing. Commit valid rows only, or fix the file and upload again. Replacing a period voids earlier imported records for the same months first, and needs the replace permission.</p>'),
    'templates' => array('Template library', 'Imports', '<p>Each template has a locked header row, a Fields sheet describing every column and an About sheet with the template code and version. Download the blank template for real work and the sample to see the expected format.</p>'),

    'generate' => array('Generate a file', 'Filing', '
<ol><li>Choose the form, schedule, branch and period. SAWT also needs the return it is attached to.</li>
<li><strong>Preview</strong> builds the rows from saved records and runs data quality checks. Nothing is stored.</li>
<li><strong>Generate</strong> freezes a snapshot of the rows, writes the DAT with the registered layout, parses it back independently and compares totals and identifiers.</li></ol>
<p>A file is labelled <em>submission-ready</em> only when data quality and structure pass, the layout is approved and every ATC is approved. Otherwise it is an <em>unvalidated draft</em>. Neither label means the BIR accepted it: only the official validator report recorded in the Validation center does that.</p>
<p>QAP files are generated one per month of the quarter. RELIEF files cover all branches of the TIN.</p>'),
    'files' => array('File library', 'Filing', '<p>Every generated or uploaded DAT with its status, checksum and layout. Filter by form, period or status. Superseded files stay for history.</p>'),
    'file_view' => array('File details', 'Filing', '
<p>The three validation levels are shown separately:</p>
<ol><li><strong>Data quality</strong>: required fields, TINs, ATCs, arithmetic.</li>
<li><strong>Structure</strong>: the bytes parsed back under the layout, record counts, field widths, control totals and filename.</li>
<li><strong>Official BIR validation</strong>: the report from the BIR validation module you ran on this exact file.</li></ol>
<p>Download the file, run it through the official module on a Windows PC, then upload the .TXT report it produces. Uploading the DAT you validated proves byte identity through SHA-256. Re-processing or regenerating creates a new file and the old validation no longer applies.</p>'),
    'dat_upload' => array('Upload an existing DAT', 'Filing', '<p>Upload DAT files produced elsewhere (or by earlier systems). The layout is detected from the filename and first record, the file is parsed and checked, and it becomes available for SRS and annual consolidation. You can also import its detail rows into saved records.</p>'),
    'consolidate' => array('SRS and annual builder', 'Filing', '
<p>Builds the 1604-E Schedule 4 and the SRS from monthly QAP sources. For each month pick the database or a stored DAT file. The grid flags missing months, months with more than one file, branch mismatches and amended files.</p>
<p>SRS: a secondary source reports that RMC 122-2019 terminated SRS submission. Confirm the current rule before using the output.</p>'),
    'reconcile' => array('Reconciliation', 'Filing', '<p>Pre-filing checks: certificates against QAP totals, SAWT against certificates received, RELIEF against records, annual roll-forward against monthly files, and exception lists such as missing TINs, names that differ for one TIN, zero or negative amounts, and edits made after approval.</p>'),
    'filings' => array('Filing records', 'Filing', '<p>After a file is approved, record how and when it was submitted and attach the acknowledgment. The system does not transmit to the BIR; it keeps your evidence.</p>'),

    'validation' => array('Validation center', 'Validation', '<p>Files grouped by where they stand: structure problems, waiting for official validation, failed official validation, evidence to review and ready for approval. Open a file to act on it.</p>'),
    'approvals' => array('Approvals', 'Validation', '<p>Requests need a second person (maker-checker). Approving a file requires accepted official validator evidence and locks the covered months. Layout and tax code approvals need the reference approval permission.</p>'),

    'certificates' => array('Certificate register', 'Certificates', '<p>Every issued 2307, 2306 and 2316 with its number and revision. Download single PDFs, a combined PDF or a ZIP of individual files. Voiding needs approval; reissuing creates the next revision with the same number.</p>'),
    'cert_issue' => array('Issue certificates', 'Certificates', '<p>Choose the form, branch and period, select payees or employees, a signatory and paper size. A signature image prints only if the signatory has an approved image and you are allowed to sign. Use the offsets to nudge the image to fit your printer.</p>'),
    'cert_quick' => array('Quick 2307 entry', 'Certificates', '<p>Type one payee and the monthly amounts per ATC. The amounts are saved as withholding records first, so the certificate and the QAP always agree, then the certificate is issued.</p>'),

    'clients' => array('Clients and branches', 'Admin', '<p>Each client is a taxpayer with its own TIN, branches, records and users. The head-office branch is 0000. Set RDO codes on the client or the branch; data quality blocks files without a 3-digit RDO.</p>'),
    'client_form' => array('Client details', 'Admin', '<p>Non-individual clients use the registered name; individual clients use last, first and middle names. Fiscal year end is the month number (12 for calendar year). Signatories and their signature images are managed here; a signature image must be approved by another person before use.</p><p><strong>Archive client</strong> keeps every record, file, certificate and audit event readable but blocks new records, imports, files and certificates. Reactivate it from the same button.</p>'),
    'users' => array('Members and roles', 'Admin', '<p>Invite people, choose their role and limit them to specific clients or branches. Nobody can change their own role. Support access is time-limited.</p>'),
    'reference' => array('RDO codes and deadlines', 'Admin', '<p>Two small reference lists live here. <strong>RDO codes</strong> come pre-loaded from a published list so client and branch forms can suggest them. Check them against the BIR website, edit any that changed, or re-import a two-column CSV (code, name). Codes are three digits (081) or digits and a letter (21B); fields accept any valid code, listed or not.</p><p><strong>Filing deadline rules</strong> drive the dashboard calendar. Each rule names the form, the month offset after the period and the due day, and must cite its BIR source (for example the revenue regulation or memorandum circular). Nothing is pre-filled because deadlines change by issuance and by eFPS group; add only rules you can cite.</p>'),
    'atc' => array('Tax codes (ATC)', 'Admin', '<p>The ATC registry is versioned by effective date. New or changed codes start as pending review and must be approved by someone else with the approve permission. Seeded codes come from the 1601-EQ January 2019 ATC annex and must be checked against the ATC patch installed with your validator.</p>'),
    'specs' => array('File layouts', 'Admin', '
<p>Every byte of every DAT comes from these layouts. Each field shows its source and confidence:</p>
<ul><li><strong>Official annex</strong>: quoted from a BIR annex table.</li>
<li><strong>Validator-passed sample</strong>: matches a file the official validator accepted.</li>
<li><strong>Two or more sources agree</strong> / <strong>Single secondary source</strong>.</li>
<li><strong>Unsourced draft</strong>: placeholder so the module can be exercised; files are always drafts.</li></ul>
<p>To correct a layout, copy it to a new draft, edit fields, then request approval citing accepted validator evidence. Historical files keep the layout they were made with.</p>'),
    'spec_view' => array('Layout details', 'Admin', '<p>Edit fields only on draft layouts. Source expressions: <code>lit:X</code> literal, <code>agent.tin</code> filer values, <code>row.x</code> detail values, <code>sum.x</code> totals, <code>period.mm_yyyy</code> and <code>period.mm_dd_yyyy_end</code>, <code>seq</code>, <code>blank</code>.</p>'),
    'audit' => array('Audit trail', 'Admin', '<p>Every create, edit, void, import, generation, download, validation and approval is recorded with the person, time and IP. Events are hash-chained; <em>Verify chain</em> recomputes every hash and reports the first altered event.</p>'),
    'backups' => array('Backup and restore', 'Admin', '<p>Client backups are encrypted with a passphrase you choose and include records, files and evidence. The passphrase is never stored. <em>Test restore</em> decrypts and checks a backup without changing data. Restore replaces the client data with the backup contents; the audit trail is never rolled back.</p>'),
    'api_tokens' => array('API tokens', 'Admin', '<p>Tokens let accounting, POS or payroll systems push records. Each token is limited to scopes and optionally one client, is shown once, and can be revoked. See docs/API.md for endpoints.</p>'),
    'system' => array('Platform settings', 'Admin', '<p>Superadmin settings: registration, MFA enforcement for privileged roles, session timeout, mail mode and the mail outbox for environments without SMTP.</p>'),
    'settings' => array('Account and security', 'Account', '<p>Change your name and password, and turn on two-step sign-in with an authenticator app. Privileged roles should always use two-step sign-in.</p>'),

    'guide_validator' => array('Running the official BIR validator', 'Guides', '
<ol><li>Install the current Alphalist Data Entry and Validation Module from the BIR Downloadables page on a Windows PC, plus the latest ATC patch. Note the version and patch date.</li>
<li>From the file page, download the DAT. Do not open and save it in Excel or Notepad; that can change bytes.</li>
<li>Open the validation module, choose the matching form and validate the file.</li>
<li>Keep the .TXT report the module writes (and a screenshot if it shows a dialog).</li>
<li>Back on the file page, upload the report, the version and the date. Optionally upload the same DAT so the system confirms the SHA-256 matches.</li>
<li>A second person reviews the evidence. Then request approval.</li></ol>
<p>If the validator rejects the file, read the line numbers in the report, correct the data or the layout, generate a new file and validate again.</p>'),
    'guide_faq' => array('Frequently asked questions', 'Guides', '
<p><strong>Does the system file with the BIR?</strong> No. It prepares files and keeps evidence. Submission happens through the BIR channels; record the acknowledgment under Filing records.</p>
<p><strong>Why is my file an unvalidated draft?</strong> The layout is not yet approved, an ATC is pending review, or checks found errors. Open the file to see which.</p>
<p><strong>Will Excel imports and direct entry give the same file?</strong> Yes. Imports commit into the same tables, and files are always generated from saved records with one serializer. The test suite checks byte equality.</p>
<p><strong>Why are some layouts drafts?</strong> The official field tables for 1601-FQ, MAP details, 1604-C Schedule 2, 1604-E and 1604-F were not obtainable as text at build time. Correct them from the BIR annex in File layouts and validate.</p>'),
);
