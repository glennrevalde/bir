<?php
$client = Ctx::client();
View::header('Dashboard');
if (!$client) {
    View::pageBar('Dashboard', e(Ctx::org() ? Ctx::org()['name'] : 'Platform'));
    echo '<div class="sheet">' . View::empty_state('Add your first client', 'Each client is a taxpayer with its own TIN, branches and records. Add one to start encoding or importing data.',
            Ctx::can('clients.manage') ? '<a class="btn btn-primary" href="' . e(url('client_form')) . '">' . View::icon('plus') . 'Add a client</a>' : '') . '</div>';
    View::footer();
    exit;
}
$cid = (int)$client['id'];
$year = (int)input('year', date('Y'));
$curMonth = date('Y-m');

// ---------------------------------------------------------------- filing calendar
$families = array(
    array('SLS', 'Sales list'), array('SLP', 'Purchases list'), array('1601EQ', 'QAP 1601-EQ'), array('1600VT', 'MAP 1600-VT'), array('SAWT', 'SAWT'),
);
$files = DB::all('SELECT form_code, period_from, period_to, status FROM dat_files WHERE client_id = ? AND status <> ? AND YEAR(period_to) = ? ORDER BY id', array($cid, 'superseded', $year));
$cell = array();
foreach ($files as $f) {
    $m = (int)substr($f['period_from'], 5, 2);
    $end = (int)substr($f['period_to'], 5, 2);
    if (substr($f['period_from'], 0, 4) != $year) {
        $m = 1;
    }
    for ($i = $m; $i <= $end; $i++) {
        $cell[$f['form_code']][$i] = $f['status'];
    }
}
$markOf = function ($st) {
    if (in_array($st, array('approved', 'submitted', 'bir_validated'), true)) {
        return array('m-ok', View::status($st));
    }
    if (in_array($st, array('bir_failed', 'structure_failed'), true)) {
        return array('m-bad', View::status($st));
    }
    return array('m-wait', View::status($st));
};

// ---------------------------------------------------------------- work queue
$queue = array();
if (Ctx::can('approvals.decide') || Ctx::can('reference.approve')) {
    $n = (int)DB::val('SELECT COUNT(*) FROM approval_requests WHERE organization_id = ? AND decision = ? AND requested_by <> ?', array(Ctx::orgId(), 'pending', Auth::id()));
    if ($n) {
        $queue[] = array(url('approvals'), plural($n, 'approval request') . ' waiting for your decision', 'Maker-checker: someone else submitted these.');
    }
}
$pendingBir = DB::all('SELECT id, filename, period_label FROM dat_files WHERE client_id = ? AND status = ? ORDER BY id DESC LIMIT 4', array($cid, 'pending_bir'));
foreach ($pendingBir as $f) {
    $queue[] = array(url('file_view', array('id' => $f['id'])), $f['filename'], 'Run it through the official BIR validator and upload the report (' . $f['period_label'] . ')');
}
foreach (DB::all('SELECT id, filename FROM dat_files WHERE client_id = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 4', array($cid, 'bir_failed', 'structure_failed')) as $f) {
    $queue[] = array(url('file_view', array('id' => $f['id'])), $f['filename'], 'Failed a validation level; open it to see the reported lines');
}
if (Ctx::can('validation.review')) {
    foreach (DB::all('SELECT d.id, d.filename FROM official_validation_evidence e JOIN dat_files d ON d.id = e.dat_file_id WHERE d.client_id = ? AND e.reviewed_at IS NULL AND e.uploaded_by <> ? ORDER BY e.id DESC LIMIT 4', array($cid, Auth::id())) as $f) {
        $queue[] = array(url('file_view', array('id' => $f['id'])), $f['filename'], 'Validator evidence to review');
    }
}
foreach (DB::all('SELECT id, original_filename, error_rows FROM import_jobs WHERE client_id = ? AND status = ? AND error_rows > 0 ORDER BY id DESC LIMIT 3', array($cid, 'staged')) as $j) {
    $queue[] = array(url('import_review', array('id' => $j['id'])), $j['original_filename'], plural($j['error_rows'], 'row') . ' with errors waiting in an import');
}
foreach (DB::all('SELECT * FROM deadline_rules WHERE is_active = 1') as $r) {
    $label = $r['form_code'] . ': ' . $r['rule_text'];
    $queue[] = array(url('generate', array('form' => $r['form_code'])), $label, $r['source_ref']);
}

