<?php
if (is_post()) {
    $id = input_int('org_id');
    if (Ctx::switchOrg($id)) {
        flash('success', 'Switched organization.');
        redirect(url('dashboard'));
    }
    flash('error', 'That organization is not available to you.');
}
$rows = Ctx::isSuper()
    ? DB::all('SELECT o.*, (SELECT COUNT(*) FROM client_entities c WHERE c.organization_id = o.id AND c.deleted_at IS NULL) AS clients FROM organizations o ORDER BY o.name')
    : DB::all('SELECT o.*, m.role_code, (SELECT COUNT(*) FROM client_entities c WHERE c.organization_id = o.id AND c.deleted_at IS NULL) AS clients FROM organizations o
        JOIN memberships m ON m.organization_id = o.id WHERE m.user_id = ? AND m.status = ? ORDER BY o.name', array(Auth::id(), 'active'));
View::header('Organizations');
View::pageBar('Organizations', 'Choose the workspace to work in', '<a class="btn" href="' . e(url('org_new')) . '">' . View::icon('plus') . 'New organization</a>');
?>
<?php if (!$rows) { ?><div class="sheet"><?= View::empty_state('No organizations yet', 'Create one for your accounting firm or your company. Clients, members and records all live inside an organization.', '<a class="btn btn-primary" href="' . e(url('org_new')) . '">New organization</a>') ?></div><?php View::footer(); return; } ?>
<div class="sheet"><div class="table-wrap"><table class="table table-stack">
  <thead><tr><th>Name</th><th>Type</th><th class="num">Clients</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $o) { ?>
    <tr<?= (int)$o['id'] === Ctx::orgId() ? ' class="hl"' : '' ?>><td data-label="Name"><strong><?= e($o['name']) ?></strong></td><td data-label="Type"><?= $o['org_type'] === 'firm' ? 'Accounting firm' : 'Company' ?></td>
      <td data-label="Clients" class="num"><?= (int)$o['clients'] ?></td>
      <td class="actions"><?php if ((int)$o['id'] !== Ctx::orgId()) { ?><form method="post"><?= csrf_field() ?><input type="hidden" name="org_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm">Switch</button></form><?php } else { ?><span class="st st-ok">Current</span><?php } ?></td></tr>
  <?php } ?>
  </tbody></table></div></div>
<?php View::footer();
