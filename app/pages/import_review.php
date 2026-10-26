<?php
Ctx::requirePerm('imports.run');
$client = Ctx::requireClient();
$job = DB::row('SELECT j.*, u.full_name, c.full_name AS committer FROM import_jobs j LEFT JOIN users u ON u.id = j.created_by LEFT JOIN users c ON c.id = j.committed_by
    WHERE j.id = ? AND j.client_id = ?', array(input_int('id'), $client['id']));
if (!$job) {
    deny('Import not found for this client.');
}
$type = Imports::type($job['import_type']);
if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'commit') {
            Ctx::requirePerm('imports.commit');
            $replace = input('replace') === '1';
            if ($replace) {
                Ctx::requirePerm('imports.replace');
                if (trim((string)input('replace_reason')) === '') {
                    throw new RuntimeException('Give a reason for replacing earlier imported records.');
                }
            }
            $n = Imports::commit($job['id'], input('mode') === 'valid' ? 'valid' : 'all', $replace, trim((string)input('replace_reason')));
            flash('success', 'Committed ' . plural($n, 'row') . ' to ' . $type['label'] . '.');
        } elseif ($action === 'rollback') {
            Ctx::requirePerm('imports.rollback');
            $reason = trim((string)input('reason'));
            if ($reason === '') {
                throw new RuntimeException('Give a reason.');
            }
            $n = Imports::rollback($job['id'], $reason);
            flash('success', 'Rolled back ' . plural($n, 'record') . '. They are kept as void records.');
        } elseif ($action === 'discard') {
            if ($job['status'] === 'committed') {
                throw new RuntimeException('Committed imports are rolled back, not discarded.');
            }
            DB::update('import_jobs', array('status' => 'failed', 'message' => 'Discarded by user', 'updated_at' => now()), 'id = ?', array($job['id']));
            DB::q('DELETE FROM import_rows WHERE job_id = ?', array($job['id']));
            Audit::log('import.discard', 'import_jobs', $job['id'], 'Discarded staged import');
            flash('success', 'Import discarded. Nothing was saved to your records.');
            redirect(url('imports'));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('import_review', array('id' => $job['id'])));
}
$show = in_array(input('show'), array('error', 'valid', 'duplicate', 'committed'), true) ? input('show') : ($job['error_rows'] > 0 ? 'error' : ($job['status'] === 'committed' ? 'committed' : 'valid'));
$per = 100;
$page = max(1, input_int('page', 1));
$cnt = (int)DB::val('SELECT COUNT(*) FROM import_rows WHERE job_id = ? AND status = ?', array($job['id'], $show));
$rows = DB::all('SELECT * FROM import_rows WHERE job_id = ? AND status = ? ORDER BY row_no LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), array($job['id'], $show));
$labels = array();
foreach ($type['columns'] as $c) {
    $labels[$c['key']] = $c['title'];
}
$isPurchases = $type['key'] === 'purchases';
$isSawt = $type['key'] === 'withheld_on_us';
$isSales = $type['key'] === 'sales';
$labels['period_month'] = $isSawt ? 'Month' : 'Purchase month';
$labels['tax_rate'] = 'TAX RATE';
$labels['gross_taxable'] = 'GROSS TAXABLE';
$jobOpts = json_decode((string)$job['options_json'], true) ?: array();
// SAWT: totals of the lines to be (or already) saved, and the file's own Grand Total against all of its lines.
$sTotals = null;
if ($isSawt && $job['status'] !== 'mapped') {
    $sTotals = array('rows' => 0, 'income' => 0, 'tax' => 0, 'file_income' => 0, 'file_tax' => 0, 'file_rows' => 0);
    foreach (DB::all('SELECT status, raw_json, normalized_json FROM import_rows WHERE job_id = ?', array($job['id'])) as $ir) {
        $raw = json_decode((string)$ir['raw_json'], true) ?: array();
        $num = isset($raw['__numeric']) ? $raw['__numeric'] : array();
        $sTotals['file_rows']++;
        foreach (array('income_amount' => 'file_income', 'tax_withheld' => 'file_tax') as $k => $t) {
            $pv = Money::parse(isset($raw[$k]) ? Imports::excelNumber($raw[$k], in_array($k, $num, true)) : '');
            $sTotals[$t] += $pv['ok'] ? $pv['cents'] : 0;
        }
        if (in_array($ir['status'], array('valid', 'committed'), true)) {
            $n = json_decode((string)$ir['normalized_json'], true);
            if (!empty($n['record'])) {
                $sTotals['rows']++;
                $sTotals['income'] += Money::fromDb($n['record']['income_amount']);
                $sTotals['tax'] += Money::fromDb($n['record']['tax_withheld']);
            }
        }
    }
    $ft = isset($jobOpts['file_totals']['values']) ? $jobOpts['file_totals']['values'] : array();
    foreach (array('income_amount' => 'grand_income', 'tax_withheld' => 'grand_tax') as $k => $t) {
        $pv = isset($ft[$k]) ? Money::parse($ft[$k]) : null;
        $sTotals[$t] = $pv && $pv['ok'] ? $pv['cents'] : null;
    }
}
// Purchases: totals of the rows that will be (or were) saved, in the sheet's own columns, to compare with the workbook.
$pTotals = null;
if ($isPurchases && $job['status'] !== 'mapped') {
    $pTotals = array('rows' => 0, 'exempt' => 0, 'zero' => 0, 'taxable' => 0, 'vat' => 0, 'total' => 0);
    foreach (DB::col('SELECT normalized_json FROM import_rows WHERE job_id = ? AND status IN (?, ?)', array($job['id'], 'valid', 'committed')) as $nj) {
        $n = json_decode((string)$nj, true);
        if (empty($n['record'])) {
            continue;
        }
        $rec = $n['record'];
        $pTotals['rows']++;
        $pTotals['exempt'] += Money::fromDb($rec['exempt_amount']);
        $pTotals['zero'] += Money::fromDb($rec['zero_rated_amount']);
        $pTotals['taxable'] += Records::purchaseTaxable($rec);
        $pTotals['vat'] += Money::fromDb($rec['input_vat']);
        $pTotals['total'] += Records::purchaseTotal($rec);
    }
}
// Sales: totals in the workbook's own columns, to compare with the sheet.
$slTotals = null;
if ($isSales && $job['status'] !== 'mapped') {
    $slTotals = array('rows' => 0, 'gross' => 0, 'exempt' => 0, 'zero' => 0, 'taxable' => 0, 'vat' => 0);
    foreach (DB::col('SELECT normalized_json FROM import_rows WHERE job_id = ? AND status IN (?, ?)', array($job['id'], 'valid', 'committed')) as $nj) {
        $n = json_decode((string)$nj, true);
        if (empty($n['record'])) {
            continue;
        }
        $rec = $n['record'];
        $slTotals['rows']++;
        $slTotals['gross'] += Records::salesGross($rec);
        $slTotals['exempt'] += Money::fromDb($rec['exempt_amount']);
        $slTotals['zero'] += Money::fromDb($rec['zero_rated_amount']);
        $slTotals['taxable'] += Money::fromDb($rec['taxable_amount']);
        $slTotals['vat'] += Money::fromDb($rec['output_vat']);
    }
}
$track = array('Uploaded', 'Mapped', 'Staged and checked', 'Committed');
$stage = array('uploaded' => 0, 'mapped' => 1, 'staged' => 2, 'committed' => 3, 'rolled_back' => 3, 'failed' => 0);
$at = isset($stage[$job['status']]) ? $stage[$job['status']] : 0;
View::header('Review import', array('page' => 'import_review'));
View::pageBar('Review import #' . $job['id'], View::clientContext(e($job['original_filename']) . ', ' . e($type['label'])), '<a class="btn btn-quiet" href="' . e(url('imports')) . '">Back to imports</a>');
?>
<div class="sheet"><ol class="track" aria-label="Import progress">
  <?php foreach ($track as $i => $t) { $cls = $i < $at ? 'done' : ($i === $at ? ($job['status'] === 'failed' ? 'fail' : ($job['status'] === 'committed' ? 'done' : 'now')) : ''); ?><li class="<?= $cls ?>"><?= e($t) ?></li><?php } ?>
</ol></div>

<?php if ($job['status'] === 'mapped') { ?>
  <div class="sheet" data-stage-url="<?= e(url('import_stage', array('id' => $job['id']))) ?>" data-done-url="<?= e(url('import_review', array('id' => $job['id']))) ?>">
    <div class="sheet-body stack"><h2>Checking rows</h2><div class="progress"><i></i></div><p class="small muted" data-stage-text>Starting...</p>
      <p class="small">Rows are checked in batches so large files do not time out. Keep this page open.</p></div>
  </div>
<?php } else { ?>
<div class="sheet">
  <div class="figures">
    <div><strong><?= (int)$job['total_rows'] ?></strong><span>Rows read</span></div>
    <div><strong><?= (int)$job['valid_rows'] ?></strong><span>Ready to commit</span></div>
    <div><strong><?= (int)$job['error_rows'] ?></strong><span>With errors</span></div>
    <div><strong><?= (int)$job['duplicate_rows'] ?></strong><span>Duplicates (skipped)</span></div>
    <div><strong><?= (int)$job['committed_rows'] ?></strong><span>Committed</span></div>
  </div>
  <?php if ($pTotals) { ?>
  <div class="import-sum">
    <p class="import-sum-title">R_Purchases totals of the <?= plural($pTotals['rows'], ($job['status'] === 'committed' ? 'committed' : 'ready') . ' row') ?><?= !empty($jobOpts['period_month']) ? ', filed under ' . e(month_label($jobOpts['period_month'])) : '' ?></p>
    <dl class="import-sum-grid">
      <div><dt>exempt</dt><dd><?= Money::fmt($pTotals['exempt']) ?></dd></div>
      <div><dt>zeroRated</dt><dd><?= Money::fmt($pTotals['zero']) ?></dd></div>
      <div><dt>taxableNetofVat</dt><dd><?= Money::fmt($pTotals['taxable']) ?></dd></div>
      <div><dt>inputVat</dt><dd><?= Money::fmt($pTotals['vat']) ?></dd></div>
      <div class="is-total"><dt>totalPurchases</dt><dd><?= Money::fmt($pTotals['total']) ?></dd></div>
    </dl>
    <p class="small muted">These should equal the column totals of your workbook.</p>
  </div>
  <?php } ?>
  <?php if ($slTotals) { ?>
  <div class="import-sum">
    <p class="import-sum-title">Totals of the <?= plural($slTotals['rows'], ($job['status'] === 'committed' ? 'committed' : 'ready') . ' row') ?>, in the workbook's columns</p>
    <dl class="import-sum-grid">
      <div><dt>GROSS TAXABLE</dt><dd><?= Money::fmt($slTotals['gross']) ?></dd></div>
      <div><dt>EXEMPT SALES</dt><dd><?= Money::fmt($slTotals['exempt']) ?></dd></div>
      <div><dt>ZERO RATED SALES</dt><dd><?= Money::fmt($slTotals['zero']) ?></dd></div>
      <div><dt>TAXABLE SALES</dt><dd><?= Money::fmt($slTotals['taxable']) ?></dd></div>
      <div class="is-total"><dt>OUTPUT VAT</dt><dd><?= Money::fmt($slTotals['vat']) ?></dd></div>
    </dl>
    <p class="small muted">These should equal the column totals of your workbook. Where a row gave only the gross taxable, its taxable sales and VAT were worked out at its VAT rate.</p>
  </div>
  <?php } ?>
  <?php if ($sTotals) { $rep = isset($jobOpts['report']) ? $jobOpts['report'] : array(); ?>
  <div class="import-sum">
    <p class="import-sum-title">SAWT<?= !empty($rep['form']) ? ' for BIR Form ' . e($rep['form']) : '' ?><?= !empty($jobOpts['period_month']) ? ', ' . e(month_label($jobOpts['period_month'])) : '' ?>: <?= plural($sTotals['rows'], ($job['status'] === 'committed' ? 'committed' : 'ready') . ' line') ?></p>
    <dl class="import-sum-grid sum-2">
      <div><dt>Amount of income payment</dt><dd><?= Money::fmt($sTotals['income']) ?></dd></div>
      <div class="is-total"><dt>Amount of tax withheld</dt><dd><?= Money::fmt($sTotals['tax']) ?></dd></div>
    </dl>
    <?php if ($sTotals['grand_income'] !== null || $sTotals['grand_tax'] !== null) {
        $agreeI = $sTotals['grand_income'] === null || $sTotals['grand_income'] === $sTotals['file_income'];
        $agreeT = $sTotals['grand_tax'] === null || $sTotals['grand_tax'] === $sTotals['file_tax']; ?>
      <p class="small <?= $agreeI && $agreeT ? 'ok-text' : 'warn-text' ?>">
        Grand Total in the file<?= isset($jobOpts['file_totals']['row']) ? ' (row ' . (int)$jobOpts['file_totals']['row'] . ')' : '' ?>:
        <?= $sTotals['grand_income'] !== null ? 'income ' . Money::fmt($sTotals['grand_income']) : '' ?><?= $sTotals['grand_income'] !== null && $sTotals['grand_tax'] !== null ? ', ' : '' ?><?= $sTotals['grand_tax'] !== null ? 'tax ' . Money::fmt($sTotals['grand_tax']) : '' ?>.
        <?php if ($agreeI && $agreeT) { ?>It agrees with the <?= plural($sTotals['file_rows'], 'line') ?> read.<?php } else { ?>
          The <?= plural($sTotals['file_rows'], 'line') ?> read add up to income <?= Money::fmt($sTotals['file_income']) ?> and tax <?= Money::fmt($sTotals['file_tax']) ?>. Check for lines left out or typed over.<?php } ?>
      </p>
    <?php } else { ?><p class="small muted">The file has no Grand Total line to compare with.</p><?php } ?>
  </div>
  <?php } ?>
</div>

<?php if ($job['status'] === 'staged') { ?>
<div class="cols cols-2">
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="commit">
    <div class="sheet-head"><h2>Commit to records</h2></div>
    <div class="sheet-body stack">
      <label class="check"><input type="radio" name="mode" value="all" <?= $job['error_rows'] ? 'disabled' : 'checked' ?>> Commit everything (allowed only when no row has errors)</label>
      <label class="check"><input type="radio" name="mode" value="valid" <?= $job['error_rows'] ? 'checked' : '' ?>> Commit the <?= (int)$job['valid_rows'] ?> valid rows and leave the rest</label>
      <?php if (Ctx::can('imports.replace') && $type['module']) { ?>
        <label class="check"><input type="checkbox" name="replace" value="1"> Replace: void earlier imported records in the same months first</label>
        <div class="field"><label for="replace_reason">Reason for replacing</label><input id="replace_reason" name="replace_reason" type="text" maxlength="200"></div>
      <?php } ?>
      <p class="small muted">Duplicates (identical to saved records or to an earlier row) are skipped, so committing twice never doubles amounts.</p>
    </div>
    <div class="sheet-foot">
      <?php if (Ctx::can('imports.commit')) { ?><button class="btn btn-primary" <?= $job['valid_rows'] ? '' : 'disabled' ?> data-confirm="Commit these rows into the client records?">Commit rows</button>
      <?php } else { ?><span class="small muted">A preparer must commit this import.</span><?php } ?>
    </div>
  </form>
  <div class="sheet">
    <div class="sheet-head"><h2>Fix and re-upload</h2></div>
    <div class="sheet-body stack small">
      <p>Download the rows with errors, correct them in Excel and upload the corrected file as a new import. Committed rows are never duplicated.</p>
      <div class="btn-row">
        <a class="btn" href="<?= e(url('import_download', array('id' => $job['id'], 'what' => 'corrections'))) ?>"><?= View::icon('download') ?>Corrections workbook</a>
        <a class="btn btn-quiet" href="<?= e(url('import_map', array('id' => $job['id']))) ?>">Change the mapping</a>
      </div>
    </div>
    <div class="sheet-foot"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-danger" data-confirm="Discard this staged import?">Discard import</button></form></div>
  </div>
</div>
<?php } elseif ($job['status'] === 'committed') { ?>
  <div class="notice notice-success">Committed by <?= e($job['committer']) ?> on <?= e(fmt_dt($job['committed_at'])) ?>.</div>
  <?php if (Ctx::can('imports.rollback') && $type['key'] !== 'counterparties') { ?>
    <form method="post" data-prompt="Why roll back this import?" class="btn-row" style="margin-bottom:16px"><?= csrf_field() ?><input type="hidden" name="action" value="rollback"><button class="btn btn-danger">Roll back this import</button></form>
  <?php } ?>
<?php } elseif ($job['status'] === 'rolled_back') { ?>
  <div class="notice notice-warning">Rolled back on <?= e(fmt_dt($job['rolled_back_at'])) ?>: <?= e($job['message']) ?></div>
<?php } ?>

<div class="sheet">
  <div class="sheet-head"><h2>Rows</h2>
    <div class="btn-row"><?php foreach (array('error' => 'Errors', 'valid' => 'Valid', 'duplicate' => 'Duplicates', 'committed' => 'Committed') as $k => $l) { ?>
      <a class="btn btn-sm <?= $show === $k ? 'btn-primary' : 'btn-quiet' ?>" href="<?= e(url('import_review', array('id' => $job['id'], 'show' => $k))) ?>"><?= e($l) ?></a><?php } ?></div></div>
  <?php if (!$rows) { ?><div class="empty"><p>No rows in this group.</p></div><?php } else { ?>
  <?php if ($isPurchases) { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Row</th><th>Messages</th><th>Vendor</th><th>Month</th><th class="num">exempt</th><th class="num">zeroRated</th><th class="num">taxableNetofVat</th><th class="num">vatRate</th><th class="num">inputVat</th><th class="num">totalPurchases</th></tr></thead>
    <tbody><?php foreach ($rows as $r) { $n = json_decode((string)$r['normalized_json'], true) ?: array(); $rec = isset($n['record']) ? $n['record'] : array(); $pt = isset($n['party']) ? $n['party'] : array();
      $errs = json_decode((string)$r['errors_json'], true) ?: array(); $warns = json_decode((string)$r['warnings_json'], true) ?: array(); $raw = json_decode($r['raw_json'], true); ?>
      <tr class="<?= $r['status'] === 'error' ? 'row-error' : ($r['status'] === 'duplicate' ? 'row-dup' : '') ?>">
        <td data-label="Row" class="nowrap"><?= (int)$r['row_no'] ?></td>
        <td data-label="Messages" class="small" style="min-width:220px"><?php foreach ($errs as $f => $m) { ?><div class="msg-bad"><?= e((isset($labels[$f]) ? $labels[$f] . ': ' : '') . $m) ?></div><?php } ?>
          <?php foreach ($warns as $f => $m) { ?><div class="muted"><?= e($m) ?></div><?php } ?><?= !$errs && !$warns ? '<span class="muted">OK</span>' : '' ?></td>
        <td data-label="Vendor"><strong><?= e($pt ? (!empty($pt['registered_name']) ? $pt['registered_name'] : trim((isset($pt['last_name']) ? $pt['last_name'] : '') . ', ' . (isset($pt['first_name']) ? $pt['first_name'] : ''), ', ')) : (isset($raw['p_registered_name']) ? $raw['p_registered_name'] : '')) ?></strong>
          <span class="sub"><?= e(!empty($pt['tin']) ? Tin::display($pt['tin'], $pt['branch_code']) : (isset($raw['p_tin']) && $raw['p_tin'] !== '' ? $raw['p_tin'] : 'No TIN')) ?></span></td>
        <td data-label="Month"><?= e(!empty($rec['period_month']) ? month_label($rec['period_month']) : '') ?></td>
        <td data-label="exempt" class="num"><?= $rec ? Money::fmtDb($rec['exempt_amount']) : '' ?></td>
        <td data-label="zeroRated" class="num"><?= $rec ? Money::fmtDb($rec['zero_rated_amount']) : '' ?></td>
        <td data-label="taxableNetofVat" class="num"><?= $rec ? Money::fmt(Records::purchaseTaxable($rec)) : '' ?></td>
        <td data-label="vatRate" class="num"><?= $rec && isset($rec['vat_rate']) && $rec['vat_rate'] !== null ? e(rtrim(rtrim(Money::rateFormat((int)Money::rateUnits($rec['vat_rate']), 'pct2'), '0'), '.')) : '' ?></td>
        <td data-label="inputVat" class="num"><?= $rec ? Money::fmtDb($rec['input_vat']) : '' ?></td>
        <td data-label="totalPurchases" class="num"><strong><?= $rec ? Money::fmt(Records::purchaseTotal($rec)) : '' ?></strong></td>
      </tr>
    <?php } ?></tbody></table></div>
  <?php } elseif ($isSales) { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Row</th><th>Messages</th><th>Customer</th><th>Invoice</th><th class="num">Gross taxable</th><th class="num">Exempt</th><th class="num">Zero-rated</th><th class="num">Taxable sales</th><th class="num">VAT rate</th><th class="num">Output VAT</th></tr></thead>
    <tbody><?php foreach ($rows as $r) { $n = json_decode((string)$r['normalized_json'], true) ?: array(); $rec = isset($n['record']) ? $n['record'] : array(); $pt = isset($n['party']) ? $n['party'] : array();
      $errs = json_decode((string)$r['errors_json'], true) ?: array(); $warns = json_decode((string)$r['warnings_json'], true) ?: array(); $raw = json_decode($r['raw_json'], true);
      $done = isset($rec['taxable_amount']) && isset($rec['output_vat']) && $rec['taxable_amount'] !== null && $rec['output_vat'] !== null; ?>
      <tr class="<?= $r['status'] === 'error' ? 'row-error' : ($r['status'] === 'duplicate' ? 'row-dup' : '') ?>">
        <td data-label="Row" class="nowrap"><?= (int)$r['row_no'] ?></td>
        <td data-label="Messages" class="small" style="min-width:220px"><?php foreach ($errs as $f => $m) { ?><div class="msg-bad"><?= e((isset($labels[$f]) ? $labels[$f] . ': ' : '') . $m) ?></div><?php } ?>
          <?php foreach ($warns as $f => $m) { ?><div class="muted"><?= e($m) ?></div><?php } ?><?= !$errs && !$warns ? '<span class="muted">OK</span>' : '' ?></td>
        <td data-label="Customer"><strong><?= e($pt ? (!empty($pt['registered_name']) ? $pt['registered_name'] : trim((isset($pt['last_name']) ? $pt['last_name'] : '') . ', ' . (isset($pt['first_name']) ? $pt['first_name'] : ''), ', ')) : (isset($raw['p_registered_name']) ? $raw['p_registered_name'] : '')) ?></strong>
          <span class="sub"><?= e(!empty($pt['tin']) ? Tin::display($pt['tin'], $pt['branch_code']) : (isset($raw['p_tin']) && $raw['p_tin'] !== '' ? $raw['p_tin'] : 'No TIN')) ?></span></td>
        <td data-label="Invoice" class="nowrap"><?= e(isset($raw['invoice_no']) ? $raw['invoice_no'] : '') ?><span class="sub"><?= e(!empty($rec['invoice_date']) ? fmt_date($rec['invoice_date'], 'm/d/Y') : (isset($raw['invoice_date']) ? $raw['invoice_date'] : '')) ?></span></td>
        <td data-label="Gross taxable" class="num"><?= $done ? Money::fmt(Records::salesGross($rec)) : e(isset($raw['gross_taxable']) ? $raw['gross_taxable'] : '') ?></td>
        <td data-label="Exempt" class="num"><?= isset($rec['exempt_amount']) ? Money::fmtDb($rec['exempt_amount']) : '' ?></td>
        <td data-label="Zero-rated" class="num"><?= isset($rec['zero_rated_amount']) ? Money::fmtDb($rec['zero_rated_amount']) : '' ?></td>
        <td data-label="Taxable sales" class="num"><?= $done ? Money::fmtDb($rec['taxable_amount']) : '' ?></td>
        <td data-label="VAT rate" class="num"><?= !empty($rec['vat_rate']) || (isset($rec['vat_rate']) && $rec['vat_rate'] === '0.0000') ? e(rtrim(rtrim(Money::rateFormat((int)Money::rateUnits($rec['vat_rate']), 'pct2'), '0'), '.')) : '' ?></td>
        <td data-label="Output VAT" class="num"><strong><?= $done ? Money::fmtDb($rec['output_vat']) : '' ?></strong></td>
      </tr>
    <?php } ?></tbody></table></div>
  <?php } elseif ($isSawt) { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Row</th><th>Messages</th><th>Payor</th><th>ATC</th><th>Nature of payment</th><th class="num">Income payment</th><th class="num">Tax rate</th><th class="num">Tax withheld</th></tr></thead>
    <tbody><?php foreach ($rows as $r) { $n = json_decode((string)$r['normalized_json'], true) ?: array(); $rec = isset($n['record']) ? $n['record'] : array(); $pt = isset($n['party']) ? $n['party'] : array();
      $errs = json_decode((string)$r['errors_json'], true) ?: array(); $warns = json_decode((string)$r['warnings_json'], true) ?: array(); $raw = json_decode($r['raw_json'], true);
      $nature = !empty($rec['nature_of_payment']) ? $rec['nature_of_payment'] : trim(preg_replace('/\s+/', ' ', isset($raw['nature_of_payment']) ? (string)$raw['nature_of_payment'] : '')); ?>
      <tr class="<?= $r['status'] === 'error' ? 'row-error' : ($r['status'] === 'duplicate' ? 'row-dup' : '') ?>">
        <td data-label="Row" class="nowrap"><?= (int)$r['row_no'] ?></td>
        <td data-label="Messages" class="small" style="min-width:220px"><?php foreach ($errs as $f => $m) { ?><div class="msg-bad"><?= e((isset($labels[$f]) ? $labels[$f] . ': ' : '') . $m) ?></div><?php } ?>
          <?php foreach ($warns as $f => $m) { ?><div class="muted"><?= e($m) ?></div><?php } ?><?= !$errs && !$warns ? '<span class="muted">OK</span>' : '' ?></td>
        <td data-label="Payor"><strong><?= e($pt ? (!empty($pt['registered_name']) ? $pt['registered_name'] : trim((isset($pt['last_name']) ? $pt['last_name'] : '') . ', ' . (isset($pt['first_name']) ? $pt['first_name'] : '') . ' ' . (isset($pt['middle_name']) ? $pt['middle_name'] : ''), ', ')) : trim(isset($raw['p_registered_name']) ? (string)$raw['p_registered_name'] : '')) ?></strong>
          <span class="sub"><?= e(!empty($pt['tin']) ? Tin::display($pt['tin'], $pt['branch_code']) : (isset($raw['p_tin']) && $raw['p_tin'] !== '' ? $raw['p_tin'] : 'No TIN')) ?></span></td>
        <td data-label="ATC" class="nowrap"><?= e(!empty($rec['atc_code']) ? $rec['atc_code'] : (isset($raw['atc_code']) ? $raw['atc_code'] : '')) ?></td>
        <td data-label="Nature of payment" class="small"><span class="clamp-2" title="<?= e($nature) ?>"><?= e($nature) ?></span></td>
        <td data-label="Income payment" class="num"><?= isset($rec['income_amount']) ? Money::fmtDb($rec['income_amount']) : e(isset($raw['income_amount']) ? $raw['income_amount'] : '') ?></td>
        <td data-label="Tax rate" class="num"><?= $rec && isset($rec['tax_rate']) && $rec['tax_rate'] !== null && $rec['tax_rate'] !== '' ? e(Money::rateFormat((int)Money::rateUnits($rec['tax_rate']), 'pct2')) : e(isset($raw['tax_rate']) ? $raw['tax_rate'] : '') ?></td>
        <td data-label="Tax withheld" class="num"><strong><?= $rec && isset($rec['tax_withheld']) ? Money::fmtDb($rec['tax_withheld']) : e(isset($raw['tax_withheld']) ? $raw['tax_withheld'] : '') ?></strong></td>
      </tr>
    <?php } ?></tbody></table></div>
  <?php } else { ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Row</th><th>Messages</th><?php foreach (array_slice($type['columns'], 0, 9) as $c) { ?><th><?= e($c['title']) ?></th><?php } ?></tr></thead>
    <tbody><?php foreach ($rows as $r) { $raw = json_decode($r['raw_json'], true); $errs = json_decode((string)$r['errors_json'], true) ?: array(); $warns = json_decode((string)$r['warnings_json'], true) ?: array(); ?>
      <tr class="<?= $r['status'] === 'error' ? 'row-error' : ($r['status'] === 'duplicate' ? 'row-dup' : '') ?>">
        <td class="nowrap"><?= (int)$r['row_no'] ?></td>
        <td class="small" style="min-width:260px"><?php foreach ($errs as $f => $m) { ?><div class="msg-bad"><?= e((isset($labels[$f]) ? $labels[$f] . ': ' : '') . $m) ?></div><?php } ?>
          <?php foreach ($warns as $f => $m) { ?><div class="muted"><?= e($m) ?></div><?php } ?></td>
        <?php foreach (array_slice($type['columns'], 0, 9) as $c) { ?><td class="small"><?= e(isset($raw[$c['key']]) ? $raw[$c['key']] : '') ?></td><?php } ?>
      </tr>
    <?php } ?></tbody></table></div>
  <?php } ?>
  <?= View::pager($cnt, $page, $per, array('id' => $job['id'], 'show' => $show)) ?>
  <?php } ?>
</div>
<?php } ?>
<?php View::footer();
