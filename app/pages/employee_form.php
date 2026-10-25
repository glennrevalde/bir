<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$id = input_int('id');
$row = $id ? DB::row('SELECT * FROM employees WHERE id = ? AND client_id = ? AND deleted_at IS NULL', array($id, $client['id'])) : null;
if ($id && !$row) {
    deny('Employee not found for this client.');
}
$errors = array();
$values = $row ?: array('tin_branch' => '0000', 'nationality' => 'FILIPINO', 'employment_status' => 'R');
if (is_post()) {
    Ctx::requirePerm('records.edit');
    list($d, $errors) = Payroll::employeeValidate($_POST, $client['id'], $id ?: null);
    $values = array_merge($values, $_POST);
    if (!$errors) {
        $d['updated_by'] = Auth::id();
        $d['updated_at'] = now();
        if ($row) {
            DB::update('employees', $d, 'id = ? AND client_id = ?', array($id, $client['id']));
            Audit::log('employee.update', 'employees', $id, 'Edited employee ' . $d['last_name'], array_intersect_key($row, $d), $d);
        } else {
            $id = DB::insert('employees', array_merge($d, array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'status' => 'active', 'source' => 'manual',
                'created_by' => Auth::id(), 'created_at' => now())));
            Audit::log('employee.create', 'employees', $id, 'Added employee ' . $d['last_name']);
        }
        flash('success', 'Employee saved.');
        redirect(url('employee_form', array('id' => $id)));
    }
}
$v = function ($k, $def = '') use ($values) {
    return isset($values[$k]) && $values[$k] !== null ? $values[$k] : $def;
};
$err = function ($k) use ($errors) {
    return isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
};
$branches = array();
foreach (Ctx::branches() as $b) {
    $branches[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
$comps = $row ? DB::all('SELECT * FROM compensation_records WHERE employee_id = ? ORDER BY tax_year DESC', array($id)) : array();
$dis = Ctx::can('records.edit') ? '' : ' disabled';
View::header($row ? $row['last_name'] . ', ' . $row['first_name'] : 'Add employee', array('page' => 'employee_form', 'help' => 'employees'));
View::pageBar($row ? $row['last_name'] . ', ' . $row['first_name'] : 'Add employee', View::clientContext(), '<a class="btn btn-quiet" href="' . e(url('employees')) . '">Back to employees</a>');
?>
<div class="cols cols-main">
<form method="post" class="sheet"><?= csrf_field() ?>
  <div class="sheet-body"><div class="form-grid">
    <div class="field <?= isset($errors['tin']) ? 'has-err' : '' ?>"><label for="tin">TIN *</label><input id="tin" name="tin" type="text" value="<?= e($v('tin') ? Tin::display($v('tin')) : '') ?>"<?= $dis ?>><?= $err('tin') ?></div>
    <div class="field"><label for="tin_branch">TIN branch</label><input id="tin_branch" name="tin_branch" type="text" maxlength="5" value="<?= e($v('tin_branch', '0000')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="employee_no">Employee no.</label><input id="employee_no" name="employee_no" type="text" maxlength="30" value="<?= e($v('employee_no')) ?>"<?= $dis ?>></div>
    <div class="field <?= isset($errors['last_name']) ? 'has-err' : '' ?>"><label for="last_name">Last name *</label><input id="last_name" name="last_name" type="text" maxlength="60" value="<?= e($v('last_name')) ?>"<?= $dis ?>><?= $err('last_name') ?></div>
    <div class="field <?= isset($errors['first_name']) ? 'has-err' : '' ?>"><label for="first_name">First name *</label><input id="first_name" name="first_name" type="text" maxlength="60" value="<?= e($v('first_name')) ?>"<?= $dis ?>><?= $err('first_name') ?></div>
    <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" type="text" maxlength="60" value="<?= e($v('middle_name')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="branch_id">Branch</label><?= View::select('branch_id', $branches, $v('branch_id'), 'id="branch_id"' . $dis, 'Choose') ?></div>
    <div class="field"><label for="region_code">Region</label><?= View::select('region_code', array_combine(Payroll::regions(), Payroll::regions()), $v('region_code'), 'id="region_code"' . $dis, 'Choose') ?></div>
    <div class="field"><label for="employment_status">Employment status</label><?= View::select('employment_status', Payroll::statusOptions(), $v('employment_status', 'R'), 'id="employment_status"' . $dis) ?></div>
    <div class="field"><label for="birth_date">Date of birth</label><input id="birth_date" name="birth_date" type="date" value="<?= e($v('birth_date')) ?>"<?= $dis ?>><?= $err('birth_date') ?></div>
    <div class="field"><label for="hired_on">Hired on</label><input id="hired_on" name="hired_on" type="date" value="<?= e($v('hired_on')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="separated_on">Separated on</label><input id="separated_on" name="separated_on" type="date" value="<?= e($v('separated_on')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="separation_reason">Reason of separation</label><?= View::select('separation_reason', Payroll::separationOptions(), $v('separation_reason'), 'id="separation_reason"' . $dis, 'Not separated') ?></div>
    <div class="field"><label for="nationality">Nationality</label><input id="nationality" name="nationality" type="text" value="<?= e($v('nationality', 'FILIPINO')) ?>"<?= $dis ?>></div>
    <div class="field half"><label for="address">Registered address</label><input id="address" name="address" type="text" maxlength="200" value="<?= e($v('address')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="zip_code">ZIP code</label><input id="zip_code" name="zip_code" type="text" maxlength="6" value="<?= e($v('zip_code')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="contact_no">Contact number</label><input id="contact_no" name="contact_no" type="text" maxlength="40" value="<?= e($v('contact_no')) ?>"<?= $dis ?>></div>
    <div class="field wide"><label class="check"><input type="checkbox" name="is_mwe" value="1" <?= $v('is_mwe') ? 'checked' : '' ?><?= $dis ?>> Minimum wage earner (reported in 1604-C Schedule 2)</label></div>
  </div></div>
  <?php if (Ctx::can('records.edit')) { ?><div class="sheet-foot"><button class="btn btn-primary">Save employee</button></div><?php } ?>
</form>
<div>
  <?php if ($row) { ?>
  <div class="sheet"><div class="sheet-head"><h2>Compensation</h2><?php if (Ctx::can('records.edit')) { ?><a class="btn btn-sm" href="<?= e(url('comp_form', array('employee_id' => $id))) ?>">Add year</a><?php } ?></div>
    <ul class="queue"><?php foreach ($comps as $c) { ?>
      <li class="<?= $c['status'] === 'void' ? 'muted' : '' ?>"><div class="q-main"><a href="<?= e(url('comp_form', array('id' => $c['id']))) ?>">Tax year <?= (int)$c['tax_year'] ?>, <?= $c['schedule_code'] === 'S2' ? 'Schedule 2' : 'Schedule 1' ?></a>
        <div class="q-sub">Gross <?= Money::fmtDb($c['pres_gross']) ?>, withheld <?= Money::fmtDb($c['tax_withheld_adjusted']) ?> <?= View::status($c['status']) ?></div></div></li>
    <?php } ?><?php if (!$comps) { ?><li class="muted">No compensation records yet.</li><?php } ?></ul></div>
  <?php } ?>
</div>
</div>
<?php View::footer();
