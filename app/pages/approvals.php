<?php
Ctx::requirePerm('records.view');
if (is_post()) {
    try {
        Workflow::decide(input_int('id'), input('decision') === 'approve', trim((string)input('note')));
        flash('success', input('decision') === 'approve' ? 'Approved and carried out.' : 'Rejected.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('approvals'));
}
$acts = Workflow::actions();
$rows = DB::all('SELECT a.*, u.full_name AS requester, d.full_name AS decider, c.registered_name, c.last_name, c.first_name FROM approval_requests a
    LEFT JOIN users u ON u.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by LEFT JOIN client_entities c ON c.id = a.client_id
    WHERE a.organization_id = ? ORDER BY a.decision = \'pending\' DESC, a.id DESC LIMIT 200', array(Ctx::orgId()));
$allowed = array();
foreach (Ctx::clients() as $c) {
    $allowed[(int)$c['id']] = true;
}
$link = function ($r) {
    switch ($r['subject_type']) {
        case 'dat_files':
            $f = DB::row('SELECT filename FROM dat_files WHERE id = ?', array($r['subject_id']));
            return '<a class="mono" href="' . e(url('file_view', array('id' => $r['subject_id']))) . '">' . e($f ? $f['filename'] : 'File #' . $r['subject_id']) . '</a>';
        case 'tax_codes':
            $t = DB::row('SELECT atc_code, rate, effective_from FROM tax_codes WHERE id = ?', array($r['subject_id']));
            return '<a href="' . e(url('atc', array('q' => $t ? $t['atc_code'] : ''))) . '">' . e($t ? $t['atc_code'] . ' ' . Money::rateFormat(Money::rateUnits($t['rate']), 'pct2') . '% from ' . fmt_date($t['effective_from']) : 'Tax code') . '</a>';
        case 'layout_specs':
            return '<a href="' . e(url('spec_view', array('id' => $r['subject_id']))) . '">Layout #' . (int)$r['subject_id'] . '</a>';
        case 'period_locks':
            $l = DB::row('SELECT period_month, scope FROM period_locks WHERE id = ?', array($r['subject_id']));
            return $l ? e(month_label($l['period_month']) . ', ' . $l['scope']) : 'Lock #' . (int)$r['subject_id'];
        case 'certificates':
            $c = DB::row('SELECT cert_no FROM certificates WHERE id = ?', array($r['subject_id']));
            return '<a href="' . e(url('cert_view', array('id' => $r['subject_id']))) . '">' . e($c ? $c['cert_no'] : 'Certificate') . '</a>';
    }
    return e($r['subject_type'] . ' #' . $r['subject_id']);
};
View::header('Approvals');
View::pageBar('Approvals', Workflow::approvalsRequired() ? 'Maker-checker: requests need someone other than the requester' : 'A record of every request; each is approved as soon as it is made');
?>
<?php if (!Workflow::approvalsRequired()) { ?><div class="notice notice-info">Approval by a second person is turned off. Unlocking a period, voiding a certificate and saving a tax code or layout happen at once and are listed here for the record. A file is approved as soon as its BIR validator report shows no errors.<?= Ctx::can('system.manage') ? ' To require a second person again, turn it on under <a href="' . e(url('system')) . '">Platform settings</a>.' : '' ?></div><?php } ?>
<div class="sheet">
  <?php if (!$rows) { echo View::empty_state('No approval requests', 'Requests to approve files, unlock periods, change tax codes or layouts, and void certificates appear here.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Request</th><th>Subject</th><th>Client</th><th>Requested</th><th>Reason</th><th>Decision</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r) {
      if ($r['client_id'] && !isset($allowed[(int)$r['client_id']])) { continue; }
      $perm = in_array($r['action'], array('approve_tax_code', 'approve_spec'), true) ? 'reference.approve' : 'approvals.decide';
      $canDecide = $r['decision'] === 'pending' && Ctx::can($perm) && (int)$r['requested_by'] !== Auth::id(); ?>
    <tr class="<?= $r['decision'] === 'pending' ? ($canDecide ? 'hl' : '') : '' ?>">
      <td data-label="Request"><strong><?= e($acts[$r['action']]) ?></strong><span class="sub">#<?= (int)$r['id'] ?></span></td>
      <td data-label="Subject"><?= $link($r) ?></td>
      <td data-label="Client"><?= e($r['client_id'] ? ($r['registered_name'] ?: $r['last_name'] . ', ' . $r['first_name']) : 'Organization') ?></td>
      <td data-label="Requested"><?= e($r['requester']) ?><span class="sub"><?= e(fmt_dt($r['requested_at'])) ?></span></td>
      <td data-label="Reason" class="small"><?= e($r['reason']) ?></td>
      <td data-label="Decision"><?= View::status($r['decision']) ?><?= $r['decider'] ? '<span class="sub">' . e($r['decider']) . ', ' . e(fmt_dt($r['decided_at'])) . ($r['decision_note'] ? ': ' . e($r['decision_note']) : '') . '</span>' : '' ?></td>
      <td class="actions"><?php if ($canDecide) { ?>
        <form method="post" class="btn-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="text" name="note" placeholder="Note" aria-label="Decision note" style="width:140px;min-height:30px">
          <button class="btn btn-sm btn-primary" name="decision" value="approve" data-confirm="Approve and carry out this request?">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
      <?php } elseif ($r['decision'] === 'pending' && (int)$r['requested_by'] === Auth::id()) { ?><span class="small muted">Waiting for someone else</span><?php } ?></td></tr>
  <?php } ?></tbody></table></div>
  <?php } ?>
</div>
<?php View::footer();
