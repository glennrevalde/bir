<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$id = input_int('id');
$row = $id ? DB::row('SELECT * FROM compensation_records WHERE id = ? AND client_id = ?', array($id, $client['id'])) : null;
if ($id && !$row) {
    deny('Record not found for this client.');
}
$errors = array();
$warnings = array();
$values = $row ?: array('tax_year' => input_int('year', (int)date('Y') - 1), 'employee_id' => input_int('employee_id'), 'schedule_code' => 'S1', 'nationality' => 'FILIPINO',
    'employment_status' => 'R', 'separation_reason' => 'NA', 'substituted_filing' => 'Y');
if (!$row && !empty($values['employee_id'])) {
    $emp = DB::row('SELECT * FROM employees WHERE id = ? AND client_id = ?', array($values['employee_id'], $client['id']));
    if ($emp) {
        $values['branch_id'] = $emp['branch_id'];
        $values['region_code'] = $emp['region_code'];
        $values['schedule_code'] = $emp['is_mwe'] ? 'S2' : 'S1';
        $values['employment_from'] = $values['tax_year'] . '-01-01';
        $values['employment_to'] = $values['tax_year'] . '-12-31';
    }
}
if (is_post()) {
    Ctx::requirePerm('records.edit');
    try {
        if (in_array(input('action'), array('void', 'restore'), true)) {
            Ctx::requirePerm('records.void');
            $reason = trim((string)input('reason'));
            if ($reason === '') {
                throw new RuntimeException('Give a reason.');
            }
            if (Records::lockedPeriod($client['id'], $row['tax_year'] . '-12', 'compensation')) {
                throw new RuntimeException('Compensation for ' . $row['tax_year'] . ' is locked.');
            }
            Records::revision('compensation_records', $row, $reason);
            DB::update('compensation_records', input('action') === 'void'
                ? array('status' => 'void', 'void_reason' => $reason, 'voided_at' => now(), 'updated_by' => Auth::id(), 'updated_at' => now())
                : array('status' => 'posted', 'void_reason' => null, 'voided_at' => null, 'updated_by' => Auth::id(), 'updated_at' => now()), 'id = ?', array($id));
            Audit::log('record.' . input('action'), 'compensation_records', $id, ucfirst(input('action')) . ' compensation record', null, null, $reason);
            flash('success', 'Done.');
            redirect(url('comp_form', array('id' => $id)));
        }
        list($d, $errors, $warnings) = Payroll::compValidate($_POST, $client['id']);
        $values = array_merge($values, $_POST);
        if (!$errors) {
            $nid = Payroll::compSave($d, $id ?: null, trim((string)input('edit_reason')) ?: null);
            flash($warnings ? 'warning' : 'success', 'Compensation record saved.' . ($warnings ? ' Check: ' . implode(' ', array_slice($warnings, 0, 3)) : ''));
            redirect(url('comp_form', array('id' => $nid)));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        $values = array_merge($values, $_POST);
    }
}
$emps = DB::all('SELECT id, last_name, first_name, middle_name, tin FROM employees WHERE client_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name', array($client['id']));
$empOpts = array();
foreach ($emps as $e) {
    $empOpts[$e['id']] = $e['last_name'] . ', ' . $e['first_name'] . ' (' . Tin::display($e['tin']) . ')';
}
$branches = array();
foreach (Ctx::branches() as $b) {
    $branches[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
$v = function ($k, $def = '') use ($values) {
    return isset($values[$k]) && $values[$k] !== null ? $values[$k] : $def;
};
$locked = $row ? Records::lockedPeriod($client['id'], $row['tax_year'] . '-12', 'compensation') : null;
$can = Ctx::can('records.edit') && !$locked && (!$row || $row['status'] !== 'void');
$dis = $can ? '' : ' disabled';
$arith = $row ? Checks::compArithmetic($row) : array();
View::header($row ? 'Compensation record' : 'Add compensation record', array('page' => 'comp_form', 'help' => 'compensation'));
View::pageBar($row ? 'Compensation record' : 'Add compensation record', View::clientContext($row ? 'tax year ' . (int)$row['tax_year'] : ''),
    '<a class="btn btn-quiet" href="' . e(url('compensation', array('year' => $v('tax_year')))) . '">Back to compensation</a>');
if ($locked) {
    echo '<div class="notice notice-warning">' . View::icon('lock') . ' This tax year is locked: ' . e($locked['reason']) . '</div>';
}
if ($arith) {
    echo '<div class="notice notice-error"><div><strong>Totals do not add up.</strong><ul style="margin:6px 0 0 18px;padding:0">';
    foreach ($arith as $m) {
        echo '<li>' . e($m) . '</li>';
    }
    echo '</ul></div></div>';
}
?>
<form method="post" class="sheet" data-autosave="comp-<?= (int)$id ?>-<?= (int)$client['id'] ?>" data-fresh="<?= $row || is_post() ? '0' : '1' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="sheet-body">
    <?php if ($errors) { ?><div class="notice notice-error"><?= e(implode(' ', array_slice($errors, 0, 4))) ?></div><?php } ?>
    <fieldset><legend>Employee and period</legend><div class="form-grid">
      <div class="field half"><label for="employee_id">Employee *</label><?= View::select('employee_id', $empOpts, $v('employee_id'), 'id="employee_id"' . $dis, 'Choose an employee') ?><span class="hint"><a href="<?= e(url('employee_form')) ?>">Add an employee</a></span></div>
      <div class="field"><label for="tax_year">Tax year *</label><input id="tax_year" name="tax_year" type="number" min="2000" max="2100" value="<?= e($v('tax_year')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="schedule_code">Schedule</label><?= View::select('schedule_code', array('S1' => 'Schedule 1: other than MWE', 'S2' => 'Schedule 2: minimum wage earner'), $v('schedule_code', 'S1'), 'id="schedule_code"' . $dis) ?></div>
      <div class="field"><label for="branch_id">Branch *</label><?= View::select('branch_id', $branches, $v('branch_id'), 'id="branch_id"' . $dis, 'Choose') ?></div>
      <div class="field"><label for="region_code">Region *</label><?= View::select('region_code', array_combine(Payroll::regions(), Payroll::regions()), $v('region_code'), 'id="region_code"' . $dis, 'Choose') ?></div>
      <div class="field"><label for="employment_from">Employed from *</label><input id="employment_from" name="employment_from" type="date" value="<?= e($v('employment_from')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="employment_to">Employed to *</label><input id="employment_to" name="employment_to" type="date" value="<?= e($v('employment_to')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="employment_status">Employment status</label><?= View::select('employment_status', Payroll::statusOptions(), $v('employment_status', 'R'), 'id="employment_status"' . $dis) ?></div>
      <div class="field"><label for="separation_reason">Reason of separation</label><?= View::select('separation_reason', Payroll::separationOptions(), $v('separation_reason', 'NA'), 'id="separation_reason"' . $dis) ?></div>
      <div class="field"><label for="nationality">Nationality</label><input id="nationality" name="nationality" type="text" value="<?= e($v('nationality', 'FILIPINO')) ?>"<?= $dis ?>></div>
      <div class="field"><label for="substituted_filing">Substituted filing</label><?= View::select('substituted_filing', array('Y' => 'Yes', 'N' => 'No'), $v('substituted_filing', 'Y'), 'id="substituted_filing"' . $dis) ?></div>
      <div class="field"><label for="factor_days">Factor used (days per year)</label><input id="factor_days" name="factor_days" type="number" value="<?= e($v('factor_days')) ?>"<?= $dis ?>></div>
      <div class="field wide"><label class="check"><input type="checkbox" name="has_previous_employer" value="1" <?= $v('has_previous_employer') ? 'checked' : '' ?><?= $dis ?>> This employee had a previous employer within the year</label></div>
    </div></fieldset>
    <?php foreach (Records::compFields() as $group => $fields) { ?>
      <fieldset><legend><?= e($group) ?></legend><div class="form-grid">
        <?php foreach ($fields as $k => $label) { $isTotal = in_array($k, array('prev_nt_total', 'prev_tx_total', 'pres_nt_total', 'pres_tx_total', 'prev_gross', 'pres_gross', 'taxable_comp_total', 'tax_withheld_adjusted'), true); ?>
          <div class="field <?= isset($errors[$k]) ? 'has-err' : '' ?>"><label for="c-<?= $k ?>"><?= e($label) ?><?= $isTotal ? ' (total)' : '' ?></label>
            <input id="c-<?= $k ?>" name="<?= $k ?>" type="text" inputmode="decimal" class="num" value="<?= e($v($k, '0.00')) ?>"<?= $dis ?>></div>
        <?php } ?>
      </div></fieldset>
    <?php } ?>
    <?php if ($row && $can) { ?><div class="field"><label for="edit_reason">Reason for this change</label><input id="edit_reason" name="edit_reason" type="text" maxlength="255"></div><?php } ?>
  </div>
  <?php if ($can) { ?>
  <div class="sheet-foot">
    <button type="button" class="btn" data-comp-compute>Compute totals</button>
    <button class="btn btn-primary" type="submit">Save record</button>
  </div>
  <?php } ?>
</form>
<?php if ($row && Ctx::can('records.void') && !$locked) { ?>
  <form method="post" data-prompt="Reason" class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $row['status'] === 'void' ? 'restore' : 'void' ?>">
    <button class="btn <?= $row['status'] === 'void' ? '' : 'btn-danger' ?>"><?= $row['status'] === 'void' ? 'Restore record' : 'Void record' ?></button></form>
<?php } ?>
<?php View::footer();
