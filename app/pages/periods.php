<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
if (is_post()) {
    try {
        if (input('action') === 'lock') {
            Ctx::requirePerm('periods.lock');
            $m = (string)input('month');
            if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
                throw new RuntimeException('Choose a month.');
            }
            $scope = in_array(input('scope'), array('all', 'sales', 'purchases', 'withholding', 'compensation'), true) ? input('scope') : 'all';
            Workflow::lock($client['id'], $m, $scope, trim((string)input('reason')) ?: 'Locked manually');
            flash('success', month_label($m) . ' is locked for ' . $scope . '.');
        } elseif (input('action') === 'unlock') {
            Ctx::requirePerm('approvals.request');
            $lock = DB::row('SELECT * FROM period_locks WHERE id = ? AND client_id = ? AND unlocked_at IS NULL', array(input_int('id'), $client['id']));
            if (!$lock) {
                throw new RuntimeException('Lock not found.');
            }
            $reason = trim((string)input('reason'));
            if ($reason === '') {
                throw new RuntimeException('Explain why the period must be reopened.');
            }
            Workflow::request('period_locks', $lock['id'], 'unlock_period', $reason, array('month' => $lock['period_month'], 'scope' => $lock['scope']));
            flash('success', Workflow::approvalsRequired() ? 'Unlock requested. A second person must approve it.' : month_label($lock['period_month']) . ' is unlocked.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('periods'));
}
$rows = DB::all('SELECT l.*, u.full_name AS locker, u2.full_name AS unlocker,
    (SELECT COUNT(*) FROM approval_requests a WHERE a.subject_type = \'period_locks\' AND a.subject_id = l.id AND a.decision = \'pending\') AS pending
    FROM period_locks l LEFT JOIN users u ON u.id = l.locked_by LEFT JOIN users u2 ON u2.id = l.unlocked_by WHERE l.client_id = ? ORDER BY l.period_month DESC, l.id DESC LIMIT 200', array($client['id']));
View::header('Period locks');
View::pageBar('Period locks', View::clientContext('locked months reject edits, imports and voids'));
?>
<div class="cols cols-main">
  <div class="sheet">
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr><th>Month</th><th>Scope</th><th>Reason</th><th>Locked</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr class="<?= $r['unlocked_at'] ? 'void' : '' ?>"><td data-label="Month"><strong><?= e(month_label($r['period_month'])) ?></strong></td><td data-label="Scope"><?= e(ucfirst($r['scope'])) ?></td>
          <td data-label="Reason"><?= e($r['reason']) ?></td><td data-label="Locked"><?= e($r['locker']) ?><span class="sub"><?= e(fmt_dt($r['locked_at'])) ?></span></td>
          <td data-label="State"><?= $r['unlocked_at'] ? '<span class="st st-muted">Unlocked by ' . e($r['unlocker']) . '</span>' : ($r['pending'] ? View::status('pending') : View::status('locked')) ?></td>
          <td class="actions"><?php if (!$r['unlocked_at'] && !$r['pending'] && Ctx::can('approvals.request')) { ?>
            <form method="post" data-prompt="Why must this period be reopened?"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm"><?= Workflow::approvalsRequired() ? 'Request unlock' : 'Unlock' ?></button></form>
          <?php } ?></td></tr>
      <?php } ?>
      <?php if (!$rows) { ?><tr><td colspan="6"><div class="empty"><h2>No locked periods</h2><p>Approving a file locks the months it covers. You can also lock a month by hand.</p></div></td></tr><?php } ?>
      </tbody></table></div>
  </div>
  <?php if (Ctx::can('periods.lock')) { ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="lock">
    <div class="sheet-head"><h2>Lock a month</h2></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field"><label for="month">Month</label><input id="month" name="month" type="month" required></div>
      <div class="field"><label for="scope">Records</label><?= View::select('scope', array('all' => 'All records', 'sales' => 'Sales', 'purchases' => 'Purchases and importations', 'withholding' => 'Withholding', 'compensation' => 'Compensation (use December)'), 'all', 'id="scope"') ?></div>
      <div class="field wide"><label for="reason">Reason</label><input id="reason" name="reason" type="text" maxlength="255" placeholder="Filed, closed books, under audit"></div>
    </div></div>
    <div class="sheet-foot"><button class="btn btn-primary">Lock month</button></div>
  </form>
  <?php } ?>
</div>
<?php View::footer();
