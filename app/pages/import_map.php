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
    $job['sheet_name'] = in_array('Data', $sheets, true) ? 'Data' : $sheets[0];
}
$job['header_row'] = max(1, input_int('header_row', (int)$job['header_row']));
try {
    $preview = Imports::readRows($job, $job['header_row'] + 8);
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect(url('imports'));
}
$headers = Imports::headersOf($preview, $job['header_row']);
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
    $problems = Imports::mappingProblems($type, $map);
    if (!$problems) {
        $opts = array('date_format' => input('date_format') === 'dmy' ? 'dmy' : 'auto');
        DB::update('import_jobs', array('sheet_name' => $job['sheet_name'], 'header_row' => $job['header_row'], 'mapping_json' => json_encode($map),
            'options_json' => json_encode($opts), 'template_version' => $exact ? Imports::TEMPLATE_VERSION : null, 'updated_at' => now()), 'id = ?', array($job['id']));
        if (trim((string)input('save_as')) !== '') {
            DB::insert('column_mappings', array('organization_id' => Ctx::orgId(), 'import_type' => $job['import_type'], 'name' => mb_substr(trim(input('save_as')), 0, 100),
                'mapping_json' => json_encode($map), 'created_by' => Auth::id(), 'created_at' => now()));
        }
        $n = Imports::prepare($job['id']);
        Audit::log('import.map', 'import_jobs', $job['id'], 'Mapped columns; ' . $n . ' rows read');
        redirect(url('import_review', array('id' => $job['id'])));
    }
    $suggest = $map;
}
$colOpts = array();
foreach ($headers as $i => $h) {
    $colOpts[$i] = XlsxWriter::colLetter($i) . ': ' . $h;
}
View::header('Map columns', array('page' => 'import_map'));
View::pageBar('Map columns', View::clientContext(e($job['original_filename']) . ', ' . e($type['label'])), '<a class="btn btn-quiet" href="' . e(url('imports')) . '">Back to imports</a>');
?>
<?php if ($exact) { ?><div class="notice notice-success">This file matches the <?= e($type['code']) ?> template. All columns are mapped; check the preview and continue.</div><?php } ?>
<?php foreach ($problems as $p) { ?><div class="notice notice-error"><?= e($p) ?></div><?php } ?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="import_map"><input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
    <?php if ($sheets) { ?><div class="field"><label for="sheet">Sheet</label><?= View::select('sheet', array_combine($sheets, $sheets), $job['sheet_name'], 'id="sheet"') ?></div><?php } ?>
    <div class="field"><label for="header_row">Header row</label><input id="header_row" name="header_row" type="number" min="1" max="50" value="<?= (int)$job['header_row'] ?>"></div>
    <?php if ($saved) { $sm = array(); foreach ($saved as $m) { $sm[$m['id']] = $m['name']; } ?><div class="field"><label for="mapping">Saved mapping</label><?= View::select('mapping', $sm, input_int('mapping'), 'id="mapping"', 'None') ?></div><?php } ?>
    <button class="btn">Reload preview</button>
  </form>
  <div class="sheet-head"><h2>Preview</h2><span class="small muted">First rows below the header</span></div>
  <div class="table-wrap"><table class="table"><thead><tr><?php foreach ($headers as $i => $h) { ?><th><?= e(XlsxWriter::colLetter($i) . ': ' . $h) ?></th><?php } ?></tr></thead><tbody>
    <?php foreach ($preview as $rn => $cells) { if ($rn <= $job['header_row']) { continue; } ?>
      <tr><?php foreach ($headers as $i => $h) { $c = isset($cells[$i]) ? $cells[$i] : array('v' => '', 'num' => false, 'date' => null); ?><td class="small<?= $c['num'] ? ' num' : '' ?>"><?= e($c['date'] ?: $c['v']) ?></td><?php } ?></tr>
    <?php } ?>
  </tbody></table></div>
  <?php if (!$headers) { ?><div class="empty"><h2>No header found on row <?= (int)$job['header_row'] ?></h2><p>Set the row number that holds the column titles.</p></div><?php } ?>
</div>
<?php if ($headers) { ?>
<form method="post" class="sheet"><?= csrf_field() ?>
  <input type="hidden" name="sheet" value="<?= e($job['sheet_name']) ?>"><input type="hidden" name="header_row" value="<?= (int)$job['header_row'] ?>">
  <div class="sheet-head"><div><h2>Fields</h2><p>Pick the source column for each field. Required fields are marked. Each column feeds one field.</p></div></div>
  <div class="sheet-body"><div class="form-grid">
    <?php foreach ($type['columns'] as $c) { $k = $c['key']; ?>
      <div class="field"><label for="m-<?= e($k) ?>"><?= e($c['title']) ?><?= !empty($c['required']) ? ' *' : '' ?></label>
        <?= View::select('map[' . $k . ']', $colOpts, isset($suggest[$k]) && $suggest[$k] !== null ? $suggest[$k] : '', 'id="m-' . e($k) . '"', 'Not in this file') ?>
        <?php if (!empty($c['help'])) { ?><span class="hint"><?= e($c['help']) ?></span><?php } ?></div>
    <?php } ?>
  </div></div>
  <div class="sheet-foot" style="justify-content:space-between">
    <div class="inline-form"><div class="field"><label for="date_format">Dates like 03/04/2026 mean</label><?= View::select('date_format', array('auto' => 'Month first (MM/DD/YYYY)', 'dmy' => 'Day first (DD/MM/YYYY)'), 'auto', 'id="date_format"') ?></div>
      <div class="field"><label for="save_as">Save this mapping as</label><input id="save_as" name="save_as" type="text" maxlength="100" placeholder="Optional name"></div></div>
    <button class="btn btn-primary" type="submit">Stage and check rows</button>
  </div>
</form>
<?php } ?>
<?php View::footer();
