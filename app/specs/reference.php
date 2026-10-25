<?php
/**
 * Reference data seeded on install: roles, permissions, form catalogue, schedules,
 * tax codes (ATCs) and default system settings.
 *
 * Tax codes are seeded as "pending_review": a reviewer must approve each one against
 * the ATC table bundled with the installed Alphalist module (v7.4 ATC patch dated
 * 18 Nov 2025 at the time of writing) before it can be used in a submission-ready file.
 */

function gsb_reference()
{
    $perms = array(
        // code => array(name, group)
        'clients.view' => array('View clients and branches', 'Clients'),
        'clients.manage' => array('Create and edit clients, branches and signatories', 'Clients'),
        'records.view' => array('View transactions and directories', 'Records'),
        'records.edit' => array('Encode and edit transactions', 'Records'),
        'records.bulk' => array('Bulk edit and bulk void', 'Records'),
        'records.void' => array('Void and restore records', 'Records'),
        'imports.run' => array('Upload and stage imports', 'Imports'),
        'imports.commit' => array('Commit staged imports', 'Imports'),
        'imports.replace' => array('Replace existing records during import', 'Imports'),
        'imports.rollback' => array('Roll back committed imports', 'Imports'),
        'reports.generate' => array('Prepare report runs and generate files', 'Filing'),
        'dat.download' => array('Download submission-ready and validated files', 'Filing'),
        'dat.export_draft' => array('Download unvalidated draft files', 'Filing'),
        'dat.upload' => array('Upload existing DAT files', 'Filing'),
        'validation.record' => array('Record official validator results', 'Validation'),
        'validation.review' => array('Review official validator evidence', 'Validation'),
        'approvals.request' => array('Request approvals', 'Approvals'),
        'approvals.decide' => array('Approve or reject requests', 'Approvals'),
        'filings.record' => array('Record filing and acknowledgment evidence', 'Filing'),
        'periods.lock' => array('Lock reporting periods', 'Periods'),
        'periods.unlock' => array('Unlock reporting periods', 'Periods'),
        'certificates.issue' => array('Issue tax certificates', 'Certificates'),
        'certificates.void' => array('Void and reissue certificates', 'Certificates'),
        'certificates.sign' => array('Act as authorized signatory', 'Certificates'),
        'reference.view' => array('View reference data and layouts', 'Reference'),
        'reference.manage' => array('Propose changes to tax codes and layouts', 'Reference'),
        'reference.approve' => array('Approve tax code and layout changes', 'Reference'),
        'users.manage' => array('Manage members, roles and scopes', 'Administration'),
        'audit.view' => array('View the audit trail', 'Administration'),
        'backups.manage' => array('Create and download backups', 'Administration'),
        'backups.restore' => array('Restore backups', 'Administration'),
        'api.manage' => array('Manage API tokens', 'Administration'),
        'system.manage' => array('Manage platform settings', 'Platform'),
    );

    $all = array_keys($perms);
    $without = function ($list, $remove) { return array_values(array_diff($list, $remove)); };

    $roles = array(
        'firm_owner' => array('Firm owner', 'Full control of the organization and all clients', 10, 1, $without($all, array('system.manage'))),
        'firm_admin' => array('Firm administrator', 'Administers members, clients and settings', 20, 1, $without($all, array('system.manage'))),
        'client_admin' => array('Client administrator', 'Administers one or more client companies', 30, 1, array(
            'clients.view', 'clients.manage', 'records.view', 'records.edit', 'records.bulk', 'records.void', 'imports.run', 'imports.commit',
            'imports.rollback', 'reports.generate', 'dat.download', 'dat.export_draft', 'dat.upload', 'validation.record', 'approvals.request',
            'filings.record', 'periods.lock', 'certificates.issue', 'certificates.void', 'reference.view', 'audit.view', 'backups.manage')),
        'preparer' => array('Accountant / preparer', 'Prepares records, imports and filing files', 40, 0, array(
            'clients.view', 'records.view', 'records.edit', 'records.bulk', 'records.void', 'imports.run', 'imports.commit', 'imports.rollback',
            'reports.generate', 'dat.download', 'dat.export_draft', 'dat.upload', 'validation.record', 'approvals.request', 'certificates.issue', 'reference.view')),
        'encoder' => array('Encoder', 'Encodes transactions and stages imports', 50, 0, array(
            'clients.view', 'records.view', 'records.edit', 'imports.run', 'reference.view')),
        'reviewer' => array('Reviewer / approver', 'Reviews evidence and approves releases', 35, 1, array(
            'clients.view', 'records.view', 'reports.generate', 'dat.download', 'dat.export_draft', 'validation.record', 'validation.review',
            'approvals.decide', 'periods.lock', 'periods.unlock', 'filings.record', 'certificates.void', 'reference.view', 'reference.approve', 'audit.view')),
        'signatory' => array('Authorized signatory', 'Signs certificates for the client', 45, 0, array(
            'clients.view', 'records.view', 'certificates.issue', 'certificates.sign', 'dat.download', 'reference.view')),
        'auditor' => array('Client read-only / auditor', 'Reads records, files and the audit trail', 60, 0, array(
            'clients.view', 'records.view', 'dat.download', 'reference.view', 'audit.view')),
        'support' => array('Support (time-limited)', 'Supervised, time-limited read access', 70, 0, array(
            'clients.view', 'records.view', 'reference.view')),
    );

    $forms = array(
        // code, family, name, frequency, output, description, sort
        array('SLS', 'RELIEF', 'Summary List of Sales', 'monthly', 'dat', 'RELIEF / SLSP sales, consolidated per customer', 10),
        array('SLP', 'RELIEF', 'Summary List of Purchases', 'monthly', 'dat', 'RELIEF / SLSP purchases, consolidated per supplier', 11),
        array('SLI', 'RELIEF', 'Summary List of Importations', 'monthly', 'dat', 'RELIEF / SLSP importations, one row per import entry', 12),
        array('1601EQ', 'QAP', 'QAP for BIR Form 1601-EQ', 'monthly', 'dat', 'Quarterly alphalist of payees (creditable withholding); one file per month', 20),
        array('1601FQ', 'QAP', 'QAP for BIR Form 1601-FQ', 'monthly', 'dat', 'Quarterly alphalist of payees (final withholding); one file per month', 21),
        array('1600VT', 'MAP', 'MAP for BIR Form 1600-VT', 'monthly', 'dat', 'Monthly alphalist of payees, VAT withheld', 30),
        array('1600PT', 'MAP', 'MAP for BIR Form 1600-PT', 'monthly', 'dat', 'Monthly alphalist of payees, percentage tax withheld', 31),
        array('1604C', 'ANNUAL', 'Annual alphalist for BIR Form 1604-C', 'annual', 'dat', 'Compensation: Schedule 1 and Schedule 2 (MWE)', 40),
        array('1604E', 'ANNUAL', 'Annual alphalist for BIR Form 1604-E', 'annual', 'dat', 'Expanded withholding: Schedules 3 and 4', 41),
        array('1604F', 'ANNUAL', 'Annual alphalist for BIR Form 1604-F', 'annual', 'dat', 'Final withholding: Schedules 4, 5 and 6', 42),
        array('SAWT', 'SAWT', 'Summary Alphalist of Withholding Taxes', 'return', 'dat', 'Attachment to the selected income tax, VAT or percentage tax return', 50),
        array('SRS', 'SRS', 'Semestral List of Regular Suppliers', 'semestral', 'csv', 'Six-month consolidation of qualifying QAP purchases', 60),
    );

    $schedules = array(
        // form, schedule, name, dataset
        array('SLS', 'S1', 'Sales per customer', 'relief_sales', 1),
        array('SLP', 'S1', 'Purchases per supplier', 'relief_purchases', 1),
        array('SLI', 'S1', 'Importations per entry', 'relief_importations', 1),
        array('1601EQ', 'S1', 'Schedule 1: payees subject to expanded withholding', 'wh_month:EWT:A', 1),
        array('1601EQ', 'S2', 'Schedule 2: second schedule (placement B)', 'wh_month:EWT:B', 2),
        array('1601FQ', 'S1', 'Schedule 1 (placement A)', 'wh_month:FWT:A', 1),
        array('1601FQ', 'S2', 'Schedule 2 (placement B)', 'wh_month:FWT:B', 2),
        array('1601FQ', 'S3', 'Schedule 3 (placement C)', 'wh_month:FWT:C', 3),
        array('1600VT', 'S1', 'Payees with VAT withheld', 'wh_month:GVAT:*', 1),
        array('1600PT', 'S1', 'Payees with percentage tax withheld', 'wh_month:GPT:*', 1),
        array('1604C', 'S1', 'Schedule 1: employees other than MWE', 'comp_year:S1', 1),
        array('1604C', 'S2', 'Schedule 2: minimum wage earners', 'comp_year:S2', 2),
        array('1604E', 'S3', 'Schedule 3 (placement B)', 'wh_year:EWT:B', 3),
        array('1604E', 'S4', 'Schedule 4: payees from the year\'s QAP (placement A)', 'wh_year:EWT:A', 4),
        array('1604F', 'S4', 'Schedule 4 (placement A)', 'wh_year:FWT:A', 4),
        array('1604F', 'S5', 'Schedule 5 (placement B)', 'wh_year:FWT:B', 5),
        array('1604F', 'S6', 'Schedule 6 (placement C)', 'wh_year:FWT:C', 6),
        array('SAWT', 'S1', 'Payors who withheld from the taxpayer', 'sawt', 1),
        array('SRS', 'S1', 'Regular suppliers for the semester', 'srs', 1),
    );

    $src2019 = '1601-EQ Jan 2019 ENCS Annex A (ATC list); confirm against v7.4 ATC patch (BIR advisory 18 Nov 2025)';
    $taxCodes = array(
        // atc, kind, payee_type, description, rate, source
        array('WI010', 'EWT', 'individual', 'Professional fees, individual: gross income for the year not over 3M', '5.0000', $src2019),
        array('WI011', 'EWT', 'individual', 'Professional fees, individual: gross income over 3M or VAT-registered', '10.0000', $src2019),
        array('WC010', 'EWT', 'corporate', 'Professional fees, juridical person: gross income not over 720,000', '10.0000', $src2019),
        array('WC011', 'EWT', 'corporate', 'Professional fees, juridical person: gross income over 720,000', '15.0000', $src2019),
        array('WI100', 'EWT', 'individual', 'Rentals (verify description against the current ATC table)', '5.0000', $src2019 . '; description disputed between sources'),
        array('WC100', 'EWT', 'corporate', 'Rentals (verify description against the current ATC table)', '5.0000', $src2019 . '; description disputed between sources'),
        array('WI120', 'EWT', 'individual', 'Income payments to certain contractors', '2.0000', $src2019),
        array('WC120', 'EWT', 'corporate', 'Income payments to certain contractors', '2.0000', $src2019),
        array('WI157', 'EWT', 'individual', 'Government and GOCC payments for services', '2.0000', $src2019),
        array('WC157', 'EWT', 'corporate', 'Government and GOCC payments for services', '2.0000', $src2019),
        array('WI158', 'EWT', 'individual', 'Top withholding agent: purchase of goods', '1.0000', $src2019),
        array('WC158', 'EWT', 'corporate', 'Top withholding agent: purchase of goods', '1.0000', $src2019),
        array('WI160', 'EWT', 'individual', 'Top withholding agent: purchase of services', '2.0000', $src2019),
        array('WC160', 'EWT', 'corporate', 'Top withholding agent: purchase of services', '2.0000', $src2019),
        array('WI515', 'EWT', 'individual', 'Commissions (lower bracket)', '5.0000', $src2019),
        array('WC515', 'EWT', 'corporate', 'Commissions', '10.0000', $src2019),
        array('WI640', 'EWT', 'individual', 'Government and GOCC payments for goods', '1.0000', $src2019),
        array('WC640', 'EWT', 'corporate', 'Government and GOCC payments for goods', '1.0000', $src2019),
    );

    $config = array(
        'registration_open' => '1',
        'enforce_mfa_privileged' => '0',
        'mail_mode' => 'outbox',
        'session_idle_minutes' => '30',
        'max_upload_mb' => '20',
        'srs_atcs' => 'WI158,WC158,WI160,WC160',
        'research_checkpoint' => '2026-10-16',
        'current_validator' => 'Alphalist Data Entry and Validation Module v7.4 (RMC 15-2025)',
        'current_validator_patch' => 'ATC patch per BIR advisory of 18 Nov 2025',
    );

    return array('perms' => $perms, 'roles' => $roles, 'forms' => $forms, 'schedules' => $schedules,
        'tax_codes' => $taxCodes, 'config' => $config);
}
