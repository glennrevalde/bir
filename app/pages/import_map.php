<?php
Ctx::requirePerm('imports.run');
$client = Ctx::requireClient();
$job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array(input_int('id'), $client['id']));
if (!$job) {
    deny('Import not found for this client.');
}
if (!in_array($job['status'], array('uploaded', 'mapped', 'staged'), true)) {
    redirect(url('import_review', array('id' => $job['id'])));
}
$type = Imports::type($job['import_type']);
$sheets = Imports::sheetNames($job);
if (input('sheet') !== null && in_array(input('sheet'), $sheets, true)) {
    $job['sheet_name'] = input('sheet');
} elseif (!$job['sheet_name'] && $sheets) {
    $want = isset($type['sheet']) ? $type['sheet'] : 'Data';
    $job['sheet_name'] = in_array($want, $sheets, true) ? $want : (in_array('Data', $sheets, true) ? 'Data' : $sheets[0]);
}
$jobOpts = json_decode((string)$job['options_json'], true) ?: array();
$requested = input('header_row') !== null;
$job['header_row'] = max(1, input_int('header_row', (int)$job['header_row']));
try {
    $scan = Imports::readRows($job, max(70, $job['header_row'] + 12));
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect(url('imports'));
}
// Find the header by itself on a fresh upload: a printed SAWT has title lines and a header stacked on three rows.
$from = null;
if (!$requested && !$job['mapping_json']) {
    $det = Imports::detectHeader($type, $scan);
    if ($det) {
        list($from, $job['header_row']) = $det;
    }
}
if ($from === null) {
    $from = Imports::headerStart($scan, $job['header_row']);
}
$headers = Imports::headersOf($scan, $job['header_row'], $from);
$preview = array();
$skipped = 0;
foreach ($scan as $rn => $cells) {
    if ($rn <= $job['header_row']) {
        continue;
    }
    $kind = Imports::rowKind($cells);
    if ($kind === 'end') {
        break;
    }
    if ($kind !== 'data') {
        $skipped += $kind === 'blank' ? 0 : 1;
        continue;
    }
    if (count($preview) < 8) {
        $preview[$rn] = $cells;
    }
}
// Title lines of a printed report: form, month, TIN and payee's name, checked against the client.
$isReport = !empty($type['report']);
$meta = $isReport ? Imports::reportMeta($scan, $from) : null;
$metaProblems = $metaNotes = array();
$metaBranch = null;
if ($isReport) {
    list($metaProblems, $metaNotes, $metaBranch) = Imports::reportCheck($meta, $client);
}
$monthValue = isset($jobOpts['period_month']) ? $jobOpts['period_month'] : '';
if ($isReport && empty($jobOpts['month_chosen']) && $meta['month']) {
    $monthValue = $meta['month'];
}
$saved = DB::all('SELECT * FROM column_mappings WHERE organization_id = ? AND import_type = ? ORDER BY name', array(Ctx::orgId(), $job['import_type']));
list($suggest, $exact) = Imports::suggestMapping($type, $headers);
if ($job['mapping_json']) {
    $suggest = json_decode($job['mapping_json'], true);
}
if ($mid = input_int('mapping')) {
    foreach ($saved as $m) {
        if ((int)$m['id'] === $mid) {
            $suggest = json_decode($m['mapping_json'], true);
        }
    }
}
$problems = array();
if (is_post()) {
    $map = array();
    foreach ($type['columns'] as $c) {
        $v = isset($_POST['map'][$c['key']]) ? $_POST['map'][$c['key']] : '';
        $map[$c['key']] = $v === '' ? null : (int)$v;
    }
    $problems = array_merge($metaProblems, Imports::mappingProblems($type, $map));
    $opts = array('date_format' => input('date_format') === 'dmy' ? 'dmy' : 'auto', 'header_from' => $from);
    $dateKey = $type['key'] === 'withheld_on_us' ? 'payment_date' : 'invoice_date';
    if (!empty($type['needs_month'])) {
        $pm = Records::parseMonth((string)input('period_month'));
        if ($pm) {
            $opts['period_month'] = $pm;
        } elseif (!isset($map[$dateKey]) || $map[$dateKey] === null) {
            $problems[] = $isReport
                ? 'Choose the month. The file has no line FOR THE MONTH OF, and no date column is mapped.'
                : 'Choose the purchase month. The sheet has no date column, so every row is filed under this month.';
        }
    }
    if ($isReport) {
        $opts['report'] = array('form' => $meta['form'], 'month' => $meta['month'], 'tin' => $meta['tin'], 'branch' => $meta['branch'], 'name' => $meta['name']);
        if ($meta['month'] && !empty($opts['period_month']) && $opts['period_month'] !== $meta['month']) {
            $opts['month_differs'] = true;
        }
    }
    if (!$problems) {
        $upd = array('sheet_name' => $job['sheet_name'], 'header_row' => $job['header_row'], 'mapping_json' => json_encode($map),
            'options_json' => json_encode($opts), 'template_version' => $exact ? (isset($type['version']) ? $type['version'] : Imports::TEMPLATE_VERSION) : null, 'updated_at' => now());
        if ($metaBranch) {
            $upd['branch_id'] = $metaBranch;
        }
        DB::update('import_jobs', $upd, 'id = ?', array($job['id']));
        if (trim((string)input('save_as')) !== '') {
            DB::insert('column_mappings', array('organization_id' => Ctx::orgId(), 'import_type' => $job['import_type'], 'name' => mb_substr(trim(input('save_as')), 0, 100),
                'mapping_json' => json_encode($map), 'created_by' => Auth::id(), 'created_at' => now()));
        }
        $n = Imports::prepare($job['id']);
        Audit::log('import.map', 'import_jobs', $job['id'], 'Mapped columns; ' . $n . ' rows read');
        if (!empty($opts['month_differs'])) {
            flash('warning', 'The file says ' . month_label($meta['month']) . ', but the rows are filed under ' . month_label($opts['period_month']) . ' as chosen.');
        }
        redirect(url('import_review', array('id' => $job['id'])));
    }
    $suggest = $map;
    $monthValue = (string)input('period_month');
}
$colOpts = array();
foreach ($headers as $i => $h) {
    $colOpts[$i] = XlsxWriter::colLetter($i) . ': ' . $h;
}
View::header('Map columns', array('page' => 'import_map'));
View::pageBar('Map columns', View::clientContext(e($job['original_filename']) . ', ' . e($type['label'])), '<a class="btn btn-quiet" href="' . e(url('imports')) . '">Back to imports</a>');
?>
<?php if ($exact) { ?><div class="notice notice-success"><?= $isReport ? 'This file is in the SAWT layout. All nine columns are mapped; check the title lines and the preview, then continue.' : 'This file matches the ' . e($type['code']) . ' template. All columns are mapped; check the preview and continue.' ?></div><?php } ?>
<?php foreach (is_post() ? $problems : $metaProblems as $p) { ?><div class="notice notice-error"><?= e($p) ?></div><?php } ?>
<?php foreach ($metaNotes as $p) { ?><div class="notice notice-warning"><?= e($p) ?></div><?php } ?>
<?php
// Before staging: which required fields have no column, and which columns of the file nothing reads.
$usedCols = array_filter((array)$suggest, function ($v) { return $v !== null && $v !== ''; });
$missingReq = array();
foreach ($type['columns'] as $c) {
    if (!empty($c['required']) && (!isset($suggest[$c['key']]) || $suggest[$c['key']] === null || $suggest[$c['key']] === '')) {
        $missingReq[] = $c['title'];
    }
}
$unused = array();
foreach ($headers as $i => $h) {
    if (!in_array($i, array_map('intval', $usedCols), true)) {
        $unused[] = XlsxWriter::colLetter($i) . ' ' . $h;
    }
}
if ($headers && !is_post() && ($missingReq || $unused)) { ?>
<div class="notice notice-warning map-check">
  <?php if ($missingReq) { ?><p><strong>Required data not found in the file:</strong> <?= e(implode(', ', $missingReq)) ?>. Choose the column that holds it below, or add the column to your workbook and upload it again.</p><?php } ?>
  <?php if ($unused) { ?><p><strong>Columns in the file that will not be read:</strong> <?= e(implode(', ', array_slice($unused, 0, 12))) ?><?= count($unused) > 12 ? ' and ' . (count($unused) - 12) . ' more' : '' ?>. If one holds data you need, pick it for the right field below.</p><?php } ?>
</div>
<?php } ?>
<?php if ($isReport) { ?>
<div class="sheet report-meta">
  <div class="sheet-head"><div><h2>Title lines</h2><p>Read from the lines above the header and checked against <?= e(Ctx::clientName($client)) ?>.</p></div></div>
  <div class="sheet-body"><dl class="kv kv-cols">
    <dt>BIR form</dt><dd><?= $meta['form'] ? e($meta['form']) : '<span class="muted">Not found</span>' ?></dd>
    <dt>Month</dt><dd><?= $meta['month'] ? e(month_label($meta['month'])) : '<span class="muted">Not found; choose it below</span>' ?></dd>
    <dt>TIN</dt><dd><?= $meta['tin'] ? e(Tin::display($meta['tin'], $meta['branch'])) . ($meta['tin'] === $client['tin'] ? ' <span class="ok-text">matches the client</span>' : ' <span class="warn-text">another taxpayer</span>') : '<span class="muted">Not found</span>' ?></dd>
    <dt>Payee's name</dt><dd><?= $meta['name'] ? e($meta['name']) : '<span class="muted">Not found</span>' ?></dd>
    <dt>Header</dt><dd>Rows <?= (int)$from ?> to <?= (int)$job['header_row'] ?><?= $skipped ? ', with ' . (int)$skipped . ' report lines (numbering, dashes, totals) left out' : '' ?></dd>
  </dl></div>
</div>
<?php } ?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="import_map"><input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
    <?php if ($sheets) { ?><div class="field"><label for="sheet">Sheet</label><?= View::select('sheet', array_combine($sheets, $sheets), $job['sheet_name'], 'id="sheet"') ?></div><?php } ?>
    <div class="field"><label for="header_row">Header row</label><input id="header_row" name="header_row" type="number" min="1" max="50" value="<?= (int)$job['header_row'] ?>"></div>
    <?php if ($saved) { $sm = array(); foreach ($saved as $m) { $sm[$m['id']] = $m['name']; } ?><div class="field"><label for="mapping">Saved mapping</label><?= View::select('mapping', $sm, input_int('mapping'), 'id="mapping"', 'None') ?></div><?php } ?>
    <button class="btn">Reload preview</button>
  </form>
  <div class="sheet-head"><h2>Preview</h2><span class="small muted">First rows below the header</span></div>
  <div class="table-wrap"><table class="table table-preview"><thead><tr><th class="num">Row</th><?php foreach ($headers as $i => $h) { ?><th><?= e(XlsxWriter::colLetter($i) . ': ' . $h) ?></th><?php } ?></tr></thead><tbody>
    <?php foreach ($preview as $rn => $cells) { ?>
      <tr><td class="num muted small"><?= (int)$rn ?></td><?php foreach ($headers as $i => $h) { $c = isset($cells[$i]) ? $cells[$i] : array('v' => '', 'num' => false, 'date' => null); $txt = $c['date'] ?: ($c['num'] ? Imports::excelNumber($c['v'], true) : trim(preg_replace('/\s+/', ' ', (string)$c['v']))); ?><td class="small<?= $c['num'] ? ' num' : '' ?>"<?= mb_strlen($txt) > 60 ? ' title="' . e($txt) . '"' : '' ?>><?= e(mb_strlen($txt) > 60 ? mb_substr($txt, 0, 57) . '...' : $txt) ?></td><?php } ?></tr>
    <?php } ?>
  </tbody></table></div>
  <?php if (!$headers) { ?><div class="empty"><h2>No header found on row <?= (int)$job['header_row'] ?></h2><p>Set the row number that holds the column titles.</p></div><?php } ?>
