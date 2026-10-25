<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$cid = (int)$client['id'];
$groups = array(
    'structure_failed' => array('Structure problems', 'The file did not parse back cleanly or totals disagree. Fix the data or layout and regenerate.'),
    'pending_bir' => array('Waiting for official validation', 'Download each file, run the BIR validator, and upload the report.'),
    'bir_failed' => array('Failed official validation', 'The BIR validator reported errors. Read the line numbers, correct and regenerate.'),
    'review' => array('Evidence to review', 'A second person must accept or reject the uploaded validator report.'),
    'ready' => array('Ready for approval', 'Officially validated with accepted evidence.'),
);
$data = array();
foreach (array('structure_failed', 'pending_bir', 'bir_failed') as $st) {
    $data[$st] = DB::all('SELECT * FROM dat_files WHERE client_id = ? AND status = ? ORDER BY period_to DESC, id DESC LIMIT 100', array($cid, $st));
}
$data['review'] = DB::all('SELECT d.*, e.id AS ev_id, e.uploaded_by FROM dat_files d JOIN official_validation_evidence e ON e.dat_file_id = d.id WHERE d.client_id = ? AND e.reviewed_at IS NULL
    AND d.status IN (?, ?) ORDER BY e.id DESC', array($cid, 'bir_validated', 'bir_failed'));
$data['ready'] = DB::all('SELECT d.* FROM dat_files d WHERE d.client_id = ? AND d.status = ? AND EXISTS (SELECT 1 FROM official_validation_evidence e WHERE e.dat_file_id = d.id
    AND e.outcome = ? AND e.review_decision = ?) ORDER BY d.period_to DESC', array($cid, 'bir_validated', 'success', 'accepted'));
$counts = DB::row('SELECT SUM(status = \'approved\') AS approved, SUM(status = \'submitted\') AS submitted, SUM(status = \'bir_validated\') AS validated,
    SUM(file_label = \'unvalidated_draft\' AND status NOT IN (\'superseded\', \'approved\', \'submitted\', \'bir_validated\')) AS drafts FROM dat_files WHERE client_id = ?', array($cid));
View::header('Validation center');
View::pageBar('Validation center', View::clientContext('three levels, tracked separately'));
?>
<div class="sheet"><div class="figures">
  <div><strong><?= count($data['pending_bir']) ?></strong><span>Waiting for BIR validation</span></div>
  <div><strong><?= (int)$counts['validated'] ?></strong><span>Officially validated</span></div>
  <div><strong><?= (int)$counts['approved'] ?></strong><span>Approved</span></div>
  <div><strong><?= (int)$counts['submitted'] ?></strong><span>Submitted</span></div>
  <div><strong><?= (int)$counts['drafts'] ?></strong><span>Unvalidated drafts</span></div>
</div></div>
<div class="cols cols-2">
<?php foreach ($groups as $k => $g) { ?>
  <div class="sheet">
    <div class="sheet-head"><div><h2><?= e($g[0]) ?> (<?= count($data[$k]) ?>)</h2><p><?= e($g[1]) ?></p></div></div>
    <?php if (!$data[$k]) { ?><div class="sheet-body muted small">Nothing here.</div><?php } else { ?>
      <ul class="queue"><?php foreach (array_slice($data[$k], 0, 12) as $f) { ?>
        <li><div class="q-main"><a class="mono" href="<?= e(url('file_view', array('id' => $f['id']))) ?>"><?= e($f['filename']) ?></a>
          <div class="q-sub"><?= e($f['form_code'] . ' ' . $f['schedule_code'] . ', ' . $f['period_label']) ?> <?= View::status($f['file_label']) ?></div></div></li>
      <?php } ?></ul>
    <?php } ?>
  </div>
<?php } ?>
  <div class="sheet"><div class="sheet-head"><h2>How validation works here</h2></div><div class="sheet-body prose small">
    <p><strong>Level 1, data quality</strong> checks the rows before writing: TINs, ATCs and their approval, arithmetic, names.</p>
    <p><strong>Level 2, structure</strong> parses the written bytes independently and compares field counts, widths, control totals and the filename.</p>
    <p><strong>Level 3, official</strong> is only the BIR validator's own report on the exact file. The system never marks a file officially validated on its own checks.</p>
    <p><a href="<?= e(url('manual', array('s' => 'guide_validator'))) ?>">Validator step-by-step guide</a></p>
  </div></div>
</div>
<?php View::footer();