// ---------------------------------------------------------------- quarter figures
$q = (int)ceil((int)date('n') / 3);
$qYear = (int)date('Y');
$qMonths = quarter_months($qYear, $q);
$qLatest = false;
$hasCur = DB::val('SELECT 1 FROM withholding_entries WHERE client_id = ? AND status = ? AND period_month BETWEEN ? AND ? LIMIT 1', array($cid, 'posted', $qMonths[0], $qMonths[2]))
    || DB::val('SELECT 1 FROM sales_entries WHERE client_id = ? AND status = ? AND period_month BETWEEN ? AND ? LIMIT 1', array($cid, 'posted', $qMonths[0], $qMonths[2]));
if (!$hasCur) {
    $last = DB::val('SELECT MAX(m) FROM (SELECT MAX(period_month) AS m FROM withholding_entries WHERE client_id = ? AND status = ? UNION ALL SELECT MAX(period_month) FROM sales_entries WHERE client_id = ? AND status = ?) x',
        array($cid, 'posted', $cid, 'posted'));
    if ($last) {
        $qYear = (int)substr($last, 0, 4);
        $q = (int)ceil((int)substr($last, 5, 2) / 3);
        $qMonths = quarter_months($qYear, $q);
        $qLatest = true;
    }
}
$sum = function ($sql, $params) {
    return Money::fromDb(DB::val($sql, $params) ?: '0');
};
$fig = array(
    'sales' => $sum('SELECT SUM(taxable_amount + exempt_amount + zero_rated_amount) FROM sales_entries WHERE client_id = ? AND status = ? AND period_month BETWEEN ? AND ?', array($cid, 'posted', $qMonths[0], $qMonths[2])),
    'vat' => $sum('SELECT SUM(output_vat) FROM sales_entries WHERE client_id = ? AND status = ? AND period_month BETWEEN ? AND ?', array($cid, 'posted', $qMonths[0], $qMonths[2])),
    'input' => $sum('SELECT SUM(input_vat) FROM purchase_entries WHERE client_id = ? AND status = ? AND period_month BETWEEN ? AND ?', array($cid, 'posted', $qMonths[0], $qMonths[2])),
    'ewt' => $sum('SELECT SUM(tax_withheld) FROM withholding_entries WHERE client_id = ? AND status = ? AND direction = ? AND period_month BETWEEN ? AND ?', array($cid, 'posted', 'agent', $qMonths[0], $qMonths[2])),
    'cwt' => $sum('SELECT SUM(tax_withheld) FROM withholding_entries WHERE client_id = ? AND status = ? AND direction = ? AND period_month BETWEEN ? AND ?', array($cid, 'posted', 'payee', $qMonths[0], $qMonths[2])),
);
$monthly = array();
foreach (DB::all('SELECT period_month, SUM(tax_withheld) AS t FROM withholding_entries WHERE client_id = ? AND status = ? AND direction = ? AND period_month LIKE ? GROUP BY period_month',
    array($cid, 'posted', 'agent', $year . '-%')) as $r) {
    $monthly[(int)substr($r['period_month'], 5, 2)] = Money::fromDb($r['t']);
}
$maxM = $monthly ? max($monthly) : 0;
$recent = DB::all('SELECT a.*, u.full_name FROM audit_events a LEFT JOIN users u ON u.id = a.user_id WHERE a.client_id = ? ORDER BY a.id DESC LIMIT 6', array($cid));

$actions = '';
if (Ctx::can('imports.run')) {
    $actions .= '<a class="btn" href="' . e(url('imports')) . '">' . View::icon('upload') . 'Import a spreadsheet</a>';
}
if (Ctx::can('reports.generate')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('generate')) . '">' . View::icon('file') . 'Generate a file</a>';
}
View::pageBar('Dashboard', View::clientContext(e(Ctx::roleName())), $actions);
?>
<?php if (Auth::isPrivileged() && !(int)Auth::user()['mfa_enabled']) { ?>
  <div class="notice notice-warning">Your role can approve filings and change settings. <a href="<?= e(url('settings', array('tab' => 'mfa'))) ?>">Turn on two-step sign-in</a> to protect it.</div>
<?php } ?>

