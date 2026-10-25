<?php
Ctx::requirePerm('certificates.issue');
$client = Ctx::requireClient();
$form = in_array(input('form'), array('2307', '2306', '2316'), true) ? input('form') : '2307';
$year = input_int('year', (int)date('Y'));
$q = max(1, min(4, input_int('q', max(1, (int)ceil(((int)date('n') - 1) / 3)))));
$ho = Ctx::headOffice();
$branch = Ctx::branchAllowed(input_int('branch_id', $ho ? (int)$ho['id'] : 0));
if (!$branch) {
    deny('Choose a branch you can access.');
}
if ($form === '2316') {
    $from = $year . '-01-01';
    $to = $year . '-12-31';
} else {
    $qm = quarter_months($year, $q);
    $from = $qm[0] . '-01';
    $to = last_day($qm[2]);
}
$signatories = DB::all('SELECT * FROM signatories WHERE client_id = ? AND status = ? ORDER BY is_default DESC, full_name', array($client['id'], 'active'));
$issued = array();
if (is_post()) {
    $ids = array_map('intval', (array)input('ids', array()));
    $opts = array('signatory_id' => input_int('signatory_id') ?: null, 'use_signature' => input('use_signature') ? 1 : 0, 'paper' => input('paper', 'A4'),
        'offset_x' => (float)input('offset_x', 0), 'offset_y' => (float)input('offset_y', 0), 'branch_id' => $branch['id']);
    $errors = array();
    foreach ($ids as $pid) {
        try {
            if ($form === '2316') {
                $payload = Certificates::build2316($client, $branch, $pid);
            } else {
                $exists = DB::val('SELECT cert_no FROM certificates WHERE client_id = ? AND form_code = ? AND counterparty_id = ? AND period_from = ? AND status = ?', array($client['id'], $form, $pid, $from, 'issued'));
                if ($exists && !input('allow_dup')) {
                    throw new RuntimeException('already has ' . $exists . ' for this period (reissue it instead)');
                }
                $payload = Certificates::buildWithholding($form, $client, $branch, $pid, $from, $to);
            }
            $issued[] = Certificates::issue($payload, $opts);
        } catch (RuntimeException $e) {
            $errors[] = '#' . $pid . ' ' . $e->getMessage();
        }
    }
    if ($issued) {
        flash('success', 'Issued ' . plural(count($issued), 'certificate') . '.');
    }
    if ($errors) {
        flash('warning', 'Skipped: ' . implode('; ', array_slice($errors, 0, 5)));
    }
}
$cands = Certificates::candidates($form, $branch['id'], $from, $to);
$branchOpts = array();
foreach (Ctx::branches() as $b) {
    $branchOpts[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
$sigOpts = array();
foreach ($signatories as $s) {
    $sigOpts[$s['id']] = $s['full_name'] . ($s['title'] ? ', ' . $s['title'] : '') . ($s['signature_approved_at'] ? ' (signature image approved)' : '');
}
View::header('Issue certificates');
View::pageBar('Issue certificates', View::clientContext('from saved records'), '<a class="btn btn-quiet" href="' . e(url('certificates')) . '">Register</a>');
?>
<form method="get" class="sheet"><input type="hidden" name="p" value="cert_issue">
  <div class="filters">
    <div class="field"><label for="form">Form</label><?= View::select('form', array('2307' => '2307: creditable tax withheld', '2306' => '2306: final tax withheld', '2316' => '2316: compensation'), $form, 'id="form"') ?></div>
    <div class="field"><label for="branch_id">Branch (payor)</label><?= View::select('branch_id', $branchOpts, $branch['id'], 'id="branch_id"') ?></div>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= (int)$year ?>"></div>
    <?php if ($form !== '2316') { ?><div class="field"><label for="q">Quarter</label><?= View::select('q', array(1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'), $q, 'id="q"') ?></div><?php } ?>
    <button class="btn">Load</button></div>
</form>
<?php if ($issued) { ?>
  <div class="sheet"><div class="sheet-head"><h2>Just issued</h2></div><ul class="queue">
    <?php foreach ($issued as $cid) { $c = DB::row('SELECT cert_no, id FROM certificates WHERE id = ?', array($cid)); ?><li><div class="q-main"><a href="<?= e(url('cert_view', array('id' => $cid))) ?>"><?= e($c['cert_no']) ?></a></div></li><?php } ?>
  </ul>
  <form method="post" action="<?= e(url('certificates')) ?>" class="sheet-foot"><?= csrf_field() ?><?php foreach ($issued as $cid) { ?><input type="hidden" name="ids[]" value="<?= (int)$cid ?>"><?php } ?>
    <button class="btn" name="action" value="combined">One combined PDF</button><button class="btn btn-primary" name="action" value="zip">ZIP of individual PDFs</button></form></div>
<?php } ?>
<form method="post" class="sheet"><?= csrf_field() ?>
  <div class="sheet-head"><div><h2><?= plural(count($cands), $form === '2316' ? 'employee' : 'payee') ?> with records for <?= $form === '2316' ? (int)$year : 'Q' . $q . ' ' . (int)$year ?></h2>
    <p><?= $form === '2316' ? 'From posted compensation records of this branch.' : 'From posted withholding of this branch where the client is the agent.' ?></p></div></div>
  <?php if (!$cands) { echo View::empty_state('Nothing to certify', 'Record withholding or compensation for this period first.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th><input type="checkbox" data-check-all="ids[]" checked aria-label="Select all"></th><th>Name</th><th>TIN</th><th class="num">Income</th><th class="num">Tax</th><th>Existing</th></tr></thead><tbody>
  <?php foreach ($cands as $r) { $ex = $form !== '2316' ? DB::val('SELECT cert_no FROM certificates WHERE client_id = ? AND form_code = ? AND counterparty_id = ? AND period_from = ? AND status = ?', array($client['id'], $form, $r['party_id'], $from, 'issued')) : DB::val('SELECT cert_no FROM certificates WHERE client_id = ? AND form_code = ? AND employee_id = ? AND period_from = ? AND status = ?', array($client['id'], '2316', $r['party_id'], DB::val('SELECT employment_from FROM compensation_records WHERE id = ?', array($r['ref_id'])), 'issued')); ?>
    <tr><td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$r['ref_id'] ?>" <?= $ex ? '' : 'checked' ?> aria-label="Select"></td><td data-label="Name"><?= e(party_name($r)) ?></td><td data-label="TIN"><?= e(Tin::display($r['tin'], isset($r['branch_code']) ? $r['branch_code'] : null)) ?></td>
      <td data-label="Income" class="num"><?= Money::fmtDb($r['income']) ?></td><td data-label="Tax" class="num"><?= Money::fmtDb($r['tax']) ?></td><td data-label="Existing"><?= $ex ? e($ex) : '<span class="muted">None</span>' ?></td></tr>
  <?php } ?></tbody></table></div>
  <div class="sheet-body"><div class="form-grid">
    <div class="field"><label for="signatory_id">Signatory</label><?= View::select('signatory_id', $sigOpts, $signatories ? $signatories[0]['id'] : '', 'id="signatory_id"', 'No signatory') ?><span class="hint"><a href="<?= e(url('client_form', array('id' => $client['id'], 'tab' => 'signatories'))) ?>">Manage signatories</a></span></div>
    <div class="field"><label for="paper">Paper</label><?= View::select('paper', array('A4' => 'A4', 'LETTER' => 'Letter (8.5 x 11 in)', 'FOLIO' => 'Long / folio (8.5 x 13 in)'), 'A4', 'id="paper"') ?></div>
    <div class="field"><label for="offset_x">Signature offset, right (points)</label><input id="offset_x" name="offset_x" type="number" step="1" value="0"></div>
    <div class="field"><label for="offset_y">Signature offset, down (points)</label><input id="offset_y" name="offset_y" type="number" step="1" value="0"></div>
    <div class="field wide"><label class="check"><input type="checkbox" name="use_signature" value="1"> Apply the signatory's approved signature image (authorized signatories only)</label>
      <label class="check"><input type="checkbox" name="allow_dup" value="1"> Issue even if a certificate already exists for the period</label></div>
  </div></div>
  <div class="sheet-foot"><button class="btn btn-primary"><?= View::icon('stamp') ?>Issue selected</button></div>
  <?php } ?>
</form>
<?php View::footer();
