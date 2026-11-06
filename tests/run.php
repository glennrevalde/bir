<?php
/**
 * G-SMaRT BIR automated test suite.
 *
 *   php tests/run.php
 *
 * Runs against a separate database named <config db name>_test, which it drops and recreates.
 * The configured MySQL user needs CREATE/DROP DATABASE rights on that name.
 * Exit code 0 when every test passes.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/bir_v74_1604c.php';
require __DIR__ . '/bir_v74_sawt.php';
require __DIR__ . '/bir_v74_withholding.php';
require dirname(__DIR__) . '/tools/seed_lib.php';

$testDb = config('db.name') . '_test';
$c = config('db');
$root = new PDO('mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';charset=utf8', $c['user'], $c['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$root->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
$root->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8');
$GLOBALS['GSB_CONFIG']['db']['name'] = $testDb;
$GLOBALS['GSB_CONFIG']['storage_path'] = sys_get_temp_dir() . '/gsb-test-' . getmypid();
@mkdir(storage_path('tmp'), 0775, true);
foreach (array('dat', 'pdf', 'evidence', 'backups', 'uploads', 'logs') as $d) {
    @mkdir(storage_path($d), 0775, true);
}
DB::reset();
gsb_run_sql_file(dirname(__DIR__) . '/database/schema.sql');
gsb_seed_reference('super@test.local', 'Test@123456');

$T = array('pass' => 0, 'fail' => 0, 'section' => '');
function section($name)
{
    global $T;
    $T['section'] = $name;
    echo "\n" . $name . "\n";
}
function ok($cond, $label, $detail = '')
{
    global $T;
    if ($cond) {
        $T['pass']++;
        echo "  ok    " . $label . "\n";
    } else {
        $T['fail']++;
        echo "  FAIL  " . $label . ($detail !== '' ? "\n        " . $detail : '') . "\n";
    }
}
function throws($fn, $label, $contains = '')
{
    try {
        $fn();
        ok(false, $label, 'no exception');
    } catch (Exception $e) {
        ok($contains === '' || stripos($e->getMessage(), $contains) !== false, $label, $e->getMessage());
    }
}
function login_as($userId, $orgId, $clientId)
{
    $_SESSION = array('uid' => $userId, 'org_id' => $orgId, 'client_id' => $clientId);
    Auth::setUserForCli(DB::row('SELECT * FROM users WHERE id = ?', array($userId)));
    Ctx::reset();
}
function tmp_file($bytes)
{
    $f = tempnam(sys_get_temp_dir(), 'gsbx');
    file_put_contents($f, $bytes);
    return $f;
}

function zip_part($bytes, $name)
{
    $z = new ZipArchive();
    $z->open(tmp_file($bytes));
    return (string)$z->getFromName($name);
}

function make_org($name, $tin, $role = 'firm_owner', $clientName = null)
{
    $org = DB::insert('organizations', array('name' => $name, 'org_type' => 'firm', 'status' => 'active', 'created_at' => now()));
    $u = DB::insert('users', array('email' => strtolower(preg_replace('/\W/', '', $name)) . '@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => $name . ' owner',
        'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
    DB::insert('memberships', array('user_id' => $u, 'organization_id' => $org, 'role_code' => $role, 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
    $c = DB::insert('client_entities', array('organization_id' => $org, 'tin' => $tin, 'taxpayer_type' => 'non_individual', 'registered_name' => $clientName ?: 'TEST CLIENT ' . strtoupper($name),
        'rdo_code' => '081', 'address1' => '1 TEST ST', 'address2' => 'CEBU CITY', 'fiscal_month_end' => 12, 'status' => 'active', 'created_at' => now()));
    $b = DB::insert('branches', array('organization_id' => $org, 'client_id' => $c, 'branch_code' => '0000', 'name' => 'Head office', 'is_head_office' => 1, 'rdo_code' => '081', 'status' => 'active', 'created_at' => now()));
    return array('org' => $org, 'user' => $u, 'client' => $c, 'branch' => $b);
}
DB::q('UPDATE tax_codes SET status = ?', array('approved'));

// =====================================================================
section('Money: exact centavo arithmetic');
$p = Money::parse('1,234.56');
ok($p['cents'] === 123456, 'parses thousands separators');
ok(Money::parse('(500.25)')['cents'] === -50025, 'parentheses mean negative');
ok(Money::parse('1.005')['cents'] === 101 && Money::parse('1.005')['rounded'], 'rounds half-up at the third decimal and reports it');
ok(Money::parse('1.23E+5')['cents'] === 12300000, 'expands scientific notation');
ok(!Money::parse('12a')['ok'], 'rejects text');
ok(Money::toPlain(-5) === '-0.05' && Money::toPlain(100000) === '1000.00', 'formats plain amounts');
ok(Money::toTrim(100000) === '1000' && Money::toTrim(100050) === '1000.50', 'trim format');
ok(Money::applyRate(10000000, Money::rateUnits('1')) === 100000, '1% of 100,000.00 = 1,000.00');
ok(Money::applyRate(5316450, Money::rateUnits('2')) === 106329, '2% of 53,164.50 = 1,063.29');
ok(Money::applyRate(333, Money::rateUnits('15')) === 50, '15% of 3.33 rounds to 0.50');
ok(Money::applyRate(999999999999999, 1000000) === 999999999999999, '100% of the largest amount does not overflow');
$okAll = true;
mt_srand(42);
for ($i = 0; $i < 2000; $i++) {
    $c = mt_rand(0, 999999999);
    $u = mt_rand(0, 300000);
    $exact = intdiv($c * $u + 500000, 1000000);
    if (Money::applyRate($c, $u) !== $exact) {
        $okAll = false;
        break;
    }
}
ok($okAll, 'applyRate matches exact integer arithmetic on 2,000 random cases');
ok(Money::rateFormat(10000, 'pct2') === '1.00' && Money::rateFormat(10000, 'frac2') === '0.01', 'rate formats (percent and fraction)');

// =====================================================================
section('TIN and branch handling');
$t = Tin::parse('123-456-789');
ok($t['ok'] && $t['tin'] === '123456789' && $t['branch'] === null, 'dashed 9-digit TIN');
$t = Tin::parse('1234567890001');
ok($t['ok'] && $t['branch'] === '0001', '13 digits split into TIN and 4-digit branch');
ok(!Tin::parse('1.23457E+11')['ok'], 'rejects scientific notation');
ok(!Tin::parse('12345678', true)['ok'], 'rejects 8-digit numeric Excel cell (lost leading zero)');
ok(Tin::parse('012345678')['tin'] === '012345678', 'keeps leading zeros');
ok(Tin::branch('1') === '0001' && Tin::branch('') === '0000', 'pads branch codes');

// =====================================================================
section('1604-C writer reproduces a validator-passed v7.4 file byte for byte');
$specId = DB::val("SELECT id FROM layout_specs WHERE form_code = '1604C' AND schedule_code = 'S1'");
$spec = Specs::load($specId);
$row = array('tin' => '901234567', 'tin_branch' => '0000', 'last_name' => 'REYES', 'first_name' => 'MARIA', 'middle_name' => 'SANTOS', 'region_code' => 'NCR',
    'employment_from' => '2025-01-01', 'employment_to' => '2025-12-31', 'nationality' => 'FILIPINO', 'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y');
foreach (Records::compFields() as $fields) {
    foreach ($fields as $k => $l) {
        $row[$k] = 0;
    }
}
$set = array('pres_gross' => '532000', 'pres_nt_13th' => '40000', 'pres_nt_deminimis' => '12000', 'pres_nt_contrib' => '30187.50', 'pres_nt_total' => '82187.50',
    'pres_tx_basic' => '449812.50', 'pres_tx_total' => '449812.50', 'taxable_comp_total' => '449812.50', 'net_taxable_comp' => '449812.50', 'tax_due' => '32462.50',
    'pres_withheld_jan_nov' => '29000', 'pres_withheld_dec' => '3462.50', 'tax_withheld_adjusted' => '32462.50');
foreach ($set as $k => $v) {
    $row[$k] = Money::fromDb($v);
}
$row['tax_year'] = 2025;
$row['actual_withheld'] = $row['prev_tax_withheld'] + $row['tax_withheld_adjusted'] + $row['pera_credit'];
$agent = array('tin' => '684785510', 'branch' => '0000');
$period = array('from' => '2025-01-01', 'to' => '2025-12-31');
$w = DatWriter::make($spec, array('agent' => $agent, 'period' => $period, 'rows' => array($row), 'vars' => array()));
$fixture = file_get_contents(__DIR__ . '/fixtures/6847855100000123120251604C.DAT');
ok($w['filename'] === '6847855100000123120251604C.DAT', 'filename matches the validated file');
ok(hash('sha256', $w['bytes']) === '59892d6a782fb46aa44060ec9ca74db5ae3a3862eb247513908b8bb6a519c788', 'SHA-256 equals the recorded hash of the validated file');
ok($w['bytes'] === $fixture, 'bytes equal the fixture');
ok(!Checks::compArithmetic($row), 'fixture amounts pass the arithmetic checks');
$s = Checks::structure($spec, $w['bytes'], $w['filename'], $agent, $period, array($row));
ok(!$s['issues'], 'structural check passes on the generated bytes', json_encode($s['issues']));
$tampered = str_replace(',532000.00,0.00,40000.00,12000.00,30187.50,0.00,82187.50,449812.50,0.00,0.00,449812.50,449812.50,449812.50,32462.50,0.00,29000.00,3462.50,0.00,32462.50,0.00',
    ',532000.00,0.00,40000.00,12000.00,30187.50,0.00,82187.50,449812.50,0.00,0.00,449812.50,449812.50,449812.50,32462.50,0.00,29000.00,3462.51,0.00,32462.50,0.00', $w['bytes']);
$s = Checks::structure($spec, $tampered, $w['filename'], $agent, $period, array($row));
$codes = array_map(function ($i) { return $i['code']; }, $s['issues']);
ok(in_array('CONTROL_TOTAL', $codes, true), 'structural check catches a control total that disagrees with the details');
$ev = Evidence::parseReport(file_get_contents(__DIR__ . '/fixtures/6847855100000123120251604C.TXT'));
ok($ev['tin'] === '684785510' && $ev['form'] === '1604C' && $ev['no_errors'] && !$ev['errors'], 'parses the official validator report fixture');

// =====================================================================
section('Writer rules: text normalisation, widths, round trip');
list($v, $w2, $e2) = DatWriter::cleanText("Peña's Café", 'name');
ok($v === 'PENAS CAFE' && count($w2) === 2 && !$e2, 'N-tilde, accents and apostrophes normalised with warnings');
list($v, $w2, $e2) = DatWriter::cleanText('A/B CORP', 'name');
ok($e2, 'slash in a name is an error, not silently removed');
list($v, $w2, $e2) = DatWriter::cleanText('Unit 5 #12 Rizal St', 'addr');
ok($v === 'UNIT 5 #12 RIZAL ST' && !$e2, 'addresses keep # and /');
$qapSpec = Specs::resolve('1601EQ', 'S1', null, '2026-01-01', '2026-01-31');
$rows = array(
    array('tin' => '300100200', 'branch' => '0000', 'registered_name' => 'ALPHA CORP', 'last_name' => '', 'first_name' => '', 'middle_name' => '', 'atc' => 'WC158', 'rate' => '1.0000', 'income' => 10000000, 'tax' => 100000, 'source_ids' => array(1)),
    array('tin' => '300100201', 'branch' => '0001', 'registered_name' => '', 'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_name' => '', 'atc' => 'WI010', 'rate' => '5.0000', 'income' => 2000000, 'tax' => 100000, 'source_ids' => array(2)),
);
$ag = array('tin' => '111222333', 'branch' => '0000', 'display_name' => 'TEST CORP', 'rdo' => '081');
$per = array('from' => '2026-01-01', 'to' => '2026-01-31');
$w3 = DatWriter::make($qapSpec, array('agent' => $ag, 'period' => $per, 'rows' => $rows, 'vars' => array()));
ok($w3['filename'] === '11122233300000120261601EQ.DAT', 'QAP filename TIN+branch+MMYYYY+form');
ok(substr($w3['bytes'], -2) === "\r\n" && substr_count($w3['bytes'], "\r\n") === 4, 'CRLF line endings with trailing line break');
$parsed = DatReader::parse($w3['bytes'], $qapSpec);
ok(count($parsed['rows']) === 2 && $parsed['rows'][1]['last_name'] === 'DELA CRUZ' && $parsed['rows'][0]['income'] === 10000000, 'independent parser reads back names and amounts');
ok(!Checks::structure($qapSpec, $w3['bytes'], $w3['filename'], $ag, $per, $rows)['issues'], 'QAP structure check passes');
ok(preg_match(Specs::filenameRegex('{TIN}{BRANCH}{MM}{YYYY}1601EQ.DAT'), '11122233300000120261601EQ.DAT', $m) && $m['tin'] === '111222333' && $m['mm'] === '01', 'filename regex recovers tokens');
$long = $rows;
$long[0]['registered_name'] = str_repeat('X', 60);
$w4 = DatWriter::make($qapSpec, array('agent' => $ag, 'period' => $per, 'rows' => $long, 'vars' => array()));
$hasWidth = false;
foreach ($w4['issues'] as $i) {
    $hasWidth = $hasWidth || $i['code'] === 'WIDTH';
}
ok($hasWidth, 'a name longer than the layout allows is an error, never truncated');

// =====================================================================
section('Records: validation, locks, revisions');
$A = make_org('Alpha', '111222333');
login_as($A['user'], $A['org'], $A['client']);
$cp = DB::insert('counterparties', array('organization_id' => $A['org'], 'client_id' => $A['client'], 'tin' => '300100200', 'branch_code' => '0000', 'entity_type' => 'non_individual',
    'registered_name' => 'ALPHA SUPPLIER INC', 'is_payee' => 1, 'status' => 'active', 'created_at' => now()));
$cpInd = DB::insert('counterparties', array('organization_id' => $A['org'], 'client_id' => $A['client'], 'tin' => '300100201', 'branch_code' => '0000', 'entity_type' => 'individual',
    'last_name' => 'SANTOS', 'first_name' => 'ANA', 'is_payee' => 1, 'status' => 'active', 'created_at' => now()));
$wh = Records::module('withholding');
$base = array('branch_id' => $A['branch'], 'counterparty_id' => $cp, 'tax_kind' => 'EWT', 'atc_code' => 'WC158', 'payment_date' => '2026-01-15', 'income_amount' => '100,000.00',
    'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => 'CV-1');
list($d, $e) = Records::validate($wh, $base, $A['client']);
ok(!$e && $d['tax_withheld'] === '1000.00' && $d['tax_rate'] === '1.0000', 'rate and tax filled from the ATC');
list($d2, $e2) = Records::validate($wh, array_merge($base, array('tax_withheld' => '1500')), $A['client']);
ok(isset($e2['tax_withheld']), 'a tax amount that disagrees with the rate needs a reason');
list($d3, $e3) = Records::validate($wh, array_merge($base, array('atc_code' => 'WI010')), $A['client']);
ok(isset($e3['atc_code']), 'an individual ATC is refused for a corporate payee');
list($d4, $e4) = Records::validate($wh, array_merge($base, array('atc_code' => 'XX999')), $A['client']);
ok(isset($e4['atc_code']), 'unknown ATC is refused');
$id = Records::save($wh, $d);
ok($id > 0, 'saves a withholding record');
Records::save($wh, array_merge($d, array('income_amount' => '200000.00', 'tax_withheld' => '2000.00')), $id, 'correction');
ok((int)DB::val('SELECT COUNT(*) FROM revisions WHERE subject_type = ? AND subject_id = ?', array('withholding_entries', $id)) === 1, 'editing keeps a revision with the old values');
Workflow::lock($A['client'], '2026-01', 'withholding', 'test lock');
throws(function () use ($wh, $d) { Records::save($wh, $d); }, 'saving into a locked month is refused', 'locked');
throws(function () use ($wh, $id) { Records::setVoid($wh, $id, true, 'x'); }, 'voiding in a locked month is refused', 'locked');

// =====================================================================
section('Tenancy and maker-checker');
$B = make_org('Beta', '444555666');
ok(Ctx::clientAllowed($B['client']) === null, 'a member cannot reach another organization\'s client');
list($dx, $ex) = Records::validate($wh, array_merge($base, array('payment_date' => '2026-02-10')), $B['client']);
ok(isset($ex['branch_id']) || isset($ex['counterparty_id']), 'records cannot point at another tenant\'s branch or counterparty');
$lockId = DB::val('SELECT id FROM period_locks WHERE client_id = ?', array($A['client']));
ok(!Workflow::approvalsRequired(), 'approval by a second person is off by default');
$auto = Workflow::request('period_locks', $lockId, 'unlock_period', 'test');
ok(DB::val('SELECT decision FROM approval_requests WHERE id = ?', array($auto)) === 'approved', 'with approvals off a request is recorded as approved at once');
ok(!Records::lockedPeriod($A['client'], '2026-01', 'withholding'), 'with approvals off the unlock takes effect when requested');
ok((int)DB::val("SELECT COUNT(*) FROM audit_events WHERE action = 'approval.auto' AND subject_id = ?", array($lockId)) === 1, 'the automatic approval is in the audit log');
$lockId = Workflow::lock($A['client'], '2026-01', 'withholding', 'locked again for the maker-checker test');
set_sys_config('require_approvals', '1');
$req = Workflow::request('period_locks', $lockId, 'unlock_period', 'test');
ok(DB::val('SELECT decision FROM approval_requests WHERE id = ?', array($req)) === 'pending' && Records::lockedPeriod($A['client'], '2026-01', 'withholding'), 'with approvals on the request waits');
throws(function () use ($req) { Workflow::decide($req, true, 'self'); }, 'the requester cannot approve their own request', 'other than the requester');
$rev = DB::insert('users', array('email' => 'rev@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Reviewer', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
DB::insert('memberships', array('user_id' => $rev, 'organization_id' => $A['org'], 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
login_as($rev, $A['org'], $A['client']);
Workflow::decide($req, true, 'ok');
ok(!Records::lockedPeriod($A['client'], '2026-01', 'withholding'), 'a second person unlocks the period');
set_sys_config('require_approvals', '0');
login_as($A['user'], $A['org'], $A['client']);

// =====================================================================
section('Excel import and direct entry produce identical DAT bytes');
// Org C: direct entry. Org D: same TIN in a different organization, data imported from the generated template workbook.
$Cc = make_org('Gamma', '777888999', 'firm_owner', 'SAME TAXPAYER INC');
$Dd = make_org('Delta', '777888999', 'firm_owner', 'SAME TAXPAYER INC');
$payees = array(
    array('300200100', '0000', 'MABUHAY PRINTING INC', '', '', '', 'WC160', '53164.50'),
    array('300200101', '0000', 'SUGBO HARDWARE CORP', '', '', '', 'WC158', '67100.50'),
    array('300200102', '0000', '', 'DELOS SANTOS', 'MARIA', 'REYES', 'WI010', '25000.00'),
    array('012345678', '0002', 'ZERO LEAD TIN CORP', '', '', '', 'WC158', '1999.99'),
);
login_as($Cc['user'], $Cc['org'], $Cc['client']);
foreach ($payees as $i => $p) {
    $cpid = DB::insert('counterparties', array('organization_id' => $Cc['org'], 'client_id' => $Cc['client'], 'tin' => $p[0], 'branch_code' => $p[1],
        'entity_type' => $p[2] ? 'non_individual' : 'individual', 'registered_name' => $p[2] ?: null, 'last_name' => $p[3] ?: null, 'first_name' => $p[4] ?: null,
        'middle_name' => $p[5] ?: null, 'is_payee' => 1, 'status' => 'active', 'created_at' => now()));
    list($d, $e) = Records::validate($wh, array('branch_id' => $Cc['branch'], 'counterparty_id' => $cpid, 'tax_kind' => 'EWT', 'atc_code' => $p[6], 'payment_date' => '2026-03-' . (10 + $i),
        'income_amount' => $p[7], 'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => ''), $Cc['client']);
    ok(!$e, 'direct entry row ' . ($i + 1) . ' valid', json_encode($e));
    Records::save($wh, $d);
}
$ctxC = Reports::context(array('form' => '1601EQ', 'schedule' => 'S1', 'branch_id' => $Cc['branch'], 'year' => 2026, 'part' => 3, 'sawt_form' => null, 'amended' => 0));
$fileC = Reports::generate($ctxC, true);
$bytesC = file_get_contents(storage_path(DB::val('SELECT storage_path FROM dat_files WHERE id = ?', array($fileC))));

login_as($Dd['user'], $Dd['org'], $Dd['client']);
$type = Imports::type('withholding');
$rowsX = array();
foreach ($payees as $i => $p) {
    $vals = array('p_tin' => substr($p[0], 0, 3) . '-' . substr($p[0], 3, 3) . '-' . substr($p[0], 6), 'p_branch' => $p[1], 'p_registered_name' => $p[2], 'p_last_name' => $p[3], 'p_first_name' => $p[4],
        'p_middle_name' => $p[5], 'p_address1' => '', 'p_address2' => '', 'client_branch' => '', 'tax_kind' => 'EWT', 'atc_code' => $p[6], 'payment_date' => '03/' . (10 + $i) . '/2026',
        'nature_of_payment' => '', 'income_amount' => $p[7], 'tax_rate' => '', 'tax_withheld' => '', 'schedule_tag' => 'A', 'reference_no' => '', 'rate_exception_note' => '');
    $r = array();
    foreach (Imports::templateColumns($type) as $col) {
        $r[] = $vals[$col['key']];
    }
    $rowsX[] = $r;
}
$xlsx = XlsxWriter::build(array(array('name' => 'Data', 'columns' => Imports::templateColumns($type), 'rows' => $rowsX, 'freeze' => true)));
$rel = 'uploads/test-import.xlsx';
file_put_contents(storage_path($rel), $xlsx);
$job = DB::insert('import_jobs', array('organization_id' => $Dd['org'], 'client_id' => $Dd['client'], 'branch_id' => $Dd['branch'], 'import_type' => 'withholding', 'original_filename' => 'test.xlsx',
    'stored_path' => $rel, 'file_sha256' => hash('sha256', $xlsx), 'sheet_name' => 'Data', 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $Dd['user'], 'created_at' => now()));
$jobRow = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job));
$readRows = Imports::readRows($jobRow);
list($map, $exact) = Imports::suggestMapping($type, Imports::headersOf($readRows, 1));
ok($exact, 'template headers are recognised exactly');
ok($readRows[5][0]['v'] === '012-345-678', 'XLSX round trip keeps the leading zero of a TIN');
DB::update('import_jobs', array('mapping_json' => json_encode($map), 'options_json' => json_encode(array('date_format' => 'auto'))), 'id = ?', array($job));
Imports::prepare($job);
$st = Imports::stageChunk($job, 100);
ok($st['done'] && $st['valid'] === 4 && $st['errors'] === 0, 'staging validates all 4 rows', json_encode($st));
$n = Imports::commit($job, 'all');
ok($n === 4, 'commit inserts 4 records');
$ctxD = Reports::context(array('form' => '1601EQ', 'schedule' => 'S1', 'branch_id' => $Dd['branch'], 'year' => 2026, 'part' => 3, 'sawt_form' => null, 'amended' => 0));
$fileD = Reports::generate($ctxD, true);
$bytesD = file_get_contents(storage_path(DB::val('SELECT storage_path FROM dat_files WHERE id = ?', array($fileD))));
ok(strlen($bytesC) > 100 && $bytesC === $bytesD, 'Excel-to-DAT and database-to-DAT bytes are identical', md5($bytesC) . ' vs ' . md5($bytesD));
ok(strpos($bytesD, '012345678,0002') !== false, 'leading-zero TIN and branch survive to the DAT');
$rowD = DB::row('SELECT * FROM dat_files WHERE id = ?', array($fileD));
unlink(storage_path($rowD['storage_path']));
ok(Reports::storedBytes($rowD) === $bytesD && is_file(storage_path($rowD['storage_path'])), 'a missing generated file is rebuilt from its snapshot with the recorded checksum');
unlink(storage_path($rowD['storage_path']));
$rowD['sha256'] = str_repeat('0', 64);
ok(Reports::storedBytes($rowD) === null && !is_file(storage_path($rowD['storage_path'])), 'a rebuild that does not match the recorded checksum is refused');
file_put_contents(storage_path($rowD['storage_path']), $bytesD);
// idempotent re-import
$job2 = DB::insert('import_jobs', array('organization_id' => $Dd['org'], 'client_id' => $Dd['client'], 'branch_id' => $Dd['branch'], 'import_type' => 'withholding', 'original_filename' => 'again.xlsx',
    'stored_path' => $rel, 'file_sha256' => hash('sha256', $xlsx), 'sheet_name' => 'Data', 'header_row' => 1, 'status' => 'uploaded', 'mapping_json' => json_encode($map),
    'options_json' => json_encode(array()), 'created_by' => $Dd['user'], 'created_at' => now()));
Imports::prepare($job2);
$st2 = Imports::stageChunk($job2, 100);
ok($st2['duplicates'] === 4 && $st2['valid'] === 0, 're-importing the same file finds 4 duplicates and adds nothing');
// bad rows
$bad = $rowsX;
$bad[0][0] = '1.23457E+11';
$bad[1][array_search('atc_code', array_column(Imports::templateColumns($type), 'key'))] = 'ZZ999';
$xb = XlsxWriter::build(array(array('name' => 'Data', 'columns' => Imports::templateColumns($type), 'rows' => $bad)));
file_put_contents(storage_path('uploads/bad.xlsx'), $xb);
$job3 = DB::insert('import_jobs', array('organization_id' => $Dd['org'], 'client_id' => $Dd['client'], 'branch_id' => $Dd['branch'], 'import_type' => 'withholding', 'original_filename' => 'bad.xlsx',
    'stored_path' => 'uploads/bad.xlsx', 'file_sha256' => hash('sha256', $xb), 'sheet_name' => 'Data', 'header_row' => 1, 'status' => 'uploaded', 'mapping_json' => json_encode($map),
    'options_json' => json_encode(array()), 'created_by' => $Dd['user'], 'created_at' => now()));
Imports::prepare($job3);
$st3 = Imports::stageChunk($job3, 100);
ok($st3['errors'] === 2, 'scientific-notation TIN and unknown ATC rows are rejected with row errors', json_encode($st3));
throws(function () use ($job3) { Imports::commit($job3, 'all'); }, 'all-or-nothing commit refuses a file with errors', 'errors');
ok(strlen(Imports::correctionsWorkbook($job3)) > 500, 'corrections workbook is produced');
$rb = Imports::rollback($job, 'test rollback');
ok($rb === 4 && (int)DB::val('SELECT COUNT(*) FROM withholding_entries WHERE import_job_id = ? AND status = ?', array($job, 'void')) === 4, 'rollback voids the committed rows');

DB::update('client_entities', array('status' => 'archived'), 'id = ?', array($Dd['client']));
Ctx::reset();
login_as($Dd['user'], $Dd['org'], $Dd['client']);
throws(function () use ($ctxD) { Reports::generate($ctxD, true); }, 'an archived client refuses new files', 'archived');
DB::update('client_entities', array('status' => 'active'), 'id = ?', array($Dd['client']));
Ctx::reset();
login_as($Dd['user'], $Dd['org'], $Dd['client']);

// =====================================================================
section('Validation evidence, approvals and filing');
login_as($Cc['user'], $Cc['org'], $Cc['client']);
$fc = DB::row('SELECT * FROM dat_files WHERE id = ?', array($fileC));
ok($fc['status'] === 'pending_bir' && $fc['file_label'] === 'unvalidated_draft', 'a pending-verification layout yields an unvalidated draft waiting for BIR validation');
$txt = "TIN of Withholding Agent TIN: 777888999-0000\r\nAlphalist Form              : 1601EQ\r\nReturn Period               : 03/2026\r\n\r\nLINE NUM       SCHEDULE     ERROR DESCRIPTION\r\n----------     --------     -----\r\n0000000000                  No Errors Encountered\r\n";
$tmp = tempnam(sys_get_temp_dir(), 'ev');
file_put_contents($tmp, $txt);
$GLOBALS['_FILES_TEST'] = true;
$upload = array('name' => 'report.txt', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($txt));
$datTmp = tempnam(sys_get_temp_dir(), 'dat');
file_put_contents($datTmp, $bytesC);
$evId = Evidence::record($fileC, array('validator_version' => '7.4', 'executed_on' => '2026-04-05', 'outcome' => '', 'patch_date' => '', 'report_type' => '', 'notes' => ''),
    $upload, array('name' => 'x.dat', 'tmp_name' => $datTmp, 'error' => 0, 'size' => strlen($bytesC)));
$evRow = DB::row('SELECT * FROM official_validation_evidence WHERE id = ?', array($evId));
ok($evRow['outcome'] === 'success' && (int)$evRow['checksum_match'] === 1, 'validator report recorded with byte identity proven');
$evRow = DB::row('SELECT * FROM official_validation_evidence WHERE id = ?', array($evId));
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileC)) === 'approved', 'with approvals off, a file the BIR validator passes is approved at once');
ok($evRow['review_decision'] === 'accepted' && $evRow['reviewed_at'], 'the validator report is accepted automatically');
ok(DB::val('SELECT status FROM layout_specs WHERE id = (SELECT spec_id FROM dat_files WHERE id = ?)', array($fileC)) === 'approved', 'the layout the validator passed is approved too');
ok((bool)Records::lockedPeriod($Cc['client'], '2026-03', 'withholding'), 'approval locks the covered month');
ok(!DB::val('SELECT COUNT(*) FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND decision = ?', array('dat_files', $fileC, 'pending')), 'nothing waits in Approvals');
$fid = Workflow::recordFiling($fileC, array('channel' => 'eSubmission', 'filed_on' => '2026-04-10', 'reference_no' => 'ACK-1', 'notes' => ''), null);
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileC)) === 'submitted', 'filing evidence marks the file submitted');
$wrongTin = str_replace('777888999', '123123123', $txt);
file_put_contents($tmp, $wrongTin);
throws(function () use ($fileD, $tmp, $Dd) {
    login_as($Dd['user'], $Dd['org'], $Dd['client']);
    Evidence::record($fileD, array('validator_version' => '7.4', 'executed_on' => '2026-04-05', 'outcome' => '', 'patch_date' => '', 'report_type' => '', 'notes' => ''),
        array('name' => 'r.txt', 'tmp_name' => $tmp, 'error' => 0, 'size' => 100), null);
}, 'a validator report for another TIN is refused', 'not this client');

// with approval by a second person turned on, the maker-checker steps come back
set_sys_config('require_approvals', '1');
file_put_contents($tmp, $txt);
login_as($Dd['user'], $Dd['org'], $Dd['client']);
$evD = Evidence::record($fileD, array('validator_version' => '7.4', 'executed_on' => '2026-04-05', 'outcome' => '', 'patch_date' => '', 'report_type' => '', 'notes' => ''),
    $upload, array('name' => 'x.dat', 'tmp_name' => $datTmp, 'error' => 0, 'size' => strlen($bytesC)));
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileD)) === 'bir_validated' && !DB::val('SELECT reviewed_at FROM official_validation_evidence WHERE id = ?', array($evD)),
    'with approvals on, the file is officially validated and the report waits for review');
throws(function () use ($evD) { Evidence::review($evD, 'accept', 'self'); }, 'the uploader cannot review their own evidence', 'different person');
$r2 = DB::insert('users', array('email' => 'rev2@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Reviewer Two', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
DB::insert('memberships', array('user_id' => $r2, 'organization_id' => $Dd['org'], 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
login_as($r2, $Dd['org'], $Dd['client']);
Evidence::review($evD, 'accept', 'checked');
login_as($Dd['user'], $Dd['org'], $Dd['client']);
$ar = Workflow::request('dat_files', $fileD, 'approve_file', 'ready');
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileD)) === 'bir_validated', 'the approval request waits');
login_as($r2, $Dd['org'], $Dd['client']);
Workflow::decide($ar, true, 'approved');
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileD)) === 'approved', 'a second person approves the file');
// a request left waiting is carried out once approvals are turned off again
login_as($Dd['user'], $Dd['org'], $Dd['client']);
$lockD = DB::val('SELECT id FROM period_locks WHERE client_id = ? AND period_month = ? AND unlocked_at IS NULL', array($Dd['client'], '2026-03'));
$waiting = Workflow::request('period_locks', $lockD, 'unlock_period', 'correction');
set_sys_config('require_approvals', '0');
ok(Workflow::settle() >= 1 && DB::val('SELECT decision FROM approval_requests WHERE id = ?', array($waiting)) === 'approved'
    && !Records::lockedPeriod($Dd['client'], '2026-03', 'withholding'), 'turning approvals off carries out requests that were waiting');

// =====================================================================
section('Certificates and PDF');
login_as($Cc['user'], $Cc['org'], $Cc['client']);
$client = Ctx::client();
$branch = DB::row('SELECT * FROM branches WHERE id = ?', array($Cc['branch']));
$cpPrint = DB::val('SELECT id FROM counterparties WHERE client_id = ? AND tin = ?', array($Cc['client'], '300200100'));
$payload = Certificates::buildWithholding('2307', $client, $branch, $cpPrint, '2026-01-01', '2026-03-31');
ok($payload['total']['tax'] === Money::applyRate(5316450, 20000), '2307 totals match the withholding records');
$certId = Certificates::issue($payload, array('paper' => 'FOLIO'));
$cert = DB::row('SELECT * FROM certificates WHERE id = ?', array($certId));
$pdf = file_get_contents(storage_path($cert['pdf_path']));
ok(substr($pdf, 0, 8) === '%PDF-1.4' && strpos($pdf, '%%EOF') !== false && $cert['cert_no'] === '2307-2026-00001', 'PDF written and numbered');
preg_match('/startxref\n(\d+)/', $pdf, $mm);
ok(isset($mm[1]) && substr($pdf, (int)$mm[1], 4) === 'xref', 'PDF cross-reference offset is correct');

// =====================================================================
section('Audit trail and backups');
$v = Audit::verify();
ok($v['ok'] && $v['checked'] > 10, 'audit hash chain verifies (' . $v['checked'] . ' events)');
$mid = DB::val('SELECT id FROM audit_events ORDER BY id LIMIT 1 OFFSET 5');
DB::q('UPDATE audit_events SET summary = CONCAT(summary, ?) WHERE id = ?', array(' (edited)', $mid));
$v2 = Audit::verify();
ok(!$v2['ok'] && (int)$v2['broken_at'] === (int)$mid, 'tampering with history is detected at the altered event');
DB::q('UPDATE audit_events SET summary = REPLACE(summary, ?, ?) WHERE id = ?', array(' (edited)', '', $mid));
login_as($Dd['user'], $Dd['org'], $Dd['client']);
$before = (int)DB::val('SELECT COUNT(*) FROM withholding_entries WHERE client_id = ?', array($Dd['client']));
$bid = Backup::createClient($Dd['client'], 'correct horse battery', 'test');
DB::q('DELETE FROM import_rows WHERE job_id IN (SELECT id FROM import_jobs WHERE client_id = ?)', array($Dd['client']));
DB::q('UPDATE withholding_entries SET status = ? WHERE client_id = ?', array('void', $Dd['client']));
$blob = file_get_contents(storage_path(DB::val('SELECT stored_path FROM backups WHERE id = ?', array($bid))));
throws(function () use ($blob, $Dd) { Backup::restoreClient($Dd['client'], $blob, 'wrong passphrase', true); }, 'a wrong passphrase cannot open the backup', 'passphrase');
throws(function () use ($blob, $Cc) { login_as($Cc['user'], $Cc['org'], $Cc['client']); Backup::restoreClient($Cc['client'], $blob, 'correct horse battery', true); }, 'a backup cannot be restored into another client', 'belongs to');
login_as($Dd['user'], $Dd['org'], $Dd['client']);
$res = Backup::restoreClient($Dd['client'], $blob, 'correct horse battery', false);
ok((int)DB::val('SELECT COUNT(*) FROM withholding_entries WHERE client_id = ?', array($Dd['client'])) === $before
    && (int)DB::val('SELECT COUNT(*) FROM withholding_entries WHERE client_id = ? AND status = ?', array($Dd['client'], 'void')) === 4, 'restore brings back the exact pre-backup state');
ok(Audit::verify()['ok'], 'audit chain still verifies after restore');

// =====================================================================
section('Purchases in the R_Purchases workbook format');
$Pp = make_org('Pucara', '444000111', 'firm_owner', 'R PURCHASES TEST CORP');
login_as($Pp['user'], $Pp['org'], $Pp['client']);
$ptype = Imports::type('purchases');
$pcols = Imports::templateColumns($ptype);
ok(implode(',', array_map(function ($c) { return $c['title']; }, $pcols)) === 'Vendor_TIN,companyName,lastName,firstName,middleName,address1,address2,exempt,zeroRated,services,capitalGoods,otherThancapitalGoods,taxableNetofVat,vatRate,inputVat,totalPurchases',
    'the purchases template has the 16 R_Purchases columns in order');
$tpl = Imports::template('purchases', false);
$tr = new XlsxReader(tmp_file($tpl));
ok($tr->sheetNames()[0] === 'R_Purchases', 'the template sheet is named R_Purchases');
ok(strpos(zip_part($tpl, 'xl/worksheets/sheet1.xml'), '<f>ROUND(M2*N2/100,2)</f>') !== false && strpos(zip_part($tpl, 'xl/worksheets/sheet1.xml'), '<f>ROUND(SUM(J2:L2),2)</f>') !== false,
    'the template carries the workbook formulas for taxableNetofVat, inputVat and totalPurchases');
$prow = function ($tin, $co, $og) { return array($tin, $co, '', '', '', '', '', 0, 0, 0, 0, $og, '', 12, '', ''); };
$lines = array($prow('000488793', 'PLDT, INC.', 1695.54), $prow('008381043', 'THREESIXTY PHARMACY', 421.875), $prow('008381043', 'THREESIXTY PHARMACY', 20.7589285714286),
    $prow('008381043', 'THREESIXTY PHARMACY', 20.7589285714286), array('', '', 'DELA CRUZ', 'JUAN', 'SANTOS', '1 RIZAL ST', 'CEBU', 1500, 0, 0, 0, 0, '', 12, '', ''));
$pxlsx = XlsxWriter::build(array(array('name' => 'R_Purchases', 'columns' => $pcols, 'rows' => $lines, 'header' => 'bold', 'formula_rows' => 20, 'defaults' => array('vat_rate' => 12))));
$pimport = function ($bytes, $opts, $sheet = 'R_Purchases') use ($Pp, $ptype) {
    $rel = 'uploads/rp-' . mt_rand() . '.xlsx';
    file_put_contents(storage_path($rel), $bytes);
    $job = DB::insert('import_jobs', array('organization_id' => $Pp['org'], 'client_id' => $Pp['client'], 'branch_id' => $Pp['branch'], 'import_type' => 'purchases', 'original_filename' => 'p.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $bytes), 'sheet_name' => $sheet, 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $Pp['user'], 'created_at' => now()));
    $jr = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job));
    list($map, $exact) = Imports::suggestMapping($ptype, Imports::headersOf(Imports::readRows($jr), 1));
    DB::update('import_jobs', array('mapping_json' => json_encode($map), 'options_json' => json_encode($opts)), 'id = ?', array($job));
    Imports::prepare($job);
    do { $st = Imports::stageChunk($job, 100); } while (!$st['done']);
    return array($job, $st, $exact);
};
list($pj, $pst, $pexact) = $pimport($pxlsx, array('period_month' => '2026-09'));
ok($pexact, 'R_Purchases headers are recognised as the template');
ok($pst['total'] === 5 && $pst['valid'] === 5, 'formula-only template rows are skipped; 5 purchases staged, including two identical lines', json_encode($pst));
Imports::commit($pj, 'all');
$pr = DB::all('SELECT p.*, cp.tin, cp.entity_type FROM purchase_entries p JOIN counterparties cp ON cp.id = p.counterparty_id WHERE p.client_id = ? ORDER BY p.id', array($Pp['client']));
ok(count($pr) === 5 && $pr[0]['input_vat'] === '203.46' && $pr[1]['other_goods_amount'] === '421.88' && $pr[1]['input_vat'] === '50.63' && $pr[2]['input_vat'] === '2.49',
    'amounts round like Excel ROUND and input VAT is ROUND(taxable x 12 / 100, 2)', json_encode(array_map(function ($r) { return $r['other_goods_amount'] . '/' . $r['input_vat']; }, $pr)));
ok($pr[4]['entity_type'] === 'individual' && $pr[4]['exempt_amount'] === '1500.00' && $pr[4]['input_vat'] === '0.00' && $pr[0]['period_month'] === '2026-09' && $pr[0]['invoice_date'] === null,
    'individual vendors, exempt purchases and the chosen month are stored');
list(, $pst2) = $pimport($pxlsx, array('period_month' => '2026-09'));
ok($pst2['duplicates'] === 5 && $pst2['valid'] === 0, 're-importing the same sheet finds all 5 rows, the repeated lines too, as duplicates', json_encode($pst2));
$bad = $lines;
$bad[0][12] = '9999';
$typedCols = array_map(function ($c) { unset($c['formula']); return $c; }, $pcols);
list($bj, $bst) = $pimport(XlsxWriter::build(array(array('name' => 'R_Purchases', 'columns' => $typedCols, 'rows' => array($bad[0]), 'header' => 'bold'))), array('period_month' => '2026-08'));
$berr = json_decode((string)DB::val('SELECT errors_json FROM import_rows WHERE job_id = ?', array($bj)), true);
ok($bst['errors'] === 1 && isset($berr['taxable_net']), 'a typed taxableNetofVat that disagrees with its parts is refused');
list($nj, $nst) = $pimport(XlsxWriter::build(array(array('name' => 'R_Purchases', 'columns' => $pcols, 'rows' => array($lines[0]), 'header' => 'bold'))), array());
$nerr = json_decode(DB::val('SELECT errors_json FROM import_rows WHERE job_id = ?', array($nj)), true);
ok(isset($nerr['period_month']), 'without a purchase month the sheet rows are refused with a clear message');
$pm = Records::module('purchases');
list($d, $e) = Records::validate($pm, array('branch_id' => $Pp['branch'], 'counterparty_id' => $pr[0]['counterparty_id'], 'period_month' => '2026-07', 'services_amount' => '1,000.00', 'vat_rate' => ''), $Pp['client']);
ok(!$e && $d['input_vat'] === '120.00' && $d['vat_rate'] === '12.0000', 'a typed purchase defaults to 12% and computes input VAT');
list($d, $e) = Records::validate($pm, array('branch_id' => $Pp['branch'], 'counterparty_id' => $pr[0]['counterparty_id'], 'period_month' => '2026-07', 'invoice_date' => '2026-08-03', 'other_goods_amount' => '100'), $Pp['client']);
ok(isset($e['invoice_date']), 'an invoice date outside the purchase month is refused');
list($d, $e) = Records::validate($pm, array('branch_id' => $Pp['branch'], 'counterparty_id' => $pr[0]['counterparty_id'], 'period_month' => '', 'other_goods_amount' => '100'), $Pp['client']);
ok(isset($e['period_month']), 'the purchase month is required when there is no invoice date');
list($party) = Records::purchaseVendor(array('v_tin' => '000-488-793'), $Pp['client']);
list($party2, $perr2) = Records::purchaseVendor(array('v_tin' => '123456789', 'v_company' => 'NEW VENDOR CORP'), $Pp['client']);
ok($party['id'] === (int)$pr[0]['counterparty_id'] && !$party2['id'] && !$perr2 && $party2['fields']['registered_name'] === 'NEW VENDOR CORP',
    'the entry form finds a vendor by TIN, or prepares a new one');
list($oj) = $pimport(XlsxWriter::build(array(array('name' => 'Data', 'columns' => array(array('title' => 'SUPPLIER TIN', 'format' => 'text'), array('title' => 'REGISTERED NAME', 'format' => 'text'),
    array('title' => 'INVOICE DATE', 'format' => 'text'), array('title' => 'GOODS OTHER THAN CAPITAL GOODS', 'format' => 'amount'), array('title' => 'INPUT VAT', 'format' => 'amount')),
    'rows' => array(array('300100999', 'OLD FORMAT SUPPLY', '07/15/2026', '500', '60'))))), array(), 'Data');
$oraw = json_decode(DB::val('SELECT normalized_json FROM import_rows WHERE job_id = ?', array($oj)), true);
ok($oraw['record']['period_month'] === '2026-07' && $oraw['record']['input_vat'] === '60.00', 'workbooks in the earlier template layout, with an invoice date, still import');
$ctxP = Reports::context(array('form' => 'SLP', 'schedule' => 'S1', 'branch_id' => $Pp['branch'], 'year' => 2026, 'part' => 9, 'sawt_form' => null, 'amended' => 0));
$fp = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($ctxP, true)));
ok($fp['total_tax'] === '259.07' && (int)$fp['record_count'] === 3, 'the Summary List of Purchases totals the imported input VAT per vendor', $fp['total_tax'] . ' ' . $fp['record_count']);
$slpBytes = file_get_contents(storage_path($fp['storage_path']));
$slpIssues = DB::all('SELECT r.level, e.severity, e.message FROM validation_errors e JOIN validation_runs r ON r.id = e.validation_run_id WHERE r.dat_file_id = ?', array($fp['id']));
$slpErr = array_filter($slpIssues, function ($i) { return $i['severity'] === 'error'; });
$slpWarn = implode(' ', array_map(function ($i) { return $i['message']; }, $slpIssues));
ok($fp['status'] === 'pending_bir' && !$slpErr && strpos($slpWarn, 'has no TIN; the line is written without one') !== false,
    'a vendor with no TIN is a warning, not an error: the BIR validator accepts a blank TIN', json_encode($slpIssues));
ok(strpos($slpBytes, "D,P,\"\",\"\",\"DELA CRUZ\",\"JUAN\",\"SANTOS\"") !== false && !array_filter(DatReader::parse($slpBytes, Specs::load($fp['spec_id']))['issues'], function ($i) { return $i['severity'] === 'error'; }),
    'the line is written with an empty TIN and reads back without a structure error');
// totalPurchases now includes the input VAT: 5,600.00 services + 672.00 VAT = 6,272.00
ok(strpos(zip_part($tpl, 'xl/worksheets/sheet1.xml'), '<f>ROUND(SUM(H2:L2)+O2,2)</f>') !== false, 'the template totalPurchases formula adds the input VAT');
ok(Records::purchaseTotal(array('services_amount' => '5600.00', 'input_vat' => '672.00')) === 627200, 'a purchase record totals 5,600.00 + 672.00 VAT as 6,272.00');
$trow = function ($total) { return array('300555777', 'TOTAL TEST SERVICES INC', '', '', '', '', '', 0, 0, 5600, 0, 0, '', 12, '', $total); };
list($tj, $tst) = $pimport(XlsxWriter::build(array(array('name' => 'R_Purchases', 'columns' => $typedCols, 'rows' => array($trow('6272'), $trow('5600'), $trow('7000')), 'header' => 'bold'))), array('period_month' => '2026-06'));
$tr3 = DB::all('SELECT status, normalized_json, errors_json, warnings_json FROM import_rows WHERE job_id = ? ORDER BY row_no', array($tj));
$tn = json_decode($tr3[0]['normalized_json'], true);
ok($tr3[0]['status'] === 'valid' && $tn['record']['input_vat'] === '672.00' && strpos((string)$tr3[0]['warnings_json'], 'total_purchases') === false, 'an imported totalPurchases of 6,272.00 (VAT included) is accepted', json_encode($tr3[0]));
ok($tr3[1]['status'] === 'valid' && strpos((string)$tr3[1]['warnings_json'], 'leaves out the input VAT') !== false, 'a total from the earlier formula (no VAT) imports with a warning', json_encode($tr3[1]));
ok($tr3[2]['status'] === 'error' && strpos((string)$tr3[2]['errors_json'], '6,272.00') !== false, 'any other total is refused and the right total is shown', json_encode($tr3[2]));

// =====================================================================
section('Taxes withheld on us in the SAWT layout');
$Gg = make_org('Guardia', '004267719', 'firm_owner', 'GUARDIAN ACES SECURITY AGENCY INC');
login_as($Gg['user'], $Gg['org'], $Gg['client']);
$stype = Imports::type('withheld_on_us');
ok(implode('|', array_map(function ($c) { return $c['title']; }, Imports::templateColumns($stype))) === 'SEQ NO|TAXPAYER IDENTIFICATION NUMBER|CORPORATION (Registered Name)|INDIVIDUAL (Last Name, First Name, Middle Name)|ATC CODE|NATURE OF PAYMENT|AMOUNT OF INCOME PAYMENT|TAX RATE|AMOUNT OF TAX WITHHELD',
    'the import reads the nine SAWT columns (1) to (8) in order');
// A SAWT printout as the BIR layout has it: title lines, a header on three rows, "(1)".."(8)", dashes,
// names padded with spaces, an INDIVIDUAL cell of spaces, Grand Total and END OF REPORT.
$pad = function ($s, $n) { return str_pad($s, $n); };
$nat160 = $pad('Income payment made by top withholding agents to their local/resident supplier of services other than those covered by other rates of withholding tax  - Corporate', 250);
$nat120 = $pad('Income payments to prime contractors/sub-contractors - Corporate', 250);
$sawtLines = array(
    array('228-755-014-0000', 'ARMORTECH INTERNATIONAL TRANSPORTER CORPORATION', '', 'WC160', $nat160, 948568.5, 18971.37),
    array('000-438-366-0000', 'BANK OF THE PHILIPPINE ISLANDS', '', 'WC120', $nat120, 1578872.77, 31577.46),
    array('234-494-328-0000', 'SAFEWAY SECURITY BUILDERS AND TRANSPORT SOLUTIONS ', '', 'WC160', $nat160, 91698, 1833.96),
    array('202-671-660-0000', 'SAMAHANG KOOPERATIBA NG M LHUILLIER PAWNSHOPS EMP ', '', 'WC160', $nat160, 33954.6, 679.09),
    array('101-202-303-0000', '', 'DELA CRUZ, JUAN, SANTOS', 'WC160', $nat160, 12000, 240),
    array('101-202-303-0000', '', 'DELA CRUZ, JUAN, SANTOS', 'WC160', $nat160, 12000, 240),
);
$sawtFile = function ($lines, $tin, $month, $grand = null) use ($pad) {
    $b = function ($v) { return array('v' => $v, 's' => 'bold'); };
    $cells = array(1 => array($b('BIR FORM 1702Q')), 2 => array($b('SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)')), 3 => array($b('FOR THE MONTH OF ' . $month)),
        6 => array($b('TIN : ' . $tin)), 7 => array($b("PAYEE'S NAME: GUARDIAN ACES SECURITY AGENCY INC")),
        11 => array($b('SEQ'), $b('TAXPAYER'), $b('CORPORATION'), $b('INDIVIDUAL'), $b('ATC CODE'), $b('NATURE OF PAYMENT'), $b('AMOUNT OF'), $b('TAX RATE'), $b('AMOUNT OF')),
        12 => array(0 => $b('NO'), 1 => $b('IDENTIFICATION'), 2 => $b('(Registered Name)'), 3 => $b('(Last Name, First Name, Middle Name)'), 6 => $b('INCOME PAYMENT'), 8 => $b('TAX WITHHELD')),
        13 => array(1 => $b('NUMBER')), 14 => array(0 => '(1)', 1 => '(2)', 2 => '(3)', 3 => '(4)', 4 => '(5)', 6 => '(6)', 7 => '(7)', 8 => '(8)'), 15 => array_fill(0, 9, str_repeat('-', 30)));
    $r = 16;
    $gi = 0;
    $gt = 0;
    foreach ($lines as $i => $l) {
        $cells[$r++] = array($i + 1, $l[0], $l[1] === '' ? '' : $pad($l[1], 50), $l[2] === '' ? str_repeat(' ', 91) : $l[2], $l[3], $l[4], array('v' => $l[5], 's' => 'amount'), array('v' => 2, 's' => 'amount'), array('v' => $l[6], 's' => 'amount'));
        $gi += (int)round($l[5] * 100);
        $gt += (int)round($l[6] * 100);
    }
    $cells[$r++] = array(6 => str_repeat('-', 18), 7 => str_repeat('-', 18), 8 => str_repeat('-', 18));
    $cells[$r++] = array(0 => 'Grand Total :', 6 => array('v' => $grand ? $grand[0] : $gi / 100, 's' => 'amount_bold'), 8 => array('v' => $grand ? $grand[1] : $gt / 100, 's' => 'amount_bold'));
    $cells[$r++] = array(6 => str_repeat('=', 18), 7 => str_repeat('=', 18), 8 => str_repeat('=', 18));
    $cells[$r] = array(0 => 'END OF REPORT');
    return XlsxWriter::build(array(array('name' => 'Sheet1', 'columns' => array(array('width' => 25.69), array('width' => 9), array('width' => 50.69)), 'cells' => $cells)));
};
$simport = function ($bytes, $opts = array(), $client = null) use ($Gg, $stype) {
    $client = $client ?: $Gg;
    $rel = 'uploads/sawt-' . mt_rand() . '.xlsx';
    file_put_contents(storage_path($rel), $bytes);
    $job = DB::insert('import_jobs', array('organization_id' => $client['org'], 'client_id' => $client['client'], 'branch_id' => $client['branch'], 'import_type' => 'withheld_on_us', 'original_filename' => 's.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $bytes), 'sheet_name' => null, 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $client['user'], 'created_at' => now()));
    $jr = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job));
    $rows = Imports::readRows($jr, 80);
    $det = Imports::detectHeader($stype, $rows);
    $meta = Imports::reportMeta($rows, $det[0]);
    list($map, $exact) = Imports::suggestMapping($stype, Imports::headersOf($rows, $det[1], $det[0]));
    if (empty($opts['period_month']) && $meta['month']) {
        $opts['period_month'] = $meta['month'];
    }
    DB::update('import_jobs', array('header_row' => $det[1], 'mapping_json' => json_encode($map), 'options_json' => json_encode($opts + array('header_from' => $det[0]))), 'id = ?', array($job));
    Imports::prepare($job);
    do { $st = Imports::stageChunk($job, 100); } while (!$st['done']);
    return array($job, $st, $exact, $det, $meta);
};
$sx = $sawtFile($sawtLines, '004267719-0000', 'MARCH, 2025');
list($sj, $sst, $sexact, $sdet, $smeta) = $simport($sx);
ok($sdet === array(11, 13) && $sexact, 'the header stacked on rows 11 to 13 is found and all nine columns map by themselves', json_encode($sdet));
ok($smeta['form'] === '1702Q' && $smeta['month'] === '2025-03' && $smeta['tin'] === '004267719' && $smeta['branch'] === '0000' && $smeta['name'] === 'GUARDIAN ACES SECURITY AGENCY INC',
    'the title lines give the form, month, TIN with branch and payee name', json_encode($smeta));
list($cp1, $cn1) = Imports::reportCheck($smeta, DB::row('SELECT * FROM client_entities WHERE id = ?', array($Gg['client'])));
list($cp2) = Imports::reportCheck($smeta, DB::row('SELECT * FROM client_entities WHERE id = ?', array($Pp['client'])));
ok(!$cp1 && !$cn1 && count($cp2) === 1 && strpos($cp2[0], '004-267-719') !== false, 'a SAWT of another TIN is refused; the right client passes without notes', json_encode($cp2));
ok($sst['total'] === 6 && $sst['valid'] === 6 && $sst['errors'] === 0, 'numbering, dashes, Grand Total and END OF REPORT are left out; 6 lines staged', json_encode($sst));
$sjob = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($sj));
$sopt = json_decode($sjob['options_json'], true);
ok($sopt['file_totals']['values']['income_amount'] === '2677093.87' && $sopt['file_totals']['values']['tax_withheld'] === '53541.88', 'the Grand Total line is kept to check the lines against', json_encode($sopt['file_totals']));
Imports::commit($sj, 'all');
$sr = DB::all('SELECT w.*, cp.tin, cp.branch_code AS cpb, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, cp.is_payor FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id WHERE w.client_id = ? ORDER BY w.id', array($Gg['client']));
ok(count($sr) === 6 && $sr[0]['direction'] === 'payee' && $sr[0]['period_month'] === '2025-03' && $sr[0]['payment_date'] === null && $sr[0]['tax_withheld'] === '18971.37' && $sr[0]['tax_rate'] === '2.0000',
    'lines are saved as taxes withheld on us for March 2025, without a payment date, with the certified tax', json_encode($sr[0]));
ok($sr[2]['registered_name'] === 'SAFEWAY SECURITY BUILDERS AND TRANSPORT SOLUTIONS' && $sr[0]['nature_of_payment'] === 'Income payment made by top withholding agents to their local/resident supplier of services other than those covered by other rates of withholding tax - Corporate'
    && $sr[0]['tin'] === '228755014' && $sr[0]['cpb'] === '0000' && (int)$sr[0]['is_payor'] === 1, 'report padding is trimmed; the TIN and its branch code are split');
ok($sr[4]['last_name'] === 'DELA CRUZ' && $sr[4]['first_name'] === 'JUAN' && $sr[4]['middle_name'] === 'SANTOS' && $sr[4]['counterparty_id'] === $sr[5]['counterparty_id'] && $sr[4]['row_hash'] !== $sr[5]['row_hash'],
    'an INDIVIDUAL written LAST, FIRST, MIDDLE is split; two identical lines are kept as two');
list(, $sst2) = $simport($sx);
ok($sst2['duplicates'] === 6 && $sst2['valid'] === 0, 're-importing the same SAWT finds every line as a duplicate', json_encode($sst2));
$odd = $sawtLines;
$odd[0][6] = 18900.00;
$odd[1][3] = 'WI120';
$odd[2][2] = 'REYES';
list($oj2, $ost) = $simport($sawtFile(array_slice($odd, 0, 3), '004267719-0000', 'APRIL, 2025'));
$oe = DB::all('SELECT row_no, status, errors_json, warnings_json FROM import_rows WHERE job_id = ? ORDER BY row_no', array($oj2));
ok($oe[0]['status'] === 'valid' && strpos($oe[0]['warnings_json'], 'certified') !== false, 'a tax that differs from income x rate is kept as certified, with a warning');
ok($oe[1]['status'] === 'error' && strpos($oe[1]['errors_json'], 'WI120') !== false, 'an ATC for individual payees is refused for a corporate client');
ok($oe[2]['status'] === 'valid' && strpos($oe[2]['warnings_json'], 'corporation') !== false, 'a line with both a corporation and an individual is kept under the corporation');
list($gj, $gst) = $simport($sawtFile(array_slice($sawtLines, 0, 2), '004267719-0000', 'MAY, 2025', array(9, 9)));
$gopt = json_decode(DB::val('SELECT options_json FROM import_jobs WHERE id = ?', array($gj)), true);
ok($gopt['file_totals']['values']['income_amount'] === '9' && $gst['valid'] === 2, 'a Grand Total that disagrees with the lines is kept for the review page to show');
list($tplJob, $tst, $texact, $tdet, $tmeta) = $simport(Imports::template('withheld_on_us', true, array('client' => DB::row('SELECT * FROM client_entities WHERE id = ?', array($Gg['client'])), 'month' => '2025-06')));
ok($texact && $tdet === array(11, 13) && $tmeta['month'] === '2025-06' && $tmeta['tin'] === '004267719' && $tst['total'] === 3,
    'the generated template has the SAWT layout and the client title lines; its open formula lines are not rows', json_encode(array($tdet, $tmeta, $tst)));
$tplXml = zip_part(Imports::template('withheld_on_us', false), 'xl/worksheets/sheet1.xml');
ok(strpos($tplXml, '<f>SUM(G15:G46)</f>') !== false && strpos($tplXml, 'IF(OR(G16=&quot;&quot;,H16=&quot;&quot;),&quot;&quot;,ROUND(G16*H16/100,2))') !== false, 'the template carries the tax and Grand Total formulas');
$wm = Records::module('withheld_on_us');
list($d, $e) = Records::validate($wm, array('branch_id' => $Gg['branch'], 'counterparty_id' => $sr[0]['counterparty_id'], 'period_month' => '2025-07', 'atc_code' => 'WC160', 'income_amount' => '50,000.00'), $Gg['client']);
ok(!$e && $d['tax_withheld'] === '1000.00' && $d['payment_date'] === null && strpos($d['nature_of_payment'], 'purchase of services') !== false, 'a typed line needs only the month; the rate, tax and nature come from the ATC', json_encode($e));
list($d, $e) = Records::validate($wm, array('branch_id' => $Gg['branch'], 'counterparty_id' => $sr[0]['counterparty_id'], 'period_month' => '2025-07', 'payment_date' => '2025-08-02', 'atc_code' => 'WC160', 'income_amount' => '100'), $Gg['client']);
ok(isset($e['payment_date']), 'a payment date outside the month is refused');
list($d, $e, $w) = Records::validate($wm, array('branch_id' => $Gg['branch'], 'counterparty_id' => $sr[0]['counterparty_id'], 'period_month' => '2025-07', 'atc_code' => 'WC160', 'income_amount' => '100', 'tax_rate' => '0.02'), $Gg['client']);
ok(!$e && $d['tax_rate'] === '2.0000' && $d['tax_withheld'] === '2.00' && isset($w['tax_rate']), 'a rate written as a fraction is read as the ATC percent');
list($py) = Records::sawtPayor(array('v_tin' => '228-755-014-0000'), $Gg['client']);
list($py2, $pe2) = Records::sawtPayor(array('v_tin' => '300400500', 'v_last' => 'REYES', 'v_first' => 'ANA'), $Gg['client']);
ok($py['id'] === (int)$sr[0]['counterparty_id'] && !$py2['id'] && !$pe2 && $py2['fields']['is_payor'] === 1 && $py2['entity_type'] === 'individual', 'the entry form finds a payor by TIN and branch, or prepares a new one');
$ctxS = Reports::context(array('form' => 'SAWT', 'schedule' => 'S1', 'branch_id' => $Gg['branch'], 'year' => 2025, 'part' => 1, 'sawt_form' => '1702Q', 'amended' => 0));
$fs = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($ctxS, true)));
ok($fs['total_tax'] === '53541.88' && $fs['total_base'] === '2677093.87' && (int)$fs['record_count'] === 5, 'the SAWT for 1702Q Q1 2025 carries the imported lines, one per payor and ATC', $fs['total_base'] . ' ' . $fs['total_tax'] . ' ' . $fs['record_count']);
$exp = Imports::sawtWorkbook(DB::row('SELECT * FROM client_entities WHERE id = ?', array($Gg['client'])), '1702Q', '2025-03', Imports::sawtLines($Gg['client'], null, '2025-03'), false);
list($ej, $est, $eexact) = $simport($exp);
ok($eexact && $est['total'] === 5 && $est['errors'] === 0, 'records exported in the SAWT layout read back in', json_encode($est));
if (getenv('GSB_SAWT_SAMPLE') && is_file(getenv('GSB_SAWT_SAMPLE'))) {
    list($rj, $rst, $rexact, $rdet, $rmeta) = $simport(file_get_contents(getenv('GSB_SAWT_SAMPLE')), array('period_month' => '2025-04'));
    $rsum = DB::row("SELECT COUNT(*) n FROM import_rows WHERE job_id = ? AND status IN ('valid','duplicate')", array($rj));
    $ropt = json_decode(DB::val('SELECT options_json FROM import_jobs WHERE id = ?', array($rj)), true);
    $ti = 0; $tt = 0; $bad = 0;
    foreach (DB::col("SELECT normalized_json FROM import_rows WHERE job_id = ?", array($rj)) as $nj) {
        $n = json_decode($nj, true);
        $ti += Money::fromDb($n['record']['income_amount']);
        $tt += Money::fromDb($n['record']['tax_withheld']);
        $bad += Money::applyRate(Money::fromDb($n['record']['income_amount']), 20000) !== Money::fromDb($n['record']['tax_withheld']) ? 1 : 0;
    }
    ok($rexact && $rdet === array(11, 13) && (int)$rsum['n'] === 16 && $rst['errors'] === 0 && $ti === 445388274 && $tt === 8907765 && $bad === 0
        && $ropt['file_totals']['values']['income_amount'] === '4453882.74' && $ropt['file_totals']['values']['tax_withheld'] === '89077.65',
        'the attached SAWT sample: 16 lines, income 4,453,882.74 and tax 89,077.65 equal its Grand Total, and every tax is 2% of the income', json_encode(array($rst, $ti, $tt, $bad)));
}

$fsBytes = file_get_contents(storage_path($fs['storage_path']));
$fsLines = preg_split('/\r\n/', rtrim($fsBytes, "\r\n"));
ok(preg_match('/^HSAWT,H1702Q,004267719,0000,"GUARDIAN ACES SECURITY AGENCY INC","","","",03\/2025,081$/', $fsLines[0]) && preg_match('/^DSAWT,D1702Q,\d+,\d{9},\d{4},/', $fsLines[1])
    && end($fsLines) === 'CSAWT,C1702Q,004267719,0000,03/2025,2677093.87,53541.88', 'the SAWT records are written as the v7.4 data entry module writes them', $fsLines[0] . ' | ' . $fsLines[1] . ' | ' . end($fsLines));
ok($fs['filename'] === '0042677190000032025' . '1702Q.DAT', 'the SAWT file name is TIN, branch, MMYYYY and the form', $fs['filename']);
$fsV = bir_v74_sawt($fsBytes, '004267719', '0000', '1702Q', '03/2025');
ok($fsV === array(), 'the 1702Q SAWT passes the ported v7.4 SAWT checks', json_encode($fsV));
$fsBad = bir_v74_sawt(str_replace(',WC120,', ',WZ999,', $fsBytes), '004267719', '0000', '1702Q', '03/2025');
$fsWrong = bir_v74_sawt($fsBytes, '004267719', '0000', '2550Q', '03/2025');
ok(count($fsBad) === 1 && $fsBad[0][1] === 'ATC is invalid.' && $fsWrong && $fsWrong[0] === array(1, 'Invalid Form Type Code.'), 'the ported checks refuse an unknown ATC and the wrong attached return', json_encode(array($fsBad, $fsWrong)));

// The 2550Q SAWT: VAT withheld on the client (ATC WV), from the 2550Q template.
$tpl25 = Imports::template('withheld_on_us', true, array('client' => DB::row('SELECT * FROM client_entities WHERE id = ?', array($Gg['client'])), 'month' => '2025-09', 'variant' => '2550Q'));
$tpl25Xml = zip_part($tpl25, 'xl/sharedStrings.xml') . zip_part($tpl25, 'xl/worksheets/sheet1.xml');
ok(strpos($tpl25Xml, 'BIR FORM 2550Q') !== false && strpos($tpl25Xml, 'WV010') !== false && strpos($tpl25Xml, 'WC160') === false, 'the 2550Q SAWT template has the 2550Q title and VAT codes');
list($vj, $vst, $vexact, $vdet, $vmeta) = $simport($tpl25);
ok($vexact && $vmeta['form'] === '2550Q' && $vmeta['month'] === '2025-09' && $vst['errors'] === 0 && $vst['valid'] === $vst['total'] && $vst['total'] > 0, 'the 2550Q template imports without errors', json_encode($vst));
Imports::commit($vj, 'all');
$vk = DB::col("SELECT DISTINCT tax_kind FROM withholding_entries WHERE client_id = ? AND period_month = '2025-09'", array($Gg['client']));
ok($vk === array('GVAT'), 'VAT withheld lines are saved as GVAT, taken from the ATC', json_encode($vk));
$ctxV = Reports::context(array('form' => 'SAWT', 'schedule' => 'S1', 'branch_id' => $Gg['branch'], 'year' => 2025, 'part' => 3, 'sawt_form' => '2550Q', 'amended' => 0));
$fv = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($ctxV, true)));
$fvBytes = file_get_contents(storage_path($fv['storage_path']));
$fvV = bir_v74_sawt($fvBytes, '004267719', '0000', '2550Q', '09/2025');
ok((int)$fv['record_count'] === $vst['valid'] && strpos($fvBytes, 'HSAWT,H2550Q,') === 0 && strpos($fvBytes, ',WV0') !== false && $fvV === array(),
    'the 2550Q SAWT carries only the VAT withheld and passes the ported v7.4 checks', json_encode(array($fv['record_count'], $fvV)));
$ctxE = Reports::context(array('form' => 'SAWT', 'schedule' => 'S1', 'branch_id' => $Gg['branch'], 'year' => 2025, 'part' => 3, 'sawt_form' => '1702Q', 'amended' => 0));
throws(function () use ($ctxE) { Reports::generate($ctxE, true); }, 'the 1702Q SAWT of the same quarter leaves the VAT withheld out', 'no records');
ok(count(Imports::sawtLines($Gg['client'], null, '2025-09', null, array('GVAT'))) === $vst['valid'] && !Imports::sawtLines($Gg['client'], null, '2025-09', null, array('EWT')),
    'the SAWT layout export picks the lines of the return it is for');
$v74 = Checks::v74Atcs();
ok(isset($v74['WC160'], $v74['WV010'], $v74['WB030']) && !isset($v74['WZ999']) && count($v74) === 469, 'the v7.4 ATC table has the 469 codes its validator accepts, VAT and percentage tax included');

// =====================================================================
section('1604-E alphalist template (Schedule 3)');
$etype = Imports::type('alphalist_1604e');
$Ee = make_org('Eagle', '006112233', 'firm_owner', 'EAGLE WITHHOLDING AGENT CORP');
login_as($Ee['user'], $Ee['org'], $Ee['client']);
$eClient = DB::row('SELECT * FROM client_entities WHERE id = ?', array($Ee['client']));
$eTpl = Imports::template('alphalist_1604e', true, array('client' => $eClient, 'month' => '2025-12'));
$eXml = zip_part($eTpl, 'xl/sharedStrings.xml') . zip_part($eTpl, 'xl/worksheets/sheet1.xml');
ok(strpos($eXml, 'BIR FORM 1604E - SCHEDULE 3') !== false && strpos($eXml, 'AS OF DECEMBER 31,2025') !== false && strpos($eXml, '006112233') !== false && strpos($eXml, 'Grand Total') !== false,
    'the 1604-E template has the Schedule 3 title lines of the BIR printout with the client TIN');
$eimport = function ($bytes, $opts = array()) use ($Ee, $etype) {
    $rel = 'uploads/e1604-' . mt_rand() . '.xlsx';
    file_put_contents(storage_path($rel), $bytes);
    $job = DB::insert('import_jobs', array('organization_id' => $Ee['org'], 'client_id' => $Ee['client'], 'branch_id' => $Ee['branch'], 'import_type' => 'alphalist_1604e', 'original_filename' => 'e.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $bytes), 'sheet_name' => null, 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $Ee['user'], 'created_at' => now()));
    $rows = Imports::readRows(DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job)), 80);
    $det = Imports::detectHeader($etype, $rows);
    $meta = Imports::reportMeta($rows, $det[0]);
    list($map, $exact) = Imports::suggestMapping($etype, Imports::headersOf($rows, $det[1], $det[0]));
    if (empty($opts['period_month']) && $meta['month']) {
        $opts['period_month'] = $meta['month'];
    }
    DB::update('import_jobs', array('header_row' => $det[1], 'mapping_json' => json_encode($map), 'options_json' => json_encode($opts + array('header_from' => $det[0]))), 'id = ?', array($job));
    Imports::prepare($job);
    do { $st = Imports::stageChunk($job, 100); } while (!$st['done']);
    return array($job, $st, $exact, $det, $meta);
};
list($ej1, $est1, $eex1, $edet1, $emeta1) = $eimport($eTpl);
ok($eex1 && $emeta1['month'] === '2025-12' && (int)$emeta1['schedule'] === 3 && $emeta1['tin'] === '006112233' && $est1['errors'] === 0 && $est1['total'] > 0 && $est1['valid'] === $est1['total'],
    'the 1604-E template imports back: header found, as of December 2025, Schedule 3, no errors', json_encode(array($emeta1, $est1)));
Imports::commit($ej1, 'all');
$er = DB::all('SELECT * FROM withholding_entries WHERE client_id = ? ORDER BY id', array($Ee['client']));
ok(count($er) === $est1['valid'] && $er[0]['direction'] === 'agent' && $er[0]['tax_kind'] === 'EWT' && $er[0]['period_month'] === '2025-12' && $er[0]['payment_date'] === '2025-12-31',
    'its lines are saved as taxes we withheld on December 31, 2025', json_encode($er[0]));

// =====================================================================
section('Sales in the VAT sales workbook format');
$Ss = make_org('Salinas', '555000111', 'firm_owner', 'VAT SALES TEST CORP');
login_as($Ss['user'], $Ss['org'], $Ss['client']);
$sltype = Imports::type('sales');
$slcols = Imports::templateColumns($sltype);
ok(implode('|', array_map(function ($c) { return $c['title']; }, $slcols)) === 'CUSTOMER TIN|CUSTOMER BRANCH CODE|REGISTERED NAME|LAST NAME|FIRST NAME|MIDDLE NAME|SUBSTREET, STREET, BARANGAY|DISTRICT, CITY, ZIP CODE|CLIENT BRANCH CODE|INVOICE NO|INVOICE DATE|GROSS TAXABLE|EXEMPT SALES|ZERO RATED SALES|TAXABLE SALES|VAT RATE|OUTPUT VAT',
    'the sales template has the 17 columns of the VAT sales workbook in order');
$sltpl = Imports::template('sales', false);
ok(strpos(zip_part($sltpl, 'xl/worksheets/sheet1.xml'), 'pane') === false && strpos(zip_part($sltpl, 'xl/worksheets/sheet1.xml'), 'sheetProtection') === false, 'the template, like the workbook, has no frozen pane or sheet protection');
$slrow = function ($tin, $co, $last, $first, $a2, $no, $date, $gross, $ex, $zr, $tx, $rate, $vat) {
    return array($tin, '', $co, $last, $first, '', '1 MAIN ST', $a2, '', $no, $date, $gross, $ex, $zr, $tx, $rate, $vat);
};
$slLines = array(
    $slrow('100-200-300', 'GROSS ONLY CORP', '', '', 'CEBU CITY 6000', 'SI-1', '09/02/2026', 112000, '', '', '', 12, ''),
    $slrow('100-200-301', 'NET ONLY CORP', '', '', 'CEBU CITY', 'SI-2', '09/03/2026', '', '', '', 45000, 12, ''),
    $slrow('100-200-302', 'ALL THREE CORP', '', '', 'CEBU CITY', 'SI-3', '09/04/2026', 1000, '', '', 892.86, 12, 107.14),
    $slrow('', '', 'DELA CRUZ', 'JUAN', 'TALISAY CITY 6045', 'SI-4', '09/05/2026', '', 8500, '', '', '', ''),
    $slrow('100-200-303', 'EXPORTER INC', '', '', 'LAPU-LAPU CITY', 'SI-5', '09/06/2026', '', '', 250000, '', 0, ''),
    $slrow('100-200-304', 'ODD GROSS CORP', '', '', 'CEBU CITY', 'SI-6', '09/07/2026', 99.99, '', '', '', '', ''),
    $slrow('100-200-305', 'MISMATCH CORP', '', '', 'CEBU CITY', 'SI-7', '09/08/2026', 1000, '', '', 800, 12, 120),
    $slrow('100-200-306', 'FRACTION CORP', '', '', 'CEBU CITY', 'SI-8', '09/09/2026', 1120, '', '', '', 0.12, ''),
);
$slimport = function ($bytes, $sheet = 'Data') use ($Ss, $sltype) {
    $rel = 'uploads/sl-' . mt_rand() . '.xlsx';
    file_put_contents(storage_path($rel), $bytes);
    $job = DB::insert('import_jobs', array('organization_id' => $Ss['org'], 'client_id' => $Ss['client'], 'branch_id' => $Ss['branch'], 'import_type' => 'sales', 'original_filename' => 's.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $bytes), 'sheet_name' => $sheet, 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $Ss['user'], 'created_at' => now()));
    $jr = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job));
    list($map, $exact) = Imports::suggestMapping($sltype, Imports::headersOf(Imports::readRows($jr), 1));
    DB::update('import_jobs', array('mapping_json' => json_encode($map), 'options_json' => json_encode(array('date_format' => 'auto'))), 'id = ?', array($job));
    Imports::prepare($job);
    do { $st = Imports::stageChunk($job, 100); } while (!$st['done']);
    return array($job, $st, $exact, $map);
};
list($slj, $slst, $slexact) = $slimport(XlsxWriter::build(array(array('name' => 'Data', 'columns' => $slcols, 'rows' => $slLines))));
$slr = DB::all('SELECT row_no, status, normalized_json, errors_json, warnings_json FROM import_rows WHERE job_id = ? ORDER BY row_no', array($slj));
$rec = function ($i) use ($slr) { $n = json_decode($slr[$i]['normalized_json'], true); return $n['record']; };
ok($slexact && $slst['total'] === 8 && $slst['valid'] === 6 && $slst['errors'] === 2, 'the workbook headers map by themselves; 6 rows valid and 2 refused', json_encode($slst));
ok($rec(0)['taxable_amount'] === '100000.00' && $rec(0)['output_vat'] === '12000.00' && $rec(0)['vat_rate'] === '12.0000', 'GROSS TAXABLE 112,000 at 12% gives taxable sales 100,000 and output VAT 12,000');
ok($rec(1)['output_vat'] === '5400.00' && Records::salesGross($rec(1)) === 5040000, 'TAXABLE SALES alone gives OUTPUT VAT 12% and a gross of 50,400');
ok($rec(2)['taxable_amount'] === '892.86' && $rec(2)['output_vat'] === '107.14' && !$slr[2]['errors_json'], 'gross, taxable and VAT that add up are kept as given');
ok($rec(3)['exempt_amount'] === '8500.00' && $rec(3)['output_vat'] === '0.00' && $rec(3)['taxable_amount'] === '0.00', 'an individual customer with exempt sales only');
ok($rec(4)['zero_rated_amount'] === '250000.00' && $rec(4)['vat_rate'] === '0.0000', 'zero-rated sales at a 0% rate');
ok($rec(5)['taxable_amount'] === '89.28' && $rec(5)['output_vat'] === '10.71' && Records::salesGross($rec(5)) === 9999, 'an odd gross is split so taxable sales + VAT equal it to the centavo', json_encode($rec(5)));
$e6 = json_decode($slr[6]['errors_json'], true);
$e7 = json_decode($slr[7]['errors_json'], true);
ok(isset($e6['gross_taxable']) && strpos($e6['gross_taxable'], '920.00') !== false, 'a gross that disagrees with taxable sales + VAT is refused', json_encode($e6));
ok(isset($e7['vat_rate']), 'a VAT rate written as a fraction (0.12) is refused');
Imports::commit($slj, 'valid');
$cp = DB::row('SELECT * FROM counterparties WHERE client_id = ? AND tin = ?', array($Ss['client'], '100200300'));
$cpi = DB::row('SELECT * FROM counterparties WHERE client_id = ? AND last_name = ?', array($Ss['client'], 'DELA CRUZ'));
ok($cp['address1'] === '1 MAIN ST' && $cp['address2'] === 'CEBU CITY 6000' && (int)$cp['is_customer'] === 1 && $cpi['tin'] === null, 'customers and their two address lines are added to the directory');
list(, $slst2) = $slimport(XlsxWriter::build(array(array('name' => 'Data', 'columns' => $slcols, 'rows' => array_slice($slLines, 0, 6)))));
ok($slst2['duplicates'] === 6 && $slst2['valid'] === 0, 're-importing the same sales finds every row as a duplicate', json_encode($slst2));
$oldCols = array();
foreach (array('CUSTOMER TIN', 'CUSTOMER BRANCH CODE', 'REGISTERED NAME', 'LAST NAME', 'FIRST NAME', 'MIDDLE NAME', 'ADDRESS 1', 'ADDRESS 2', 'CLIENT BRANCH CODE', 'INVOICE NO', 'INVOICE DATE', 'EXEMPT SALES', 'ZERO RATED SALES', 'TAXABLE SALES', 'OUTPUT VAT', 'DESCRIPTION') as $t) {
    $oldCols[] = array('title' => $t, 'format' => 'text');
}
list($olj, $olst, , $olmap) = $slimport(XlsxWriter::build(array(array('name' => 'Data', 'columns' => $oldCols, 'rows' => array(array('100-200-399', '0000', 'OLD LAYOUT CORP', '', '', '', '9 OLD ST', 'CEBU CITY', '', 'SI-9', '09/10/2026', '0', '0', '5000', '600', 'Old file'))))));
$oln = json_decode(DB::val('SELECT normalized_json FROM import_rows WHERE job_id = ?', array($olj)), true);
ok($olst['valid'] === 1 && $oln['party']['address1'] === '9 OLD ST' && $oln['record']['description'] === 'Old file' && $oln['record']['vat_rate'] === '12.0000', 'workbooks in the earlier sales layout still import', json_encode($olst));
$sm = Records::module('sales');
list($d, $e) = Records::validate($sm, array('branch_id' => $Ss['branch'], 'counterparty_id' => $cp['id'], 'invoice_date' => '2026-09-20', 'gross_taxable' => '5,600.00', 'vat_rate' => '12'), $Ss['client']);
ok(!$e && $d['taxable_amount'] === '5000.00' && $d['output_vat'] === '600.00' && !isset($d['gross_taxable']), 'the entry form takes a gross taxable and stores taxable sales and VAT', json_encode($e));
list($d, $e) = Records::validate($sm, array('branch_id' => $Ss['branch'], 'counterparty_id' => $cp['id'], 'invoice_date' => '2026-09-20'), $Ss['client']);
ok(isset($e['gross_taxable']), 'a sale without any amount is refused');
list($cu) = Records::salesCustomer(array('v_tin' => '100-200-300'), $Ss['client']);
list($cu2, $cue2) = Records::salesCustomer(array('v_tin' => '', 'v_company' => 'WALK-IN CUSTOMER', 'v_address2' => 'MANDAUE CITY 6014'), $Ss['client']);
ok($cu['id'] === (int)$cp['id'] && !$cu2['id'] && !$cue2 && $cu2['fields']['zip_code'] === '6014' && $cu2['fields']['is_customer'] === 1, 'the entry form finds a customer by TIN, or prepares a new one with its ZIP code');
$ctxSl = Reports::context(array('form' => 'SLS', 'schedule' => 'S1', 'branch_id' => $Ss['branch'], 'year' => 2026, 'part' => 9, 'sawt_form' => null, 'amended' => 0));
$fsl = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($ctxSl, true)));
ok($fsl['total_tax'] === '17517.85' && (int)$fsl['record_count'] === 6, 'the Summary List of Sales carries the imported output VAT', $fsl['total_tax'] . ' ' . $fsl['record_count']);
// Problems are explained cell by cell: a gross typed again as zero-rated, and an invoice number used twice.
list($pj) = $slimport(XlsxWriter::build(array(array('name' => 'Data', 'columns' => $slcols, 'rows' => array(
    $slrow('249-048-941', 'INTEGRATED FLOW SYSTEMS', '', '', 'LAPU LAPU CITY', 'SI-000000', '07/03/2026', 970842.07, '0', 970842.07, '0', '0', '0'),
    $slrow('267-704-538', 'CEBU COFFEE VENTURES INC', '', '', 'MANDAUE CITY', 'SI-000000', '07/08/2026', 8695.96, '0', '0', 7764.25, 12, 931.71))))));
$pjob = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($pj));
$psum = Imports::problemSummary($pjob);
$pe = json_decode(DB::val('SELECT errors_json FROM import_rows WHERE job_id = ? AND row_no = 2', array($pj)), true);
ok(isset($pe['gross_taxable']) && strpos($pe['gross_taxable'], 'same amount as the zero-rated sales') !== false, 'a gross typed again under ZERO RATED SALES is explained as such', json_encode($pe));
ok(count($psum) === 2 && $psum[0]['kind'] === 'error' && $psum[0]['col'] === 'L' && $psum[0]['rows'] === array(2) && $psum[1]['key'] === 'invoice_no' && $psum[1]['rows'] === array(2, 3),
    'the correction list names the column and rows: GROSS TAXABLE (L) on row 2, invoice SI-000000 on rows 2 and 3', json_encode($psum));
$loc = Imports::locate(Imports::fieldColumns($pjob), 'gross_taxable', 2, json_decode(DB::val('SELECT raw_json FROM import_rows WHERE job_id = ? AND row_no = 2', array($pj)), true), 'x');
ok($loc['ref'] === 'L2' && $loc['title'] === 'GROSS TAXABLE' && $loc['value'] === '970842.07', 'each message points at its cell and shows what the cell held', json_encode($loc));
ok(strpos(Imports::correctionsWorkbook($pj), 'PK') === 0, 'the corrections workbook is produced with located messages');
list($fmsg) = explain_failure(new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'gsmart_bir.upload_sessions' doesn't exist", 0));
ok(strpos($fmsg, 'upload_sessions') !== false && strpos($fmsg, 'Reference GSB-') !== false, 'a server failure is explained in plain words with a reference for the log');
if (getenv('GSB_SALES_SAMPLE') && is_file(getenv('GSB_SALES_SAMPLE'))) {
    list($uj, $ust, $uexact, $umap) = $slimport(file_get_contents(getenv('GSB_SALES_SAMPLE')), 'Sheet1');
    $tplWidths = array_map(function ($c) { return $c['width']; }, $slcols);
    ok($uexact && count($umap) === 17 && $ust['total'] === 1 && $ust['errors'] === 1, 'the attached VAT sales workbook maps all 17 columns by itself; its "New Field" notes are reported, not saved', json_encode(array($ust, $umap)));
}

// =====================================================================
section('Taxes we withheld: revised template, schedule 1 or 2, tax codes from Excel');
$Ww = make_org('Wendell', '605188970', 'firm_owner', 'A3 FACILITY TEST CORP');
$wb1226 = DB::insert('branches', array('organization_id' => $Ww['org'], 'client_id' => $Ww['client'], 'branch_code' => '1226', 'name' => 'Branch 1226', 'is_head_office' => 0, 'rdo_code' => '081', 'status' => 'active', 'created_at' => now()));
$rev = DB::insert('users', array('email' => 'wendell.reviewer@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Wendell reviewer', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
DB::insert('memberships', array('user_id' => $rev, 'organization_id' => $Ww['org'], 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
login_as($Ww['user'], $Ww['org'], $Ww['client']);
$wtype = Imports::type('withholding');
$wcols = Imports::templateColumns($wtype);
ok(implode('|', array_map(function ($c) { return $c['title']; }, $wcols)) === 'PAYEE TIN|PAYEE BRANCH CODE|REGISTERED NAME|LAST NAME|FIRST NAME|MIDDLE NAME|ADDRESS 1|ADDRESS 2|CLIENT BRANCH CODE|KIND|ATC|PAYMENT DATE|NATURE OF PAYMENT|INCOME PAYMENT|TAX RATE|TAX WITHHELD|SCHEDULE',
    'the template ends at SCHEDULE: REFERENCE NO and RATE EXCEPTION NOTE are removed');
$wtpl = Imports::template('withholding', true);
$wx = zip_part($wtpl, 'xl/worksheets/sheet1.xml');
$wr = new XlsxReader(tmp_file($wtpl));
ok(strpos($wx, "<formula1>&apos;ATC codes&apos;!\$A\$2:\$A\$") !== false && strpos($wx, 'sqref="K2:K20000"') !== false && $wr->sheetNames()[1] === 'ATC codes',
    'the ATC column is a dropdown of the tax codes saved in the system, listed on the sheet ATC codes');
ok(strpos($wx, '<formula1>&quot;1,2,3&quot;</formula1>') !== false && strpos($wx, 'sqref="Q2:Q20000"') !== false, 'SCHEDULE is a dropdown of 1, 2 and 3');
$wm = Records::module('withholding');
$visible = array_values(array_filter(array_map(function ($f) { return empty($f['hidden']) ? $f['key'] : null; }, $wm['fields'])));
ok(!in_array('reference_no', $visible, true) && !in_array('rate_exception_note', $visible, true) && strpos($wm['fields'][array_search('schedule_tag', array_column($wm['fields'], 'key'))]['options']['A'], '1:') === 0,
    'the entry form no longer asks for the reference or the rate note, and shows schedule 1 and 2');
// Your sample row: TIN with a 3-digit branch, client branch 1226, ATC WC860 not yet in the system, rate 0.005.
$sampleRow = array('006-034-537-000', '', 'PTT Philippines Corporation', '', '', '', '32/F Tower 6801 Ayala Ave., Makati City, Philippines', '', 1226, 'EWT', 'WC860', '07/31/2026',
    'INCOME FAYMENTS MADE BY TOP WITHHOLDING AGENTS', 4154566.44, 0.005, 20772.83, 'A');
$wimport = function ($rows) use ($Ww, $wtype, $wcols) {
    $bytes = XlsxWriter::build(array(array('name' => 'Data', 'columns' => $wcols, 'rows' => $rows)));
    $rel = 'uploads/wh-' . mt_rand() . '.xlsx';
    file_put_contents(storage_path($rel), $bytes);
    $job = DB::insert('import_jobs', array('organization_id' => $Ww['org'], 'client_id' => $Ww['client'], 'branch_id' => $Ww['branch'], 'import_type' => 'withholding', 'original_filename' => 'qap.xlsx',
        'stored_path' => $rel, 'file_sha256' => hash('sha256', $bytes), 'sheet_name' => 'Data', 'header_row' => 1, 'status' => 'uploaded', 'created_by' => $Ww['user'], 'created_at' => now()));
    list($map) = Imports::suggestMapping($wtype, Imports::headersOf(Imports::readRows(DB::row('SELECT * FROM import_jobs WHERE id = ?', array($job))), 1));
    DB::update('import_jobs', array('mapping_json' => json_encode($map), 'options_json' => json_encode(array('date_format' => 'auto'))), 'id = ?', array($job));
    Imports::prepare($job);
    do { $st = Imports::stageChunk($job, 100); } while (!$st['done']);
    return array($job, $st);
};
list($wj1, $wst1) = $wimport(array($sampleRow));
$we1 = json_decode(DB::val('SELECT errors_json FROM import_rows WHERE job_id = ?', array($wj1)), true);
ok($wst1['errors'] === 1 && isset($we1['atc_code']) && strpos($we1['atc_code'], 'WC860') !== false, 'before WC860 is in the system, the row is refused at the ATC cell', json_encode($we1));
// Add WC860 from an Excel upload, approved by a second person.
$atcBook = XlsxWriter::build(array(array('name' => 'Tax codes', 'columns' => TaxCodes::columns(), 'rows' => array(
    array('WC860', 'EWT', 'Non-individual', '0.5', 'Income payments made by top withholding agents'),
    array('WC158', 'EWT', 'Non-individual', '1', 'Top withholding agent: purchase of goods'),
    array('WC160', 'EWT', 'Non-individual', '2', 'Top withholding agent: purchase of services, revised wording'),
    array('WI860', 'EWT', 'Non-individual', 'abc', ''),
    array('WC860', 'EWT', 'Any', '0.5', 'Twice in the file'),
))));
$atcRead = TaxCodes::read(tmp_file($atcBook), 'xlsx');
$plans = array_map(function ($r) { return $r['errors'] ? 'error' : $r['plan']; }, $atcRead['rows']);
ok($plans === array('new', 'same', 'change', 'error', 'error'), 'an ATC upload is checked row by row: new, unchanged, changed, and errors', json_encode($plans));
$e4 = $atcRead['rows'][3]['errors'];
ok($e4['rate']['ref'] === 'D5' && isset($e4['description']) && isset($atcRead['rows'][4]['errors']['atc_code']), 'upload errors point at their cells (D5 RATE, a blank DESCRIPTION, a code twice in the file)', json_encode($e4));
foreach ($atcRead['rows'] as $r) {
    if (!$r['errors'] && in_array($r['plan'], array('new', 'change'), true)) {
        TaxCodes::propose($r['data'], 'test upload');
    }
}
$wc860 = DB::row('SELECT * FROM tax_codes WHERE atc_code = ?', array('WC860'));
$wc160new = DB::row('SELECT * FROM tax_codes WHERE atc_code = ? AND effective_from = ?', array('WC160', today()));
ok($wc860['status'] === 'approved' && $wc860['effective_from'] === TaxCodes::FROM_START && $wc860['rate'] === '0.5000' && $wc160new && $wc160new['status'] === 'approved',
    'with approvals off, a new code applies from the start and a change from today, both in use as soon as they are saved');
ok(!DB::val('SELECT COUNT(*) FROM tax_codes WHERE status = ?', array('pending_review')) || !DB::val('SELECT COUNT(*) FROM tax_codes WHERE status = ? AND atc_code IN (?, ?)', array('pending_review', 'WC860', 'WC160')),
    'nothing from the upload waits for approval');
$cur = array();
foreach (TaxCodes::current('WC') as $r) { $cur[$r['atc_code']] = $r; }
ok($cur['WC860']['status'] === 'approved' && strpos($cur['WC160']['description'], 'revised wording') !== false && count($cur['WC160']['older']) >= 1, 'the list shows one current row per code, older versions kept');
// with approval by a second person on, a code waits and its maker cannot approve it
set_sys_config('require_approvals', '1');
$wi861 = TaxCodes::propose(array('atc_code' => 'WI861', 'tax_kind' => 'EWT', 'payee_type' => 'individual', 'rate' => '1.0000', 'description' => 'Test code for maker-checker'), 'test');
ok(DB::val('SELECT status FROM tax_codes WHERE id = ?', array($wi861)) === 'pending_review', 'with approvals on, a new code waits for approval');
throws(function () use ($wi861) { TaxCodes::approve($wi861); }, 'the person who added a code cannot approve it', 'Someone other');
login_as($rev, $Ww['org'], $Ww['client']);
TaxCodes::approve($wi861);
ok(DB::val('SELECT status FROM tax_codes WHERE id = ?', array($wi861)) === 'approved', 'a second person approves it');
set_sys_config('require_approvals', '0');
ok(Records::taxCode('WC160', 'EWT', '2025-01-15')['description'] !== $cur['WC160']['description'], 'earlier periods keep the version they had');
login_as($Ww['user'], $Ww['org'], $Ww['client']);
list($wj2, $wst2) = $wimport(array($sampleRow, array_replace($sampleRow, array(10 => 'WC860', 16 => '2', 15 => 20700))));
$wr2 = DB::all('SELECT row_no, status, normalized_json, errors_json, warnings_json FROM import_rows WHERE job_id = ? ORDER BY row_no', array($wj2));
$wn2 = json_decode($wr2[0]['normalized_json'], true);
ok($wr2[0]['status'] === 'valid' && $wn2['record']['tax_rate'] === '0.5000' && $wn2['record']['tax_withheld'] === '20772.83' && $wn2['record']['schedule_tag'] === 'A' && $wn2['record']['branch_id'] === $wb1226
    && $wn2['party']['tin'] === '006034537' && strpos($wr2[0]['warnings_json'], 'decimal') !== false, 'your sample row imports once WC860 is added: rate 0.005 read as 0.5%, tax 20,772.83, client branch 1226', json_encode(array($wr2[0], $wn2['record'])));
$we2 = json_decode($wr2[1]['errors_json'], true);
ok($wr2[1]['status'] === 'error' && isset($we2['tax_withheld']) && strpos($we2['tax_withheld'], 'Correct the tax withheld or the tax rate') !== false, 'a tax that is not income x rate is refused with what to correct', json_encode($we2));
list($d, $e, $w) = Records::validate($wm, array('branch_id' => $Ww['branch'], 'counterparty_id' => DB::val('SELECT id FROM counterparties WHERE client_id = ? LIMIT 1', array($Ww['client'])) ?: 0,
    'tax_kind' => 'EWT', 'atc_code' => 'WC158', 'payment_date' => '2026-07-10', 'income_amount' => '1000', 'tax_rate' => '2', 'schedule_tag' => '2'), $Ww['client'], array('skip_party' => true, 'party_entity_type' => 'non_individual'));
ok(!$e && $d['schedule_tag'] === 'B' && $d['tax_withheld'] === '20.00' && isset($w['tax_rate']), 'schedule 2 is saved as the second schedule; a rate other than the ATC is kept with a warning', json_encode(array($e, $w)));

// =====================================================================
section('A payee with no TIN in the QAP file');
$Nt = make_org('Notin', '555111222', 'firm_owner', 'NO TIN PAYEE TEST CORP');
login_as($Nt['user'], $Nt['org'], $Nt['client']);
$ntCp = DB::insert('counterparties', array('organization_id' => $Nt['org'], 'client_id' => $Nt['client'], 'tin' => null, 'branch_code' => '0000', 'entity_type' => 'non_individual',
    'registered_name' => 'CONSINGSAY CORPORATION', 'is_payee' => 1, 'status' => 'active', 'created_at' => now()));
$ntCp2 = DB::insert('counterparties', array('organization_id' => $Nt['org'], 'client_id' => $Nt['client'], 'tin' => '300200100', 'branch_code' => '0000', 'entity_type' => 'non_individual',
    'registered_name' => 'MABUHAY PRINTING INC', 'is_payee' => 1, 'status' => 'active', 'created_at' => now()));
foreach (array($ntCp, $ntCp2) as $i => $cpid) {
    list($d, $e) = Records::validate($wh, array('branch_id' => $Nt['branch'], 'counterparty_id' => $cpid, 'tax_kind' => 'EWT', 'atc_code' => 'WC158', 'payment_date' => '2026-05-1' . $i,
        'income_amount' => '10,000.00', 'tax_rate' => '', 'tax_withheld' => '', 'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => ''), $Nt['client']);
    ok(!$e, 'a withholding record for ' . ($i ? 'a payee with a TIN' : 'a payee with no TIN') . ' is valid', json_encode($e));
    Records::save($wh, $d);
}
$ntFile = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate(Reports::context(array('form' => '1601EQ', 'schedule' => 'S1', 'branch_id' => $Nt['branch'], 'year' => 2026, 'part' => 5, 'sawt_form' => null, 'amended' => 0)), true)));
$ntIssues = DB::all('SELECT r.level, e.severity, e.message FROM validation_errors e JOIN validation_runs r ON r.id = e.validation_run_id WHERE r.dat_file_id = ?', array($ntFile['id']));
$ntErr = array_values(array_filter($ntIssues, function ($i) { return $i['severity'] === 'error'; }));
ok($ntFile['status'] === 'pending_bir' && !$ntErr, 'the QAP is written and passes Levels 1 and 2 with a payee that has no TIN', json_encode($ntIssues));
ok(strpos(json_encode($ntIssues), 'CONSINGSAY CORPORATION) has no TIN') !== false && strpos(json_encode($ntIssues), 'Payee TIN is') === false, 'Level 1 notes the missing TIN as a warning; Level 2 does not call it an error');
$ntBytes = file_get_contents(storage_path($ntFile['storage_path']));
ok((bool)preg_match('/^D1,1601EQ,[^\r\n]*,,(""|),?"?CONSINGSAY CORPORATION/m', $ntBytes) || strpos($ntBytes, '"CONSINGSAY CORPORATION"') !== false, 'the payee line is written', substr($ntBytes, 0, 400));

// =====================================================================
section('QAP, MAP, 1604-E and 1604-F as the BIR Alphalist module v7.4 writes them');
DB::q('UPDATE tax_codes SET status = ?', array('approved'));
$Vq = make_org('Vqap', '007100200', 'firm_owner', 'VALIDATOR QAP TEST CORP');
login_as($Vq['user'], $Vq['org'], $Vq['client']);
$vcp = function ($tin, $reg, $last = null, $first = null, $foreign = null) use ($Vq) {
    return DB::insert('counterparties', array('organization_id' => $Vq['org'], 'client_id' => $Vq['client'], 'tin' => $tin, 'branch_code' => '0000',
        'entity_type' => $reg ? 'non_individual' : 'individual', 'registered_name' => $reg, 'last_name' => $last, 'first_name' => $first, 'middle_name' => $last ? 'CRUZ' : null,
        'foreign_address' => $foreign, 'is_payee' => 1, 'created_at' => now()));
};
$vA = $vcp('300111222', 'ALPHA SERVICES CORP');
$vB = $vcp('300111333', null, 'REYES', 'MARIA');
$vC = $vcp('300111444', 'EXEMPT COOPERATIVE');
$vD = $vcp('', 'OVERSEAS LICENSOR LTD', null, null, '1 MARKET ST, SINGAPORE');
$vE = $vcp('300111555', null, 'SANTOS', 'PEDRO');
$vwh = function ($cp, $kind, $atc, $month, $income, $rate, $tag) use ($Vq) {
    static $k = 0;
    $k++;
    DB::insert('withholding_entries', array('organization_id' => $Vq['org'], 'client_id' => $Vq['client'], 'branch_id' => $Vq['branch'], 'counterparty_id' => $cp, 'direction' => 'agent',
        'tax_kind' => $kind, 'atc_code' => $atc, 'payment_date' => $month . '-15', 'period_month' => $month, 'income_amount' => $income, 'tax_rate' => $rate,
        'tax_withheld' => Money::toPlain(Money::applyRate(Money::fromDb($income), Money::rateUnits($rate))), 'schedule_tag' => $tag, 'status' => 'posted', 'source' => 'manual',
        'row_hash' => sha1('v74|' . $k), 'created_at' => now()));
};
$vwh($vA, 'EWT', 'WC160', '2026-03', '50000.00', '2.0000', 'A');
$vwh($vB, 'EWT', 'WI010', '2026-03', '30000.00', '5.0000', 'A');
$vwh($vC, 'EWT', 'WC158', '2026-03', '80000.00', '0.0000', 'B');
$vwh($vD, 'FWT', 'WC250', '2026-03', '100000.00', '20.0000', 'A');
$vwh($vE, 'FWT', 'WF360', '2026-03', '20000.00', '35.0000', 'B');
$vwh($vD, 'FWT', 'WC230', '2026-03', '40000.00', '0.0000', 'C');
$vwh($vA, 'GVAT', 'WV020', '2026-03', '60000.00', '5.0000', 'A');
$vwh($vA, 'GPT', 'WB080', '2026-03', '45000.00', '3.0000', 'A');
$vgen = function ($form, $schedule, $year, $part) use ($Vq) {
    $ctx = Reports::context(array('form' => $form, 'schedule' => $schedule, 'branch_id' => $Vq['branch'], 'year' => $year, 'part' => $part, 'sawt_form' => null, 'amended' => 0));
    $f = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($ctx, true)));
    return array($f, (string)Reports::storedBytes($f), $ctx);
};
ok(Datasets::generateSchedules('1601EQ')[0]['schedule_code'] === 'S1+S2' && Datasets::generateSchedules('1604F')[0]['schedule_code'] === 'S4-6' && Specs::scheduleLabel('S1-3') === 'Schedules 1 to 3',
    'each QAP and annual form is generated as one file for all its schedules');
list($qf, $qb, $qctx) = $vgen('1601EQ', 'S1+S2', 2026, 3);
$ql = explode("\r\n", rtrim($qb, "\r\n"));
ok($qf['filename'] === '00710020000000320261601EQ.DAT' && $ql[0] === 'HQAP,H1601EQ,007100200,0000,"VALIDATOR QAP TEST CORP",03/2026,081'
    && $ql[1] === 'D1,1601EQ,1,300111222,0000,"ALPHA SERVICES CORP",,,,03/2026,WC160,2.00,50000.00,1000.00'
    && $ql[3] === 'C1,1601EQ,007100200,0000,03/2026,80000.00,2500.00'
    && $ql[4] === 'D2,1601EQ,1,300111444,0000,"EXEMPT COOPERATIVE",,,,03/2026,WC158,80000.00' && $ql[5] === 'C2,1601EQ,007100200,0000,03/2026,80000.00' && count($ql) === 6,
    'the 1601-EQ holds the header once, Schedule 1 with rate and tax and its C1, then Schedule 2 with income only and its C2', implode(' | ', $ql));
$qv = bir_v74_withholding($qb, '1601EQ', '007100200', '0000', '03/2026');
ok($qv === array(), 'the 1601-EQ passes the ported v7.4 checks', json_encode($qv));
list($ff, $fb) = $vgen('1601FQ', 'S1-3', 2026, 3);
$fl = explode("\r\n", rtrim($fb, "\r\n"));
ok($fl[1] === 'D1,1601FQ,,0000,"OVERSEAS LICENSOR LTD",,,,03/2026,1,WC250,20.00,100000.00,20000.00'
    && $fl[3] === 'D2,1601FQ,300111555,0000,"SANTOS","PEDRO","CRUZ",03/2026,1,WF360,13000.00,20000.00,7000.00' && $fl[4] === 'C2,1601FQ,007100200,0000,03/2026,13000.00,20000.00,7000.00'
    && $fl[5] === 'D3,1601FQ,,0000,"OVERSEAS LICENSOR LTD",,,,03/2026,1,G,WC230,40000.00' && $fl[6] === 'C3,1601FQ,007100200,0000,03/2026,40000.00',
    'the 1601-FQ puts the sequence after the period, the fringe benefit and grossed-up value in Schedule 2 and the status code in Schedule 3', implode(' | ', $fl));
$fv = bir_v74_withholding($fb, '1601FQ', '007100200', '0000', '03/2026');
ok($fv === array(), 'the 1601-FQ passes the ported v7.4 checks', json_encode($fv));
foreach (array('1600VT' => 'WV020,,5.00,60000.00,3000.00', '1600PT' => 'WB080,,3.00,45000.00,1350.00') as $mf => $tail) {
    list($mfile, $mb) = $vgen($mf, 'S1', 2026, 3);
    $ml = explode("\r\n", rtrim($mb, "\r\n"));
    $mv = bir_v74_withholding($mb, $mf, '007100200', '0000', '03/2026');
    ok($ml[0] === 'HMAP,H' . $mf . ',007100200,0000,"VALIDATOR QAP TEST CORP",03/2026,081' && strpos($ml[1], 'DMAP,D' . $mf . ',1,300111222,0000,"ALPHA SERVICES CORP",,,,03/2026,' . $tail) === 0
        && strpos($ml[2], 'CMAP,C' . $mf . ',007100200,0000,03/2026,') === 0 && $mv === array(), 'the ' . $mf . ' has DMAP and CMAP lines with the empty nature of income and passes the ported v7.4 checks', json_encode(array($ml, $mv)));
}
list($ef, $eb) = $vgen('1604E', 'S3+S4', 2026, 1);
$el = explode("\r\n", rtrim($eb, "\r\n"));
ok($ef['filename'] === '007100200000012312026' . '1604E.DAT' && $el[0] === 'H1604E,007100200,0000,12/31/2026'
    && $el[1] === 'D3,1604E,007100200,0000,12/31/2026,1,300111222,0000,"ALPHA SERVICES CORP",,,,WC160,50000.00,2.00,1000.00'
    && $el[3] === 'C3,1604E,007100200,0000,12/31/2026,2500.00' && $el[4] === 'D4,1604E,007100200,0000,12/31/2026,1,300111444,0000,"EXEMPT COOPERATIVE",,,,WC158,80000.00'
    && $el[5] === 'C4,1604E,007100200,0000,12/31/2026,80000.00',
    'the 1604-E holds Schedule 3 (the QAP Schedule 1 payees, control with the tax only) and Schedule 4 (exempt payees, control with the income)', implode(' | ', $el));
$ev = bir_v74_withholding($eb, '1604E', '007100200', '0000', '12/31/2026');
ok($ev === array(), 'the 1604-E passes the ported v7.4 checks', json_encode($ev));
list($Ff, $Fb) = $vgen('1604F', 'S4-6', 2026, 1);
$Fl = explode("\r\n", rtrim($Fb, "\r\n"));
$Fv = bir_v74_withholding($Fb, '1604F', '007100200', '0000', '12/31/2026');
ok($Fl[1] === 'D4,1604F,007100200,0000,12/31/2026,1,,0000,"OVERSEAS LICENSOR LTD",,,,G,WC250,100000.00,20.00,20000.00' && $Fl[2] === 'C4,1604F,007100200,0000,12/31/2026,100000.00,20000.00'
    && $Fl[3] === 'D5,1604F,007100200,0000,12/31/2026,1,300111555,0000,"SANTOS","PEDRO","CRUZ",WF360,13000.00,20000.00,7000.00' && $Fv === array(),
    'the 1604-F carries the status code, the fringe benefit schedule and passes the ported v7.4 checks', json_encode(array($Fl, $Fv)));
$Fp = DatReader::parse($Fb, Specs::forFile($Ff));
ok(!array_filter($Fp['issues'], function ($i) { return $i['severity'] === 'error'; }) && count($Fp['rows']) === 3 && $Fp['row_parts'] === array(0, 1, 2), 'the combined 1604-F reads back with each line in its schedule');
// Old per-schedule layouts are retired; files made with the earlier layouts would fail the module.
ok(!DB::val("SELECT COUNT(*) FROM layout_specs WHERE form_code IN ('1601EQ','1601FQ','1600VT','1600PT','1604E','1604F') AND status <> 'retired' AND version_label <> 'Alphalist v7.4 (BIR data entry module)'"),
    'only the v7.4 layouts are in use for the QAP, MAP, 1604-E and 1604-F');
ok(count(bir_v74_withholding(str_replace('D2,1601EQ,1,300111444,0000,"EXEMPT COOPERATIVE",,,,03/2026,WC158,80000.00', 'D2,1601EQ,1,300111444,0000,"EXEMPT COOPERATIVE",,,,03/2026,WC158,0.00,80000.00,0.00', $qb), '1601EQ', '007100200', '0000', '03/2026')) >= 1,
    'the ported checks refuse a Schedule 2 line with rate and tax columns, as the earlier layout wrote it');
// Level 1 mirrors the validator: zero tax in 1601-EQ Schedule 1 and a tax that is not income x rate are errors.
$z = Checks::v74Withholding('1601EQ', 'S1', array('income' => 1000000, 'rate' => '2.0000', 'tax' => 0, 'atc' => 'WC160', 'last_name' => '', 'first_name' => '', 'middle_name' => ''));
$m = Checks::v74Withholding('1600VT', 'S1', array('income' => 1000000, 'rate' => '5.0000', 'tax' => 40000, 'atc' => 'WV020', 'last_name' => '', 'first_name' => '', 'middle_name' => ''));
ok(in_array('TAX_ZERO', array_map(function ($i) { return $i[1]; }, $z), true) && $m && $m[0][1] === 'TAX_V74' && $m[0][0] === 'error', 'data quality reports what the v7.4 validator refuses', json_encode(array($z, $m)));
// The 1604-E alphalist template's year totals go to the 1604-E Schedule 3 and not to the December QAP.
ok(Imports::tag1604eS3() === 'Y', 'lines from a 1604-E alphalist are kept as year totals');
$vwh($vA, 'EWT', 'WC160', '2026-12', '10000.00', '2.0000', 'Y');
$vwh($vB, 'EWT', 'WI010', '2026-12', '1000.00', '5.0000', 'A');
list(, $eb2) = $vgen('1604E', 'S3+S4', 2026, 1);
list(, $qb12) = $vgen('1601EQ', 'S1+S2', 2026, 12);
ok(strpos($eb2, 'WC160,60000.00,2.00,1200.00') !== false && strpos($qb12, 'WC160') === false && strpos($qb12, 'WI010,5.00,1000.00,50.00') !== false,
    'year totals appear in the 1604-E Schedule 3 and stay out of the December 1601-EQ');

// =====================================================================
section('1604-C: Schedules 1 and 2 in one file');
$Cm = make_org('Combo', '684785510', 'firm_owner', 'COMBINED ALPHALIST CORP');
login_as($Cm['user'], $Cm['org'], $Cm['client']);
ok(array_map(function ($x) { return $x['schedule_code']; }, Datasets::generateSchedules('1604C')) === array(Specs::COMBINED_CODE), 'Generate offers 1604-C only as Schedules 1 and 2 in one file');
$compRow = function ($empId, $sched, $amounts, $region) use ($Cm) {
    $d = array('organization_id' => $Cm['org'], 'client_id' => $Cm['client'], 'branch_id' => $Cm['branch'], 'employee_id' => $empId, 'tax_year' => 2025, 'schedule_code' => $sched,
        'region_code' => $region, 'employment_from' => '2025-01-01', 'employment_to' => '2025-12-31', 'factor_days' => $sched === 'S2' ? 312 : null, 'nationality' => 'FILIPINO',
        'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y', 'status' => 'posted', 'created_at' => now());
    foreach (Records::compFields() as $fields) {
        foreach ($fields as $k => $l) {
            $d[$k] = isset($amounts[$k]) ? $amounts[$k] : '0.00';
        }
    }
    return DB::insert('compensation_records', $d);
};
$e1 = DB::insert('employees', array('organization_id' => $Cm['org'], 'client_id' => $Cm['client'], 'branch_id' => $Cm['branch'], 'tin' => '901234567', 'tin_branch' => '0000',
    'last_name' => 'REYES', 'first_name' => 'MARIA', 'middle_name' => 'SANTOS', 'region_code' => 'NCR', 'status' => 'active', 'created_at' => now()));
$compRow($e1, 'S1', $set, 'NCR');
$cin = array('form' => '1604C', 'schedule' => Specs::COMBINED_CODE, 'branch_id' => $Cm['branch'], 'year' => 2025, 'part' => 1, 'sawt_form' => null, 'amended' => 0);
$cctx = Reports::context($cin);
ok(empty($cctx['spec']['composite']) && $cctx['preview']['bytes'] === $fixture, 'with only Schedule 1 employees the file is byte for byte the validator-passed sample');
$cf1 = Reports::generate($cctx, true);
$cfr1 = DB::row('SELECT * FROM dat_files WHERE id = ?', array($cf1));
ok($cfr1['schedule_code'] === Specs::COMBINED_CODE && $cfr1['status'] === 'pending_bir', 'the file is stored as 1604C S1+S2 and passes the structure check', json_encode($cfr1));
$e2 = DB::insert('employees', array('organization_id' => $Cm['org'], 'client_id' => $Cm['client'], 'branch_id' => $Cm['branch'], 'tin' => '124950665', 'tin_branch' => '0000',
    'last_name' => 'ABABAO', 'first_name' => 'ERLINDA', 'middle_name' => 'AMOLONG', 'region_code' => 'VII', 'is_mwe' => 1, 'status' => 'active', 'created_at' => now()));
$mweComp = $compRow($e2, 'S2', array('pres_gross' => '296250.00', 'pres_nt_smw' => '240000.00', 'pres_nt_13th' => '35850.00', 'pres_nt_contrib' => '20400.00', 'pres_nt_total' => '296250.00',
    'smw_per_day' => '769.23', 'smw_per_month' => '19999.98', 'smw_per_year' => '240000.00'), 'VII');
$cctx = Reports::context($cin);
$clines = explode("\r\n", rtrim($cctx['preview']['bytes'], "\r\n"));
$codes = array_map(function ($l) { return substr($l, 0, strpos($l, ',')); }, $clines);
ok(!empty($cctx['spec']['composite']) && $codes === array('H1604C', 'D1', 'C1', 'D2', 'C2'), 'both schedules go into one file: header, Schedule 1 lines and control, Schedule 2 lines and control', implode(' ', $codes));
ok(implode("\r\n", array_slice($clines, 0, 3)) . "\r\n" === $fixture && strpos($clines[3], 'D2,1604C,684785510,0000,12/31/2025,1,124950665,0000,"ABABAO","ERLINDA","AMOLONG",VII,') === 0,
    'Schedule 1 keeps the validated bytes; Schedule 2 numbers its lines from 1', $clines[3]);
ok(strpos($clines[4], 'C2,1604C,684785510,0000,12/31/2025,' . str_repeat('0.00,', 14) . '296250.00,769.23,19999.98,240000.00,0.00,0.00,0.00,0.00,35850.00,0.00,20400.00,0.00,296250.00,') === 0
    && substr($clines[4], -10) === ',240000.00' && count(explode(',', $clines[4])) === 45, 'each control line totals its own schedule (C2: the 45 fields of the BIR module)', $clines[4]);
ok(count(explode(',', $clines[3])) === 59 && substr($clines[3], -31) === ',FILIPINO,R,NA,Y,0.00,240000.00', 'a Schedule 2 line has the 59 fields of the BIR module, ending with substituted filing, PERA credit and the actual SMW', $clines[3]);
$cErr = array_filter($cctx['dq'], function ($i) { return $i['severity'] === 'error'; });
ok(!$cErr && $cctx['preview']['filename'] === '6847855100000123120251604C.DAT', 'one filename for the whole 1604-C, no data errors', json_encode(array_values($cErr)));
$cf2 = Reports::generate($cctx, true);
$cfr2 = DB::row('SELECT * FROM dat_files WHERE id = ?', array($cf2));
$l2 = DB::all('SELECT e.severity, e.message FROM validation_errors e JOIN validation_runs r ON r.id = e.validation_run_id WHERE r.dat_file_id = ? AND r.level = 2', array($cf2));
ok($cfr2['status'] === 'pending_bir' && !$l2 && (int)$cfr2['record_count'] === 2, 'Level 2 reads the combined file back with no issues', json_encode($l2));
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($cf1)) === 'superseded', 'the new file replaces the earlier one for the year');
$cspec = Specs::forFile($cfr2);
ok(count($cspec['parts']) === 2 && $cspec['parts'][1]['schedule_code'] === 'S2', 'the stored file remembers both layouts');
$tamp = str_replace($clines[4], str_replace(',296250.00,769.23,', ',296250.01,769.23,', $clines[4]), $cctx['preview']['bytes']);
$ts = Checks::structure($cspec, $tamp, $cfr2['filename'], $cctx['agent'], $cctx['period'], $cctx['dataset']['rows']);
ok(in_array('CONTROL_TOTAL', array_map(function ($i) { return $i['code']; }, $ts['issues']), true), 'a Schedule 2 control total that disagrees with its lines is caught');
$bad = str_replace($clines[2] . "\r\n", '', $cctx['preview']['bytes']);
$bs = DatReader::parse($bad, $cspec);
ok(in_array('NO_CONTROL', array_map(function ($i) { return $i['code']; }, $bs['issues']), true), 'a missing Schedule 1 control line is caught');
@unlink(storage_path($cfr2['storage_path']));
ok(Reports::storedBytes($cfr2) === $cctx['preview']['bytes'], 'a missing combined file is rebuilt from its snapshot with both layouts');
$det = DatReader::detect($cfr2['filename'], $cctx['preview']['bytes']);
ok($det && strpos($det[0]['key'], 'c:') === 0 && $det[0]['errors'] === 0, 'an uploaded combined 1604-C is recognised with both layouts', json_encode(array_map(function ($c) { return array($c['key'], $c['errors']); }, $det)));
ok(Specs::byKey($det[0]['key'])['parts'][0]['id'] === $cspec['parts'][0]['id'], 'the upload choice loads the joined layouts');
$rg = Reports::regenerate($cf2);
ok(DB::val('SELECT sha256 FROM dat_files WHERE id = ?', array($rg)) === $cfr2['sha256'] && DB::val('SELECT schedule_code FROM dat_files WHERE id = ?', array($rg)) === Specs::COMBINED_CODE,
    're-processing writes the same combined bytes');
$repTmp = tmp_file(file_get_contents(__DIR__ . '/fixtures/6847855100000123120251604C.TXT'));
Evidence::record($rg, array('validator_version' => '7.4', 'executed_on' => '2026-01-20', 'outcome' => '', 'patch_date' => '', 'report_type' => '', 'notes' => ''),
    array('name' => 'r.txt', 'tmp_name' => $repTmp, 'error' => 0, 'size' => filesize($repTmp)), null);
$partStatus = DB::col('SELECT status FROM layout_specs WHERE id IN (' . DB::in(array_map(function ($p) { return $p['id']; }, $cspec['parts'])) . ')', array_map(function ($p) { return $p['id']; }, $cspec['parts']));
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($rg)) === 'approved' && $partStatus === array('approved', 'approved'), 'a validator pass approves the file and both schedule layouts', json_encode($partStatus));

section('BIR Form 2316, September 2021 (ENCS)');
$pdfText = function ($pdf) {
    $t = '';
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $mm);
    foreach ($mm[1] as $st) {
        $u = @gzuncompress($st);
        if ($u !== false) {
            $t .= $u;
        }
    }
    return $t;
};
$p2316 = Certificates::build2316(Ctx::client(), DB::row('SELECT * FROM branches WHERE id = ?', array($Cm['branch'])), $mweComp);
$cid2316 = Certificates::issue($p2316, array('paper' => 'FOLIO', 'branch_id' => $Cm['branch']));
$c2316 = DB::row('SELECT * FROM certificates WHERE id = ?', array($cid2316));
$tx = $pdfText(file_get_contents(storage_path($c2316['pdf_path'])));
ok($c2316['template_revision'] === Certificates::REV_2316 && $c2316['template_revision'] === '2316 September 2021 (ENCS), BIR v7.4' && strlen(Certificates::REV_2316) <= 40,
    'the certificate records the September 2021 revision as printed by the BIR module v7.4');
$need = array('(September 2021 \(ENCS\))', '(2316 09/21 ENCS)', '(Local)', '(Home Address)', '(Foreign Address)', '(Telephone Number)', '(44A)', '(51B)', '(\(Specify\))',
    '(To be accomplished under substituted filing)', '(53)', '(54)', '(ERLINDA AMOLONG ABABAO)', '(ABABAO, ERLINDA AMOLONG)', '(296,250.00)', '(240,000.00)', '(769.23)', '(19,999.98)',
    '(Amount Paid, if CTC)', '(5% Tax Credit \(PERA Act of 2008\))', '(X)');
$missing = array();
foreach ($need as $n) {
    if (strpos($tx, $n) === false) {
        $missing[] = $n;
    }
}
ok(!$missing, 'the PDF carries the items of the new form: 6B to 6E, 8, 44A/44B, 51A/51B, CTC, 53 and 54, and the MWE mark', implode(' ', $missing));
ok(strpos($tx, 'January 2018') === false && strpos($tx, '01/18ENCS') === false, 'nothing of the January 2018 layout remains in the text');
$pdfA4 = new Pdf('A4');
Certificates::render($pdfA4, json_decode($c2316['payload_json'], true), $c2316['template_revision']);
$a4 = $pdfText($pdfA4->output('x'));
$sc = min(1, (595.28 - 24) / (595.13 - 15.37), (841.89 - 24) / 913.38);
ok(preg_match('/^q ' . preg_quote(rtrim(rtrim(sprintf('%.3F', $sc), '0'), '.'), '/') . ' 0 0 /m', $a4) === 1, 'on A4 the 8.5 x 13 form is scaled down to fit', substr($a4, 0, 80));

section('BIR forms as printed by the BIR Alphalist module v7.4');
// the PDF417 symbols were read from the module's own templates; zxing decodes them as below
$expect = array('2316' => '2316 01/18ENCS', '2307' => '2307 01/18ENCS', '2306' => '2316 01/18ENCS');
foreach ($expect as $code => $text) {
    $s = Certificates::formSpec($code);
    $b = $s['barcode'];
    $rowsOk = count($b['rows']) === 6;
    foreach ($b['rows'] as $r) {
        // start pattern 81111113 and stop pattern 711311121, in modules
        $rowsOk = $rowsOk && strlen($r) === 120 && strpos($r, '11111111010101000') === 0 && substr($r, -18) === '111111101000101001';
    }
    ok($b['text'] === $text && $rowsOk, 'Form ' . $code . ': the PDF417 barcode of the module (6 rows of 3 data columns, start and stop patterns intact)', $b['text']);
    $bg = file_get_contents(APP_PATH . '/specs/forms/' . $s['bg']);
    ok(strlen($bg) > 20000 && strpos($bg, 'BT /F') !== false && strpos($bg, '%IMG') === false && is_file(APP_PATH . '/specs/forms/bir_seal.png'), 'Form ' . $code . ': the blank form drawing and the BIR seal are there');
}
$s16 = $pdfText(file_get_contents(storage_path($c2316['pdf_path'])));
ok(strpos($s16, 'Certificate of Compensation') !== false && strpos($s16, '/Im1 Do') !== false && preg_match('/q 0 g [\d. ]+ re\n/', $s16) === 1,
    'a printed 2316 carries the form, the seal image and the barcode');
// boxes: each value lands inside the box the module writes it to
$boxCheck = function ($stream, $spec, $pageH, $name, $value) {
    $f = $spec['fields'][$name];
    preg_match_all('/([\d.]+) ([\d.]+) Td \(' . preg_quote($value, '/') . '\) Tj/', $stream, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $x = (float)$m[1];
        $top = $pageH - (float)$m[2];
        if ($x >= $f[0] - 0.5 && $x <= $f[2] && $top >= $f[1] && $top <= $f[3] + 1) {
            return true;
        }
    }
    return false;
};
$sp16 = Certificates::formSpec('2316');
$folio = $pdfText(file_get_contents(storage_path($c2316['pdf_path'])));
ok($boxCheck($folio, $sp16, 936, 'Rectangle 131', 'ABABAO, ERLINDA AMOLONG') && $boxCheck($folio, $sp16, 936, 'Rectangle 557', '296,250.00')
    && $boxCheck($folio, $sp16, 936, 'Text Box 810', 'ERLINDA AMOLONG ABABAO') && $boxCheck($folio, $sp16, 936, 'Rectangle 17', '2025'),
    'on folio the 2316 keeps the module positions: name in item 4, gross in item 19, employee in item 52, year in item 1');
$w7 = array('form' => '2307', 'period_from' => '2026-07-01', 'period_to' => '2026-09-30',
    'payee' => array('tin' => '123456789', 'branch' => '0000', 'name' => 'Consingsay Corporation', 'address' => 'Mandaue City', 'zip' => '6014', 'foreign' => ''),
    'payor' => array('tin' => '605188970', 'branch' => '0000', 'name' => 'A3 Facility Management', 'address' => 'North Road Tabok', 'zip' => '6014', 'rdo' => '083'),
    'lines' => array(array('nature' => 'Rentals - real/personal properties', 'atc' => 'WC100', 'm' => array(0, 0, 1500000), 'total' => 1500000, 'tax' => 75000)),
    'biz' => array(), 'total' => array('m' => array(0, 0, 1500000), 'total' => 1500000, 'tax' => 75000), 'biz_total' => array('m' => array(0, 0, 0), 'total' => 0, 'tax' => 0),
    'cert_no' => '2307-2026-00001', 'revision' => 1, 'signatory' => array('name' => 'Jonah Lafuente', 'title' => '', 'tin' => ''), 'use_signature' => 0, 'signature_path' => null, 'offset_x' => 0, 'offset_y' => 0);
$p7 = new Pdf(Certificates::defaultPaper('2307'));
Certificates::render($p7, $w7, Certificates::REV_2307);
$t7 = $pdfText($p7->output('x'));
$sp7 = Certificates::formSpec('2307');
ok(Certificates::defaultPaper('2307') === 'LEGAL' && Certificates::defaultPaper(2307) === 'LEGAL' && Certificates::defaultPaper('2316') === 'FOLIO' && Certificates::defaultPaper('2306') === 'FOLIO',
    'the module paper: legal for 2307, long for 2316 and 2306');
ok($boxCheck($t7, $sp7, 1008, 'Rectangle 138', '07') && $boxCheck($t7, $sp7, 1008, 'Rectangle 543', '01') && $boxCheck($t7, $sp7, 1008, 'Rectangle 19', '2026')
    && $boxCheck($t7, $sp7, 1008, 'Rectangle 292', '09') && $boxCheck($t7, $sp7, 1008, 'Rectangle 541', '30'),
    '2307 period in the module boxes: month, day and year separately');
ok($boxCheck($t7, $sp7, 1008, 'Rectangle 27', '123') && $boxCheck($t7, $sp7, 1008, 'Rectangle 22', '0000') && $boxCheck($t7, $sp7, 1008, 'Rectangle 496', '605')
    && $boxCheck($t7, $sp7, 1008, 'Rectangle 540', 'CONSINGSAY CORPORATION') && $boxCheck($t7, $sp7, 1008, 'Text Box 562', 'JONAH LAFUENTE')
    && $boxCheck($t7, $sp7, 1008, 'Text Box 564', 'CONSINGSAY CORPORATION'), '2307 TINs, payee name, signatory and conforme in the module boxes');
ok(strpos($t7, '(WC100)') !== false && strpos($t7, '(15,000.00)') !== false && strpos($t7, '(750.00)') !== false && preg_match('/\(-\) Tj/', $t7) === 1,
    'the income line, its 3rd-month amount, and a dash for the empty Money Payments total as in the module');
$many = $w7;
$many['lines'] = array_fill(0, 15, $w7['lines'][0]);
$pm = new Pdf('LEGAL');
Certificates::render($pm, $many, Certificates::REV_2307);
$tm = $pdfText($pm->output('x'));
ok(substr_count($tm, 'Certificate of Creditable') === 2 && strpos($tm, '(Continued on the next page)') !== false, 'more lines than the form holds continue on a second copy of the form');
$w6 = $w7;
$w6['form'] = '2306';
$p6 = new Pdf('FOLIO');
Certificates::render($p6, $w6, Certificates::REV_2306);
$t6 = $pdfText($p6->output('x'));
$sp6 = Certificates::formSpec('2306');
ok(strpos($t6, 'Certificate of Final Tax') !== false && $boxCheck($t6, $sp6, 936, 'Rectangle 15', 'CONSINGSAY CORPORATION') && $boxCheck($t6, $sp6, 936, 'Rectangle 74', '2026')
    && $boxCheck($t6, $sp6, 936, 'TextBox 399', 'CONSINGSAY CORPORATION') && strpos($t6, 'Fill in all applicable') === false && strpos($t6, 'W I') === false,
    '2306 fills the module boxes; the template leftovers the module hides are not printed');

// =====================================================================
section('Uploading new clients from a CSV file');
$Cu = make_org('Csvup', '008100100', 'firm_owner', 'CSV UPLOAD FIRST CLIENT');
login_as($Cu['user'], $Cu['org'], $Cu['client']);
$tplC = ClientImport::template(false);
$tplHead = str_getcsv(trim(substr($tplC, 3)));
ok(substr($tplC, 0, 3) === "\xEF\xBB\xBF" && count($tplHead) === 17 && $tplHead[0] === 'TIN' && $tplHead[16] === 'PHONE', 'the CSV template has the 17 client columns, UTF-8 with a BOM');
$sb = ClientImport::stage(tmp_file(ClientImport::template(true)), 'sample.csv');
$sc = ClientImport::counts($sb);
ok($sc['total'] === 2 && $sc['error'] === 0, 'the template with sample clients checks without errors', json_encode($sc));
ClientImport::discard($sb);
ok(DB::val('SELECT status FROM client_import_batches WHERE id = ?', array($sb)) === 'discarded' && !DB::val('SELECT COUNT(*) FROM client_import_rows WHERE batch_id = ?', array($sb)), 'a discarded upload keeps no rows');
throws(function () { ClientImport::stage(tmp_file("NAME,ADDRESS\r\nA,B\r\n"), 'x.csv'); }, 'a file without TIN and name columns is refused', 'No header row');
throws(function () { ClientImport::stage(tmp_file("TIN,REGISTERED NAME\r\n"), 'x.csv'); }, 'a file without clients is refused', 'no clients');
$csv = "TIN,TAXPAYER TYPE,REGISTERED NAME,LAST NAME,FIRST NAME,MIDDLE NAME,RDO CODE,ZIP CODE,CLASSIFICATION,VAT STATUS,ACCOUNTING PERIOD,FISCAL YEAR ENDS,EMAIL,REMARKS\r\n"
    . "301-100-001,Non-individual,ALPHA ONE CORP,,,,81,6000,TWA,VAT,Fiscal,June,a@alpha.ph,x\r\n"      // row 2: fine (RDO 81 becomes 081)
    . "301100002,Individual,,CRUZ,JUAN,DELA,050,1200,,Non-VAT,,,,\r\n"                                // row 3: fine
    . "301-100-001,,ALPHA AGAIN CORP,,,,081,,,,,,,\r\n"                                                 // row 4: same TIN as row 2
    . "008-100-100,,CSV UPLOAD FIRST CLIENT,,,,081,,,,,,,\r\n"                                          // row 5: already a client
    . "30110000,,EIGHT DIGITS CORP,,,,081,,,,,,,\r\n"                                                   // row 6: Excel dropped a zero
    . "301-100-007,Partner,,,,,ABC,60A0,Mega,Maybe,Quarterly,,bad-email,\r\n"                         // row 7: many errors
    . "301-100-008,Non-individual,,,,,081,,,,Fiscal,,,\r\n"                                             // row 8: no name, no fiscal month
    . "301-100-009-0002,,BRAVO & SONS,,,,,,Government,,,,,\r\n";                                        // row 9: warnings only
$cb = ClientImport::stage(tmp_file($csv), 'clients.csv');
$cr = array();
foreach (ClientImport::rows($cb) as $r) {
    $cr[$r['row_no']] = $r;
}
$cc = ClientImport::counts($cb);
ok($cc['total'] === 8 && $cc['ok'] === 3 && $cc['error'] === 5 && $cc['warning'] === 1, 'every row is checked and flagged', json_encode($cc));
ok(DB::val('SELECT ignored_columns FROM client_import_batches WHERE id = ?', array($cb)) === 'REMARKS', 'a column that is not a client field is reported as not read');
ok(strpos($cr[4]['errors']['tin'], 'Same TIN as row 2') === 0 && strpos($cr[5]['errors']['tin'], 'Already a client: CSV UPLOAD FIRST CLIENT') === 0
    && strpos($cr[6]['errors']['tin'], 'leading zero') !== false, 'TINs repeated in the file, already used by a client, or missing a zero are errors', json_encode(array($cr[4]['errors'], $cr[5]['errors'], $cr[6]['errors'])));
ok(array_keys($cr[7]['errors']) == array('taxpayer_type', 'last_name', 'rdo_code', 'zip_code', 'classification', 'vat_status', 'reporting_cycle', 'email'),
    'unknown type, classification, VAT status and period, a missing name, a bad RDO, ZIP and email are each flagged on their column', json_encode(array_keys($cr[7]['errors'])));
ok(isset($cr[8]['errors']['registered_name'], $cr[8]['errors']['fiscal_month_end']) && !$cr[9]['errors'] && isset($cr[9]['warnings']['tin'], $cr[9]['warnings']['registered_name'], $cr[9]['warnings']['rdo_code']),
    'a missing name and fiscal month are errors; a branch suffix, a character the validator refuses and a missing RDO are warnings', json_encode(array($cr[8]['errors'], $cr[9]['warnings'])));
throws(function () use ($cb) { ClientImport::save($cb); }, 'the list cannot be saved while rows have errors', '5 rows still have errors');
ClientImport::updateRow($cb, $cr[7]['id'], array('tin' => '301-100-007', 'taxpayer_type' => 'individual', 'last_name' => 'GARCIA', 'first_name' => 'MARIO', 'rdo_code' => '082', 'zip_code' => '6000',
    'classification' => 'regular', 'vat_status' => 'non_vat', 'reporting_cycle' => 'calendar', 'email' => 'mario@garcia.ph', 'fiscal_month_end' => '', 'registered_name' => '', 'middle_name' => ''));
ClientImport::updateRow($cb, $cr[8]['id'], array('registered_name' => 'CHARLIE HOLDINGS INC', 'fiscal_month_end' => 'March'));
ClientImport::updateRow($cb, $cr[2]['id'], array('tin' => '301-100-010'));
$cr4 = DB::row('SELECT status FROM client_import_rows WHERE id = ?', array($cr[4]['id']));
ok(ClientImport::counts($cb)['error'] === 2 && $cr4['status'] === 'ok', 'corrected rows pass, and changing row 2\'s TIN clears the repeat in row 4', json_encode(ClientImport::counts($cb)));
$n = ClientImport::deleteRows($cb, array($cr[5]['id']));
$n += ClientImport::deleteRows($cb, 'errors');
ok($n === 2 && ClientImport::counts($cb)['error'] === 0 && ClientImport::counts($cb)['total'] === 6, 'flagged rows can be deleted one by one or all at once', json_encode(ClientImport::counts($cb)));
$before = (int)DB::val('SELECT COUNT(*) FROM client_entities WHERE organization_id = ?', array($Cu['org']));
$saved = ClientImport::save($cb);
$after = DB::all('SELECT c.*, (SELECT COUNT(*) FROM branches b WHERE b.client_id = c.id AND b.branch_code = ? AND b.is_head_office = 1) AS ho FROM client_entities c WHERE c.organization_id = ? AND c.id <> ? ORDER BY c.tin',
    array('0000', $Cu['org'], $Cu['client']));
$byTin = array();
foreach ($after as $a) {
    $byTin[$a['tin']] = $a;
}
ok($saved === 6 && count($after) === $before - 1 + 6 && array_sum(array_column($after, 'ho')) === 6, 'saving adds each client with its head office branch 0000', json_encode(array($saved, count($after))));
ok($byTin['301100010']['classification'] === 'top_withholding_agent' && $byTin['301100010']['rdo_code'] === '081' && $byTin['301100010']['reporting_cycle'] === 'fiscal' && (int)$byTin['301100010']['fiscal_month_end'] === 6
    && $byTin['301100002']['taxpayer_type'] === 'individual' && $byTin['301100002']['vat_status'] === 'non_vat' && $byTin['301100007']['last_name'] === 'GARCIA'
    && (int)$byTin['301100008']['fiscal_month_end'] === 3 && $byTin['301100009']['rdo_code'] === null && $byTin['301100009']['classification'] === 'government',
    'the saved clients carry the values as checked and corrected', json_encode(array_keys($byTin)));
ok(DB::val('SELECT status FROM client_import_batches WHERE id = ?', array($cb)) === 'saved' && !DB::val("SELECT COUNT(*) FROM client_import_rows WHERE batch_id = ? AND (status <> 'saved' OR client_id IS NULL)", array($cb))
    && DB::val("SELECT COUNT(*) FROM audit_events WHERE action = 'client.create' AND organization_id = ?", array($Cu['org'])) >= 6, 'the upload is marked saved and each client is in the audit trail');
throws(function () use ($cb) { ClientImport::save($cb); }, 'a saved upload cannot be saved twice', 'already saved');
$again = ClientImport::stage(tmp_file($csv), 'clients-again.csv');
ok(ClientImport::counts($again)['ok'] === 0, 'uploading the same file again flags every client as already added');
// A member limited to some clients gets access to the clients they upload.
$su = DB::insert('users', array('email' => 'scoped.csv@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Scoped uploader', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
$sm = DB::insert('memberships', array('user_id' => $su, 'organization_id' => $Cu['org'], 'role_code' => 'firm_owner', 'all_clients' => 0, 'status' => 'active', 'created_at' => now()));
login_as($su, $Cu['org'], null);
$semi = ClientImport::stage(tmp_file(mb_convert_encoding("TIN;REGISTERED NAME;RDO CODE\r\n401-200-300;PE\xC3\x91A TRADING CORP;081\r\n", 'Windows-1252', 'UTF-8')), 'semicolon.csv');
$sr = ClientImport::rows($semi);
ok(count($sr) === 1 && $sr[0]['status'] === 'ok' && $sr[0]['data']['registered_name'] === "PE\xC3\x91A TRADING CORP", 'a semicolon-delimited Windows-1252 file is read with its Ñ', json_encode($sr[0]['data']));
ClientImport::save($semi);
$nc = DB::val('SELECT id FROM client_entities WHERE organization_id = ? AND tin = ?', array($Cu['org'], '401200300'));
ok($nc && DB::val('SELECT COUNT(*) FROM membership_scopes WHERE membership_id = ? AND client_id = ?', array($sm, $nc)) && Ctx::clientAllowed($nc), 'a member limited to some clients can open the clients they uploaded');
// Uploads stay inside their organization.
login_as($Cu['user'], $Cu['org'], $Cu['client']);
$mine = ClientImport::stage(tmp_file("TIN,REGISTERED NAME\r\n402-200-300,OWN ORG CORP\r\n"), 'own.csv');
$Co = make_org('Csvother', '008200200', 'firm_owner');
login_as($Co['user'], $Co['org'], $Co['client']);
ok(ClientImport::batch($mine) === null, 'another organization cannot open the upload');
throws(function () use ($mine) { ClientImport::save($mine); }, 'nor save it', 'not found');

// =====================================================================
section('Changing a sign-in email');
$Em = make_org('Emailco', '777111222');
login_as($Em['user'], $Em['org'], $Em['client']);
$emU = DB::insert('users', array('email' => 'old.address@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Email Mover', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
$hashBefore = DB::val('SELECT password_hash FROM users WHERE id = ?', array($emU));
$outBefore = (int)DB::val('SELECT COUNT(*) FROM mail_outbox');
Auth::changeEmail($emU, '  New.Address@Test.local ', 'company address');
ok(DB::val('SELECT email FROM users WHERE id = ?', array($emU)) === 'new.address@test.local' && DB::val('SELECT password_hash FROM users WHERE id = ?', array($emU)) === $hashBefore,
    'the email changes (trimmed, lower case) and the password stays');
ok((int)DB::val('SELECT COUNT(*) FROM mail_outbox') - $outBefore === 2, 'both the old and the new address get a message');
ok(strpos((string)DB::val("SELECT before_json FROM audit_events WHERE action = 'user.email' AND subject_id = ? ORDER BY id DESC LIMIT 1", array($emU)), 'old.address@test.local') !== false, 'the audit trail keeps the old address');
throws(function () use ($emU) { Auth::changeEmail($emU, 'emailco@test.local'); }, 'an address used by another account is refused', 'already used');
throws(function () use ($emU) { Auth::changeEmail($emU, 'not-an-email'); }, 'an invalid address is refused', 'valid email');
ok(!DB::val('SELECT id FROM users WHERE email = ?', array('old.address@test.local')), 'the old address no longer signs in to the account');

section('Temporary password set by an administrator');
$tpU = DB::insert('users', array('email' => 'invited.never@test.local', 'password_hash' => Auth::hash(random_token(24)), 'full_name' => 'Never Accepted', 'status' => 'active',
    'mfa_enabled' => 1, 'mfa_secret' => 'x', 'created_at' => now()));
DB::insert('login_attempts', array('email' => 'invited.never@test.local', 'ip' => '10.0.0.9', 'success' => 0, 'created_at' => now()));
throws(function () use ($tpU) { Auth::setTemporaryPassword($tpU, 'short1'); }, 'a weak temporary password is refused', 'at least 10');
Auth::setTemporaryPassword($tpU, 'Temp4Ever22', false, 'forgot password');
$tpRow = DB::row('SELECT * FROM users WHERE id = ?', array($tpU));
ok(password_verify('Temp4Ever22', $tpRow['password_hash']) && (int)$tpRow['must_change_password'] === 1 && $tpRow['email_verified_at'], 'the person can sign in with it and must choose their own password; the unaccepted invitation counts as accepted');
ok(!DB::val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0', array('invited.never@test.local')) && (int)$tpRow['mfa_enabled'] === 1, 'failed attempts are cleared; two-step stays on unless asked');
ok(strpos((string)DB::val("SELECT CONCAT(summary, COALESCE(before_json, ''), COALESCE(after_json, ''), COALESCE(reason, '')) FROM audit_events WHERE action = 'user.temp_password' AND subject_id = ? ORDER BY id DESC LIMIT 1", array($tpU)), 'Temp4Ever22') === false
    && strpos((string)DB::val('SELECT body FROM mail_outbox WHERE to_email = ? ORDER BY id DESC LIMIT 1', array('invited.never@test.local')), 'Temp4Ever22') === false, 'the password is not in the audit trail or the email');
Auth::setTemporaryPassword($tpU, 'Another9Temp', true);
ok((int)DB::val('SELECT mfa_enabled FROM users WHERE id = ?', array($tpU)) === 0, 'two-step sign-in can be turned off for a lost phone');

// =====================================================================
section('1604-C against the checks of the BIR Alphalist module v7.4');
ok(bir_v74_1604c($fixture, '684785510', '0000', '2025') === array(), 'the validator-passed sample passes the ported v7.4 checks');
$Bv = make_org('Bvseven', '555000444', 'firm_owner', 'MINIMUM WAGE FARMS CORP');
login_as($Bv['user'], $Bv['org'], $Bv['client']);
$bvEmp = function ($tin, $last, $first, $middle, $mwe) use ($Bv) {
    return DB::insert('employees', array('organization_id' => $Bv['org'], 'client_id' => $Bv['client'], 'branch_id' => $Bv['branch'], 'tin' => $tin, 'tin_branch' => '0000',
        'last_name' => $last, 'first_name' => $first, 'middle_name' => $middle, 'region_code' => 'VII', 'is_mwe' => $mwe, 'status' => 'active', 'created_at' => now()));
};
$bvComp = function ($empId, $sched, $from, $amounts) use ($Bv) {
    $d = array('organization_id' => $Bv['org'], 'client_id' => $Bv['client'], 'branch_id' => $Bv['branch'], 'employee_id' => $empId, 'tax_year' => 2025, 'schedule_code' => $sched,
        'region_code' => 'VII', 'employment_from' => $from, 'employment_to' => '2025-12-31', 'factor_days' => $sched === 'S2' ? 312 : null, 'nationality' => 'FILIPINO',
        'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y', 'status' => 'posted', 'created_at' => now());
    foreach (Records::compFields() as $fields) {
        foreach ($fields as $k => $l) {
            $d[$k] = isset($amounts[$k]) ? $amounts[$k] : '0.00';
        }
    }
    return DB::insert('compensation_records', $d);
};
// Schedule 1 as imported from a payroll file: net taxable left at 0.00 (the cause of the BIR's "Net Taxable" and "Tax Due" errors)
foreach (array(array('300400501', 'DIMAANO', 'LUZ', 'PEREZ'), array('300400502', 'DIMAANO', 'RAMON JOSE', 'TAN')) as $p) {
    $bvComp($bvEmp($p[0], $p[1], $p[2], $p[3], 0), 'S1', '2025-01-01', array('pres_gross' => '390000.00', 'pres_nt_13th' => '30000.00', 'pres_nt_contrib' => '15840.00',
        'pres_nt_total' => '45840.00', 'pres_tx_basic' => '344160.00', 'pres_tx_total' => '344160.00', 'taxable_comp_total' => '344160.00', 'net_taxable_comp' => '0.00',
        'tax_due' => '14124.00', 'pres_withheld_jan_nov' => '12947.00', 'pres_withheld_dec' => '1177.00', 'tax_withheld_adjusted' => '14124.00'));
}
// Schedule 2: minimum wage earners, full year and part year, with the actual SMW net of contributions
$mweRows = array(
    array('300400601', 'ALCANTARA', 'JOEL', 'PITO', '2025-01-01', '550.00', '14300.00', '171600.00', '185900.00', '14300.00', '12732.00'),
    array('300400602', 'BAUTISTA', 'JENNY', 'GERA', '2025-07-09', '550.00', '14300.00', '171600.00', '77015.63', '5515.63', '5312.50'),
    array('300400603', 'CABRERA', 'PRISCA', 'ABEL', '2025-07-11', '650.00', '16900.00', '202800.00', '91020.83', '6520.83', '6250.00'),
);
foreach ($mweRows as $p) {
    $smw = Money::toPlain(Money::fromDb($p[8]) - Money::fromDb($p[9]) - Money::fromDb($p[10]));
    $bvComp($bvEmp($p[0], $p[1], $p[2], $p[3], 1), 'S2', $p[4], array('pres_gross' => $p[8], 'smw_per_day' => $p[5], 'smw_per_month' => $p[6], 'smw_per_year' => $p[7],
        'pres_nt_smw' => $smw, 'pres_nt_13th' => $p[9], 'pres_nt_contrib' => $p[10], 'pres_nt_total' => $p[8]));
}
$bvCtx = Reports::context(array('form' => '1604C', 'schedule' => Specs::COMBINED_CODE, 'branch_id' => $Bv['branch'], 'year' => 2025, 'part' => 1, 'sawt_form' => null, 'amended' => 0));
$bvErr = array_values(array_filter($bvCtx['dq'], function ($i) { return $i['severity'] === 'error'; }));
$bvWarn = implode(' ', array_map(function ($i) { return $i['message']; }, $bvCtx['dq']));
ok(!$bvErr && strpos($bvWarn, 'net taxable compensation was saved as 0.00') !== false, 'net taxable saved as 0.00 is written as the taxable compensation, with a warning to save the record again', json_encode($bvErr));
$bvBytes = $bvCtx['preview']['bytes'];
$bvLines = explode("\r\n", rtrim($bvBytes, "\r\n"));
$bvD1 = explode(',', $bvLines[1]);
ok($bvD1[37] === '344160.00' && $bvD1[38] === '14124.00' && $bvD1[43] === '14124.00', 'Schedule 1 writes net taxable 344,160.00, tax due 14,124.00 and the actual amount withheld', $bvLines[1]);
$bvD2 = explode(',', $bvLines[5]);
ok(count($bvD2) === 59 && $bvD2[26] === '07/09/2025' && $bvD2[29] === '550.00' && $bvD2[32] === '312' && $bvD2[58] === '66187.50', 'a part-year minimum wage earner is written in the module\'s columns: dates, SMW per day, factor and the actual SMW', $bvLines[5]);
$bvReport = bir_v74_1604c($bvBytes, '555000444', '0000', '2025');
ok($bvReport === array(), 'the generated 1604-C passes every ported check of the BIR module v7.4', json_encode($bvReport));
$bvFile = DB::row('SELECT * FROM dat_files WHERE id = ?', array(Reports::generate($bvCtx, true)));
ok($bvFile['status'] === 'pending_bir' && (int)$bvFile['record_count'] === 5, 'Level 2 reads the file back with both schedules');
// wrong tax due, unequal withholding, a region the validator refuses: stopped at Level 1 with the amount to use
$bad = $bvCtx['dataset']['rows'][0];
$bad['tax_due'] = 1412400 + 100;
$bad['region_code'] = 'NIR';
$badDq = Checks::dataQuality('1604C', Specs::COMBINED_CODE, Ctx::client(), $bvCtx['agent'], array($bad), $bvCtx['spec'], $bvCtx['period']);
$badMsg = implode(' | ', array_map(function ($i) { return $i['message']; }, array_filter($badDq, function ($i) { return $i['severity'] === 'error'; })));
ok(strpos($badMsg, 'does not match the graduated rates on 344,160.00 (14,124.00)') !== false && strpos($badMsg, 'does not equal tax due') !== false && strpos($badMsg, 'region NIR') !== false,
    'a tax due off the graduated rates, withholding that does not equal it and region NIR are errors at Level 1', $badMsg);
ok(Payroll::graduatedTax(34416000, 2025) == 1412400 && Payroll::graduatedTax(90000000, 2025) == 12750000 && Payroll::graduatedTax(25000000, 2025) == 0
    && Payroll::graduatedTax(90000000, 2022) == 16000000, 'graduated rates: 2023 schedule from 2023, 2018 schedule before');
list($cd, $ce, $cw) = Payroll::compValidate(array_merge(array('tax_year' => 2025, 'schedule_code' => 'S1', 'branch_id' => $Bv['branch'], 'region_code' => 'VII', 'employment_from' => '01/01/2025',
    'employment_to' => '12/31/2025'), array('pres_gross' => '390000', 'pres_nt_13th' => '30000', 'pres_nt_contrib' => '15840', 'pres_nt_total' => '45840', 'pres_tx_basic' => '344160',
    'pres_tx_total' => '344160', 'taxable_comp_total' => '344160', 'net_taxable_comp' => '0', 'tax_due' => '14124', 'pres_withheld_jan_nov' => '12947', 'pres_withheld_dec' => '1177',
    'tax_withheld_adjusted' => '14124')), $Bv['client'], array('skip_party' => true));
ok($cd['net_taxable_comp'] === '344160.00' && !$ce, 'saving a compensation record sets net taxable to the taxable compensation', json_encode(array($ce, $cw)));

// =====================================================================
section("User's Manual");
$missing = array();
foreach (Help::chapters() as $c) {
    foreach ($c['sections'] as $k => $sec) {
        preg_match_all('/\{img:([a-z0-9_]+)\}/', $sec['html'], $m);
        foreach ($m[1] as $img) {
            if (!is_file(BASE_PATH . '/assets/img/manual/' . $img . '.jpg')) {
                $missing[] = $k . ': image ' . $img;
            }
        }
        preg_match_all('/\{sec:([a-z0-9_]+)\}/', $sec['html'], $m);
        foreach ($m[1] as $ref) {
            if (!Help::has($ref)) {
                $missing[] = $k . ': link ' . $ref;
            }
        }
    }
}
ok(!$missing, 'every manual image and cross-reference exists', implode(', ', $missing));
$public = array('login', 'logout', 'register', 'verify', 'resend', 'forgot', 'reset', 'mfa', 'file_download', 'import_download', 'upload_chunk');
$noHelp = array();
foreach (glob(APP_PATH . '/pages/*.php') as $f) {
    $pg = basename($f, '.php');
    if (!in_array($pg, $public, true) && !Help::has($pg)) {
        $noHelp[] = $pg;
    }
}
ok(!$noHelp, 'every signed-in page has a manual section for its Help button', implode(', ', $noHelp));
ok(count(Help::search('2307')) > 3 && !Help::search('qqqzzz'), 'manual search finds sections and reports no match');

// =====================================================================
section('Seeded layouts are well-formed');
$bad = 0;
foreach (DB::col('SELECT id FROM layout_specs') as $sid) {
    $sp = Specs::load($sid);
    $roles = array();
    foreach ($sp['records'] as $r) {
        $roles[$r['role']] = true;
        foreach ($r['fields'] as $f) {
            if (!preg_match('/^(lit:.*|tpl:.+|agent\.\w+|row\.\w+|sum\.\w+|period\.(mm_yyyy|mm_dd_yyyy_end|yyyy|mm)|seq|blank|ctx\.\w+)$/', $f['source_expr'])) {
                $bad++;
            }
        }
    }
    if (empty($roles['detail'])) {
        $bad++;
    }
}
ok($bad === 0, 'every layout has a detail record and valid source expressions');
ok((int)DB::val("SELECT COUNT(*) FROM layout_specs WHERE status = 'approved' AND approval_evidence_id IS NULL") === 0, 'no layout ships approved: approval needs this installation\'s own validator evidence');

echo "\n" . $T['pass'] . ' passed, ' . $T['fail'] . " failed\n";
$root->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
exit($T['fail'] ? 1 : 0);
