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
        array('1601EQ', 'S1', 'Schedule 1: payees subject to expanded withholding tax', 'wh_month:EWT:A', 1),
        array('1601EQ', 'S2', 'Schedule 2: payees exempt from withholding tax', 'wh_month:EWT:B', 2),
        array('1601FQ', 'S1', 'Schedule 1: payees subject to final withholding tax', 'wh_month:FWT:A', 1),
        array('1601FQ', 'S2', 'Schedule 2: fringe benefits', 'wh_month:FWT:B', 2),
        array('1601FQ', 'S3', 'Schedule 3: payees exempt from final withholding tax', 'wh_month:FWT:C', 3),
        array('1600VT', 'S1', 'Payees with VAT withheld', 'wh_month:GVAT:*', 1),
        array('1600PT', 'S1', 'Payees with percentage tax withheld', 'wh_month:GPT:*', 1),
        array('1604C', 'S1', 'Schedule 1: employees other than MWE', 'comp_year:S1', 1),
        array('1604C', 'S2', 'Schedule 2: minimum wage earners', 'comp_year:S2', 2),
        // Schedule 3 takes the year's QAP Schedule 1 lines (A) and year totals entered from a 1604-E alphalist (Y).
        array('1604E', 'S3', 'Schedule 3: payees subject to expanded withholding tax', 'wh_year:EWT:A+Y', 3),
        array('1604E', 'S4', 'Schedule 4: payees exempt from withholding tax', 'wh_year:EWT:B', 4),
        array('1604F', 'S4', 'Schedule 4: payees subject to final withholding tax', 'wh_year:FWT:A', 4),
        array('1604F', 'S5', 'Schedule 5: fringe benefits', 'wh_year:FWT:B', 5),
        array('1604F', 'S6', 'Schedule 6: payees exempt from final withholding tax', 'wh_year:FWT:C', 6),
        array('SAWT', 'S1', 'Payors who withheld from the taxpayer', 'sawt', 1),
        array('SRS', 'S1', 'Regular suppliers for the semester', 'srs', 1),
    );

    $src2019 = '1601-EQ Jan 2019 ENCS Annex A (ATC list); confirm against v7.4 ATC patch (BIR advisory 18 Nov 2025)';
    $srcV74 = 'BIR Alphalist Data Entry and Validation Module v7.4, ATC table (reg_atcs)';
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
        // VAT (GVAT) and percentage tax (GPT) withheld: the codes, rates and descriptions of the BIR module v7.4
        array('WB030', 'GPT', 'any', 'Tax on carriers and keepers of garages', '3.0000', $srcV74),
        array('WB040', 'GPT', 'any', 'Franchise Tax on Gas and Water Utilities', '2.0000', $srcV74),
        array('WB050', 'GPT', 'any', 'Franchise Tax on radio & TV broadcasting companies whose annual gross receipts does not exceed P10M & who are not VAT registered taxpayers', '3.0000', $srcV74),
        array('WB070', 'GPT', 'any', 'Tax on life insurance premiums', '2.0000', $srcV74),
        array('WB080', 'GPT', 'any', 'Persons exempt from VAT under Sec. 109(v) (creditable)-Government Withholding Agent', '3.0000', $srcV74),
        array('WB082', 'GPT', 'any', 'Persons exempt from VAT under Section 109v (creditable) - Private Withholding Agent', '3.0000', $srcV74),
        array('WB084', 'GPT', 'any', 'Persons exempt from VAT under Section 109v (final)', '3.0000', $srcV74),
        array('WB090', 'GPT', 'any', 'Tax on overseas dispatch, message or conversation originating from the Philippines', '10.0000', $srcV74),
        array('WB102', 'GPT', 'any', 'Tax on Banks and Non-Bank Financial Intermediaries Performing Quasi-Banking Functions. On dividends and equity shares and net income of subsidiaries', '0.0000', $srcV74),
        array('WB103', 'GPT', 'any', 'Tax on Banks and Non-Bank Financial Intermediaries Performing Quasi-Banking Functions. On royalties, rentals of property, real or personal, profits from exchange and all other items treated as gross income under the Code', '7.0000', $srcV74),
        array('WB104', 'GPT', 'any', 'Tax on Banks and Non-Bank Financial Intermediaries Perfoming Quasi-Banking Functions. On the net trading gains within the taxable year on foreign currency, debt securities, derivatives, and other similar financial instruments', '7.0000', $srcV74),
        array('WB108', 'GPT', 'any', 'Tax on Other Non-Banks Financial Intermediaries Not Performing Quasi-Banking Functions. On interest, commissions and discounts from lending activities as well as income from financial leasing, on the basis of the remaining maturities of instrument f', '5.0000', $srcV74),
        array('WB109', 'GPT', 'any', 'Tax on Other Non-Banks Financial Intermediaries Not Performing Quasi-Banking Functions. On interest, commissions and discounts from lending activities as well as income from financial leasing, on the basis of the remaining maturities of instrument f', '1.0000', $srcV74),
        array('WB110', 'GPT', 'any', 'Tax on Other Non-Banks Financial Intermediaries Not Performing Quasi-Banking Functions. On all other items treated as gross income under the Code', '5.0000', $srcV74),
        array('WB120', 'GPT', 'any', 'Business Tax on Agents of foreign insurance co. - insurance agents', '4.0000', $srcV74),
        array('WB121', 'GPT', 'any', 'Business Tax on Agents of foreign insurance co. - owner of the property', '5.0000', $srcV74),
        array('WB130', 'GPT', 'any', 'Tax on International Carriers', '3.0000', $srcV74),
        array('WB140', 'GPT', 'any', 'Tax on cockpits', '18.0000', $srcV74),
        array('WB150', 'GPT', 'any', 'Tax on cabarets, night and day clubs', '18.0000', $srcV74),
        array('WB160', 'GPT', 'any', 'Tax on boxing exhibitions', '10.0000', $srcV74),
        array('WB170', 'GPT', 'any', 'Tax on professional basketball games', '15.0000', $srcV74),
        array('WB180', 'GPT', 'any', 'Tax on jai-alai & race tracks', '30.0000', $srcV74),
        array('WB191', 'GPT', 'any', 'Tax on Winnings and Prizes (Sec. 126) (double/forecast/quinella/trifecta bets) - Gov\'t. Withholding Agent', '4.0000', $srcV74),
        array('WB192', 'GPT', 'any', 'Tax on Winnings and Prizes on horse races/owners of winning racehorses (Sec. 126) - Gov\'t. Withholding Agent', '10.0000', $srcV74),
        array('WB193', 'GPT', 'any', 'Tax on Winnings and Prizes (Sec. 126) (double/forecast/quinella/trifecta bets) - Private Withholding Agent', '4.0000', $srcV74),
        array('WB194', 'GPT', 'any', 'Tax on Winnings and Prizes on horse races/owners of winning racehorses (Sec. 126) - Private Withholding Agent', '10.0000', $srcV74),
        array('WB200', 'GPT', 'any', 'Tax on sale, barter or exchange of shares of stocks listed and traded through the Local Stock Exchange', '0.6000', $srcV74),
        array('WB201', 'GPT', 'any', 'Tax on shares of stock sold or exchanged through initial and secondary public offering - Not over 25%', '4.0000', $srcV74),
        array('WB202', 'GPT', 'any', 'Tax on shares of stock sold or exchanged through initial and secondary public offering - Over 25% but not exceeding 33 1/3%', '2.0000', $srcV74),
        array('WB203', 'GPT', 'any', 'Tax on shares of stock sold or exchanged through initial and secondary public offering - Over 33 1/3%', '1.0000', $srcV74),
        array('WB301', 'GPT', 'any', 'Tax on Banks and Non-Bank Financial Internediaries Performing Quasi-Banking Functions. On interest, commissions and discounts from lending activities as well as income from financial leasing, on the basis of remaining maturities of instruments fr', '5.0000', $srcV74),
        array('WB303', 'GPT', 'any', 'Tax on Banks and Non-Bank Financial Intermediaries Performing Quasi-Banking Functions. On interest, commissions and discounts from lending activities as well as income from financial leasing, on the basis of remaining maturities of instruments f', '1.0000', $srcV74),
        array('WV010', 'GVAT', 'any', 'FWVAT on payments for purchases of Goods', '5.0000', $srcV74),
        array('WV012', 'GVAT', 'any', 'VAT Withholding on Purchase of Goods (with waiver of privilege to claim input tax credit (creditable)', '12.0000', $srcV74),
        array('WV014', 'GVAT', 'any', 'Vat Withholding on Purchase of Goods (with waiver of privilege to claim input tax credit (final)', '12.0000', $srcV74),
        array('WV020', 'GVAT', 'any', 'FWVAT on payments for purchases of Services', '5.0000', $srcV74),
        array('WV022', 'GVAT', 'any', 'VAT Withholding on Purchase of Services (with waiver of privilege to claim input tax credit (creditable)', '12.0000', $srcV74),
        array('WV024', 'GVAT', 'any', 'VAT Withholding on Purchase of Services (with waiver of privilege to claim input tax credit (final)', '12.0000', $srcV74),
        array('WV030', 'GVAT', 'any', 'FWVAT on Government Public Work Contracts', '5.0000', $srcV74),
        array('WV040', 'GVAT', 'any', 'Lease or use of properties or property rights owned by non-residents (Government Withholding Agent)', '12.0000', $srcV74),
        array('WV050', 'GVAT', 'any', 'Lease or use of properties or property rights owned by non-residents (Private Withholding Agent)', '12.0000', $srcV74),
        array('WV060', 'GVAT', 'any', 'Other services rendered in the Philippines by non-residents (Government Withholding Agent)', '12.0000', $srcV74),
        array('WV070', 'GVAT', 'any', 'Other services rendered in the Philippines by non-residents (Private Withholding Agent)', '12.0000', $srcV74),
        // Final withholding tax (1601-FQ, 1604-F) and fringe benefit tax, from the same table.
        array('WC180', 'FWT', 'corporate', 'Interest on foreign loans payable to non-resident foreign corporations (NRFCs)', '20.0000', $srcV74),
        array('WC190', 'FWT', 'corporate', 'Interest and other income payments on foreign currency transactions/loans payable to OBUs', '10.0000', $srcV74),
        array('WC191', 'FWT', 'corporate', 'Interest and other income payments on foreign currency transactions/loans payable to FCDUs', '10.0000', $srcV74),
        array('WI202', 'FWT', 'individual', 'Cash dividend payments by domestic corporation to citizens and resident aliens', '10.0000', $srcV74),
        array('WI203', 'FWT', 'individual', 'Property dividend payments by domestic corporation to citizens and resident aliens', '10.0000', $srcV74),
        array('WC212', 'FWT', 'corporate', 'Cash dividend payment by domestic corporation to Non-Resident Foreign Corporations', '25.0000', $srcV74),
        array('WC213', 'FWT', 'corporate', 'Property dividend payment by domestic corporation to Non-Resident Foreign Corporations', '25.0000', $srcV74),
        array('WC222', 'FWT', 'corporate', 'Cash dividend payment by domestic corporation to Non-Resident Foreign Corporations whose countries allowed tax deemed paid credit (subject to tax sparing rule)', '15.0000', $srcV74),
        array('WC223', 'FWT', 'corporate', 'Property dividend payment by domestic corporation to Non-Resident Foreign Corporations whose countries allowed tax deemed paid credit (subject to tax sparing rule)', '15.0000', $srcV74),
        array('WI224', 'FWT', 'individual', 'Cash dividend payment by domestic corporation to non-resident alien engaged in trade or business (NRAETB)', '20.0000', $srcV74),
        array('WI225', 'FWT', 'individual', 'Property dividend payment by domestic corporation to non-resident alien engaged in trade or business (NRAETB)', '20.0000', $srcV74),
        array('WI226', 'FWT', 'individual', 'Share of a NRAETB in the distributable net income after tax of a partnership (except GPP) of which he is a partner, or share in the net income after tax of an association, joint account, or a joint venture taxable as a corporation of which he is a m', '20.0000', $srcV74),
        array('WC230', 'FWT', 'corporate', 'On other payments to non-resident foreign corporations (NRFCs)', '25.0000', $srcV74),
        array('WI240', 'FWT', 'individual', 'Distributive share of individual partners in a taxable partnerships, association, joint account, or joint venture or consortium', '10.0000', $srcV74),
        array('WI250', 'FWT', 'individual', 'All kinds of royalty payments to citizens, resident aliens and non-resident aliens engaged in trade or business (other than WI 380 and WI 341)', '20.0000', $srcV74),
        array('WC250', 'FWT', 'corporate', 'All kinds of royalty payments to domestic and resident foreign corporation', '20.0000', $srcV74),
        array('WI260', 'FWT', 'individual', 'On prizes exceeding P10,000 and other winnings paid to individuals', '20.0000', $srcV74),
        array('WC280', 'FWT', 'corporate', 'Branch profit remittances by all corporations except PEZA/SBMA/CDA registered', '15.0000', $srcV74),
        array('WC290', 'FWT', 'corporate', 'On gross rentals, lease and charter fees derived by non-resident owner or lessor of foreign vessels', '4.5000', $srcV74),
        array('WC300', 'FWT', 'corporate', 'On the gross rentals, lease and charter fees derived by non-resident lessor of aircraft, machineries and other equipment', '7.5000', $srcV74),
        array('WI310', 'FWT', 'individual', 'On payments to oil exploration service contractors and subcontractors (OESS) - Individual (NRAETB)', '8.0000', $srcV74),
        array('WC310', 'FWT', 'corporate', 'On payments to oil exploration service contractors and subcontractors (OESS) - Corporate (non-resident foreign corporation engage in trade or business)', '8.0000', $srcV74),
        array('WI320', 'FWT', 'individual', 'Payments to Filipinos or alien individuals employed by Foreign Petroleum Service Contractors/Subcontractors, Offshore Banking Units and Regional or Area Headquarters and Regional Operating Headquarters of Multinational Companies occupying executive/m', '0.0000', $srcV74),
        array('WI330', 'FWT', 'individual', 'Payments to NRANETB except on sale of shares in domestic corporation and real property', '25.0000', $srcV74),
        array('WI340', 'FWT', 'individual', 'On payments to non-resident individual cinematographic film owners, lessors or distributors', '25.0000', $srcV74),
        array('WC340', 'FWT', 'corporate', 'On payments to foreign corporate cinematographic film owners, lessors or distributors', '25.0000', $srcV74),
        array('WI341', 'FWT', 'individual', 'Royalties paid to NRAETB on cinematographic films and similar works', '25.0000', $srcV74),
        array('WI350', 'FWT', 'individual', 'Final tax on interest or other payments upon tax-free covenant bonds, mortgages, deeds of trust or other obligations under Sec. 57C of the NIRC of 1997', '30.0000', $srcV74),
        array('WI380', 'FWT', 'individual', 'Royalties paid to citizens, resident aliens and NRAETB on books, other literary works and musical composition', '10.0000', $srcV74),
        array('WI410', 'FWT', 'individual', 'Informer\'s cash reward paid to individuals', '10.0000', $srcV74),
        array('WC410', 'FWT', 'corporate', 'Informer\'s cash reward paid to juridical persons', '10.0000', $srcV74),
        array('WF330', 'FWT', 'individual', 'Payment of fringe benefits to Non-Resident Alien Not Engaged in Trade or Business (NRAETB)', '25.0000', $srcV74),
        array('WF360', 'FWT', 'individual', 'Employees other than rank and file (Fringe Benefit Tax) based on the grossed up monetary value', '35.0000', $srcV74),
        array('WC700', 'FWT', 'corporate', 'Cash or property dividend paid by a Real Estate Investment Trust (REIT) - Corporate', '10.0000', $srcV74),
        array('WI700', 'FWT', 'individual', 'Cash or property dividend paid by a Real Estate Investment Trust (REIT) - Individual', '10.0000', $srcV74),
        array('WI740', 'FWT', 'individual', 'Final Withholding Tax on Foreign Nationals Employed by POGO Entities', '25.0000', $srcV74),
        array('WI750', 'FWT', 'individual', 'On gross income earned by foreign nationals or non-Filipino citizens, regardless of their residency, who are employed and assigned in the Philippines by Offshore Gaming Licensee or its Accredited Service Provider', '0.0000', $srcV74),
    );

    $config = array(
        'schema_version' => '8',
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