<div class="sheet">
  <div class="sheet-head">
    <div><h2>Filing calendar <?= (int)$year ?></h2><p>Newest file per family and month. Open the file library for details.</p></div>
    <form method="get" class="btn-row"><input type="hidden" name="p" value="dashboard">
      <a class="btn btn-sm btn-quiet" href="<?= e(url('dashboard', array('year' => $year - 1))) ?>">Previous year</a>
      <?php if ($year < (int)date('Y')) { ?><a class="btn btn-sm btn-quiet" href="<?= e(url('dashboard', array('year' => $year + 1))) ?>">Next year</a><?php } ?>
    </form>
  </div>
  <div class="table-wrap">
    <div class="cal" role="table" aria-label="Filing calendar">
      <div class="cal-h cal-row" role="columnheader">Family</div>
      <?php for ($m = 1; $m <= 12; $m++) { $ym = sprintf('%04d-%02d', $year, $m); ?>
        <div class="cal-h <?= $ym === $curMonth ? 'cal-now' : '' ?>" role="columnheader"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></div>
      <?php } ?>
      <?php foreach ($families as $fam) { ?>
        <div class="cal-row" role="rowheader"><a href="<?= e(url('files', array('form' => $fam[0]))) ?>"><?= e($fam[1]) ?></a></div>
        <?php for ($m = 1; $m <= 12; $m++) {
            $st = isset($cell[$fam[0]][$m]) ? $cell[$fam[0]][$m] : null;
            if ($st) { list($cls, $label) = $markOf($st); } ?>
          <div role="cell"><?php if ($st) { ?><span class="mark <?= $cls ?>" title="<?= e(strip_tags($label)) ?>"></span><span class="sr"><?= strip_tags($label) ?></span><?php } else { ?><span class="mark" title="No file"></span><?php } ?></div>
        <?php } ?>
      <?php } ?>
    </div>
  </div>
  <div class="legend"><span><i class="mark m-ok"></i>Validated, approved or submitted</span><span><i class="mark m-wait"></i>Generated, waiting for BIR validation</span><span><i class="mark m-bad"></i>Failed a check</span><span><i class="mark"></i>No file</span></div>
</div>

<div class="cols cols-main">
  <div>
    <div class="sheet">
      <div class="sheet-head"><div><h2>Q<?= $q ?> <?= $qYear ?><?= $qLatest ? '' : ' so far' ?></h2><p><?= $qLatest ? 'Latest quarter with records' : 'Posted records' ?>, <?= e(month_label($qMonths[0])) ?> to <?= e(month_label($qMonths[2])) ?></p></div></div>
      <div class="figures">
        <div><strong><?= Money::fmt($fig['sales']) ?></strong><span>Sales, all categories</span></div>
        <div><strong><?= Money::fmt($fig['vat']) ?></strong><span>Output VAT</span></div>
        <div><strong><?= Money::fmt($fig['input']) ?></strong><span>Input VAT</span></div>
        <div><strong><?= Money::fmt($fig['ewt']) ?></strong><span>Taxes we withheld</span></div>
        <div><strong><?= Money::fmt($fig['cwt']) ?></strong><span>Withheld on us</span></div>
      </div>
    </div>
    <div class="sheet">
      <div class="sheet-head"><div><h2>Taxes withheld per month, <?= (int)$year ?></h2><p>Posted withholding where this client is the agent</p></div></div>
      <div class="bars" role="img" aria-label="Monthly taxes withheld">
        <?php for ($m = 1; $m <= 12; $m++) { $v = isset($monthly[$m]) ? $monthly[$m] : 0; $h = $maxM ? max(2, round($v / $maxM * 100)) : 2; ?>
          <div class="bar" title="<?= e(date('M', mktime(0, 0, 0, $m, 1)) . ': ' . Money::fmt($v)) ?>"><i style="height:<?= $h ?>%"></i><span><?= date('M', mktime(0, 0, 0, $m, 1)) ?></span></div>
        <?php } ?>
      </div>
      <div style="height:12px"></div>
    </div>
  </div>
  <div>
    <div class="sheet">
      <div class="sheet-head"><h2>Work queue</h2></div>
      <?php if ($queue) { ?>
        <ul class="queue">
          <?php foreach (array_slice($queue, 0, 9) as $it) { ?>
            <li><div class="q-main"><a href="<?= e($it[0]) ?>"><?= e($it[1]) ?></a><div class="q-sub"><?= e($it[2]) ?></div></div></li>
          <?php } ?>
        </ul>
      <?php } else { ?>
        <div class="empty"><h2>Nothing waiting</h2><p>Files to validate, approvals and import errors will appear here.</p></div>
      <?php } ?>
    </div>
    <div class="sheet">
      <div class="sheet-head"><h2>Recent activity</h2><?php if (Ctx::can('audit.view')) { ?><a class="small" href="<?= e(url('audit')) ?>">Audit trail</a><?php } ?></div>
      <ul class="queue">
        <?php foreach ($recent as $a) { ?>
          <li><div class="q-main"><?= e($a['summary']) ?><div class="q-sub"><?= e($a['full_name'] ?: 'System') ?>, <?= e(fmt_dt($a['created_at'])) ?></div></div></li>
        <?php } ?>
        <?php if (!$recent) { ?><li class="muted">No activity yet.</li><?php } ?>
      </ul>
    </div>
  </div>
</div>
<?php View::footer();
