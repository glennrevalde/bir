<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$rows = DB::all('SELECT fr.*, d.filename, d.form_code, d.schedule_code, d.period_label, u.full_name FROM filing_records fr JOIN dat_files d ON d.id = fr.dat_file_id
    LEFT JOIN users u ON u.id = fr.recorded_by WHERE fr.client_id = ? ORDER BY fr.filed_at DESC, fr.id DESC LIMIT 200', array($client['id']));
$ready = DB::all('SELECT id, filename, period_label FROM dat_files WHERE client_id = ? AND status = ? ORDER BY period_to DESC', array($client['id'], 'approved'));
View::header('Filing records');
View::pageBar('Filing records', View::clientContext('submission evidence you recorded'));
?>
<?php if ($ready) { ?><div class="notice notice-warning"><div><strong><?= plural(count($ready), 'approved file') ?> not yet recorded as submitted:</strong>
  <?php foreach ($ready as $i => $r) { ?><?= $i ? ', ' : ' ' ?><a href="<?= e(url('file_view', array('id' => $r['id']))) ?>"><?= e($r['filename']) ?></a><?php } ?></div></div><?php } ?>
<div class="sheet">
  <?php if (!$rows) { echo View::empty_state('No submissions recorded', 'After a file is approved, open it and record how and when it was submitted, with the BIR acknowledgment.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>File</th><th>Form</th><th>Period</th><th>Channel</th><th>Date</th><th>Reference</th><th>Acknowledgment</th><th>Recorded by</th></tr></thead><tbody>
  <?php foreach ($rows as $r) { ?><tr><td data-label="File"><a class="mono" href="<?= e(url('file_view', array('id' => $r['dat_file_id']))) ?>"><?= e($r['filename']) ?></a></td>
    <td data-label="Form"><?= e($r['form_code'] . ' ' . $r['schedule_code']) ?></td><td data-label="Period"><?= e($r['period_label']) ?></td><td data-label="Channel"><?= e($r['channel']) ?></td>
    <td data-label="Date"><?= e(fmt_date($r['filed_at'])) ?></td><td data-label="Reference"><?= e($r['reference_no']) ?></td>
    <td data-label="Acknowledgment"><?= $r['ack_path'] ? '<a href="' . e(url('file_download', array('id' => $r['dat_file_id'], 'ack' => $r['id']))) . '">' . e($r['ack_filename']) . '</a>' : '<span class="muted">None attached</span>' ?></td>
    <td data-label="Recorded by"><?= e($r['full_name']) ?></td></tr><?php } ?>
  </tbody></table></div>
  <?php } ?>
</div>
<?php View::footer();
