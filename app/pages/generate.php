<?php
Ctx::requirePerm('reports.generate');
$client = Ctx::requireClient();
$forms = DB::all('SELECT * FROM form_types WHERE is_active = 1 ORDER BY sort_order');
$formMap = array();
foreach ($forms as $f) {
    $formMap[$f['code']] = $f;
}
$form = isset($formMap[input('form')]) ? input('form') : '1601EQ';
$schedules = DB::all('SELECT * FROM report_schedules WHERE form_code = ? ORDER BY sort_order', array($form));
$schedCodes = array();
foreach ($schedules as $s) {
    $schedCodes[$s['schedule_code']] = $s['schedule_code'] . ': ' . $s['name'];
}
$schedule = isset($schedCodes[input('schedule')]) ? input('schedule') : key($schedCodes);
$f = $formMap[$form];
$sawtForms = Datasets::sawtForms();
$sawtForm = isset($sawtForms[input('sawt_form')]) ? input('sawt_form') : ($form === 'SAWT' ? ($client['taxpayer_type'] === 'individual' ? '1701Q' : '1702Q') : null);
$freq = $f['frequency'] === 'return' ? $sawtForms[$sawtForm][1] : $f['frequency'];
$year = input_int('year', (int)date('Y'));
$defaultPart = $freq === 'monthly' ? (int)date('n', strtotime('first day of last month')) : ($freq === 'quarterly' ? max(1, (int)ceil(((int)date('n') - 1) / 3)) : ($freq === 'semestral' ? 1 : 1));
$part = input_int('part', $defaultPart);
$ho = Ctx::headOffice();
$branchId = input_int('branch_id', $ho ? (int)$ho['id'] : 0);
$in = array('form' => $form, 'schedule' => $schedule, 'branch_id' => $branchId, 'year' => $year, 'part' => $part, 'sawt_form' => $sawtForm,
    'amended' => input('amended') ? 1 : 0, 'source' => 'database');