</div>
<?php if ($headers) { ?>
<form method="post" class="sheet"><?= csrf_field() ?>
  <input type="hidden" name="sheet" value="<?= e($job['sheet_name']) ?>"><input type="hidden" name="header_row" value="<?= (int)$job['header_row'] ?>">
  <div class="sheet-head"><div><h2>Fields</h2><p>Pick the source column for each field. Required fields are marked. Each column feeds one field.</p></div></div>
  <div class="sheet-body"><div class="form-grid">
    <?php foreach ($type['columns'] as $c) { if (!empty($c['extra'])) { continue; } $k = $c['key']; ?>
      <div class="field"><label for="m-<?= e($k) ?>"><?= e($c['title']) ?><?= !empty($c['required']) ? ' *' : '' ?><?= !empty($c['computed']) ? ' <span class="muted">(checked)</span>' : '' ?></label>
        <?= View::select('map[' . $k . ']', $colOpts, isset($suggest[$k]) && $suggest[$k] !== null ? $suggest[$k] : '', 'id="m-' . e($k) . '"', 'Not in this file') ?>
        <?php if (!empty($c['help'])) { ?><span class="hint"><?= e($c['help']) ?></span><?php } ?></div>
    <?php } ?>
  </div>
  <?php $extras = array_filter($type['columns'], function ($c) { return !empty($c['extra']); }); if ($extras) { $used = false; foreach ($extras as $c) { if (isset($suggest[$c['key']]) && $suggest[$c['key']] !== null) { $used = true; } } ?>
  <details class="map-extras"<?= $used ? ' open' : '' ?>><summary>Optional columns not in the standard sheet</summary><div class="form-grid">
    <?php foreach ($extras as $c) { $k = $c['key']; ?>
      <div class="field"><label for="m-<?= e($k) ?>"><?= e($c['title']) ?></label>
        <?= View::select('map[' . $k . ']', $colOpts, isset($suggest[$k]) && $suggest[$k] !== null ? $suggest[$k] : '', 'id="m-' . e($k) . '"', 'Not in this file') ?>
        <?php if (!empty($c['help'])) { ?><span class="hint"><?= e($c['help']) ?></span><?php } ?></div>
    <?php } ?>
  </div></details><?php } ?>
  </div>
  <div class="sheet-foot" style="justify-content:space-between">
    <div class="inline-form">
      <?php if (!empty($type['needs_month'])) { ?><div class="field"><label for="period_month"><?= $isReport ? 'Month' : 'Purchase month' ?> *</label><input id="period_month" name="period_month" type="month" value="<?= e($monthValue) ?>"><?php if ($isReport && $meta['month']) { ?><span class="hint">From the file: <?= e(month_label($meta['month'])) ?></span><?php } ?></div><?php } ?>
      <div class="field"><label for="date_format">Dates like 03/04/2026 mean</label><?= View::select('date_format', array('auto' => 'Month first (MM/DD/YYYY)', 'dmy' => 'Day first (DD/MM/YYYY)'), 'auto', 'id="date_format"') ?></div>
      <div class="field"><label for="save_as">Save this mapping as</label><input id="save_as" name="save_as" type="text" maxlength="100" placeholder="Optional name"></div></div>
    <button class="btn btn-primary" type="submit">Stage and check rows</button>
  </div>
</form>
<?php } ?>
<?php View::footer();
