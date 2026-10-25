<?php
Ctx::requirePerm('imports.run');
$client = Ctx::requireClient();
$types = Imports::types();

/** Resolve the uploaded file from a normal upload or a completed chunked session. */
function gsb_take_upload($dir)
{
    $tok = (string)input('upload_token');
    if ($tok !== '') {
        $s = DB::row('SELECT * FROM upload_sessions WHERE token = ? AND user_id = ? AND status = ?', array($tok, Auth::id(), 'complete'));
        if (!$s) {
            throw new RuntimeException('The uploaded file was not found. Upload it again.');
        }
        $ext = strtolower(pathinfo($s['original_filename'], PATHINFO_EXTENSION));
        $src = storage_path($s['temp_path']);
        $sig = file_get_contents($src, false, null, 0, 4);
        if ($ext === 'xlsx' && substr($sig, 0, 2) !== 'PK') {
            throw new RuntimeException('The file content is not a valid XLSX workbook.');
        }
        $rel = $dir . '/' . date('Y/m') . '/' . random_token(12) . '.' . $ext;
        if (!is_dir(dirname(storage_path($rel)))) {
            mkdir(dirname(storage_path($rel)), 0775, true);
        }
        rename($src, storage_path($rel));
        DB::update('upload_sessions', array('status' => 'used', 'updated_at' => now()), 'id = ?', array($s['id']));
        return array('path' => $rel, 'name' => $s['original_filename'], 'size' => filesize(storage_path($rel)), 'sha256' => hash_file('sha256', storage_path($rel)), 'ext' => $ext);
    }
    return store_upload(isset($_FILES['file']) ? $_FILES['file'] : null, $dir, array('xlsx', 'csv', 'xls'));
}

if (is_post()) {
    try {
        $type = (string)input('import_type');
        if (!isset($types[$type])) {
            throw new RuntimeException('Choose what the file contains.');
        }
        $branch = Ctx::branchAllowed(input_int('branch_id'));
        if (!$branch) {
            throw new RuntimeException('Choose a branch.');
        }
        $f = gsb_take_upload('uploads');
        if (!in_array($f['ext'], array('xlsx', 'csv'), true)) {
            throw new RuntimeException('Only .xlsx and .csv files can be imported.');
        }
        $dup = DB::row('SELECT id, status FROM import_jobs WHERE client_id = ? AND file_sha256 = ? AND import_type = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1',
            array($client['id'], $f['sha256'], $type, 'committed', 'staged'));
        $jobId = DB::insert('import_jobs', array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => $branch['id'], 'import_type' => $type,
            'original_filename' => mb_substr($f['name'], 0, 200), 'stored_path' => $f['path'], 'file_sha256' => $f['sha256'], 'status' => 'uploaded',
            'header_row' => 1, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()));
        Audit::log('import.upload', 'import_jobs', $jobId, 'Uploaded ' . $f['name'] . ' (' . $types[$type]['label'] . ')');
        if ($dup) {
            flash('warning', 'This exact file was imported before (import #' . $dup['id'] . ', ' . $dup['status'] . '). Identical rows will be skipped as duplicates.');
        }
        redirect(url('import_map', array('id' => $jobId)));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect(url('imports', array('type' => input('import_type'))));
    }
}
$jobs = DB::all('SELECT j.*, u.full_name FROM import_jobs j LEFT JOIN users u ON u.id = j.created_by WHERE j.client_id = ? ORDER BY j.id DESC LIMIT 50', array($client['id']));
$ho = Ctx::headOffice();
$typeOpts = array();
foreach ($types as $k => $t) {
    $typeOpts[$k] = $t['label'];
}
View::header('Import a spreadsheet');
View::pageBar('Import a spreadsheet', View::clientContext('Excel or CSV into staging, then into saved records'),
    '<a class="btn" href="' . e(url('templates')) . '">' . View::icon('sheet') . 'Template library</a>');
?>
<div class="cols cols-main">
  <div class="sheet">
    <div class="sheet-head"><h2>Recent imports</h2></div>
    <?php if (!$jobs) { echo View::empty_state('No imports yet', 'Upload a G-SMaRT template or any spreadsheet; you can map its columns in the next step.'); } else { ?>
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr><th>File</th><th>Type</th><th class="num">Rows</th><th class="num">Errors</th><th>Status</th><th>By</th><th></th></tr></thead>
      <tbody><?php foreach ($jobs as $j) { ?>
        <tr><td data-label="File"><strong><?= e($j['original_filename']) ?></strong><span class="sub">#<?= (int)$j['id'] ?>, <?= e(fmt_dt($j['created_at'])) ?></span></td>
          <td data-label="Type"><?= e(isset($types[$j['import_type']]) ? $types[$j['import_type']]['label'] : $j['import_type']) ?></td>
          <td data-label="Rows" class="num"><?= (int)$j['total_rows'] ?></td><td data-label="Errors" class="num"><?= (int)$j['error_rows'] ?></td>
          <td data-label="Status"><?= View::status($j['status']) ?></td><td data-label="By"><?= e($j['full_name']) ?></td>
          <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url($j['status'] === 'uploaded' ? 'import_map' : 'import_review', array('id' => $j['id']))) ?>">Open</a></td></tr>
      <?php } ?></tbody></table></div>
    <?php } ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="sheet" data-chunked="<?= e(url('upload_chunk')) ?>"><?= csrf_field() ?>
    <div class="sheet-head"><div><h2>Upload</h2><p>Nothing is saved to your records until you review and commit.</p></div></div>
    <div class="sheet-body stack">
      <div class="field"><label for="import_type">The file contains</label><?= View::select('import_type', $typeOpts, input('type', 'withholding'), 'id="import_type" required') ?></div>
      <div class="field"><label for="branch_id">Default branch</label><select id="branch_id" name="branch_id"><?php foreach (Ctx::branches() as $b) { ?><option value="<?= (int)$b['id'] ?>" <?= $ho && (int)$ho['id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['branch_code'] . ' ' . $b['name']) ?></option><?php } ?></select>
        <span class="hint">Used when a row has no CLIENT BRANCH CODE.</span></div>
      <div class="field"><label for="file">Spreadsheet (.xlsx or .csv, up to <?= (int)sys_config('max_upload_mb', 20) ?> MB)</label><input id="file" name="file" type="file" accept=".xlsx,.csv" required></div>
      <div class="progress" aria-hidden="true"><i></i></div><p class="small muted" data-upload-status>Large files upload in pieces and resume if the connection drops.</p>
    </div>
    <div class="sheet-foot"><button class="btn btn-primary" type="submit"><?= View::icon('upload') ?>Upload and map columns</button></div>
  </form>
</div>
<?php View::footer();