if (is_post()) {
    try {
        $ctx = Reports::context($in);
        $draft = input('draft') === '1';
        $fid = Reports::generate($ctx, $draft, trim((string)input('notes')) ?: null);
        flash('success', 'Generated ' . DB::val('SELECT filename FROM dat_files WHERE id = ?', array($fid)) . '. Structure was checked by parsing the file back.');
        redirect(url('file_view', array('id' => $fid)));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect(url('generate', array_merge($in, array('preview' => 1))));
    }
}
$ctx = null;
$error = null;
if (input('preview')) {
    try {
        $ctx = Reports::context($in);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$partOpts = array();
if ($freq === 'monthly') {
    for ($m = 1; $m <= 12; $m++) {
        $partOpts[$m] = date('F', mktime(0, 0, 0, $m, 1));
    }
} elseif ($freq === 'quarterly') {
    $partOpts = array(1 => 'Q1 (Jan to Mar)', 2 => 'Q2 (Apr to Jun)', 3 => 'Q3 (Jul to Sep)', 4 => 'Q4 (Oct to Dec)');
} elseif ($freq === 'semestral') {
    $partOpts = array(1 => 'January to June', 2 => 'July to December');
}
$formOpts = array();
foreach ($forms as $fo) {
    $formOpts[$fo['code']] = $fo['name'];
}
$branchOpts = array();
foreach (Ctx::branches() as $b) {
    $branchOpts[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
View::header('Generate a file');
View::pageBar('Generate a file', View::clientContext('from saved records'), '<a class="btn btn-quiet" href="' . e(url('files')) . '">File library</a>');
?>
<form method="get" class="sheet">
  <input type="hidden" name="p" value="generate"><input type="hidden" name="preview" value="1">
  <div class="sheet-body"><div class="form-grid">
    <div class="field half"><label for="form">Form</label><?= View::select('form', $formOpts, $form, 'id="form" onchange="this.form.preview.value=\'\';this.form.submit()"') ?><span class="hint"><?= e($f['description']) ?></span></div>
    <div class="field half"><label for="schedule">Schedule</label><?= View::select('schedule', $schedCodes, $schedule, 'id="schedule"') ?></div>
    <?php if ($form === 'SAWT') { ?>
      <div class="field half"><label for="sawt_form">Attached to return</label><?= View::select('sawt_form', array_map(function ($x) { return $x[0]; }, $sawtForms), $sawtForm, 'id="sawt_form" onchange="this.form.preview.value=\'\';this.form.submit()"') ?></div>
    <?php } ?>
    <?php if ($f['family'] !== 'RELIEF') { ?>
      <div class="field"><label for="branch_id">Branch</label><?= View::select('branch_id', $branchOpts, $branchId, 'id="branch_id"') ?></div>
    <?php } else { ?>
      <div class="field"><label>Branches</label><input type="text" value="All branches of TIN <?= e(Tin::display($client['tin'])) ?>" readonly></div>
    <?php } ?>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" min="2000" max="2100" value="<?= (int)$year ?>"></div>
    <?php if ($partOpts) { ?><div class="field"><label for="part"><?= $freq === 'monthly' ? 'Month' : ($freq === 'quarterly' ? 'Quarter' : 'Semester') ?></label><?= View::select('part', $partOpts, $part, 'id="part"') ?></div><?php } ?>
    <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="amended" value="1" <?= input('amended') ? 'checked' : '' ?>> Amended return</label></div>
  </div>
  <?php if (in_array($form, array('1601EQ', '1601FQ'), true)) { ?><p class="small muted" style="margin:12px 0 0">The BIR job aid describes three QAP files per quarter, one per month. Generate each month of the quarter.</p><?php } ?>
  <?php if ($form === 'SRS') { ?><div class="callout warn" style="margin-top:12px">A secondary source reports that RMC 122-2019 terminated SRS submission, and the official SRS column layout was not obtainable. Confirm the current rule before using this output. For multi-month consolidation from stored QAP files, use the <a href="<?= e(url('consolidate')) ?>">SRS and annual builder</a>.</div><?php } ?>
  </div>
  <div class="sheet-foot"><button class="btn btn-primary"><?= View::icon('search') ?>Preview rows and checks</button></div>
</form>

<?php if ($error) { ?><div class="notice notice-error"><?= e($error) ?></div><?php } ?>
<?php if ($ctx) {
    $spec = $ctx['spec'];
    list($nErr, $nWarn) = Checks::counts($ctx['dq']);
    list($base, $tax) = Reports::totals($ctx['dataset']['rows']);
    ?>
  <div class="cols cols-main">
    <div>
      <div class="sheet">
        <div class="sheet-head"><div><h2><?= e($ctx['period']['label']) ?>: <?= plural(count($ctx['dataset']['rows']), 'detail row') ?></h2><p>Built from <?= plural($ctx['dataset']['source_count'], 'saved record') ?><?= $f['family'] === 'RELIEF' ? ', consolidated per counterparty' : ', grouped per payee and ATC' ?></p></div></div>
        <div class="figures">
          <div><strong><?= Money::fmt($base) ?></strong><span><?= $form === '1604C' ? 'Gross compensation' : 'Base amount' ?></span></div>
          <div><strong><?= Money::fmt($tax) ?></strong><span><?= in_array($form, array('SLS'), true) ? 'Output VAT' : ($form === 'SLP' ? 'Input tax' : 'Tax withheld') ?></span></div>
          <div><strong><?= $nErr ?></strong><span>Errors</span></div>
          <div><strong><?= $nWarn ?></strong><span>Warnings</span></div>
        </div>
      </div>
      <?php if ($ctx['dq']) { ?>
      <div class="sheet"><div class="sheet-head"><h2>Data quality</h2><span class="small muted">Level 1</span></div>
        <ul class="issues">
          <?php foreach (array_slice($ctx['dq'], 0, 150) as $i) { ?><li class="<?= e($i['severity']) ?>"><?= e($i['message']) ?><?php if ($i['record_no']) { ?><span class="where">record <?= (int)$i['record_no'] ?></span><?php } ?></li><?php } ?>
          <?php if (count($ctx['dq']) > 150) { ?><li class="warning"><?= count($ctx['dq']) - 150 ?> more not shown</li><?php } ?>
        </ul></div>
      <?php } ?>
      <div class="sheet"><div class="sheet-head"><h2>Rows</h2><span class="small muted">First 200</span></div>
        <div class="table-wrap"><table class="table table-stack">
          <?php $rows = array_slice($ctx['dataset']['rows'], 0, 200);
          if ($form === '1604C') { ?>
            <thead><tr><th>Employee</th><th>TIN</th><th class="num">Gross</th><th class="num">Taxable</th><th class="num">Tax due</th><th class="num">Withheld</th></tr></thead><tbody>
            <?php foreach ($rows as $r) { ?><tr><td data-label="Employee"><?= e($r['last_name'] . ', ' . $r['first_name']) ?></td><td data-label="TIN"><?= e(Tin::display($r['tin'], $r['tin_branch'])) ?></td>
              <td data-label="Gross" class="num"><?= Money::fmt($r['pres_gross']) ?></td><td data-label="Taxable" class="num"><?= Money::fmt($r['taxable_comp_total']) ?></td>
              <td data-label="Tax due" class="num"><?= Money::fmt($r['tax_due']) ?></td><td data-label="Withheld" class="num"><?= Money::fmt($r['tax_withheld_adjusted']) ?></td></tr><?php } ?>
          <?php } elseif ($f['family'] === 'RELIEF') {
              $keys = $form === 'SLS' ? array('exempt' => 'Exempt', 'zero_rated' => 'Zero-rated', 'taxable' => 'Taxable', 'output_vat' => 'Output VAT')
                  : ($form === 'SLP' ? array('services' => 'Services', 'capital_goods' => 'Capital goods', 'other_goods' => 'Other goods', 'input_tax' => 'Input tax')
                  : array('landed_cost' => 'Landed cost', 'taxable' => 'Taxable', 'vat' => 'VAT')); ?>
            <thead><tr><th>Counterparty</th><th>TIN</th><?php foreach ($keys as $l) { ?><th class="num"><?= e($l) ?></th><?php } ?></tr></thead><tbody>
            <?php foreach ($rows as $r) { ?><tr><td data-label="Counterparty"><?= e($form === 'SLI' ? $r['supplier_name'] . ' ' . $r['import_entry_no'] : party_name($r)) ?></td><td data-label="TIN"><?= e(isset($r['tin']) ? Tin::display($r['tin']) : '') ?></td>
              <?php foreach ($keys as $k => $l) { ?><td data-label="<?= e($l) ?>" class="num"><?= Money::fmt($r[$k]) ?></td><?php } ?></tr><?php } ?>
          <?php } else { ?>
            <thead><tr><th>Payee / payor</th><th>TIN</th><th>ATC</th><th class="num">Rate</th><th class="num">Income</th><th class="num">Tax</th><th class="num">Sources</th></tr></thead><tbody>
            <?php foreach ($rows as $r) { ?><tr><td data-label="Name"><?= e(party_name($r)) ?></td><td data-label="TIN" class="nowrap"><?= e(Tin::display($r['tin'], $r['branch'])) ?></td><td data-label="ATC"><?= e($r['atc']) ?></td>
              <td data-label="Rate" class="num"><?= e(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2')) ?>%</td><td data-label="Income" class="num"><?= Money::fmt($r['income']) ?></td>
              <td data-label="Tax" class="num"><?= Money::fmt($r['tax']) ?></td><td data-label="Sources" class="num"><?= count($r['source_ids']) ?></td></tr><?php } ?>
          <?php } ?>
          </tbody></table></div></div>
    </div>
    <div>
      <div class="sheet">
        <div class="sheet-head"><h2>File</h2></div>
        <div class="sheet-body"><dl class="kv">
          <dt>Filename</dt><dd class="mono"><?= $ctx['preview'] ? e($ctx['preview']['filename']) : '' ?></dd>
          <?php if ($spec) { ?>
          <dt>Layout</dt><dd><a href="<?= e(url('spec_view', array('id' => $spec['id']))) ?>">#<?= (int)$spec['id'] ?> <?= e($spec['version_label']) ?></a></dd>
          <dt>Layout status</dt><dd><?= View::status($spec['status']) ?></dd>
          <dt>Evidence</dt><dd class="conf conf-<?= e($spec['confidence']) ?>"><?= e(Specs::confidenceLabel($spec['confidence'])) ?></dd>
          <dt>Encoding</dt><dd><?= e($spec['encoding']) ?>, <?= e($spec['line_ending']) ?> line endings</dd>
          <?php } ?>
        </dl></div>
      </div>
      <?php if ($ctx['preview']) { ?>
      <div class="sheet"><div class="sheet-head"><h2>Byte preview</h2><span class="small muted">First 12 lines</span></div>
        <div class="dat-raw"><?php foreach (array_slice($ctx['preview']['lines'], 0, 12) as $ln) { ?><div><?= e($ln) ?></div><?php } ?></div></div>
      <?php } ?>
      <form method="post" class="sheet"><?= csrf_field() ?>
        <?php foreach ($in as $k => $v) { ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php } ?>
        <div class="sheet-body stack">
          <div class="field"><label for="notes">Note for reviewers</label><input id="notes" name="notes" type="text" maxlength="255"></div>
          <?php if ($nErr) { ?>
            <div class="callout bad small">Errors block a submission-ready file. <?= Ctx::can('dat.export_draft') ? 'You can still generate a diagnostic draft labelled UNVALIDATED to inspect it.' : '' ?></div>
          <?php } elseif (!$spec || $spec['status'] !== 'approved') { ?>
            <div class="callout warn small">The layout is not approved yet, so the file will be labelled unvalidated draft until the official validator accepts it.</div>
          <?php } ?>
        </div>
        <div class="sheet-foot">
          <?php if (!$nErr && $ctx['dataset']['rows']) { ?><button class="btn btn-primary" type="submit"><?= View::icon('file') ?>Generate file</button>
          <?php } elseif (Ctx::can('dat.export_draft') && $ctx['dataset']['rows'] && $spec) { ?><button class="btn" type="submit" name="draft" value="1" data-confirm="Create a diagnostic draft despite the errors? It cannot be marked submission-ready.">Generate diagnostic draft</button><?php } ?>
        </div>
      </form>
    </div>
  </div>
<?php } ?>
<?php View::footer();
