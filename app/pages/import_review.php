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
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Row</th><th>Messages</th><?php foreach (array_slice($type['columns'], 0, 9) as $c) { ?><th><?= e($c['title']) ?></th><?php } ?></tr></thead>
    <tbody><?php foreach ($rows as $r) { $raw = json_decode($r['raw_json'], true); $errs = json_decode((string)$r['errors_json'], true) ?: array(); $warns = json_decode((string)$r['warnings_json'], true) ?: array(); ?>
      <tr class="<?= $r['status'] === 'error' ? 'row-error' : ($r['status'] === 'duplicate' ? 'row-dup' : '') ?>">
        <td class="nowrap"><?= (int)$r['row_no'] ?></td>
        <td class="small" style="min-width:260px"><?php foreach ($errs as $f => $m) { ?><div class="st st-bad" style="white-space:normal"><?= e((isset($labels[$f]) ? $labels[$f] . ': ' : '') . $m) ?></div><?php } ?>
          <?php foreach ($warns as $f => $m) { ?><div class="muted"><?= e($m) ?></div><?php } ?></td>
        <?php foreach (array_slice($type['columns'], 0, 9) as $c) { ?><td class="small"><?= e(isset($raw[$c['key']]) ? $raw[$c['key']] : '') ?></td><?php } ?>
      </tr>
    <?php } ?></tbody></table></div>
  <?= View::pager($cnt, $page, $per, array('id' => $job['id'], 'show' => $show)) ?>
  <?php } ?>
</div>
<?php } ?>
<?php View::footer();
