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
$req = Workflow::request('period_locks', $lockId, 'unlock_period', 'test');
throws(function () use ($req) { Workflow::decide($req, true, 'self'); }, 'the requester cannot approve their own request', 'other than the requester');
$rev = DB::insert('users', array('email' => 'rev@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Reviewer', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
DB::insert('memberships', array('user_id' => $rev, 'organization_id' => $A['org'], 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
login_as($rev, $A['org'], $A['client']);
Workflow::decide($req, true, 'ok');
ok(!Records::lockedPeriod($A['client'], '2026-01', 'withholding'), 'a second person unlocks the period');
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
    foreach ($type['columns'] as $col) {
        $r[] = $vals[$col['key']];
    }
    $rowsX[] = $r;
}
$xlsx = XlsxWriter::build(array(array('name' => 'Data', 'columns' => $type['columns'], 'rows' => $rowsX, 'freeze' => true)));
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
$bad[1][array_search('atc_code', array_column($type['columns'], 'key'))] = 'ZZ999';
$xb = XlsxWriter::build(array(array('name' => 'Data', 'columns' => $type['columns'], 'rows' => $bad)));
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
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileC)) === 'bir_validated', 'file is officially validated only through the uploaded report');
throws(function () use ($evId) { Evidence::review($evId, 'accept', 'self'); }, 'the uploader cannot review their own evidence', 'different person');
$r2 = DB::insert('users', array('email' => 'rev2@test.local', 'password_hash' => Auth::hash('Test@123456'), 'full_name' => 'Reviewer Two', 'status' => 'active', 'email_verified_at' => now(), 'created_at' => now()));
DB::insert('memberships', array('user_id' => $r2, 'organization_id' => $Cc['org'], 'role_code' => 'reviewer', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
login_as($r2, $Cc['org'], $Cc['client']);
Evidence::review($evId, 'accept', 'checked');
login_as($Cc['user'], $Cc['org'], $Cc['client']);
$ar = Workflow::request('dat_files', $fileC, 'approve_file', 'ready');
login_as($r2, $Cc['org'], $Cc['client']);
Workflow::decide($ar, true, 'approved');
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileC)) === 'approved', 'a second person approves the file');
ok((bool)Records::lockedPeriod($Cc['client'], '2026-03', 'withholding'), 'approval locks the covered month');
$fid = Workflow::recordFiling($fileC, array('channel' => 'eSubmission', 'filed_on' => '2026-04-10', 'reference_no' => 'ACK-1', 'notes' => ''), null);
ok(DB::val('SELECT status FROM dat_files WHERE id = ?', array($fileC)) === 'submitted', 'filing evidence marks the file submitted');
$wrongTin = str_replace('777888999', '123123123', $txt);
file_put_contents($tmp, $wrongTin);
throws(function () use ($fileD, $tmp, $Dd) {
    login_as($Dd['user'], $Dd['org'], $Dd['client']);
    Evidence::record($fileD, array('validator_version' => '7.4', 'executed_on' => '2026-04-05', 'outcome' => '', 'patch_date' => '', 'report_type' => '', 'notes' => ''),
        array('name' => 'r.txt', 'tmp_name' => $tmp, 'error' => 0, 'size' => 100), null);
}, 'a validator report for another TIN is refused', 'not this client');

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
ok((int)DB::val("SELECT COUNT(*) FROM layout_specs WHERE status = 'approved'") === 0, 'no layout ships approved: approval needs this installation\'s own validator evidence');

echo "\n" . $T['pass'] . ' passed, ' . $T['fail'] . " failed\n";
$root->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
exit($T['fail'] ? 1 : 0);
