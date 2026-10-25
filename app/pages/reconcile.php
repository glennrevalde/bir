<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$cid = (int)$client['id'];
$tab = in_array(input('tab'), array('certs', 'sawt', 'relief', 'annual', 'exceptions'), true) ? input('tab') : 'certs';
$year = input_int('year', (int)date('Y'));
$q = max(1, min(4, input_int('q', (int)ceil((int)date('n') / 3))));
$qm = quarter_months($year, $q);
$tabs = array('certs' => 'Certificates vs QAP', 'sawt' => 'SAWT vs certificates received', 'relief' => 'RELIEF vs records', 'annual' => 'Annual roll-forward', 'exceptions' => 'Exceptions');
View::header('Reconciliation');
View::pageBar('Reconciliation', View::clientContext('pre-filing checks'));
?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="reconcile">
    <div class="field grow"><label for="tab">Report</label><?= View::select('tab', $tabs, $tab, 'id="tab"') ?></div>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= (int)$year ?>"></div>
    <?php if (in_array($tab, array('certs', 'sawt'), true)) { ?><div class="field"><label for="q">Quarter</label><?= View::select('q', array(1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'), $q, 'id="q"') ?></div><?php } ?>
    <button class="btn">Run</button>
  </form>
<?php
if ($tab === 'certs') {
    $rows = DB::all('SELECT cp.id, cp.tin, cp.branch_code, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name,
        SUM(w.tax_withheld) AS wh_tax, SUM(w.income_amount) AS wh_inc,
        (SELECT COALESCE(SUM(c.total_tax),0) FROM certificates c WHERE c.client_id = ? AND c.counterparty_id = cp.id AND c.form_code = ? AND c.status = ? AND c.period_from = ?) AS cert_tax,
        (SELECT COUNT(*) FROM certificates c WHERE c.client_id = ? AND c.counterparty_id = cp.id AND c.form_code = ? AND c.status = ? AND c.period_from = ?) AS cert_n
        FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id
        WHERE w.client_id = ? AND w.direction = ? AND w.tax_kind IN (?, ?, ?) AND w.status = ? AND w.period_month BETWEEN ? AND ?
        GROUP BY cp.id, cp.tin, cp.branch_code, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name ORDER BY cp.registered_name, cp.last_name',
        array($cid, '2307', 'issued', $qm[0] . '-01', $cid, '2307', 'issued', $qm[0] . '-01', $cid, 'agent', 'EWT', 'GVAT', 'GPT', 'posted', $qm[0], $qm[2]));
    $qapTax = 0;
    foreach (DB::all('SELECT total_tax FROM dat_files WHERE client_id = ? AND form_code = ? AND status <> ? AND period_to IN (?, ?, ?)', array($cid, '1601EQ', 'superseded', last_day($qm[0]), last_day($qm[1]), last_day($qm[2]))) as $fq) {
        $qapTax += Money::fromDb($fq['total_tax']);
    }
    $whTot = 0;
    $certTot = 0; ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Payee</th><th>TIN</th><th class="num">Withheld (records)</th><th class="num">2307 issued</th><th class="num">Difference</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r) { $d = Money::fromDb($r['wh_tax']) - Money::fromDb($r['cert_tax']); $whTot += Money::fromDb($r['wh_tax']); $certTot += Money::fromDb($r['cert_tax']); ?>
    <tr class="<?= $d ? 'hl' : '' ?>"><td data-label="Payee"><?= e(party_name($r)) ?></td><td data-label="TIN"><?= e(Tin::display($r['tin'], $r['branch_code'])) ?></td>
      <td data-label="Withheld" class="num"><?= Money::fmtDb($r['wh_tax']) ?></td><td data-label="2307 issued" class="num"><?= Money::fmtDb($r['cert_tax']) ?></td>
      <td data-label="Difference" class="num"><?= Money::fmt($d) ?></td>
      <td data-label="Status"><?= !(int)$r['cert_n'] ? '<span class="st st-wait">No certificate</span>' : ($d ? '<span class="st st-bad">Does not match</span>' : '<span class="st st-ok">Matches</span>') ?></td></tr>
  <?php } ?></tbody>
  <tfoot><tr><td>Totals</td><td></td><td class="num"><?= Money::fmt($whTot) ?></td><td class="num"><?= Money::fmt($certTot) ?></td><td class="num"><?= Money::fmt($whTot - $certTot) ?></td><td></td></tr></tfoot></table></div>
  <div class="sheet-body small">QAP files for Q<?= $q ?> (current, not superseded) total <strong><?= Money::fmt($qapTax) ?></strong> in tax withheld<?= $qapTax !== $whTot ? ', which differs from records by ' . Money::fmt($whTot - $qapTax) . ' (GVAT/GPT rows appear in MAP files, not QAP)' : '' ?>.</div>
<?php } elseif ($tab === 'sawt') {
    $rows = DB::all('SELECT cp.tin, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, w.atc_code, SUM(w.income_amount) AS inc, SUM(w.tax_withheld) AS tax, COUNT(*) AS n,
        SUM(CASE WHEN w.reference_no IS NULL OR w.reference_no = \'\' THEN 1 ELSE 0 END) AS no_ref FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id
        WHERE w.client_id = ? AND w.direction = ? AND w.status = ? AND w.period_month BETWEEN ? AND ? GROUP BY cp.tin, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, w.atc_code',
        array($cid, 'payee', 'posted', $qm[0], $qm[2]));
    $sawt = DB::all('SELECT * FROM dat_files WHERE client_id = ? AND form_code = ? AND status <> ? AND period_to = ?', array($cid, 'SAWT', 'superseded', last_day($qm[2]))); ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Payor</th><th>TIN</th><th>ATC</th><th class="num">Income</th><th class="num">Tax credited</th><th>Certificates</th></tr></thead><tbody>
  <?php $tt = 0; foreach ($rows as $r) { $tt += Money::fromDb($r['tax']); ?><tr class="<?= $r['no_ref'] ? 'hl' : '' ?>"><td data-label="Payor"><?= e(party_name($r)) ?></td><td data-label="TIN"><?= e(Tin::display($r['tin'])) ?></td><td data-label="ATC"><?= e($r['atc_code']) ?></td>
    <td data-label="Income" class="num"><?= Money::fmtDb($r['inc']) ?></td><td data-label="Tax" class="num"><?= Money::fmtDb($r['tax']) ?></td>
    <td data-label="Certificates"><?= $r['no_ref'] ? '<span class="st st-wait">' . (int)$r['no_ref'] . ' without a 2307 reference</span>' : '<span class="st st-ok">All referenced</span>' ?></td></tr><?php } ?>
  </tbody><tfoot><tr><td>Total</td><td></td><td></td><td></td><td class="num"><?= Money::fmt($tt) ?></td><td></td></tr></tfoot></table></div>
  <div class="sheet-body small"><?php if (!$sawt) { ?>No SAWT file for the quarter ending <?= e(month_label($qm[2])) ?> yet.<?php } foreach ($sawt as $s) { ?>
    <div><a href="<?= e(url('file_view', array('id' => $s['id']))) ?>"><?= e($s['filename']) ?></a>: tax <?= Money::fmtDb($s['total_tax']) ?> <?= Money::fromDb($s['total_tax']) === $tt ? '<span class="st st-ok">matches records</span>' : '<span class="st st-bad">differs by ' . Money::fmt($tt - Money::fromDb($s['total_tax'])) . '</span>' ?></div><?php } ?></div>
<?php } elseif ($tab === 'relief') { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Month</th><th class="num">Sales taxable (records)</th><th class="num">SLS file</th><th class="num">Purchases input VAT (records)</th><th class="num">SLP file</th><th>Status</th></tr></thead><tbody>
  <?php for ($m = 1; $m <= 12; $m++) { $ym = sprintf('%04d-%02d', $year, $m);
      $s = Money::fromDb(DB::val('SELECT COALESCE(SUM(taxable_amount),0) FROM sales_entries WHERE client_id = ? AND status = ? AND period_month = ?', array($cid, 'posted', $ym)));
      $pv = Money::fromDb(DB::val('SELECT COALESCE(SUM(input_vat),0) FROM purchase_entries WHERE client_id = ? AND status = ? AND period_month = ?', array($cid, 'posted', $ym)));
      $sf = DB::row('SELECT total_base FROM dat_files WHERE client_id = ? AND form_code = ? AND status <> ? AND period_to = ? ORDER BY id DESC LIMIT 1', array($cid, 'SLS', 'superseded', last_day($ym)));
      $pf = DB::row('SELECT total_tax FROM dat_files WHERE client_id = ? AND form_code = ? AND status <> ? AND period_to = ? ORDER BY id DESC LIMIT 1', array($cid, 'SLP', 'superseded', last_day($ym)));
      if (!$s && !$pv && !$sf && !$pf) { continue; }
      $ok = (!$sf || Money::fromDb($sf['total_base']) === $s) && (!$pf || Money::fromDb($pf['total_tax']) === $pv); ?>
    <tr class="<?= $ok ? '' : 'hl' ?>"><td data-label="Month"><?= e(month_label($ym)) ?></td><td data-label="Sales" class="num"><?= Money::fmt($s) ?></td><td data-label="SLS file" class="num"><?= $sf ? Money::fmtDb($sf['total_base']) : '<span class="muted">No file</span>' ?></td>
      <td data-label="Input VAT" class="num"><?= Money::fmt($pv) ?></td><td data-label="SLP file" class="num"><?= $pf ? Money::fmtDb($pf['total_tax']) : '<span class="muted">No file</span>' ?></td>
      <td data-label="Status"><?= $ok ? '<span class="st st-ok">Consistent</span>' : '<span class="st st-bad">Records changed after the file was made</span>' ?></td></tr>
  <?php } ?></tbody></table></div>
<?php } elseif ($tab === 'annual') {
    $months = DB::all('SELECT period_month, SUM(tax_withheld) AS tax FROM withholding_entries WHERE client_id = ? AND direction = ? AND tax_kind = ? AND status = ? AND period_month LIKE ? GROUP BY period_month ORDER BY period_month',
        array($cid, 'agent', 'EWT', 'posted', $year . '-%'));
    $files = array();
    foreach (DB::all('SELECT period_to, SUM(total_tax) AS tax FROM dat_files WHERE client_id = ? AND form_code = ? AND status <> ? AND YEAR(period_to) = ? GROUP BY period_to', array($cid, '1601EQ', 'superseded', $year)) as $r) {
        $files[substr($r['period_to'], 0, 7)] = Money::fromDb($r['tax']);
    }
    $annual = DB::row('SELECT * FROM dat_files WHERE client_id = ? AND form_code = ? AND schedule_code = ? AND status <> ? AND YEAR(period_to) = ? ORDER BY id DESC LIMIT 1', array($cid, '1604E', 'S4', 'superseded', $year));
    $sumRec = 0;
    $sumFiles = 0; ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Month</th><th class="num">EWT in records</th><th class="num">QAP files</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($months as $r) { $t = Money::fromDb($r['tax']); $f = isset($files[$r['period_month']]) ? $files[$r['period_month']] : null; $sumRec += $t; $sumFiles += (int)$f; ?>
    <tr class="<?= $f === null || $f !== $t ? 'hl' : '' ?>"><td data-label="Month"><?= e(month_label($r['period_month'])) ?></td><td data-label="Records" class="num"><?= Money::fmt($t) ?></td><td data-label="QAP" class="num"><?= $f === null ? '<span class="muted">No file</span>' : Money::fmt($f) ?></td>
      <td data-label="Status"><?= $f === null ? '<span class="st st-wait">Missing QAP</span>' : ($f === $t ? '<span class="st st-ok">Matches</span>' : '<span class="st st-bad">Differs</span>') ?></td></tr>
  <?php } ?></tbody><tfoot><tr><td>Year</td><td class="num"><?= Money::fmt($sumRec) ?></td><td class="num"><?= Money::fmt($sumFiles) ?></td><td></td></tr></tfoot></table></div>
  <div class="sheet-body small"><?= $annual ? '1604-E Schedule 4 file <a href="' . e(url('file_view', array('id' => $annual['id']))) . '">' . e($annual['filename']) . '</a> totals ' . Money::fmtDb($annual['total_tax']) . (Money::fromDb($annual['total_tax']) === $sumRec ? ' <span class="st st-ok">equal to records</span>' : ' <span class="st st-bad">differs from records</span>') : 'No 1604-E Schedule 4 file for ' . (int)$year . ' yet. Build it in the <a href="' . e(url('consolidate')) . '">SRS and annual builder</a>.' ?></div>
<?php } else {
    $ex = array();
    foreach (DB::all('SELECT DISTINCT cp.id, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id
        WHERE w.client_id = ? AND w.status = ? AND cp.tin IS NULL AND w.period_month LIKE ?', array($cid, 'posted', $year . '-%')) as $r) {
        $ex[] = array('bad', 'Missing TIN', party_name($r) . ' has withholding but no TIN', url('cp_form', array('id' => $r['id'])));
    }
    foreach (DB::all('SELECT tin, COUNT(DISTINCT COALESCE(registered_name, CONCAT(last_name, first_name))) AS n FROM counterparties WHERE client_id = ? AND tin IS NOT NULL AND deleted_at IS NULL GROUP BY tin HAVING n > 1', array($cid)) as $r) {
        $ex[] = array('wait', 'Name mismatch', 'TIN ' . Tin::display($r['tin']) . ' is recorded under ' . $r['n'] . ' different names', url('directory', array('q' => $r['tin'])));
    }
    foreach (array('sales_entries' => array('sales', 'taxable_amount + exempt_amount + zero_rated_amount'), 'purchase_entries' => array('purchases', 'services_amount + capital_goods_amount + other_goods_amount + exempt_amount + zero_rated_amount'),
                 'withholding_entries' => array('withholding', 'income_amount')) as $t => $cfg) {
        foreach (DB::all('SELECT id FROM ' . $t . ' WHERE client_id = ? AND status = ? AND (' . $cfg[1] . ') <= 0 AND period_month LIKE ? LIMIT 50', array($cid, 'posted', $year . '-%')) as $r) {
            $ex[] = array('wait', 'Zero or negative', ucfirst($cfg[0]) . ' record #' . $r['id'] . ' has a zero or negative amount', url('record_form', array('m' => $cfg[0], 'id' => $r['id'])));
        }
    }
    foreach (array('sales_entries' => array('sales', 'invoice_date'), 'purchase_entries' => array('purchases', 'invoice_date'), 'withholding_entries' => array('withholding', 'payment_date')) as $t => $cfg) {
        foreach (DB::all('SELECT id, ' . $cfg[1] . ' AS d FROM ' . $t . ' WHERE client_id = ? AND status = ? AND ' . $cfg[1] . ' > ? LIMIT 50', array($cid, 'posted', date('Y-m-d'))) as $r) {
            $ex[] = array('wait', 'Future date', ucfirst($cfg[0]) . ' record #' . $r['id'] . ' is dated ' . fmt_date($r['d']), url('record_form', array('m' => $cfg[0], 'id' => $r['id'])));
        }
    }
    foreach (array('sales_entries' => 'sales', 'purchase_entries' => 'purchases') as $t => $m) {
        foreach (DB::all('SELECT counterparty_id, invoice_no, COUNT(*) AS n FROM ' . $t . ' WHERE client_id = ? AND status = ? AND invoice_no IS NOT NULL AND invoice_no <> \'\' GROUP BY counterparty_id, invoice_no HAVING n > 1 LIMIT 50', array($cid, 'posted')) as $r) {
            $ex[] = array('bad', 'Duplicate invoice', ucfirst($m) . ' invoice ' . $r['invoice_no'] . ' appears ' . $r['n'] . ' times for one counterparty', url($m, array('q' => $r['invoice_no'])));
        }
    }
    foreach (DB::all('SELECT r.subject_type, r.subject_id, r.changed_at, d.filename, d.id AS fid FROM revisions r JOIN dat_files d ON d.client_id = ? AND d.status IN (?, ?) AND d.approved_at IS NOT NULL AND r.changed_at > d.approved_at
        WHERE r.organization_id = ? AND r.subject_type = ? AND r.subject_id IN (SELECT w.id FROM withholding_entries w WHERE w.client_id = ? AND w.period_month BETWEEN DATE_FORMAT(d.period_from, \'%Y-%m\') AND DATE_FORMAT(d.period_to, \'%Y-%m\')) LIMIT 50',
        array($cid, 'approved', 'submitted', Ctx::orgId(), 'withholding_entries', $cid)) as $r) {
        $ex[] = array('bad', 'Edited after approval', 'Withholding record #' . $r['subject_id'] . ' changed after ' . $r['filename'] . ' was approved', url('record_form', array('m' => 'withholding', 'id' => $r['subject_id'])));
    } ?>
  <?php if (!$ex) { echo View::empty_state('No exceptions found', 'Missing TINs, name mismatches, zero amounts, future dates, duplicate invoices and post-approval edits appear here.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Type</th><th>Finding</th><th></th></tr></thead><tbody>
    <?php foreach ($ex as $x) { ?><tr><td data-label="Type"><span class="st st-<?= $x[0] ?>"><?= e($x[1]) ?></span></td><td data-label="Finding"><?= e($x[2]) ?></td><td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e($x[3]) ?>">Open</a></td></tr><?php } ?>
  </tbody></table></div>
  <?php } ?>
<?php } ?>
</div>
<?php View::footer();
