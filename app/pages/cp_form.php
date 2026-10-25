<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$id = input_int('id');
$row = $id ? DB::row('SELECT * FROM counterparties WHERE id = ? AND client_id = ? AND deleted_at IS NULL', array($id, $client['id'])) : null;
if ($id && !$row) {
    deny('Entry not found for this client.');
}
$errors = array();
$values = $row ?: array('entity_type' => 'non_individual', 'branch_code' => '0000', 'is_' . input('role', 'supplier') => 1);
if (is_post()) {
    Ctx::requirePerm('records.edit');
    if (input('action') === 'delete') {
        $used = 0;
        foreach (array('sales_entries', 'purchase_entries', 'importation_entries', 'withholding_entries') as $t) {
            $used += (int)DB::val('SELECT COUNT(*) FROM ' . $t . ' WHERE counterparty_id = ?', array($id));
        }
        if ($used) {
            flash('error', 'This entry is used by ' . $used . ' records and cannot be removed. Mark it inactive instead.');
        } else {
            DB::update('counterparties', array('deleted_at' => now(), 'updated_by' => Auth::id()), 'id = ?', array($id));
            Audit::log('directory.delete', 'counterparties', $id, 'Removed ' . party_name($row));
            flash('success', 'Entry removed.');
            redirect(url('directory'));
        }
        redirect(url('cp_form', array('id' => $id)));
    }
    list($d, $errors) = Records::counterpartyValidate($_POST, $client['id'], $id ?: null);
    $d['status'] = input('status') === 'inactive' ? 'inactive' : 'active';
    $values = array_merge($values, $_POST);
    if (!$errors) {
        $d['updated_by'] = Auth::id();
        $d['updated_at'] = now();
        if ($row) {
            DB::update('counterparties', $d, 'id = ? AND client_id = ?', array($id, $client['id']));
            Audit::log('directory.update', 'counterparties', $id, 'Edited ' . party_name($d), array_intersect_key($row, $d), $d);
        } else {
            $d = array_merge($d, array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'source' => 'manual', 'created_by' => Auth::id(), 'created_at' => now()));
            $id = DB::insert('counterparties', $d);
            Audit::log('directory.create', 'counterparties', $id, 'Added ' . party_name($d), null, $d);
        }
        flash('success', 'Saved ' . party_name($d) . '.');
        redirect(url('cp_form', array('id' => $id)));
    }
}
$v = function ($k, $def = '') use ($values) {
    return isset($values[$k]) && $values[$k] !== null ? $values[$k] : $def;
};
$err = function ($k) use ($errors) {
    return isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
};
$canEdit = Ctx::can('records.edit');
$dis = $canEdit ? '' : ' disabled';
View::header($row ? party_name($row) : 'Add directory entry', array('page' => 'cp_form', 'help' => 'directory'));
View::pageBar($row ? party_name($row) : 'Add directory entry', View::clientContext($row && $row['tin'] ? Tin::display($row['tin'], $row['branch_code']) : ''),
    '<a class="btn btn-quiet" href="' . e(url('directory')) . '">Back to directory</a>');
