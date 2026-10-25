<?php
Ctx::requirePerm('reference.view');
$tab = input('tab') === 'deadlines' ? 'deadlines' : 'rdo';
if (is_post()) {
    Ctx::requirePerm('reference.manage');
    try {
        if (input('action') === 'rdo') {
            $code = rdo_normalize(input('code'));
            $name = trim((string)input('name'));
            if (!$code || $name === '') {
                throw new RuntimeException('Enter the RDO code (081 or 21B) and its name.');
            }
            DB::q('INSERT INTO rdo_codes (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', array($code, mb_substr($name, 0, 120)));
            Audit::log('reference.rdo', 'rdo_codes', null, 'Saved RDO ' . $code . ' ' . $name);
        } elseif (input('action') === 'rdo_import') {
            $f = store_upload(isset($_FILES['csv']) ? $_FILES['csv'] : null, 'uploads', array('csv'));
            $n = 0;
            foreach (CsvReader::rows(storage_path($f['path'])) as $row) {
                $code = rdo_normalize(isset($row[0]) ? $row[0]['v'] : '');
                $name = trim(isset($row[1]) ? $row[1]['v'] : '');
                if ($code && $name !== '') {
                    DB::q('INSERT INTO rdo_codes (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', array($code, mb_substr($name, 0, 120)));
                    $n++;
                }
            }
            @unlink(storage_path($f['path']));
            Audit::log('reference.rdo_import', 'rdo_codes', null, 'Imported ' . $n . ' RDO codes');
            flash('success', 'Imported ' . $n . ' RDO codes.');
            redirect(url('reference'));
        } elseif (input('action') === 'rdo_delete') {
            DB::q('DELETE FROM rdo_codes WHERE code = ?', array((string)input('code')));
        } elseif (input('action') === 'deadline') {
            $form = (string)input('form_code');
            if (!DB::val('SELECT code FROM form_types WHERE code = ?', array($form)) || trim((string)input('rule_text')) === '' || trim((string)input('source_ref')) === '') {
                throw new RuntimeException('Choose a form, describe the rule and cite its BIR source.');
            }
            $id = input_int('id');
            $d = array('form_code' => $form, 'rule_text' => mb_substr(trim(input('rule_text')), 0, 200), 'months_after_period' => max(0, min(12, input_int('months_after_period', 1))),
                'due_day' => max(1, min(31, input_int('due_day', 31))), 'source_ref' => mb_substr(trim(input('source_ref')), 0, 255), 'is_active' => input('is_active') ? 1 : 0);
            if ($id) {
                DB::update('deadline_rules', $d, 'id = ?', array($id));
            } else {
                $d['created_at'] = now();
                $id = DB::insert('deadline_rules', $d);
            }
            Audit::log('reference.deadline', 'deadline_rules', $id, 'Saved deadline rule for ' . $form);
        }
        flash('success', 'Saved.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('reference', array('tab' => $tab)));
}
$forms = array();
foreach (DB::all('SELECT code, name FROM form_types ORDER BY sort_order') as $f) {
    $forms[$f['code']] = $f['code'] . ': ' . $f['name'];
}
$can = Ctx::can('reference.manage');
View::header('RDO codes and deadlines', array('help' => 'reference'));
View::pageBar('RDO codes and deadlines', 'Reference lists maintained by your administrators');
?>
<div class="btn-row" style="margin-bottom:14px"><a class="btn btn-sm <?= $tab === 'rdo' ? 'btn-primary' : 'btn-quiet' ?>" href="<?= e(url('reference')) ?>">RDO codes</a><a class="btn btn-sm <?= $tab === 'deadlines' ? 'btn-primary' : 'btn-quiet' ?>" href="<?= e(url('reference', array('tab' => 'deadlines'))) ?>">Filing deadline rules</a></div>
<?php if ($tab === 'rdo') { $rows = DB::all('SELECT * FROM rdo_codes ORDER BY code'); ?>
<div class="cols cols-main">
  <div class="sheet"><div class="sheet-head"><div><h2><?= plural(count($rows), 'RDO code') ?></h2><p>Suggested in client and branch forms. Installed from a published secondary list (checked 17 October 2026); confirm against the BIR website and edit or re-import as BIR changes offices. Client and branch fields accept any valid code, listed or not.</p></div></div>
    <?php if (!$rows) { echo View::empty_state('No RDO codes loaded', 'Import the current list from the BIR website as a two-column CSV (code, name), or add codes one at a time.'); } else { ?>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Code</th><th>Name</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r) { ?><tr><td data-label="Code"><strong><?= e($r['code']) ?></strong></td><td data-label="Name"><?= e($r['name']) ?></td><td class="actions"><?php if ($can) { ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rdo_delete"><input type="hidden" name="code" value="<?= e($r['code']) ?>"><button class="btn btn-sm btn-quiet">Remove</button></form><?php } ?></td></tr><?php } ?>
    </tbody></table></div><?php } ?></div>
  <?php if ($can) { ?><div>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="rdo"><div class="sheet-head"><h2>Add or rename</h2></div>
      <div class="sheet-body stack"><div class="field"><label for="code">Code</label><input id="code" name="code" type="text" maxlength="4" required></div><div class="field"><label for="name">Name</label><input id="name" name="name" type="text" required></div></div>
      <div class="sheet-foot"><button class="btn btn-primary">Save</button></div></form>
    <form method="post" enctype="multipart/form-data" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="rdo_import"><div class="sheet-head"><h2>Import CSV</h2></div>
      <div class="sheet-body"><div class="field"><label for="csv">Two columns: code, name</label><input id="csv" name="csv" type="file" accept=".csv" required></div></div>
      <div class="sheet-foot"><button class="btn">Import</button></div></form>
  </div><?php } ?>
</div>
<?php } else { $rows = DB::all('SELECT * FROM deadline_rules ORDER BY form_code, id'); $ed = input_int('edit') ? DB::row('SELECT * FROM deadline_rules WHERE id = ?', array(input_int('edit'))) : null; ?>
<div class="cols cols-main">
  <div class="sheet"><div class="sheet-head"><div><h2>Deadline rules</h2><p>Shown on the dashboard. Add only rules you can cite from a current BIR issuance; the system never guesses due dates.</p></div></div>
    <?php if (!$rows) { echo View::empty_state('No deadline rules', 'Add a rule per form with its BIR source.'); } else { ?>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Form</th><th>Rule</th><th>Due</th><th>Source</th><th>Active</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r) { ?><tr class="<?= $r['is_active'] ? '' : 'void' ?>"><td data-label="Form"><strong><?= e($r['form_code']) ?></strong></td><td data-label="Rule"><?= e($r['rule_text']) ?></td>
      <td data-label="Due">Day <?= (int)$r['due_day'] ?>, <?= (int)$r['months_after_period'] ?> month(s) after the period</td><td data-label="Source" class="small"><?= e($r['source_ref']) ?></td>
      <td data-label="Active"><?= $r['is_active'] ? 'Yes' : 'No' ?></td><td class="actions"><?php if ($can) { ?><a class="btn btn-sm btn-quiet" href="<?= e(url('reference', array('tab' => 'deadlines', 'edit' => $r['id']))) ?>">Edit</a><?php } ?></td></tr><?php } ?>
    </tbody></table></div><?php } ?></div>
  <?php if ($can) { ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="deadline"><input type="hidden" name="id" value="<?= $ed ? (int)$ed['id'] : 0 ?>">
    <div class="sheet-head"><h2><?= $ed ? 'Edit rule' : 'Add a rule' ?></h2></div>
    <div class="sheet-body stack">
      <div class="field"><label for="form_code">Form</label><?= View::select('form_code', $forms, $ed ? $ed['form_code'] : '', 'id="form_code"') ?></div>
      <div class="field"><label for="rule_text">Rule</label><input id="rule_text" name="rule_text" type="text" maxlength="200" value="<?= e($ed ? $ed['rule_text'] : '') ?>" required></div>
      <div class="field"><label for="months_after_period">Months after the period</label><input id="months_after_period" name="months_after_period" type="number" min="0" max="12" value="<?= $ed ? (int)$ed['months_after_period'] : 1 ?>"></div>
      <div class="field"><label for="due_day">Due day of that month</label><input id="due_day" name="due_day" type="number" min="1" max="31" value="<?= $ed ? (int)$ed['due_day'] : 31 ?>"></div>
      <div class="field"><label for="source_ref">BIR source</label><input id="source_ref" name="source_ref" type="text" maxlength="255" value="<?= e($ed ? $ed['source_ref'] : '') ?>" required></div>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= !$ed || $ed['is_active'] ? 'checked' : '' ?>> Active</label>
    </div>
    <div class="sheet-foot"><button class="btn btn-primary">Save rule</button></div>
  </form>
  <?php } ?>
</div>
<?php } ?>
<?php View::footer();
