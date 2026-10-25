<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$f = array('form' => (string)input('form', ''), 'status' => (string)input('status', ''), 'year' => input_int('year'), 'q' => trim((string)input('q', '')), 'origin' => (string)input('origin', ''));
$where = array('d.client_id = ?');
$p = array($client['id']);
if ($f['form'] !== '') {
    $where[] = 'd.form_code = ?';
    $p[] = $f['form'];
}
if ($f['status'] === 'active') {
    $where[] = 'd.status <> ?';
    $p[] = 'superseded';
} elseif ($f['status'] !== '') {
    $where[] = 'd.status = ?';
    $p[] = $f['status'];
} else {
    $where[] = 'd.status <> ?';
    $p[] = 'superseded';
}
if ($f['year']) {
    $where[] = 'YEAR(d.period_to) = ?';
    $p[] = $f['year'];
}
if ($f['origin'] !== '') {
    $where[] = 'd.origin = ?';
    $p[] = $f['origin'];
}
if ($f['q'] !== '') {
    $where[] = '(d.filename LIKE ? OR d.period_label LIKE ?)';
    $p[] = '%' . $f['q'] . '%';
    $p[] = '%' . $f['q'] . '%';
}
$total = (int)DB::val('SELECT COUNT(*) FROM dat_files d WHERE ' . implode(' AND ', $where), $p);
$per = 50;
$page = max(1, input_int('page', 1));
$rows = DB::all('SELECT d.*, b.branch_code, u.full_name, s.status AS spec_status FROM dat_files d LEFT JOIN branches b ON b.id = d.branch_id LEFT JOIN users u ON u.id = d.created_by
    LEFT JOIN layout_specs s ON s.id = d.spec_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.period_to DESC, d.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$forms = array();
foreach (DB::all('SELECT code, name FROM form_types ORDER BY sort_order') as $fo) {
    $forms[$fo['code']] = $fo['code'] . ': ' . $fo['name'];
}
$statuses = array('active' => 'Current files', 'pending_bir' => 'Pending BIR validation', 'bir_validated' => 'Officially validated', 'bir_failed' => 'Failed BIR validation',
    'structure_failed' => 'Structure failed', 'approved' => 'Approved', 'submitted' => 'Submitted', 'superseded' => 'Superseded');
View::header('File library');
View::pageBar('File library', View::clientContext(plural($total, 'file')),
    (Ctx::can('reports.generate') ? '<a class="btn btn-primary" href="' . e(url('generate')) . '">' . View::icon('file') . 'Generate a file</a>' : '')
    . (Ctx::can('dat.upload') ? '<a class="btn" href="' . e(url('dat_upload')) . '">' . View::icon('upload') . 'Upload a DAT</a>' : ''));
?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="files">
    <div class="field"><label for="form">Form</label><?= View::select('form', $forms, $f['form'], 'id="form"', 'All forms') ?></div>
    <div class="field"><label for="status">Status</label><?= View::select('status', $statuses, $f['status'], 'id="status"', 'Current files') ?></div>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= $f['year'] ?: '' ?>" placeholder="Any"></div>
    <div class="field"><label for="origin">Origin</label><?= View::select('origin', array('generated' => 'Generated here', 'uploaded' => 'Uploaded'), $f['origin'], 'id="origin"', 'Any') ?></div>
    <div class="field grow"><label for="q">Filename or period</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>"></div>
    <button class="btn">Filter</button>
  </form>
  <?php if (!$rows) { echo View::empty_state('No files yet', 'Generate a file from saved records, or upload DAT files made elsewhere.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>File</th><th>Form</th><th>Period</th><th>Branch</th><th class="num">Records</th><th class="num">Tax</th><th>Status</th><th>By</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r) { ?>
      <tr class="<?= $r['status'] === 'superseded' ? 'void' : '' ?>">
        <td data-label="File"><strong class="mono"><?= e($r['filename']) ?></strong><span class="sub">#<?= (int)$r['id'] ?><?= $r['origin'] === 'uploaded' ? ', uploaded' : '' ?><?= $r['amended'] ? ', amended' : '' ?></span></td>
        <td data-label="Form"><?= e($r['form_code'] . ' ' . $r['schedule_code']) ?><?= $r['variant_key'] ? '<span class="sub">' . e($r['variant_key']) . '</span>' : '' ?></td>
        <td data-label="Period"><?= e($r['period_label']) ?></td><td data-label="Branch"><?= e($r['branch_code'] ?: 'All') ?></td>
        <td data-label="Records" class="num"><?= (int)$r['record_count'] ?></td><td data-label="Tax" class="num"><?= Money::fmtDb($r['total_tax']) ?></td>
        <td data-label="Status"><?= Reports::fileStatusLine($r) ?></td><td data-label="By"><?= e($r['full_name']) ?><span class="sub"><?= e(fmt_dt($r['created_at'])) ?></span></td>
        <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('file_view', array('id' => $r['id']))) ?>">Open</a></td></tr>
    <?php } ?></tbody></table></div>
  <?= View::pager($total, $page, $per, $f) ?>
  <?php } ?>
</div>
<?php View::footer();
