<?php
if (is_post()) {
    DB::q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', array(now(), Auth::id()));
    redirect(url('notifications'));
}
if ($open = input_int('open')) {
    $n = DB::row('SELECT * FROM notifications WHERE id = ? AND user_id = ?', array($open, Auth::id()));
    if ($n) {
        DB::update('notifications', array('read_at' => now()), 'id = ?', array($open));
        redirect($n['link'] ?: url('notifications'));
    }
}
$rows = DB::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', array(Auth::id()));
$pending = Ctx::can('approvals.decide') ? (int)DB::val('SELECT COUNT(*) FROM approval_requests WHERE organization_id = ? AND decision = ? AND requested_by <> ?', array(Ctx::orgId(), 'pending', Auth::id())) : 0;
View::header('Notifications');
View::pageBar('Notifications', 'Approvals, evidence reviews and decisions', '<form method="post">' . csrf_field() . '<button class="btn btn-quiet">Mark all read</button></form>');
if ($pending) {
    echo '<div class="notice notice-warning">' . plural($pending, 'approval request') . ' waiting. <a href="' . e(url('approvals')) . '">Open approvals</a></div>';
}
?>
<div class="sheet">
  <?php if (!$rows) { echo View::empty_state('No notifications', 'You will be notified when someone requests your approval or decides on your request.'); } else { ?>
  <ul class="queue">
    <?php foreach ($rows as $n) { ?>
      <li class="<?= $n['read_at'] ? '' : 'hl' ?>"><div class="q-main"><a href="<?= e(url('notifications', array('open' => $n['id']))) ?>"><?= e($n['title']) ?></a>
        <div class="q-sub"><?= e($n['body']) ?> <?= e(fmt_dt($n['created_at'])) ?></div></div></li>
    <?php } ?>
  </ul>
  <?php } ?>
</div>
<?php View::footer();
