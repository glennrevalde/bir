<?php
/**
 * Demo tenants, users and records. All TINs, names and amounts are synthetic.
 * Nothing here fabricates official BIR validation evidence: demo files stay "pending BIR validation".
 */
function gsb_demo_login($userId, $orgId, $clientId)
{
    $GLOBALS['_SESSION'] = isset($GLOBALS['_SESSION']) ? $GLOBALS['_SESSION'] : array();
    $_SESSION['uid'] = $userId;
    $_SESSION['org_id'] = $orgId;
    $_SESSION['client_id'] = $clientId;
    Auth::setUserForCli(DB::row('SELECT * FROM users WHERE id = ?', array($userId)));
    Ctx::reset();
}

function gsb_seed_demo()
{
    mt_srand(20261016);
    $pw = Auth::hash('Demo@123456');
    $orgId = DB::insert('organizations', array('name' => 'Bayanihan Accounting Partners', 'org_type' => 'firm', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()));
    $users = array();
    foreach (array(
                 'owner' => array('owner@demo.local', 'Andrea Villanueva', 'firm_owner'),
                 'preparer' => array('preparer@demo.local', 'Paolo Ramos', 'preparer'),
                 'reviewer' => array('reviewer@demo.local', 'Liza Fernandez', 'reviewer'),
                 'encoder' => array('encoder@demo.local', 'Jonel Abad', 'encoder'),
                 'auditor' => array('auditor@demo.local', 'Grace Uy', 'auditor'),
             ) as $k => $u) {
        $id = DB::insert('users', array('email' => $u[0], 'password_hash' => $pw, 'full_name' => $u[1], 'status' => 'active', 'email_verified_at' => now(),
            'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()));
        DB::insert('memberships', array('user_id' => $id, 'organization_id' => $orgId, 'role_code' => $u[2], 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
        $users[$k] = $id;
    }

    // ---------------------------------------------------------------- clients
    $c1 = DB::insert('client_entities', array('organization_id' => $orgId, 'tin' => '111222333', 'taxpayer_type' => 'non_individual', 'registered_name' => 'KALAMANSI TRADING CORPORATION',
        'trade_name' => 'KALAMANSI TRADING', 'classification' => 'top_withholding_agent', 'vat_status' => 'vat', 'rdo_code' => '081',
        'address1' => '45 OSMENA BLVD BRGY KALUBIHAN', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'email' => 'books@kalamansi.example',
        'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12, 'status' => 'active', 'created_by' => $users['owner'], 'created_at' => now(), 'updated_at' => now()));
    $b0 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c1, 'branch_code' => '0000', 'name' => 'Head office, Cebu City', 'is_head_office' => 1,
        'rdo_code' => '081', 'address1' => '45 OSMENA BLVD BRGY KALUBIHAN', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'status' => 'active', 'created_at' => now()));
    $b1 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c1, 'branch_code' => '0001', 'name' => 'Mandaue warehouse', 'is_head_office' => 0,
        'rdo_code' => '080', 'address1' => '12 A.S. FORTUNA ST BRGY BAKILID', 'address2' => 'MANDAUE CITY', 'zip_code' => '6014', 'status' => 'active', 'created_at' => now()));
    DB::insert('signatories', array('organization_id' => $orgId, 'client_id' => $c1, 'full_name' => 'Ramon T. Ocampo', 'title' => 'Treasurer', 'tin' => '123-456-789-000',
        'is_default' => 1, 'status' => 'active', 'created_at' => now()));

    $c2 = DB::insert('client_entities', array('organization_id' => $orgId, 'tin' => '222333444', 'taxpayer_type' => 'individual', 'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN',
        'middle_name' => 'SANTOS', 'trade_name' => 'JSDC ENGINEERING CONSULTANCY', 'classification' => 'regular', 'vat_status' => 'non_vat', 'rdo_code' => '082',
        'address1' => '8 V. RAMA AVE BRGY GUADALUPE', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12,
        'status' => 'active', 'created_by' => $users['owner'], 'created_at' => now(), 'updated_at' => now()));
    $b2 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c2, 'branch_code' => '0000', 'name' => 'Main office', 'is_head_office' => 1,
        'rdo_code' => '082', 'address1' => '8 V. RAMA AVE BRGY GUADALUPE', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'status' => 'active', 'created_at' => now()));

    // approve the seeded ATCs as the demo reviewer (simulates the review a real installation must do)
    DB::q('UPDATE tax_codes SET status = ?, approved_by = ?, approved_at = ?, notes = ? WHERE status = ?',
        array('approved', $users['reviewer'], now(), 'Demo approval. In production, approve each code after checking the installed ATC patch.', 'pending_review'));

    gsb_demo_login($users['preparer'], $orgId, $c1);

    $cp = function ($d) use ($orgId, $c1, $users) {
        return DB::insert('counterparties', array_merge(array('organization_id' => $orgId, 'client_id' => $c1, 'branch_code' => '0000', 'entity_type' => 'non_individual',
            'status' => 'active', 'source' => 'manual', 'created_by' => $users['preparer'], 'created_at' => now(), 'updated_at' => now()), $d));
    };
    $sup = array(
        'print' => $cp(array('tin' => '300100200', 'registered_name' => 'MABUHAY PRINTING SERVICES INC', 'address1' => '22 COLON ST', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC160')),
        'hardware' => $cp(array('tin' => '300100201', 'registered_name' => 'SUGBO HARDWARE SUPPLY CORP', 'address1' => '101 N. BACALSO AVE', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC158')),
        'logistics' => $cp(array('tin' => '300100203', 'registered_name' => 'ISLA LOGISTICS CORPORATION', 'address1' => 'PIER 3 RECLAMATION AREA', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC160')),
        'lessor' => $cp(array('tin' => '300100205', 'registered_name' => 'MANDAUE PROPERTY HOLDINGS INC', 'address1' => '5 OUANO AVE', 'address2' => 'MANDAUE CITY', 'zip_code' => '6014', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC100')),
        'lawyer' => $cp(array('tin' => '300100202', 'branch_code' => '0000', 'entity_type' => 'individual', 'last_name' => 'DELOS SANTOS', 'first_name' => 'MARIA LUISA', 'middle_name' => 'REYES',
            'address1' => '3F ARLINGTON BLDG ESCARIO ST', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_payee' => 1, 'default_atc' => 'WI010')),
        'caterer' => $cp(array('tin' => '300100204', 'entity_type' => 'individual', 'last_name' => 'PEÑA', 'first_name' => 'ROSARIO', 'middle_name' => 'LIM',
            'address1' => '17 SAN JOSE ST', 'address2' => 'TALISAY CITY', 'zip_code' => '6045', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WI158')),
        'japan' => $cp(array('tin' => null, 'registered_name' => 'OSAKA FRESH PRODUCE KK', 'foreign_address' => '2-1 NAMBA, OSAKA, JAPAN', 'is_supplier' => 1)),
    );
    $cus = array(
        'mart' => $cp(array('tin' => '400100300', 'registered_name' => 'VISAYAS MEGA MART INC', 'address1' => 'NORTH RECLAMATION AREA', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_customer' => 1)),
        'cafe' => $cp(array('tin' => '400100301', 'registered_name' => 'CEBU PORT CAFE CORP', 'address1' => '9 MJ CUENCO AVE', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_customer' => 1)),
        'hotel' => $cp(array('tin' => '400100302', 'registered_name' => 'MACTAN BAYVIEW HOTEL INC', 'address1' => 'PUNTA ENGANO RD', 'address2' => 'LAPU-LAPU CITY', 'zip_code' => '6015', 'is_customer' => 1)),
        'resto' => $cp(array('tin' => '400100303', 'entity_type' => 'individual', 'last_name' => 'GO', 'first_name' => 'ANTONIO', 'middle_name' => 'SY', 'address1' => '33 JUAN LUNA AVE', 'address2' => 'CEBU CITY', 'zip_code' => '6000', 'is_customer' => 1)),
    );

    $sales = Records::module('sales');
    $purch = Records::module('purchases');
    $wh = Records::module('withholding');
    $imp = Records::module('importations');
    $amt = function ($min, $max) {
        return mt_rand($min, $max) * 100 + (mt_rand(0, 3) * 25);
    };
    for ($m = 1; $m <= 9; $m++) {
        $ym = sprintf('2026-%02d', $m);
        foreach ($cus as $key => $cid) {
            for ($i = 0; $i < 2; $i++) {
                $net = $amt(18000, 160000);
                $d = array('branch_id' => $i === 0 ? $b0 : $b1, 'counterparty_id' => $cid, 'invoice_no' => 'SI-' . $m . str_pad((string)mt_rand(1, 999), 4, '0', STR_PAD_LEFT),
                    'invoice_date' => $ym . '-' . sprintf('%02d', mt_rand(1, 27)), 'exempt_amount' => '0.00', 'zero_rated_amount' => '0.00',
                    'taxable_amount' => Money::toPlain($net), 'output_vat' => Money::toPlain(Money::applyRate($net, 120000)), 'description' => 'Sale of goods');
                $d['period_month'] = substr($d['invoice_date'], 0, 7);
                Records::save($sales, $d);
            }
        }
        foreach (array('print' => 'services', 'hardware' => 'other', 'logistics' => 'services', 'lessor' => 'services', 'caterer' => 'other') as $key => $kind) {
            $net = $amt(8000, 90000);
            $d = array('branch_id' => $b0, 'counterparty_id' => $sup[$key], 'invoice_no' => 'INV-' . mt_rand(1000, 9999), 'invoice_date' => $ym . '-' . sprintf('%02d', mt_rand(1, 27)),
                'exempt_amount' => '0.00', 'zero_rated_amount' => '0.00', 'services_amount' => $kind === 'services' ? Money::toPlain($net) : '0.00',
                'capital_goods_amount' => '0.00', 'other_goods_amount' => $kind === 'other' ? Money::toPlain($net) : '0.00',
                'input_vat' => $key === 'caterer' ? '0.00' : Money::toPlain(Money::applyRate($net, 120000)), 'non_creditable_vat' => '0.00', 'description' => null);
            if ($key === 'caterer') {
                $d['exempt_amount'] = $d['other_goods_amount'];
                $d['other_goods_amount'] = '0.00';
            }
            $d['period_month'] = substr($d['invoice_date'], 0, 7);
            Records::save($purch, $d);
            // matching withholding
            $atcs = array('print' => 'WC160', 'hardware' => 'WC158', 'logistics' => 'WC160', 'lessor' => 'WC100', 'caterer' => 'WI158');
            $atc = $atcs[$key];
            $in = array('branch_id' => $b0, 'counterparty_id' => $sup[$key], 'tax_kind' => 'EWT', 'atc_code' => $atc, 'payment_date' => $d['invoice_date'],
                'nature_of_payment' => $kind === 'services' ? 'Services' : 'Purchase of goods', 'income_amount' => Money::toPlain($net), 'tax_rate' => '',
                'tax_withheld' => '', 'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => 'CV-' . $m . mt_rand(100, 999));
            list($data, $err) = Records::validate($wh, $in, $c1);
            if ($err) {
                throw new RuntimeException('Demo withholding failed: ' . implode('; ', $err));
            }
            Records::save($wh, $data);
        }
        // professional fees from the Mandaue branch every month
        $in = array('branch_id' => $b1, 'counterparty_id' => $sup['lawyer'], 'tax_kind' => 'EWT', 'atc_code' => 'WI010', 'payment_date' => $ym . '-15',
            'nature_of_payment' => 'Legal retainer', 'income_amount' => '25000.00', 'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => 'CV-LR' . $m);
        list($data) = Records::validate($wh, $in, $c1);
        Records::save($wh, $data);
    }
    // one importation
    $d = array('branch_id' => $b0, 'counterparty_id' => $sup['japan'], 'import_entry_no' => 'IE-2026-000418', 'importation_date' => '2026-02-11', 'assessment_date' => '2026-02-13',
        'country_origin' => 'JAPAN', 'landed_cost' => '486500.00', 'other_charges' => '12400.00', 'exempt_amount' => '0.00', 'taxable_amount' => '498900.00',
        'vat_amount' => '59868.00', 'or_no' => 'BOC-88124', 'vat_payment_date' => '2026-02-13', 'period_month' => '2026-02');
    Records::save($imp, $d);

    // ---------------------------------------------------------------- employees and TY2025 compensation
    $emps = array(
        array('901234567', 'REYES', 'MARIA', 'SANTOS', 'NCR', '532000.00', '0.00', '40000.00', '12000.00', '30187.50', '449812.50', '32462.50', '29000.00', '3462.50'),
        array('901234568', 'LIM', 'CARLO', 'TAN', 'VII', '480000.00', '0.00', '40000.00', '10000.00', '28800.00', '401200.00', '25180.00', '23000.00', '2180.00'),
        array('901234569', 'BACALSO', 'ANA', 'MARIE', 'VII', '312000.00', '0.00', '26000.00', '10000.00', '19200.00', '256800.00', '1020.00', '1000.00', '20.00'),
    );
    foreach ($emps as $i => $e) {
        $eid = DB::insert('employees', array('organization_id' => $orgId, 'client_id' => $c1, 'branch_id' => $b0, 'employee_no' => 'E-' . (101 + $i), 'tin' => $e[0], 'tin_branch' => '0000',
            'last_name' => $e[1], 'first_name' => $e[2], 'middle_name' => $e[3], 'region_code' => $e[4], 'nationality' => 'FILIPINO', 'employment_status' => 'R',
            'hired_on' => '2023-06-01', 'status' => 'active', 'source' => 'manual', 'created_by' => $users['preparer'], 'created_at' => now(), 'updated_at' => now()));
        $nt = Money::fromDb($e[7]) + Money::fromDb($e[8]) + Money::fromDb($e[9]);
        $in = array('tax_year' => 2025, 'schedule_code' => 'S1', 'employee_id' => $eid, 'branch_id' => $b0, 'region_code' => $e[4], 'employment_from' => '2025-01-01',
            'employment_to' => '2025-12-31', 'pres_gross' => $e[5], 'pres_nt_smw' => $e[6], 'pres_nt_13th' => $e[7], 'pres_nt_deminimis' => $e[8], 'pres_nt_contrib' => $e[9],
            'pres_nt_other' => '0', 'pres_nt_total' => Money::toPlain($nt), 'pres_tx_basic' => $e[10], 'pres_tx_13th' => '0', 'pres_tx_other' => '0', 'pres_tx_total' => $e[10],
            'taxable_comp_total' => $e[10], 'net_taxable_comp' => $e[10], 'tax_due' => $e[11], 'pres_withheld_jan_nov' => $e[12], 'pres_withheld_dec' => $e[13],
            'over_withheld_refunded' => '0', 'tax_withheld_adjusted' => $e[11], 'nationality' => 'FILIPINO', 'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y');
        list($d, $err) = Payroll::compValidate($in, $c1);
        if ($err) {
            throw new RuntimeException('Demo compensation failed: ' . implode('; ', $err));
        }
        Payroll::compSave($d);
    }

    // ---------------------------------------------------------------- files and certificates for client 1
    foreach (quarter_months(2026, 1) as $mm) {
        $ctx = Reports::context(array('form' => '1601EQ', 'schedule' => 'S1', 'branch_id' => $b0, 'year' => 2026, 'part' => (int)substr($mm, 5, 2), 'sawt_form' => null, 'amended' => 0));
        Reports::generate($ctx, true, 'Demo file');
    }
    $ctx = Reports::context(array('form' => 'SLS', 'schedule' => 'S1', 'branch_id' => $b0, 'year' => 2026, 'part' => 1, 'sawt_form' => null, 'amended' => 0));
    Reports::generate($ctx, true, 'Demo file');
    $ctx = Reports::context(array('form' => 'SLP', 'schedule' => 'S1', 'branch_id' => $b0, 'year' => 2026, 'part' => 1, 'sawt_form' => null, 'amended' => 0));
    Reports::generate($ctx, true, 'Demo file');
    $ctx = Reports::context(array('form' => '1604C', 'schedule' => 'S1', 'branch_id' => $b0, 'year' => 2025, 'part' => 1, 'sawt_form' => null, 'amended' => 0));
    Reports::generate($ctx, true, 'Demo file');

    $client = Ctx::client();
    $branch = DB::row('SELECT * FROM branches WHERE id = ?', array($b0));
    $sig = DB::val('SELECT id FROM signatories WHERE client_id = ?', array($c1));
    foreach (array('print', 'hardware') as $k) {
        $payload = Certificates::buildWithholding('2307', $client, $branch, $sup[$k], '2026-01-01', '2026-03-31');
        Certificates::issue($payload, array('signatory_id' => $sig, 'paper' => 'A4', 'branch_id' => $b0));
    }
    $comp = DB::val('SELECT id FROM compensation_records WHERE client_id = ? ORDER BY id LIMIT 1', array($c1));
    Certificates::issue(Certificates::build2316($client, $branch, $comp), array('signatory_id' => $sig, 'paper' => 'A4', 'branch_id' => $b0));

    // ---------------------------------------------------------------- client 2: creditable taxes withheld on the professional (SAWT)
    gsb_demo_login($users['preparer'], $orgId, $c2);
    $payor = function ($tin, $name) use ($orgId, $c2, $users) {
        return DB::insert('counterparties', array('organization_id' => $orgId, 'client_id' => $c2, 'tin' => $tin, 'branch_code' => '0000', 'entity_type' => 'non_individual',
            'registered_name' => $name, 'address2' => 'CEBU CITY', 'is_payor' => 1, 'status' => 'active', 'source' => 'manual', 'created_by' => $users['preparer'], 'created_at' => now(), 'updated_at' => now()));
    };
    $p1 = $payor('400100300', 'VISAYAS MEGA MART INC');
    $p2 = $payor('500200100', 'CENTRAL VISAYAS BUILDERS CORP');
    $won = Records::module('withheld_on_us');
    foreach (array(array($p1, '2026-01-20', '60000.00'), array($p1, '2026-02-20', '45000.00'), array($p2, '2026-03-05', '120000.00')) as $r) {
        list($d, $err) = Records::validate($won, array('branch_id' => $b2, 'counterparty_id' => $r[0], 'atc_code' => 'WI010', 'payment_date' => $r[1], 'income_amount' => $r[2],
            'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'reference_no' => '2307-' . substr($r[1], 5, 2)), $c2);
        if ($err) {
            throw new RuntimeException('Demo SAWT failed: ' . implode('; ', $err));
        }
        Records::save($won, $d);
    }
    $ctx = Reports::context(array('form' => 'SAWT', 'schedule' => 'S1', 'branch_id' => $b2, 'year' => 2026, 'part' => 1, 'sawt_form' => '1701Q', 'amended' => 0));
    Reports::generate($ctx, true, 'Demo file');

    // one pending approval request and a notification so the queue is not empty
    gsb_demo_login($users['preparer'], $orgId, $c1);
    $tc = DB::insert('tax_codes', array('atc_code' => 'WC120', 'tax_kind' => 'EWT', 'payee_type' => 'corporate', 'description' => 'Income payments to certain contractors (proposed new version)',
        'rate' => '2.0000', 'effective_from' => '2026-07-01', 'version_tag' => 'demo-proposal', 'source_ref' => 'Demo proposal; attach the BIR issuance before approval',
        'status' => 'pending_review', 'submitted_by' => $users['preparer'], 'created_at' => now()));
    Workflow::request('tax_codes', $tc, 'approve_tax_code', 'Demo: proposed effective-date change for WC120');

    // deadline rules for the demo calendar (illustrative; confirm against current BIR issuances)
    DB::insert('deadline_rules', array('form_code' => '1601EQ', 'rule_text' => 'Demo rule: last day of the month after the quarter', 'months_after_period' => 1, 'due_day' => 31,
        'source_ref' => 'Demo value only. Replace with the current BIR issuance.', 'is_active' => 1, 'created_at' => now()));
    gsb_seed_demo_more($orgId, $users, $c1, $b0, $b1, $sup, $cus);
    $GLOBALS['_SESSION'] = array();
    Auth::setUserForCli(null);
    Ctx::reset();
}

// ====================================================================== extended demo data

/** Graduated income tax on annual taxable compensation (TRAIN schedule effective 2023), in centavos. */
function gsb_demo_tax($t)
{
    $brackets = array(array(800000000, 220250000, 35), array(200000000, 40250000, 30), array(80000000, 10250000, 25), array(40000000, 2250000, 20), array(25000000, 0, 15));
    foreach ($brackets as $b) {
        if ($t > $b[0]) {
            return $b[1] + (int)round(($t - $b[0]) * $b[2] / 100);
        }
    }
    return 0;
}

/**
 * One employee and a TY2025 Schedule 1 compensation record built from a monthly salary.
 * Contributions are illustrative employee shares, not a payroll computation.
 */
function gsb_demo_employee($orgId, $clientId, $branchId, $userId, $no, $e)
{
    list($tin, $last, $first, $middle, $region, $monthly, $deminimis, $from) = $e;
    $eid = DB::insert('employees', array('organization_id' => $orgId, 'client_id' => $clientId, 'branch_id' => $branchId, 'employee_no' => $no, 'tin' => $tin, 'tin_branch' => '0000',
        'last_name' => $last, 'first_name' => $first, 'middle_name' => $middle, 'region_code' => $region, 'nationality' => 'FILIPINO', 'employment_status' => 'R',
        'hired_on' => $from === '2025-01-01' ? '2022-03-01' : $from, 'status' => 'active', 'source' => 'manual', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()));
    $months = 12 - ((int)substr($from, 5, 2) - 1);
    $S = $monthly * 100;
    $basic = $S * $months;
    $thirteenth = (int)round($S * $months / 12);
    $contrib = $months * ((int)round(min($S, 3500000) * 5 / 100) + (int)round(min($S, 10000000) * 25 / 1000) + 20000);
    $D = $deminimis * 100;
    $nt13 = min($thirteenth, 9000000);
    $tx13 = $thirteenth - $nt13;
    $txBasic = $basic - $contrib;
    $txTotal = $txBasic + $tx13;
    $tax = gsb_demo_tax($txTotal);
    $janNov = (int)round($tax * ($months - 1) / $months);
    $P = function ($c) {
        return Money::toPlain($c);
    };
    $in = array('tax_year' => 2025, 'schedule_code' => 'S1', 'employee_id' => $eid, 'branch_id' => $branchId, 'region_code' => $region, 'employment_from' => $from,
        'employment_to' => '2025-12-31', 'pres_gross' => $P($basic + $thirteenth + $D), 'pres_nt_smw' => '0', 'pres_nt_13th' => $P($nt13), 'pres_nt_deminimis' => $P($D),
        'pres_nt_contrib' => $P($contrib), 'pres_nt_other' => '0', 'pres_nt_total' => $P($nt13 + $D + $contrib), 'pres_tx_basic' => $P($txBasic), 'pres_tx_13th' => $P($tx13),
        'pres_tx_other' => '0', 'pres_tx_total' => $P($txTotal), 'taxable_comp_total' => $P($txTotal), 'net_taxable_comp' => $P($txTotal), 'tax_due' => $P($tax),
        'pres_withheld_jan_nov' => $P($janNov), 'pres_withheld_dec' => $P($tax - $janNov), 'over_withheld_refunded' => '0', 'tax_withheld_adjusted' => $P($tax),
        'nationality' => 'FILIPINO', 'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => $from === '2025-01-01' ? 'Y' : 'N');
    list($d, $err) = Payroll::compValidate($in, $clientId);
    if ($err) {
        throw new RuntimeException('Demo compensation for ' . $last . ' failed: ' . implode('; ', $err));
    }
    Payroll::compSave($d);
    return $eid;
}

function gsb_demo_party($orgId, $clientId, $userId, $d)
{
    return DB::insert('counterparties', array_merge(array('organization_id' => $orgId, 'client_id' => $clientId, 'branch_code' => '0000', 'entity_type' => 'non_individual',
        'status' => 'active', 'source' => 'manual', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()), $d));
}

function gsb_demo_wh($clientId, $branchId, $cpId, $atc, $date, $amount, $nature, $ref, $tag = 'A')
{
    list($d, $err) = Records::validate(Records::module('withholding'), array('branch_id' => $branchId, 'counterparty_id' => $cpId, 'tax_kind' => 'EWT', 'atc_code' => $atc,
        'payment_date' => $date, 'nature_of_payment' => $nature, 'income_amount' => $amount, 'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '',
        'schedule_tag' => $tag, 'reference_no' => $ref), $clientId);
    if ($err) {
        throw new RuntimeException('Demo withholding failed: ' . implode('; ', $err));
    }
    return Records::save(Records::module('withholding'), $d);
}

function gsb_demo_purchase($branchId, $cpId, $date, $net, $kind, $vat = true)
{
    $d = array('branch_id' => $branchId, 'counterparty_id' => $cpId, 'invoice_no' => 'INV-' . mt_rand(10000, 99999), 'invoice_date' => $date,
        'exempt_amount' => $vat ? '0.00' : Money::toPlain($net), 'zero_rated_amount' => '0.00',
        'services_amount' => $vat && $kind === 'services' ? Money::toPlain($net) : '0.00', 'capital_goods_amount' => $vat && $kind === 'capital' ? Money::toPlain($net) : '0.00',
        'other_goods_amount' => $vat && $kind === 'goods' ? Money::toPlain($net) : '0.00', 'input_vat' => $vat ? Money::toPlain(Money::applyRate($net, 120000)) : '0.00',
        'non_creditable_vat' => '0.00', 'description' => null, 'period_month' => substr($date, 0, 7));
    return Records::save(Records::module('purchases'), $d);
}

function gsb_demo_sale($branchId, $cpId, $date, $net, $no)
{
    $d = array('branch_id' => $branchId, 'counterparty_id' => $cpId, 'invoice_no' => $no, 'invoice_date' => $date, 'exempt_amount' => '0.00', 'zero_rated_amount' => '0.00',
        'taxable_amount' => Money::toPlain($net), 'output_vat' => Money::toPlain(Money::applyRate($net, 120000)), 'description' => 'Sale of goods', 'period_month' => substr($date, 0, 7));
    return Records::save(Records::module('sales'), $d);
}

function gsb_demo_gen($form, $schedule, $branchId, $year, $part, $sawtForm = null)
{
    $ctx = Reports::context(array('form' => $form, 'schedule' => $schedule, 'branch_id' => $branchId, 'year' => $year, 'part' => $part, 'sawt_form' => $sawtForm, 'amended' => 0));
    return Reports::generate($ctx, true, 'Demo file');
}

function gsb_demo_day($ym)
{
    return $ym . '-' . sprintf('%02d', mt_rand(2, 27));
}

function gsb_seed_demo_more($orgId, $users, $c1, $b0, $b1, $sup, $cus)
{
    $pw = Auth::hash('Demo@123456');
    $user = function ($email, $name) use ($pw) {
        return DB::insert('users', array('email' => $email, 'password_hash' => $pw, 'full_name' => $name, 'status' => 'active', 'email_verified_at' => now(),
            'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()));
    };

    // ------------------------------------------------------------------ Kalamansi: more staff, Q2-Q3 files, edits and locks
    gsb_demo_login($users['preparer'], $orgId, $c1);
    $staff = array(
        array('901234570', 'GARCIA', 'JOSE', 'MANALO', 'VII', 38000, 10000, '2025-01-01'),
        array('901234571', 'NAVARRO', 'KRISTINE', 'ABELLA', 'VII', 29500, 10000, '2025-01-01'),
        array('901234572', 'TAN', 'BENEDICT', 'CO', 'VII', 65000, 12000, '2025-01-01'),
        array('901234573', 'SALAZAR', 'MA. CECILIA', 'RAMOS', 'VII', 24000, 8000, '2025-04-01'),
        array('901234574', 'VILLAFLOR', 'RICARDO', 'ESTRADA', 'VII', 52000, 12000, '2025-01-01'),
    );
    foreach ($staff as $i => $e) {
        gsb_demo_employee($orgId, $c1, $i % 2 ? $b1 : $b0, $users['preparer'], 'E-' . (104 + $i), $e);
    }
    foreach (array(4, 5, 6, 7, 8, 9) as $m) {
        gsb_demo_gen('1601EQ', 'S1', $b0, 2026, $m);
    }
    gsb_demo_gen('1601EQ', 'S1', $b1, 2026, 9);
    foreach (array(6, 7, 8, 9) as $m) {
        gsb_demo_gen('SLS', 'S1', $b0, 2026, $m);
        gsb_demo_gen('SLP', 'S1', $b0, 2026, $m);
    }
    gsb_demo_gen('SLI', 'S1', $b0, 2026, 2);
    // regenerate 1604-C now that all employees are in (supersedes the earlier file)
    gsb_demo_gen('1604C', 'S1', $b0, 2025, 1);
    $client = Ctx::client();
    $branch = DB::row('SELECT * FROM branches WHERE id = ?', array($b0));
    $sig = DB::val('SELECT id FROM signatories WHERE client_id = ?', array($c1));
    foreach (array('logistics', 'lessor', 'caterer') as $k) {
        Certificates::issue(Certificates::buildWithholding('2307', $client, $branch, $sup[$k], '2026-01-01', '2026-03-31'), array('signatory_id' => $sig, 'paper' => 'A4', 'branch_id' => $b0));
    }
    foreach (array('print', 'hardware', 'logistics', 'lessor') as $k) {
        Certificates::issue(Certificates::buildWithholding('2307', $client, $branch, $sup[$k], '2026-04-01', '2026-06-30'), array('signatory_id' => $sig, 'paper' => 'A4', 'branch_id' => $b0));
    }
    foreach (DB::col('SELECT id FROM compensation_records WHERE client_id = ? ORDER BY id LIMIT 1, 20', array($c1)) as $compId) {
        Certificates::issue(Certificates::build2316($client, $branch, $compId), array('signatory_id' => $sig, 'paper' => 'A4', 'branch_id' => $b0));
    }
    // a corrected sale (keeps a revision) and a voided duplicate purchase
    $sales = Records::module('sales');
    $row = DB::row('SELECT * FROM sales_entries WHERE client_id = ? AND period_month = ? ORDER BY id LIMIT 1', array($c1, '2026-08'));
    $d = array('period_month' => $row['period_month']);
    foreach ($sales['fields'] as $f) {
        $d[$f['key']] = $row[$f['key']];
    }
    $net = Money::fromDb($row['taxable_amount']) + 150000;
    $d['taxable_amount'] = Money::toPlain($net);
    $d['output_vat'] = Money::toPlain(Money::applyRate($net, 120000));
    Records::save($sales, $d, $row['id'], 'Invoice amount corrected to match the delivery receipt');
    $dup = gsb_demo_purchase($b0, $sup['print'], '2026-08-19', 1250000, 'services');
    Records::setVoid(Records::module('purchases'), $dup, true, 'Duplicate of an invoice already encoded');
    // Q1 closed by the reviewer
    gsb_demo_login($users['reviewer'], $orgId, $c1);
    foreach (array('2026-01', '2026-02', '2026-03') as $m) {
        foreach (array('sales', 'purchases', 'withholding') as $scope) {
            Workflow::lock($c1, $m, $scope, 'First quarter reviewed and closed');
        }
    }

    // ------------------------------------------------------------------ Banwa Agri-Ventures: Iloilo client fed by an Excel import
    $c3 = DB::insert('client_entities', array('organization_id' => $orgId, 'tin' => '333444555', 'taxpayer_type' => 'non_individual', 'registered_name' => 'BANWA AGRI-VENTURES INC',
        'trade_name' => 'BANWA FARMS', 'classification' => 'top_withholding_agent', 'vat_status' => 'vat', 'rdo_code' => '074',
        'address1' => 'LOT 7 DIVERSION RD BRGY SAN RAFAEL MANDURRIAO', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'email' => 'finance@banwa.example',
        'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12, 'status' => 'active', 'created_by' => $users['owner'], 'created_at' => now(), 'updated_at' => now()));
    $b3 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c3, 'branch_code' => '0000', 'name' => 'Head office, Iloilo City', 'is_head_office' => 1, 'rdo_code' => '074',
        'address1' => 'LOT 7 DIVERSION RD BRGY SAN RAFAEL MANDURRIAO', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'status' => 'active', 'created_at' => now()));
    $b3b = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c3, 'branch_code' => '0001', 'name' => 'Bacolod depot', 'is_head_office' => 0, 'rdo_code' => '077',
        'address1' => 'KM 4 BURGOS AVE BRGY TANGUB', 'address2' => 'BACOLOD CITY', 'zip_code' => '6100', 'status' => 'active', 'created_at' => now()));
    DB::insert('signatories', array('organization_id' => $orgId, 'client_id' => $c3, 'full_name' => 'Teresita M. Gonzaga', 'title' => 'Chief Finance Officer', 'tin' => '234-567-890-000',
        'is_default' => 1, 'status' => 'active', 'created_at' => now()));
    gsb_demo_login($users['preparer'], $orgId, $c3);
    $bs = array(
        'fert' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200300', 'registered_name' => 'PANAY FERTILIZER DISTRIBUTORS INC', 'address1' => '88 GEN LUNA ST', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC158')),
        'feeds' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200301', 'registered_name' => 'WESTERN VISAYAS FEEDMILL CORP', 'address1' => 'BRGY TABUC SUBA JARO', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC158')),
        'truck' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200302', 'registered_name' => 'DINAGYANG TRUCKING SERVICES INC', 'address1' => 'NATIONAL HIGHWAY BRGY UNGKA', 'address2' => 'PAVIA, ILOILO', 'zip_code' => '5001', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC160')),
        'guard' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200303', 'registered_name' => 'BANTAY SEGURIDAD AGENCY INC', 'address1' => '12 BONIFACIO DR', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC120')),
        'land' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200304', 'entity_type' => 'individual', 'last_name' => 'LEDESMA', 'first_name' => 'FRANCISCO', 'middle_name' => 'ARANETA', 'address1' => '40 LACSON ST', 'address2' => 'BACOLOD CITY', 'zip_code' => '6100', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WI100')),
        'vet' => gsb_demo_party($orgId, $c3, $users['preparer'], array('tin' => '310200305', 'entity_type' => 'individual', 'last_name' => 'ESPINOSA', 'first_name' => 'DANIELA', 'middle_name' => 'CRUZ', 'address1' => '5 QUEZON ST', 'address2' => 'ILOILO CITY', 'zip_code' => '5000', 'is_payee' => 1, 'default_atc' => 'WI010')),
    );
    $buyers = array(
        array('410300400', 'ILONGGO FRESH MARKETS INC', 'ILOILO CITY'), array('410300401', 'NEGROS FOOD TERMINAL CORP', 'BACOLOD CITY'),
        array('410300402', 'CAPIZ SEAFOOD AND PRODUCE INC', 'ROXAS CITY'), array('410300403', 'GUIMARAS MANGO EXPORTERS INC', 'JORDAN, GUIMARAS'),
        array('410300404', 'PAVIA COMMISSARY CORP', 'PAVIA, ILOILO'),
    );
    // January to June sales arrive as an Excel workbook built from the system's own template
    $type = Imports::type('sales');
    $xrows = array();
    for ($m = 1; $m <= 6; $m++) {
        foreach ($buyers as $i => $by) {
            $net = mt_rand(250, 1900) * 10000 + mt_rand(0, 3) * 2500;
            $vals = array('p_tin' => substr($by[0], 0, 3) . '-' . substr($by[0], 3, 3) . '-' . substr($by[0], 6), 'p_branch' => '0000', 'p_registered_name' => $by[1],
                'p_address2' => $by[2], 'client_branch' => $i === 1 ? '0001' : '0000', 'invoice_no' => sprintf('BSI-%02d%03d', $m, 101 + $i),
                'invoice_date' => sprintf('%02d/%02d/2026', $m, mt_rand(3, 26)), 'exempt_amount' => '0.00', 'zero_rated_amount' => '0.00',
                'taxable_amount' => Money::toPlain($net), 'output_vat' => Money::toPlain(Money::applyRate($net, 120000)), 'description' => 'Rice and vegetables');
            $r = array();
            foreach ($type['columns'] as $col) {
                $r[] = isset($vals[$col['key']]) ? $vals[$col['key']] : '';
            }
            $xrows[] = $r;
        }
    }
    $xlsx = XlsxWriter::build(array(array('name' => 'Data', 'columns' => $type['columns'], 'rows' => $xrows, 'freeze' => true)));
    $rel = 'uploads/demo/banwa-sales-jan-jun-2026.xlsx';
    if (!is_dir(dirname(storage_path($rel)))) {
        mkdir(dirname(storage_path($rel)), 0775, true);
    }
    file_put_contents(storage_path($rel), $xlsx);
    $job = DB::insert('import_jobs', array('organization_id' => $orgId, 'client_id' => $c3, 'branch_id' => $b3, 'import_type' => 'sales', 'original_filename' => 'Banwa sales Jan-Jun 2026.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $xlsx), 'sheet_name' => 'Data', 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $users['encoder'], 'created_at' => now()));
    $jobRow = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job));
    list($map) = Imports::suggestMapping($type, Imports::headersOf(Imports::readRows($jobRow), 1));
    DB::update('import_jobs', array('mapping_json' => json_encode($map), 'options_json' => json_encode(array('date_format' => 'mdy')), 'template_version' => Imports::TEMPLATE_VERSION), 'id = ?', array($job));
    DB::insert('column_mappings', array('organization_id' => $orgId, 'import_type' => 'sales', 'name' => 'Banwa sales ledger export', 'mapping_json' => json_encode($map),
        'created_by' => $users['encoder'], 'created_at' => now()));
    gsb_demo_login($users['encoder'], $orgId, $c3);
    Imports::prepare($job);
    do {
        $st = Imports::stageChunk($job, 200);
    } while (!$st['done']);
    gsb_demo_login($users['preparer'], $orgId, $c3);
    Imports::commit($job, 'all');
    // July to September sales encoded directly; purchases and withholding all year
    for ($m = 1; $m <= 9; $m++) {
        $ym = sprintf('2026-%02d', $m);
        if ($m >= 7) {
            foreach ($buyers as $i => $by) {
                $cp = DB::val('SELECT id FROM counterparties WHERE client_id = ? AND tin = ?', array($c3, $by[0]));
                gsb_demo_sale($i === 1 ? $b3b : $b3, $cp, gsb_demo_day($ym), mt_rand(250, 1900) * 10000, sprintf('BSI-%02d%03d', $m, 101 + $i));
            }
        }
        foreach (array('fert' => array('goods', 'WC158', 'Fertilizer'), 'feeds' => array('goods', 'WC158', 'Animal feeds'), 'truck' => array('services', 'WC160', 'Hauling'),
                     'guard' => array('services', 'WC120', 'Security services')) as $k => $x) {
            $net = mt_rand(60, 420) * 1000 * 100;
            $date = gsb_demo_day($ym);
            gsb_demo_purchase($b3, $bs[$k], $date, $net, $x[0]);
            gsb_demo_wh($c3, $b3, $bs[$k], $x[1], $date, Money::toPlain($net), $x[2], sprintf('CV-%02d-%s', $m, strtoupper($k)));
        }
        gsb_demo_wh($c3, $b3b, $bs['land'], 'WI100', $ym . '-05', '45000.00', 'Depot rental', sprintf('CV-%02d-RENT', $m));
        if ($m % 3 === 0) {
            gsb_demo_wh($c3, $b3, $bs['vet'], 'WI010', $ym . '-25', '18000.00', 'Veterinary consultancy', sprintf('CV-%02d-VET', $m));
        }
    }
    foreach (array(4, 5, 6, 7, 8, 9) as $m) {
        gsb_demo_gen('1601EQ', 'S1', $b3, 2026, $m);
    }
    foreach (array(6, 9) as $m) {
        gsb_demo_gen('SLS', 'S1', $b3, 2026, $m);
        gsb_demo_gen('SLP', 'S1', $b3, 2026, $m);
    }
    $client3 = Ctx::client();
    $br3 = DB::row('SELECT * FROM branches WHERE id = ?', array($b3));
    $sig3 = DB::val('SELECT id FROM signatories WHERE client_id = ?', array($c3));
    foreach (array('fert', 'truck', 'guard') as $k) {
        Certificates::issue(Certificates::buildWithholding('2307', $client3, $br3, $bs[$k], '2026-04-01', '2026-06-30'), array('signatory_id' => $sig3, 'paper' => 'FOLIO', 'branch_id' => $b3));
    }

    // ------------------------------------------------------------------ Tala Bakeshop: a non-VAT individual whose customers withhold (SAWT)
    $c4 = DB::insert('client_entities', array('organization_id' => $orgId, 'tin' => '444555666', 'taxpayer_type' => 'individual', 'last_name' => 'MENDOZA', 'first_name' => 'CLARISSA',
        'middle_name' => 'DIZON', 'trade_name' => 'TALA BAKESHOP', 'classification' => 'regular', 'vat_status' => 'non_vat', 'rdo_code' => '083',
        'address1' => '21 TABUNOK RD BRGY TABUNOK', 'address2' => 'TALISAY CITY, CEBU', 'zip_code' => '6045', 'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12,
        'status' => 'active', 'created_by' => $users['owner'], 'created_at' => now(), 'updated_at' => now()));
    $b4 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c4, 'branch_code' => '0000', 'name' => 'Bakeshop', 'is_head_office' => 1, 'rdo_code' => '083',
        'address1' => '21 TABUNOK RD BRGY TABUNOK', 'address2' => 'TALISAY CITY, CEBU', 'zip_code' => '6045', 'status' => 'active', 'created_at' => now()));
    gsb_demo_login($users['preparer'], $orgId, $c4);
    $payors = array(
        gsb_demo_party($orgId, $c4, $users['preparer'], array('tin' => '400100300', 'registered_name' => 'VISAYAS MEGA MART INC', 'address2' => 'CEBU CITY', 'is_payor' => 1)),
        gsb_demo_party($orgId, $c4, $users['preparer'], array('tin' => '400100302', 'registered_name' => 'MACTAN BAYVIEW HOTEL INC', 'address2' => 'LAPU-LAPU CITY', 'is_payor' => 1)),
        gsb_demo_party($orgId, $c4, $users['preparer'], array('tin' => '111222333', 'registered_name' => 'KALAMANSI TRADING CORPORATION', 'address2' => 'CEBU CITY', 'is_payor' => 1)),
    );
    $won = Records::module('withheld_on_us');
    for ($m = 1; $m <= 9; $m++) {
        foreach ($payors as $i => $pid) {
            if ($i === 2 && $m < 4) {
                continue;
            }
            list($d, $err) = Records::validate($won, array('branch_id' => $b4, 'counterparty_id' => $pid, 'atc_code' => 'WI158', 'payment_date' => gsb_demo_day(sprintf('2026-%02d', $m)),
                'income_amount' => Money::toPlain(mt_rand(40, 260) * 100000), 'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'reference_no' => sprintf('2307-%02d-%d', $m, $i + 1)), $c4);
            if ($err) {
                throw new RuntimeException('Demo SAWT failed: ' . implode('; ', $err));
            }
            Records::save($won, $d);
        }
    }
    gsb_demo_gen('SAWT', 'S1', $b4, 2026, 1, '1701Q');
    gsb_demo_gen('SAWT', 'S1', $b4, 2026, 2, '1701Q');
    gsb_demo_gen('SAWT', 'S1', $b4, 2026, 3, '1701Q');

    // ------------------------------------------------------------------ the public validator sample, with its genuine BIR validator report
    $c5 = DB::insert('client_entities', array('organization_id' => $orgId, 'tin' => '684785510', 'taxpayer_type' => 'non_individual', 'registered_name' => 'VALIDATOR SAMPLE EMPLOYER',
        'trade_name' => 'PUBLIC 1604-C SAMPLE', 'classification' => 'regular', 'vat_status' => 'vat', 'rdo_code' => '050', 'address1' => 'SAMPLE ADDRESS', 'address2' => 'MAKATI CITY',
        'zip_code' => '1200', 'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12, 'status' => 'active', 'created_by' => $users['owner'], 'created_at' => now(), 'updated_at' => now()));
    $b5 = DB::insert('branches', array('organization_id' => $orgId, 'client_id' => $c5, 'branch_code' => '0000', 'name' => 'Head office', 'is_head_office' => 1, 'rdo_code' => '050',
        'address2' => 'MAKATI CITY', 'zip_code' => '1200', 'status' => 'active', 'created_at' => now()));
    gsb_demo_login($users['preparer'], $orgId, $c5);
    $eid = DB::insert('employees', array('organization_id' => $orgId, 'client_id' => $c5, 'branch_id' => $b5, 'employee_no' => 'S-001', 'tin' => '901234567', 'tin_branch' => '0000',
        'last_name' => 'REYES', 'first_name' => 'MARIA', 'middle_name' => 'SANTOS', 'region_code' => 'NCR', 'nationality' => 'FILIPINO', 'employment_status' => 'R',
        'hired_on' => '2020-01-06', 'status' => 'active', 'source' => 'manual', 'created_by' => $users['preparer'], 'created_at' => now(), 'updated_at' => now()));
    list($d, $err) = Payroll::compValidate(array('tax_year' => 2025, 'schedule_code' => 'S1', 'employee_id' => $eid, 'branch_id' => $b5, 'region_code' => 'NCR', 'employment_from' => '2025-01-01',
        'employment_to' => '2025-12-31', 'pres_gross' => '532000.00', 'pres_nt_smw' => '0', 'pres_nt_13th' => '40000.00', 'pres_nt_deminimis' => '12000.00', 'pres_nt_contrib' => '30187.50',
        'pres_nt_other' => '0', 'pres_nt_total' => '82187.50', 'pres_tx_basic' => '449812.50', 'pres_tx_13th' => '0', 'pres_tx_other' => '0', 'pres_tx_total' => '449812.50',
        'taxable_comp_total' => '449812.50', 'net_taxable_comp' => '449812.50', 'tax_due' => '32462.50', 'pres_withheld_jan_nov' => '29000.00', 'pres_withheld_dec' => '3462.50',
        'over_withheld_refunded' => '0', 'tax_withheld_adjusted' => '32462.50', 'nationality' => 'FILIPINO', 'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y'), $c5);
    if ($err) {
        throw new RuntimeException('Demo sample compensation failed: ' . implode('; ', $err));
    }
    Payroll::compSave($d);
    $sampleFile = gsb_demo_gen('1604C', 'S1', $b5, 2025, 1);
    $fx = dirname(__DIR__) . '/tests/fixtures/';
    $sample = DB::row('SELECT * FROM dat_files WHERE id = ?', array($sampleFile));
    if ($sample['sha256'] !== hash_file('sha256', $fx . '6847855100000123120251604C.DAT')) {
        throw new RuntimeException('The sample 1604-C no longer reproduces the validator-passed bytes.');
    }
    $GLOBALS['_FILES_TEST'] = true;
    $tmpTxt = tempnam(sys_get_temp_dir(), 'gsbtxt');
    $tmpDat = tempnam(sys_get_temp_dir(), 'gsbdat');
    copy($fx . '6847855100000123120251604C.TXT', $tmpTxt);
    copy($fx . '6847855100000123120251604C.DAT', $tmpDat);
    $evId = Evidence::record($sampleFile, array('validator_version' => '7.4', 'executed_on' => date('Y-m-d'), 'outcome' => '', 'patch_date' => '', 'report_type' => '',
        'notes' => 'Report published with the public sample (github.com/ReyJrFerrer/BIR-dat). The run date is not printed on the report, so the recording date is used.'),
        array('name' => '6847855100000123120251604C.TXT', 'tmp_name' => $tmpTxt, 'error' => 0, 'size' => filesize($tmpTxt)),
        array('name' => '6847855100000123120251604C.DAT', 'tmp_name' => $tmpDat, 'error' => 0, 'size' => filesize($tmpDat)));
    gsb_demo_login($users['reviewer'], $orgId, $c5);
    Evidence::review($evId, 'accept', 'Report TIN, form and year match; DAT bytes match the generated file.');
    gsb_demo_login($users['preparer'], $orgId, $c5);
    Workflow::request('dat_files', $sampleFile, 'approve_file', 'Validator evidence accepted; ready for release.');
    $specId = DB::val("SELECT id FROM layout_specs WHERE form_code = '1604C' AND schedule_code = 'S1' ORDER BY id LIMIT 1");
    Workflow::request('layout_specs', $specId, 'approve_spec', 'File made with this layout passed the BIR validator (evidence #' . $evId . ').', array('evidence_id' => $evId));

    // ------------------------------------------------------------------ users with narrower access, sign-in history, invites, an API token
    $ca = $user('clientadmin@demo.local', 'Noel Batungbakal');
    $mid = DB::insert('memberships', array('user_id' => $ca, 'organization_id' => $orgId, 'role_code' => 'client_admin', 'all_clients' => 0, 'status' => 'active', 'created_at' => now()));
    DB::insert('membership_scopes', array('membership_id' => $mid, 'client_id' => $c3, 'branch_id' => null));
    $sg = $user('signatory@demo.local', 'Ramon Ocampo');
    $mid = DB::insert('memberships', array('user_id' => $sg, 'organization_id' => $orgId, 'role_code' => 'signatory', 'all_clients' => 0, 'status' => 'active', 'created_at' => now()));
    DB::insert('membership_scopes', array('membership_id' => $mid, 'client_id' => $c1, 'branch_id' => null));
    DB::update('signatories', array('user_id' => $sg), 'client_id = ?', array($c1));
    gsb_demo_login($users['owner'], $orgId, $c3);
    Mailer::send('clientadmin@demo.local', 'You were added to Bayanihan Accounting Partners', "Hello Noel,\n\nYou now have access to BANWA AGRI-VENTURES INC in G-SMaRT BIR.\n");
    Mailer::send('signatory@demo.local', 'You were added to Bayanihan Accounting Partners', "Hello Ramon,\n\nYou can now review certificates for KALAMANSI TRADING CORPORATION in G-SMaRT BIR.\n");
    $plain = 'gsb_' . str_repeat('0123456789abcdef', 3);
    DB::insert('api_tokens', array('organization_id' => $orgId, 'client_id' => $c1, 'user_id' => $users['owner'], 'name' => 'Demo POS link', 'token_hash' => hash('sha256', $plain),
        'token_hint' => substr($plain, -6), 'scopes' => 'records:read,files:read', 'created_at' => now()));
    $t = time();
    foreach (array(array('owner@demo.local', 1, 86400 * 3), array('preparer@demo.local', 1, 86400 * 2), array('preparer@demo.local', 0, 86400 + 600),
                 array('preparer@demo.local', 1, 86400), array('reviewer@demo.local', 1, 7200), array('encoder@demo.local', 1, 3600)) as $a) {
        DB::insert('login_attempts', array('email' => $a[0], 'ip' => '192.168.1.' . (20 + strlen($a[0])), 'success' => $a[1], 'created_at' => date('Y-m-d H:i:s', $t - $a[2])));
    }

    // ------------------------------------------------------------------ a second tenant: a company that files for itself
    $org2 = DB::insert('organizations', array('name' => 'Puerto Digital Solutions Inc', 'org_type' => 'company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()));
    $co = $user('company@demo.local', 'Isabel Aquino');
    DB::insert('memberships', array('user_id' => $co, 'organization_id' => $org2, 'role_code' => 'firm_owner', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
    $hr = $user('payroll@demo.local', 'Mark Anthony Dizon');
    DB::insert('memberships', array('user_id' => $hr, 'organization_id' => $org2, 'role_code' => 'preparer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
    DB::insert('memberships', array('user_id' => $users['reviewer'], 'organization_id' => $org2, 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
    $c6 = DB::insert('client_entities', array('organization_id' => $org2, 'tin' => '555666777', 'taxpayer_type' => 'non_individual', 'registered_name' => 'PUERTO DIGITAL SOLUTIONS INC',
        'trade_name' => 'PUERTO DIGITAL', 'classification' => 'regular', 'vat_status' => 'vat', 'rdo_code' => '050', 'address1' => '18F TOWER ONE AYALA AVE', 'address2' => 'MAKATI CITY',
        'zip_code' => '1226', 'email' => 'tax@puerto.example', 'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12, 'status' => 'active', 'created_by' => $co, 'created_at' => now(), 'updated_at' => now()));
    $b6 = DB::insert('branches', array('organization_id' => $org2, 'client_id' => $c6, 'branch_code' => '0000', 'name' => 'Makati head office', 'is_head_office' => 1, 'rdo_code' => '050',
        'address1' => '18F TOWER ONE AYALA AVE', 'address2' => 'MAKATI CITY', 'zip_code' => '1226', 'status' => 'active', 'created_at' => now()));
    DB::insert('signatories', array('organization_id' => $org2, 'client_id' => $c6, 'full_name' => 'Isabel R. Aquino', 'title' => 'President', 'tin' => '345-678-901-000', 'user_id' => $co,
        'is_default' => 1, 'status' => 'active', 'created_at' => now()));
    gsb_demo_login($hr, $org2, $c6);
    $team = array(
        array('920100100', 'AQUINO', 'PAOLO', 'REYES', 'NCR', 145000, 12000, '2025-01-01'), array('920100101', 'BAUTISTA', 'JANELLE', 'CRUZ', 'NCR', 98000, 12000, '2025-01-01'),
        array('920100102', 'CASTILLO', 'MIGUEL', 'SANTOS', 'NCR', 72000, 12000, '2025-01-01'), array('920100103', 'DE GUZMAN', 'ANDREA', 'LOPEZ', 'NCR', 58000, 10000, '2025-01-01'),
        array('920100104', 'ESGUERRA', 'RAFAEL', 'TORRES', 'NCR', 46000, 10000, '2025-01-01'), array('920100105', 'FLORES', 'BEATRIZ', 'MENDOZA', 'NCR', 41000, 10000, '2025-02-01'),
        array('920100106', 'GONZALES', 'LUIS', 'MARIANO', 'NCR', 36500, 8000, '2025-01-01'), array('920100107', 'HERNANDEZ', 'PATRICIA', 'VELASCO', 'NCR', 33000, 8000, '2025-06-01'),
        array('920100108', 'IGNACIO', 'CARLOS', 'DOMINGO', 'NCR', 28000, 8000, '2025-01-01'), array('920100109', 'JAVIER', 'SOFIA', 'PASCUAL', 'NCR', 26000, 8000, '2025-09-01'),
    );
    foreach ($team as $i => $e) {
        gsb_demo_employee($org2, $c6, $b6, $hr, 'PD-' . (1001 + $i), $e);
    }
    $vend = array(
        'office' => gsb_demo_party($org2, $c6, $hr, array('tin' => '320300400', 'registered_name' => 'AYALA TOWER LEASING CORP', 'address2' => 'MAKATI CITY', 'zip_code' => '1226', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC100')),
        'cloud' => gsb_demo_party($org2, $c6, $hr, array('tin' => '320300401', 'registered_name' => 'MAHARLIKA DATA CENTERS INC', 'address2' => 'TAGUIG CITY', 'zip_code' => '1634', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC120')),
        'dev' => gsb_demo_party($org2, $c6, $hr, array('tin' => '320300402', 'entity_type' => 'individual', 'last_name' => 'OCAMPO', 'first_name' => 'JERICHO', 'middle_name' => 'LIM', 'address2' => 'PASIG CITY', 'zip_code' => '1600', 'is_payee' => 1, 'default_atc' => 'WI010')),
        'audit' => gsb_demo_party($org2, $c6, $hr, array('tin' => '320300403', 'registered_name' => 'SANTOS LIM AND CO CPAS', 'address2' => 'MAKATI CITY', 'zip_code' => '1200', 'is_supplier' => 1, 'is_payee' => 1, 'default_atc' => 'WC010')),
    );
    $clients6 = array(array('420400500', 'MANILA BAY BANKING CORP'), array('420400501', 'LUZON POWER DISTRIBUTION INC'), array('420400502', 'PHILIPPINE ISLANDS RETAIL GROUP INC'));
    $cust6 = array();
    foreach ($clients6 as $c) {
        $cust6[] = gsb_demo_party($org2, $c6, $hr, array('tin' => $c[0], 'registered_name' => $c[1], 'address2' => 'METRO MANILA', 'is_customer' => 1));
    }
    foreach (array(2025, 2026) as $y) {
        for ($m = 1; $m <= ($y === 2025 ? 12 : 9); $m++) {
            $ym = sprintf('%d-%02d', $y, $m);
            gsb_demo_wh($c6, $b6, $vend['office'], 'WC100', $ym . '-05', '185000.00', 'Office rent', sprintf('PD-%s-RENT', str_replace('-', '', $ym)));
            gsb_demo_wh($c6, $b6, $vend['dev'], 'WI010', $ym . '-28', $y === 2025 ? '60000.00' : '65000.00', 'Software development retainer', sprintf('PD-%s-DEV', str_replace('-', '', $ym)));
            if ($m % 3 === 0) {
                gsb_demo_wh($c6, $b6, $vend['cloud'], 'WC120', $ym . '-15', Money::toPlain(mt_rand(180, 320) * 100000), 'Managed hosting', sprintf('PD-%s-HOST', str_replace('-', '', $ym)));
            }
            if ($y === 2026 && $m >= 7) {
                gsb_demo_purchase($b6, $vend['office'], $ym . '-05', 18500000, 'services');
                gsb_demo_purchase($b6, $vend['cloud'], gsb_demo_day($ym), mt_rand(180, 320) * 100000, 'services');
                foreach ($cust6 as $i => $cp) {
                    gsb_demo_sale($b6, $cp, gsb_demo_day($ym), mt_rand(800, 2600) * 100000, sprintf('PD-SI-%02d%02d', $m, $i + 1));
                }
            }
        }
    }
    gsb_demo_wh($c6, $b6, $vend['audit'], 'WC010', '2026-04-20', '350000.00', 'Annual audit fee', 'PD-202604-AUD');
    gsb_demo_gen('1604C', 'S1', $b6, 2025, 1);
    gsb_demo_gen('1604E', 'S4', $b6, 2025, 1);
    foreach (array(7, 8, 9) as $m) {
        gsb_demo_gen('1601EQ', 'S1', $b6, 2026, $m);
    }
    foreach (array(7, 8, 9) as $m) {
        gsb_demo_gen('SLS', 'S1', $b6, 2026, $m);
        gsb_demo_gen('SLP', 'S1', $b6, 2026, $m);
    }
    $client6 = Ctx::client();
    $sig6 = DB::val('SELECT id FROM signatories WHERE client_id = ?', array($c6));
    foreach (DB::col('SELECT id FROM compensation_records WHERE client_id = ? ORDER BY id', array($c6)) as $compId) {
        Certificates::issue(Certificates::build2316($client6, DB::row('SELECT * FROM branches WHERE id = ?', array($b6)), $compId), array('signatory_id' => $sig6, 'paper' => 'A4', 'branch_id' => $b6));
    }
}
