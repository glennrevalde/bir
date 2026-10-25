<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$key = (string)input('m', 'sales');
$mods = Records::modules();
if (!isset($mods[$key])) {
    deny('Unknown record type.');
}
$mod = Records::module($key);
$id = input_int('id');
$row = $id ? DB::row('SELECT * FROM ' . $mod['table'] . ' WHERE id = ? AND client_id = ?', array($id, $client['id'])) : null;
if ($id && !$row) {
    deny('Record not found for this client.');
}
if ($row && !empty($mod['fixed']['direction']) && $row['direction'] !== $mod['fixed']['direction']) {
    redirect(url('record_form', array('m' => $row['direction'] === 'payee' ? 'withheld_on_us' : 'withholding', 'id' => $id)));
}
$errors = array();
$values = $row ?: array();

if (is_post()) {
    $action = input('action', 'save');
    try {
        if ($action === 'void' || $action === 'restore') {
            Ctx::requirePerm('records.void');
            $reason = trim((string)input('reason'));
            if ($reason === '') {
                throw new RuntimeException('Give a reason.');
            }
            Records::setVoid($mod, $id, $action === 'void', $reason);
            flash('success', $action === 'void' ? 'Record voided.' : 'Record restored.');
            redirect(url('record_form', array('m' => $key, 'id' => $id)));
        }
        Ctx::requirePerm('records.edit');
        list($data, $errors, $warnings) = Records::validate($mod, $_POST, $client['id']);
        $values = array_merge($values, $_POST);
        if (!$errors) {
            if ($row && $row['period_month'] && isset($data['period_month']) && $data['period_month'] !== $row['period_month'] && Records::lockedPeriod($client['id'], $data['period_month'], $mod['lock_scope'])) {
                throw new RuntimeException('The new period is locked.');
            }
            $newId = Records::save($mod, $data, $id ?: null, $id ? trim((string)input('edit_reason')) : null);
            flash('success', ($id ? 'Saved changes to ' : 'Added ') . $mod['singular'] . '.' . ($warnings ? ' Note: ' . implode(' ', $warnings) : ''));
            if (input('again')) {
                redirect(url('record_form', array('m' => $key, 'branch_id' => $data['branch_id'], 'last_date' => $data[$mod['date']])));
            }
            redirect(url('record_form', array('m' => $key, 'id' => $newId)));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        if ($action !== 'save') {
            redirect(url('record_form', array('m' => $key, 'id' => $id)));
        }
        $values = array_merge($values, $_POST);
    }
}
if (!$row && !is_post()) {
    $ho = Ctx::headOffice();
    $values['branch_id'] = input_int('branch_id') ?: ($ho ? $ho['id'] : 0);
    $values[$mod['date']] = input('last_date', '');
    $values['schedule_tag'] = 'A';
    $values['tax_kind'] = 'EWT';
}

$role = null;
foreach ($mod['fields'] as $fd) {
    if ($fd['type'] === 'counterparty') {
        $role = $fd['role'];
    }
}
$parties = DB::all('SELECT id, tin, branch_code, registered_name, last_name, first_name, middle_name, is_' . $role . ' AS has_role, default_atc FROM counterparties
    WHERE client_id = ? AND deleted_at IS NULL AND status = ? ORDER BY is_' . $role . ' DESC, registered_name, last_name LIMIT 3000', array($client['id'], 'active'));
$atcs = $mod['table'] === 'withholding_entries' ? Records::atcOptions(null, isset($values['payment_date']) && $values['payment_date'] ? Records::parseDate($values['payment_date']) : null) : array();
$isLocked = $row ? Records::lockedPeriod($client['id'], $row['period_month'], $mod['lock_scope']) : null;
$canEdit = Ctx::can('records.edit') && !$isLocked && (!$row || $row['status'] !== 'void');
$revs = $row ? DB::all('SELECT r.*, u.full_name FROM revisions r LEFT JOIN users u ON u.id = r.changed_by WHERE r.subject_type = ? AND r.subject_id = ? ORDER BY r.revision_no DESC', array($mod['table'], $id)) : array();

$fieldHtml = function ($fd) use ($values, $errors, $parties, $atcs, $canEdit, $mod) {
    $k = $fd['key'];
    if (isset($mod['fixed'][$k])) {
        return '';
    }
    $v = isset($values[$k]) ? $values[$k] : '';
    $dis = $canEdit ? '' : ' disabled';
    $req = !empty($fd['required']) ? ' required' : '';
    $h = '<div class="field' . (isset($errors[$k]) ? ' has-err' : '') . ($fd['type'] === 'text' && isset($fd['max']) && $fd['max'] > 100 ? ' half' : '') . '"><label for="f-' . $k . '">' . e($fd['label']) . (!empty($fd['required']) ? ' *' : '') . '</label>';
    switch ($fd['type']) {
        case 'branch':
            $opts = array();
            foreach (Ctx::branches() as $b) {
                $opts[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
            }
            $h .= View::select($k, $opts, $v, 'id="f-' . $k . '"' . $dis . $req);
            break;
        case 'counterparty':
            $h .= '<select id="f-' . $k . '" name="' . $k . '"' . $dis . $req . '><option value="">Choose from the directory</option>';
            $group = null;
            foreach ($parties as $p) {
                $g = $p['has_role'] ? 'Marked as ' . $fd['role'] : 'Other directory entries';
                if ($g !== $group) {
                    $h .= ($group ? '</optgroup>' : '') . '<optgroup label="' . e($g) . '">';
                    $group = $g;
                }
                $h .= '<option value="' . (int)$p['id'] . '" data-atc="' . e($p['default_atc']) . '"' . ((string)$v === (string)$p['id'] ? ' selected' : '') . '>' . e(party_name($p) . ' (' . ($p['tin'] ? Tin::display($p['tin'], $p['branch_code']) : 'no TIN') . ')') . '</option>';
            }
            $h .= ($group ? '</optgroup>' : '') . '</select><span class="hint"><a href="' . e(url('cp_form', array('role' => $fd['role']))) . '" target="_blank" rel="noopener">Add a new ' . e($fd['role']) . '</a></span>';
            break;
        case 'select':
            $h .= View::select($k, $fd['options'], $v, 'id="f-' . $k . '"' . $dis . $req);
            break;
        case 'atc':
            $h .= '<select id="f-' . $k . '" name="' . $k . '"' . $dis . $req . '><option value="">Choose an ATC</option>';
            foreach ($atcs as $a) {
                $h .= '<option value="' . e($a['atc_code']) . '" data-kind="' . e($a['tax_kind']) . '" data-rate="' . e(Money::rateFormat(Money::rateUnits($a['rate']), 'pct2')) . '" data-desc="' . e($a['description']) . '" data-status="' . e($a['status']) . '"'
                    . ((string)$v === $a['atc_code'] ? ' selected' : '') . '>' . e($a['atc_code'] . ' ' . Money::rateFormat(Money::rateUnits($a['rate']), 'pct2') . '%: ' . $a['description']) . ($a['status'] !== 'approved' ? ' (pending review)' : '') . '</option>';
            }
            $h .= '</select><span class="hint" data-atc-note></span>';
            break;
        case 'amount':
            $pv = Money::parse($v);
            $h .= '<input id="f-' . $k . '" name="' . $k . '" type="text" inputmode="decimal" class="num" value="' . e($v === '' || $v === null ? '' : ($pv['ok'] ? Money::fmt($pv['cents']) : $v)) . '"' . $dis . $req . '>';
            if ($k === 'tax_withheld' && $canEdit) {
                $h .= '<span class="hint"><button type="button" class="link-btn" data-fill-tax>Fill from rate</button> <span data-tax-preview></span></span>';
            }
            break;
        case 'rate':
            $h .= '<input id="f-' . $k . '" name="' . $k . '" type="text" inputmode="decimal" class="num" value="' . e($v === null || $v === '' ? '' : Money::rateFormat(Money::rateUnits($v), 'pct2')) . '" placeholder="From ATC"' . $dis . '>';
            break;
        case 'date':
            $dv = $v ? (Records::parseDate($v) ?: $v) : '';
            $h .= '<input id="f-' . $k . '" name="' . $k . '" type="date" value="' . e($dv) . '"' . $dis . $req . '>';
            break;
        default:
            $h .= '<input id="f-' . $k . '" name="' . $k . '" type="text" value="' . e($v) . '"' . (isset($fd['max']) ? ' maxlength="' . (int)$fd['max'] . '"' : '') . $dis . $req . '>';
    }
    if (isset($errors[$k])) {
        $h .= '<span class="err">' . e($errors[$k]) . '</span>';
    }
    return $h . '</div>';
};

$title = ($row ? 'Edit ' : 'Add ') . $mod['singular'];
View::header($title, array('page' => 'record_form', 'help' => $key));
View::pageBar($title, View::clientContext($row ? e('#' . $row['id'] . ', ' . month_label($row['period_month'])) : ''),
    '<a class="btn btn-quiet" href="' . e(url($key)) . '">Back to ' . e(strtolower($mod['title'])) . '</a>');
?>
<?php if ($isLocked) { ?><div class="notice notice-warning"><?= View::icon('lock') ?> This record is in a locked period (<?= e($isLocked['reason']) ?>). It cannot be changed.</div><?php } ?>
<?php if ($row && $row['status'] === 'void') { ?><div class="notice notice-warning">This record is void: <?= e($row['void_reason']) ?></div><?php } ?>
<div class="cols cols-main">
  <div>
    <form method="post" class="sheet" <?= $mod['table'] === 'withholding_entries' ? 'data-withholding' : '' ?> data-autosave="<?= e($key . '-' . ($id ?: 'new') . '-' . $client['id']) ?>" data-fresh="<?= $row || is_post() ? '0' : '1' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="sheet-body">
        <?php if ($errors) { ?><div class="notice notice-error">Fix the highlighted fields.</div><?php } ?>
        <div class="form-grid">
          <?php foreach ($mod['fields'] as $fd) { echo $fieldHtml($fd); } ?>
          <?php if ($row && $canEdit) { ?>
            <div class="field wide"><label for="edit_reason">Reason for this change</label><input id="edit_reason" name="edit_reason" type="text" maxlength="255" placeholder="Kept in the revision history"></div>
          <?php } ?>
        </div>
      </div>
      <?php if ($canEdit) { ?>
      <div class="sheet-foot">
        <?php if (!$row) { ?><button class="btn" type="submit" name="again" value="1">Save and add another</button><?php } ?>
        <button class="btn btn-primary" type="submit"><?= $row ? 'Save changes' : 'Save' ?></button>
      </div>
      <?php } ?>
    </form>
  </div>
  <div>
    <?php if ($row) { ?>
    <div class="sheet">
      <div class="sheet-head"><h2>Record</h2><?= View::status($row['status']) ?></div>
      <div class="sheet-body"><dl class="kv">
        <dt>Period</dt><dd><?= e(month_label($row['period_month'])) ?></dd>
        <dt>Source</dt><dd><?= e($row['source']) ?><?= $row['import_job_id'] ? ', <a href="' . e(url('import_review', array('id' => $row['import_job_id']))) . '">import #' . (int)$row['import_job_id'] . '</a>' : '' ?></dd>
        <dt>Created</dt><dd><?= e(fmt_dt($row['created_at'])) ?></dd>
        <dt>Updated</dt><dd><?= e(fmt_dt($row['updated_at'])) ?></dd>
        <?php if (!empty($row['certificate_id'])) { ?><dt>Certificate</dt><dd><a href="<?= e(url('cert_view', array('id' => $row['certificate_id']))) ?>">Issued</a></dd><?php } ?>
      </dl></div>
      <?php if (Ctx::can('records.void') && !$isLocked) { ?>
      <div class="sheet-foot">
        <form method="post" data-prompt="Reason"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $row['status'] === 'void' ? 'restore' : 'void' ?>">
          <button class="btn <?= $row['status'] === 'void' ? '' : 'btn-danger' ?>" type="submit"><?= $row['status'] === 'void' ? 'Restore' : 'Void record' ?></button></form>
      </div>
      <?php } ?>
    </div>
    <div class="sheet">
      <div class="sheet-head"><h2>Revision history</h2></div>
      <?php if (!$revs) { ?><div class="sheet-body muted small">No changes since it was created.</div><?php } else { ?>
        <ul class="queue"><?php foreach ($revs as $rv) { $old = json_decode($rv['data_json'], true); ?>
          <li><div class="q-main">Revision <?= (int)$rv['revision_no'] ?> by <?= e($rv['full_name'] ?: 'System') ?><div class="q-sub"><?= e(fmt_dt($rv['changed_at'])) ?><?= $rv['reason'] ? ': ' . e($rv['reason']) : '' ?></div>
            <details class="small"><summary>Values before this change</summary><dl class="kv" style="margin-top:6px"><?php foreach ($mod['fields'] as $fd) { if (isset($old[$fd['key']])) { ?><dt><?= e($fd['label']) ?></dt><dd><?= e($fd['type'] === 'amount' ? Money::fmtDb($old[$fd['key']]) : $old[$fd['key']]) ?></dd><?php } } ?></dl></details>
          </div></li>
        <?php } ?></ul>
      <?php } ?>
    </div>
    <?php } else { ?>
    <div class="sheet"><div class="sheet-body prose small">
      <p><?= e($mod['help']) ?></p>
      <p>Amounts are stored to the centavo. Use <em>Save and add another</em> to keep the branch and date for the next entry. For many rows at once use <a href="<?= e(url('grid')) ?>">grid entry</a> or <a href="<?= e(url('imports')) ?>">import a spreadsheet</a>.</p>
    </div></div>
    <?php } ?>
  </div>
</div>
<?php View::footer();
