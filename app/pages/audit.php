<?php
Ctx::requirePerm('audit.view');
$verify = null;
if (is_post() && input('action') === 'verify') {
    $verify = Audit::verify();
    Audit::log('audit.verify', null, null, 'Verified audit chain: ' . ($verify['ok'] ? 'intact' : 'broken at event #' . $verify['broken_at']));
}
$f = array('q' => trim((string)input('q', '')), 'user' => input_int('user'), 'action_f' => (string)input('action_f', ''), 'from' => (string)input('from', ''), 'to' => (string)input('to', ''), 'scope' => (string)input('scope', 'client'));
$where = array('a.organization_id = ?');
$p = array(Ctx::orgId());
if (Ctx::isSuper() && $f['scope'] === 'all') {
    $where = array('1=1');
    $p = array();
} elseif ($f['scope'] === 'client' && Ctx::clientId()) {
    $where[] = 'a.client_id = ?';
    $p[] = Ctx::clientId();
}
if ($f['q'] !== '') {
    $where[] = '(a.summary LIKE ? OR a.reason LIKE ?)';
    $p[] = '%' . $f['q'] . '%';
    $p[] = '%' . $f['q'] . '%';
}
if ($f['user']) {
    $where[] = 'a.user_id = ?';
    $p[] = $f['user'];
}
if ($f['action_f'] !== '') {
    $where[] = 'a.action LIKE ?';
    $p[] = $f['action_f'] . '%';
}
if ($f['from'] !== '') {
    $where[] = 'a.created_at >= ?';
    $p[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $where[] = 'a.created_at <= ?';
    $p[] = $f['to'] . ' 23:59:59';
}
$total = (int)DB::val('SELECT COUNT(*) FROM audit_events a WHERE ' . implode(' AND ', $where), $p);
$per = 100;
$page = max(1, input_int('page', 1));
$rows = DB::all('SELECT a.*, u.full_name FROM audit_events a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$users = array();
foreach (DB::all('SELECT u.id, u.full_name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? ORDER BY u.full_name', array(Ctx::orgId())) as $u) {
    $users[$u['id']] = $u['full_name'];
}
$families = array('auth' => 'Sign-in', 'record' => 'Records', 'import' => 'Imports', 'report' => 'File generation', 'download' => 'Downloads', 'validation' => 'Validation',
    'approval' => 'Approvals', 'period' => 'Period locks', 'certificate' => 'Certificates', 'filing' => 'Filing', 'spec' => 'Layouts', 'taxcode' => 'Tax codes', 'backup' => 'Backups', 'member' => 'Members');
View::header('Audit trail');
View::pageBar('Audit trail', plural($total, 'event'), '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="verify"><button class="btn">' . View::icon('check') . 'Verify chain</button></form>');
?>
<?php if ($verify) { ?><div class="notice <?= $verify['ok'] ? 'notice-success' : 'notice-error' ?>"><?= $verify['ok'] ? 'The audit chain is intact: ' . number_format($verify['checked']) . ' events checked and every hash matches.' : 'The chain is broken at event #' . (int)$verify['broken_at'] . '. History was altered or removed at that point.' ?></div><?php } ?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="audit">
    <div class="field"><label for="scope">Scope</label><?= View::select('scope', array_merge(array('client' => 'Active client', 'org' => 'Whole organization'), Ctx::isSuper() ? array('all' => 'All organizations') : array()), $f['scope'], 'id="scope"') ?></div>
    <div class="field"><label for="user">Person</label><?= View::select('user', $users, $f['user'], 'id="user"', 'Anyone') ?></div>
    <div class="field"><label for="action_f">Kind</label><?= View::select('action_f', $families, $f['action_f'], 'id="action_f"', 'All') ?></div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <div class="field grow"><label for="q">Text</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>"></div><button class="btn">Filter</button></form>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th><th>From</th></tr></thead><tbody>
  <?php foreach ($rows as $r) { ?>
    <tr><td data-label="When" class="nowrap"><?= e(fmt_dt($r['created_at'])) ?><span class="sub">#<?= (int)$r['id'] ?></span></td><td data-label="Who"><?= e($r['full_name'] ?: 'System') ?></td>
      <td data-label="What"><strong><?= e($r['summary']) ?></strong><span class="sub mono"><?= e($r['action']) ?><?= $r['subject_type'] ? ' ' . e($r['subject_type']) . ' #' . (int)$r['subject_id'] : '' ?></span><?= $r['reason'] ? '<span class="sub">Reason: ' . e($r['reason']) . '</span>' : '' ?></td>
      <td data-label="Detail" class="small"><?php if ($r['before_json'] || $r['after_json']) { ?><details><summary>Values</summary><?php if ($r['before_json']) { ?><div class="mono" style="white-space:pre-wrap;max-width:420px">Before: <?= e($r['before_json']) ?></div><?php } ?><?php if ($r['after_json']) { ?><div class="mono" style="white-space:pre-wrap;max-width:420px">After: <?= e(mb_substr($r['after_json'], 0, 1200)) ?></div><?php } ?></details><?php } ?></td>
      <td data-label="From" class="small muted"><?= e($r['ip']) ?></td></tr>
  <?php } ?></tbody></table></div>
  <?= View::pager($total, $page, $per, $f) ?>
</div>
<?php View::footer();
