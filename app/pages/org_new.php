<?php
$error = null;
if (is_post()) {
    $name = trim((string)input('name'));
    if ($name === '') {
        $error = 'Enter a name.';
    } else {
        $id = DB::tx(function () use ($name) {
            $id = DB::insert('organizations', array('name' => $name, 'org_type' => input('org_type') === 'company' ? 'company' : 'firm', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()));
            DB::insert('memberships', array('user_id' => Auth::id(), 'organization_id' => $id, 'role_code' => 'firm_owner', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
            return $id;
        });
        Ctx::switchOrg($id);
        Audit::log('org.create', 'organizations', $id, 'Created organization ' . $name);
        flash('success', 'Organization created. Add your first client next.');
        redirect(url('client_form'));
    }
}
View::header('New organization');
View::pageBar('New organization', 'A separate workspace with its own clients and members');
?>
<div class="sheet" style="max-width:640px">
  <form method="post"><?= csrf_field() ?>
    <div class="sheet-body">
      <?php if ($error) { ?><div class="notice notice-error"><?= e($error) ?></div><?php } ?>
      <?php if (!Ctx::memberships() && !Ctx::isSuper()) { ?><p>You are not a member of any organization yet. Create one, or ask an administrator to invite you.</p><?php } ?>
      <div class="form-grid">
        <div class="field wide"><label for="name">Name</label><input id="name" name="name" type="text" required value="<?= e(input('name', '')) ?>"></div>
        <div class="field wide"><label for="org_type">Type</label><?= View::select('org_type', array('firm' => 'Accounting firm serving several clients', 'company' => 'One company and its branches'), input('org_type', 'firm'), 'id="org_type"') ?></div>
      </div>
    </div>
    <div class="sheet-foot"><button class="btn btn-primary" type="submit">Create organization</button></div>
  </form>
</div>
<?php View::footer();