?>
<form method="post" class="sheet" style="max-width:980px"><?= csrf_field() ?>
  <div class="sheet-body">
    <?php if ($errors) { ?><div class="notice notice-error">Fix the highlighted fields.</div><?php } ?>
    <div class="form-grid">
      <div class="field"><label for="entity_type">Type</label><?= View::select('entity_type', array('non_individual' => 'Non-individual (corporation, partnership, cooperative)', 'individual' => 'Individual'), $v('entity_type'), 'id="entity_type"' . $dis) ?></div>
      <div class="field <?= isset($errors['tin']) ? 'has-err' : '' ?>"><label for="tin">TIN</label><input id="tin" name="tin" type="text" inputmode="numeric" value="<?= e($v('tin') ? Tin::display($v('tin')) : '') ?>" placeholder="123-456-789"<?= $dis ?>><span class="hint">9 digits. Leave blank only when the party has no TIN.</span><?= $err('tin') ?></div>
      <div class="field <?= isset($errors['branch_code']) ? 'has-err' : '' ?>"><label for="branch_code">Branch code</label><input id="branch_code" name="branch_code" type="text" inputmode="numeric" maxlength="5" value="<?= e($v('branch_code', '0000')) ?>"<?= $dis ?>><span class="hint">0000 for head office. Alphalists use 4 digits.</span><?= $err('branch_code') ?></div>
      <div class="field half <?= isset($errors['registered_name']) ? 'has-err' : '' ?>"><label for="registered_name">Registered name (non-individual)</label><input id="registered_name" name="registered_name" type="text" maxlength="150" value="<?= e($v('registered_name')) ?>"<?= $dis ?>><?= $err('registered_name') ?></div>
      <div class="field <?= isset($errors['last_name']) ? 'has-err' : '' ?>"><label for="last_name">Last name (individual)</label><input id="last_name" name="last_name" type="text" maxlength="60" value="<?= e($v('last_name')) ?>"<?= $dis ?>><?= $err('last_name') ?></div>
      <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" maxlength="60" value="<?= e($v('first_name')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" type="text" maxlength="60" value="<?= e($v('middle_name')) ?>"<?= $dis ?>></div>
      <div class="field half"><label for="address1">Address line 1</label><input id="address1" name="address1" type="text" maxlength="150" value="<?= e($v('address1')) ?>" placeholder="Unit, street, barangay"<?= $dis ?>></div>
      <div class="field"><label for="address2">Address line 2</label><input id="address2" name="address2" type="text" maxlength="150" value="<?= e($v('address2')) ?>" placeholder="Town or city, province"<?= $dis ?>></div>
      <div class="field <?= isset($errors['zip_code']) ? 'has-err' : '' ?>"><label for="zip_code">ZIP code</label><input id="zip_code" name="zip_code" type="text" maxlength="6" value="<?= e($v('zip_code')) ?>"<?= $dis ?>><?= $err('zip_code') ?></div>
      <div class="field half"><label for="foreign_address">Foreign address, if any</label><input id="foreign_address" name="foreign_address" type="text" maxlength="200" value="<?= e($v('foreign_address')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e($v('email')) ?>"<?= $dis ?>><?= $err('email') ?></div>
      <div class="field"><label for="default_atc">Default ATC</label><input id="default_atc" name="default_atc" type="text" maxlength="10" value="<?= e($v('default_atc')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="status">Status</label><?= View::select('status', array('active' => 'Active', 'inactive' => 'Inactive'), $v('status', 'active'), 'id="status"' . $dis) ?></div>
      <div class="field wide"><label>Roles</label><div class="btn-row">
        <?php foreach (array('supplier' => 'Supplier', 'customer' => 'Customer', 'payee' => 'Payee (we withhold from them)', 'payor' => 'Payor (they withhold from us)') as $k => $l) { ?>
          <label class="check"><input type="checkbox" name="is_<?= $k ?>" value="1" <?= $v('is_' . $k) ? 'checked' : '' ?><?= $dis ?>> <?= e($l) ?></label>
        <?php } ?></div></div>
      <div class="field wide"><label for="notes">Notes</label><input id="notes" name="notes" type="text" maxlength="255" value="<?= e($v('notes')) ?>"<?= $dis ?>></div>
    </div>
  </div>
  <?php if ($canEdit) { ?>
  <div class="sheet-foot">
    <?php if ($row) { ?><button class="btn btn-danger" name="action" value="delete" data-confirm="Remove this directory entry? Entries used by records cannot be removed.">Remove</button><?php } ?>
    <button class="btn btn-primary" type="submit" name="action" value="save">Save entry</button>
  </div>
  <?php } ?>
</form>
<?php View::footer();
