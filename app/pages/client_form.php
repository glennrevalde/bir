<?php
Ctx::requirePerm('clients.view');
if (!Ctx::orgId()) {
    flash('info', 'Open an organization first. Clients always belong to an organization.');
    redirect(url('orgs'));
}
$id = input_int('id');
$client = $id ? Ctx::clientAllowed($id) : null;
if ($id && !$client) {
    deny('Client not found or not in your scope.');
}
$tab = $client ? (string)input('tab', 'details') : 'details';
$errors = array();
$values = $client ?: array('taxpayer_type' => 'non_individual', 'classification' => 'regular', 'vat_status' => 'vat', 'reporting_cycle' => 'calendar', 'fiscal_month_end' => 12);

if (is_post()) {
    Ctx::requirePerm('clients.manage');
    $action = input('action', 'details');
    try {
        if ($action === 'details') {
            $t = Tin::parse(input('tin'));
            if (!$t['ok']) {
                $errors['tin'] = $t['error'];
            }
            $d = array('tin' => $t['ok'] ? $t['tin'] : '', 'taxpayer_type' => input('taxpayer_type') === 'individual' ? 'individual' : 'non_individual');
            foreach (array('registered_name' => 150, 'last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'trade_name' => 150, 'address1' => 150, 'address2' => 150, 'zip_code' => 6, 'email' => 190, 'phone' => 40) as $k => $max) {
                $v = trim((string)input($k));
                if (mb_strlen($v) > $max) {
                    $errors[$k] = 'Too long';
                }
                $d[$k] = $v === '' ? null : $v;
            }
            if ($d['taxpayer_type'] === 'individual' && (!$d['last_name'] || !$d['first_name'])) {
                $errors['last_name'] = 'Individuals need a last and first name';
            }
            if ($d['taxpayer_type'] === 'non_individual' && !$d['registered_name']) {
                $errors['registered_name'] = 'Registered name is required';
            }
            $rdo = rdo_normalize(input('rdo_code'));
            if ($rdo === null) {
                $errors['rdo_code'] = 'Enter the RDO code as 3 digits (081) or digits and a letter (21B)';
            }
            $d['rdo_code'] = $rdo ?: null;
            $d['classification'] = in_array(input('classification'), array('regular', 'top_withholding_agent', 'large_taxpayer', 'government'), true) ? input('classification') : 'regular';
            $d['vat_status'] = input('vat_status') === 'non_vat' ? 'non_vat' : 'vat';
            $d['reporting_cycle'] = input('reporting_cycle') === 'fiscal' ? 'fiscal' : 'calendar';
            $d['fiscal_month_end'] = max(1, min(12, input_int('fiscal_month_end', 12)));
            if ($d['reporting_cycle'] === 'calendar') {
                $d['fiscal_month_end'] = 12;
            }
            if ($d['tin'] && DB::val('SELECT id FROM client_entities WHERE organization_id = ? AND tin = ? AND deleted_at IS NULL' . ($id ? ' AND id <> ?' : ''), $id ? array(Ctx::orgId(), $d['tin'], $id) : array(Ctx::orgId(), $d['tin']))) {
                $errors['tin'] = 'A client with this TIN already exists in this organization';
            }
            $values = array_merge($values, $_POST);
            if (!$errors) {
                $d['updated_at'] = now();
                if ($client) {
                    DB::update('client_entities', $d, 'id = ? AND organization_id = ?', array($id, Ctx::orgId()));
                    Audit::log('client.update', 'client_entities', $id, 'Edited client details', array_intersect_key($client, $d), $d, null, $id);
                } else {
                    $id = DB::tx(function () use ($d) {
                        $id = DB::insert('client_entities', array_merge($d, array('organization_id' => Ctx::orgId(), 'status' => 'active', 'created_by' => Auth::id(), 'created_at' => now())));
                        DB::insert('branches', array('organization_id' => Ctx::orgId(), 'client_id' => $id, 'branch_code' => '0000', 'name' => 'Head office', 'is_head_office' => 1,
                            'rdo_code' => $d['rdo_code'], 'address1' => $d['address1'], 'address2' => $d['address2'], 'zip_code' => $d['zip_code'], 'status' => 'active', 'created_at' => now()));
                        return $id;
                    });
                    $m = Ctx::membership();
                    if ($m && !(int)$m['all_clients']) {
                        DB::insert('membership_scopes', array('membership_id' => $m['id'], 'client_id' => $id, 'branch_id' => null));
                    }
                    Ctx::reset();
                    Ctx::switchClient($id);
                    Audit::log('client.create', 'client_entities', $id, 'Added client ' . ($d['registered_name'] ?: $d['last_name']), null, $d, null, $id);
                }
                flash('success', 'Client saved.');
                redirect(url('client_form', array('id' => $id)));
            }
        } elseif ($action === 'branch') {
            $bid = input_int('branch_id');
            $code = Tin::branch(input('branch_code'));
            if ($code === null) {
                throw new RuntimeException('Branch code must have 4 or 5 digits.');
            }
            $rdo = rdo_normalize(input('rdo_code'));
            if ($rdo === null) {
                throw new RuntimeException('Enter the RDO code as 3 digits (081) or digits and a letter (21B).');
            }
            $d = array('branch_code' => $code, 'name' => trim((string)input('name')) ?: 'Branch ' . $code, 'rdo_code' => $rdo ?: null, 'address1' => trim((string)input('address1')) ?: null,
                'address2' => trim((string)input('address2')) ?: null, 'zip_code' => trim((string)input('zip_code')) ?: null, 'updated_at' => now());
            if (DB::val('SELECT id FROM branches WHERE client_id = ? AND branch_code = ?' . ($bid ? ' AND id <> ?' : ''), $bid ? array($id, $code, $bid) : array($id, $code))) {
                throw new RuntimeException('Branch code ' . $code . ' already exists for this client.');
            }
            if ($bid) {
                DB::update('branches', $d, 'id = ? AND client_id = ?', array($bid, $id));
                Audit::log('branch.update', 'branches', $bid, 'Edited branch ' . $code, null, $d, null, $id);
            } else {
                $bid = DB::insert('branches', array_merge($d, array('organization_id' => Ctx::orgId(), 'client_id' => $id, 'is_head_office' => $code === '0000' ? 1 : 0, 'status' => 'active', 'created_at' => now())));
                Audit::log('branch.create', 'branches', $bid, 'Added branch ' . $code, null, $d, null, $id);
            }
            flash('success', 'Branch saved.');
        } elseif ($action === 'signatory') {
            $sid = input_int('signatory_id');
            $d = array('full_name' => trim((string)input('full_name')), 'title' => trim((string)input('title')) ?: null, 'tin' => trim((string)input('sig_tin')) ?: null,
                'user_id' => input_int('user_id') ?: null, 'is_default' => input('is_default') ? 1 : 0, 'status' => input('status') === 'inactive' ? 'inactive' : 'active');
            if ($d['full_name'] === '') {
                throw new RuntimeException('Enter the signatory name.');
            }
            if (isset($_FILES['signature']) && $_FILES['signature']['error'] !== UPLOAD_ERR_NO_FILE) {
                $up = store_upload($_FILES['signature'], 'signatures', array('png', 'jpg', 'jpeg'), 2 * 1024 * 1024);
                $d['signature_path'] = $up['path'];
                $d['signature_approved_by'] = null;
                $d['signature_approved_at'] = null;
            }
            if ($d['is_default']) {
                DB::q('UPDATE signatories SET is_default = 0 WHERE client_id = ?', array($id));
            }
            if ($sid) {
                DB::update('signatories', $d, 'id = ? AND client_id = ?', array($sid, $id));
            } else {
                $sid = DB::insert('signatories', array_merge($d, array('organization_id' => Ctx::orgId(), 'client_id' => $id, 'created_at' => now())));
            }
            Audit::log('signatory.save', 'signatories', $sid, 'Saved signatory ' . $d['full_name'] . (isset($d['signature_path']) ? ' with a new signature image (needs approval)' : ''), null, null, null, $id);
            flash('success', 'Signatory saved.' . (isset($d['signature_path']) ? ' Another person must approve the signature image before it can be used.' : ''));
        } elseif ($action === 'archive' && $client) {
            $to = $client['status'] === 'archived' ? 'active' : 'archived';
            DB::update('client_entities', array('status' => $to, 'updated_at' => now()), 'id = ?', array($id));
            Audit::log('client.' . ($to === 'archived' ? 'archive' : 'reactivate'), 'client_entities', $id, ($to === 'archived' ? 'Archived ' : 'Reactivated ') . Ctx::clientName($client), null, null, null, $id);
            flash('success', $to === 'archived' ? 'Client archived. Records stay available for viewing; new records, imports and files are blocked.' : 'Client reactivated.');
            redirect(url('client_form', array('id' => $id)));
        } elseif ($action === 'approve_signature') {
            Ctx::requirePerm('approvals.decide');
            $s = DB::row('SELECT * FROM signatories WHERE id = ? AND client_id = ?', array(input_int('signatory_id'), $id));
            if (!$s || !$s['signature_path']) {
                throw new RuntimeException('No signature image to approve.');
            }
            if ((int)$s['user_id'] === Auth::id()) {
                throw new RuntimeException('A signatory cannot approve their own signature image.');
            }
            DB::update('signatories', array('signature_approved_by' => Auth::id(), 'signature_approved_at' => now()), 'id = ?', array($s['id']));
            Audit::log('signatory.approve', 'signatories', $s['id'], 'Approved signature image of ' . $s['full_name'], null, null, null, $id);
            flash('success', 'Signature image approved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    if (!$errors) {
        redirect(url('client_form', array('id' => $id, 'tab' => $tab)));
    }
}
if (input('sigimg') && $client) {
    $s = DB::row('SELECT * FROM signatories WHERE id = ? AND client_id = ?', array(input_int('sigimg'), $id));
    if ($s && $s['signature_path'] && is_file(storage_path($s['signature_path']))) {
        $ext = strtolower(pathinfo($s['signature_path'], PATHINFO_EXTENSION));
        send_bytes(file_get_contents(storage_path($s['signature_path'])), 'signature.' . $ext, $ext === 'png' ? 'image/png' : 'image/jpeg', true);
    }
    exit;
}
$v = function ($k, $def = '') use ($values) {
    return isset($values[$k]) && $values[$k] !== null ? $values[$k] : $def;
};
$err = function ($k) use ($errors) {
    return isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
};
$dis = Ctx::can('clients.manage') ? '' : ' disabled';
$title = $client ? Ctx::clientName($client) : 'Add client';
View::header($title, array('page' => 'client_form'));
View::pageBar($title, $client ? Tin::boxes($client['tin']) : 'A taxpayer you prepare BIR reports for', '<a class="btn btn-quiet" href="' . e(url('clients')) . '">All clients</a>');
?>
<?php if ($client) { ?><div class="btn-row" style="margin-bottom:14px"><?php foreach (array('details' => 'Details', 'branches' => 'Branches', 'signatories' => 'Signatories') as $k => $l) { ?>
  <a class="btn btn-sm <?= $tab === $k ? 'btn-primary' : 'btn-quiet' ?>" href="<?= e(url('client_form', array('id' => $id, 'tab' => $k))) ?>"><?= e($l) ?></a><?php } ?></div><?php } ?>

<?php if ($tab === 'details') { ?>
<form method="post" class="sheet" style="max-width:1000px"><?= csrf_field() ?><input type="hidden" name="action" value="details">
  <div class="sheet-body"><div class="form-grid">
    <div class="field"><label for="taxpayer_type">Taxpayer type</label><?= View::select('taxpayer_type', array('non_individual' => 'Non-individual', 'individual' => 'Individual'), $v('taxpayer_type'), 'id="taxpayer_type"' . $dis) ?></div>
    <div class="field <?= isset($errors['tin']) ? 'has-err' : '' ?>"><label for="tin">TIN *</label><input id="tin" name="tin" type="text" value="<?= e($v('tin') ? Tin::display($v('tin')) : '') ?>" placeholder="123-456-789"<?= $dis ?>><?= $err('tin') ?></div>
    <div class="field <?= isset($errors['rdo_code']) ? 'has-err' : '' ?>"><label for="rdo_code">RDO code</label><input id="rdo_code" name="rdo_code" type="text" maxlength="4" list="rdo-list" value="<?= e($v('rdo_code')) ?>" placeholder="081"<?= $dis ?>><span class="hint">Branches can have their own RDO.</span><?= $err('rdo_code') ?></div>
    <div class="field half <?= isset($errors['registered_name']) ? 'has-err' : '' ?>"><label for="registered_name">Registered name (non-individual)</label><input id="registered_name" name="registered_name" type="text" value="<?= e($v('registered_name')) ?>"<?= $dis ?>><?= $err('registered_name') ?></div>
    <div class="field <?= isset($errors['last_name']) ? 'has-err' : '' ?>"><label for="last_name">Last name (individual)</label><input id="last_name" name="last_name" type="text" value="<?= e($v('last_name')) ?>"<?= $dis ?>><?= $err('last_name') ?></div>
    <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" value="<?= e($v('first_name')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" type="text" value="<?= e($v('middle_name')) ?>"<?= $dis ?>></div>
    <div class="field half"><label for="trade_name">Trade name</label><input id="trade_name" name="trade_name" type="text" value="<?= e($v('trade_name')) ?>"<?= $dis ?>></div>
    <div class="field half"><label for="address1">Registered address</label><input id="address1" name="address1" type="text" value="<?= e($v('address1')) ?>" placeholder="Unit, street, barangay"<?= $dis ?>></div>
    <div class="field"><label for="address2">City or municipality</label><input id="address2" name="address2" type="text" value="<?= e($v('address2')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="zip_code">ZIP code</label><input id="zip_code" name="zip_code" type="text" maxlength="6" value="<?= e($v('zip_code')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="classification">Classification</label><?= View::select('classification', array('regular' => 'Regular', 'top_withholding_agent' => 'Top withholding agent', 'large_taxpayer' => 'Large taxpayer', 'government' => 'Government office or GOCC'), $v('classification'), 'id="classification"' . $dis) ?></div>
    <div class="field"><label for="vat_status">VAT status</label><?= View::select('vat_status', array('vat' => 'VAT-registered', 'non_vat' => 'Non-VAT'), $v('vat_status'), 'id="vat_status"' . $dis) ?></div>
    <div class="field"><label for="reporting_cycle">Accounting period</label><?= View::select('reporting_cycle', array('calendar' => 'Calendar year', 'fiscal' => 'Fiscal year'), $v('reporting_cycle'), 'id="reporting_cycle"' . $dis) ?></div>
    <div class="field"><label for="fiscal_month_end">Fiscal year ends (month)</label><input id="fiscal_month_end" name="fiscal_month_end" type="number" min="1" max="12" value="<?= (int)$v('fiscal_month_end', 12) ?>"<?= $dis ?>></div>
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e($v('email')) ?>"<?= $dis ?>></div>
    <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="text" value="<?= e($v('phone')) ?>"<?= $dis ?>></div>
  </div></div>
  <?php if (Ctx::can('clients.manage')) { ?><div class="sheet-foot"><?php if ($client) { ?><button class="btn btn-quiet" name="action" value="archive" formnovalidate data-confirm="<?= $client['status'] === 'archived' ? 'Reactivate this client?' : 'Archive this client? Records stay readable, but no new records, imports or files can be made.' ?>"><?= $client['status'] === 'archived' ? 'Reactivate client' : 'Archive client' ?></button><?php } ?><button class="btn btn-primary"><?= $client ? 'Save client' : 'Add client' ?></button></div><?php } ?>
</form>
<?php } elseif ($tab === 'branches') { $branches = DB::all('SELECT * FROM branches WHERE client_id = ? ORDER BY branch_code', array($id)); $edit = input_int('edit'); ?>
<div class="cols cols-main">
  <div class="sheet"><div class="table-wrap"><table class="table table-stack"><thead><tr><th>Code</th><th>Name</th><th>RDO</th><th>Address</th><th></th></tr></thead><tbody>
    <?php foreach ($branches as $b) { ?><tr><td data-label="Code"><?= Tin::boxes($client['tin'], $b['branch_code']) ?></td><td data-label="Name"><?= e($b['name']) ?><?= $b['is_head_office'] ? '<span class="sub">Head office</span>' : '' ?></td>
      <td data-label="RDO"><?= e($b['rdo_code'] ?: $client['rdo_code']) ?></td><td data-label="Address"><?= e(trim($b['address1'] . ' ' . $b['address2'])) ?></td>
      <td class="actions"><?php if (Ctx::can('clients.manage')) { ?><a class="btn btn-sm btn-quiet" href="<?= e(url('client_form', array('id' => $id, 'tab' => 'branches', 'edit' => $b['id']))) ?>">Edit</a><?php } ?></td></tr><?php } ?>
  </tbody></table></div></div>
  <?php if (Ctx::can('clients.manage')) { $eb = $edit ? DB::row('SELECT * FROM branches WHERE id = ? AND client_id = ?', array($edit, $id)) : null; ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="branch"><input type="hidden" name="branch_id" value="<?= $eb ? (int)$eb['id'] : 0 ?>">
    <div class="sheet-head"><h2><?= $eb ? 'Edit branch ' . e($eb['branch_code']) : 'Add a branch' ?></h2></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field"><label for="branch_code">Branch code</label><input id="branch_code" name="branch_code" type="text" maxlength="5" value="<?= e($eb ? $eb['branch_code'] : '') ?>" placeholder="0001" required></div>
      <div class="field"><label for="bname">Name</label><input id="bname" name="name" type="text" value="<?= e($eb ? $eb['name'] : '') ?>"></div>
      <div class="field"><label for="brdo">RDO code</label><input id="brdo" name="rdo_code" type="text" maxlength="4" list="rdo-list" value="<?= e($eb ? $eb['rdo_code'] : '') ?>"></div>
      <div class="field"><label for="bzip">ZIP code</label><input id="bzip" name="zip_code" type="text" maxlength="6" value="<?= e($eb ? $eb['zip_code'] : '') ?>"></div>
      <div class="field wide"><label for="ba1">Address</label><input id="ba1" name="address1" type="text" value="<?= e($eb ? $eb['address1'] : '') ?>"></div>
      <div class="field wide"><label for="ba2">City</label><input id="ba2" name="address2" type="text" value="<?= e($eb ? $eb['address2'] : '') ?>"></div>
    </div></div>
    <div class="sheet-foot"><button class="btn btn-primary">Save branch</button></div>
  </form>
  <?php } ?>
</div>
<?php } else { $sigs = DB::all('SELECT s.*, u.full_name AS approver FROM signatories s LEFT JOIN users u ON u.id = s.signature_approved_by WHERE s.client_id = ? ORDER BY s.is_default DESC, s.full_name', array($id));
    $members = DB::all('SELECT u.id, u.full_name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.status = ? ORDER BY u.full_name', array(Ctx::orgId(), 'active'));
    $mo = array(); foreach ($members as $m) { $mo[$m['id']] = $m['full_name']; } ?>
<div class="cols cols-main">
  <div class="sheet"><div class="table-wrap"><table class="table table-stack"><thead><tr><th>Signatory</th><th>TIN</th><th>Signature image</th><th></th></tr></thead><tbody>
    <?php foreach ($sigs as $s) { ?><tr><td data-label="Signatory"><strong><?= e($s['full_name']) ?></strong><span class="sub"><?= e($s['title']) ?><?= $s['is_default'] ? ', default' : '' ?><?= $s['status'] === 'inactive' ? ', inactive' : '' ?></span></td>
      <td data-label="TIN"><?= e($s['tin']) ?></td>
      <td data-label="Signature"><?php if ($s['signature_path']) { ?><img src="<?= e(url('client_form', array('id' => $id, 'sigimg' => $s['id']))) ?>" alt="Signature of <?= e($s['full_name']) ?>" style="max-height:42px;background:#fff;border-radius:4px;padding:2px"><br>
        <?= $s['signature_approved_at'] ? '<span class="st st-ok">Approved by ' . e($s['approver']) . '</span>' : '<span class="st st-wait">Needs approval</span>' ?><?php } else { ?><span class="muted">None</span><?php } ?></td>
      <td class="actions"><?php if ($s['signature_path'] && !$s['signature_approved_at'] && Ctx::can('approvals.decide')) { ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve_signature"><input type="hidden" name="signatory_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm">Approve image</button></form><?php } ?>
        <a class="btn btn-sm btn-quiet" href="<?= e(url('client_form', array('id' => $id, 'tab' => 'signatories', 'edit' => $s['id']))) ?>">Edit</a></td></tr><?php } ?>
    <?php if (!$sigs) { ?><tr><td colspan="4" class="muted">No signatories yet.</td></tr><?php } ?>
  </tbody></table></div></div>
  <?php if (Ctx::can('clients.manage')) { $es = input_int('edit') ? DB::row('SELECT * FROM signatories WHERE id = ? AND client_id = ?', array(input_int('edit'), $id)) : null; ?>
  <form method="post" enctype="multipart/form-data" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="signatory"><input type="hidden" name="signatory_id" value="<?= $es ? (int)$es['id'] : 0 ?>">
    <div class="sheet-head"><h2><?= $es ? 'Edit signatory' : 'Add a signatory' ?></h2></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field wide"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" value="<?= e($es ? $es['full_name'] : '') ?>" required></div>
      <div class="field"><label for="title">Title or designation</label><input id="title" name="title" type="text" value="<?= e($es ? $es['title'] : '') ?>"></div>
      <div class="field"><label for="sig_tin">TIN</label><input id="sig_tin" name="sig_tin" type="text" value="<?= e($es ? $es['tin'] : '') ?>"></div>
      <div class="field"><label for="user_id">Linked member</label><?= View::select('user_id', $mo, $es ? $es['user_id'] : '', 'id="user_id"', 'None') ?></div>
      <div class="field"><label for="sstatus">Status</label><?= View::select('status', array('active' => 'Active', 'inactive' => 'Inactive'), $es ? $es['status'] : 'active', 'id="sstatus"') ?></div>
      <div class="field wide"><label for="signature">Signature image (transparent PNG or JPEG, up to 2 MB)</label><input id="signature" name="signature" type="file" accept=".png,.jpg,.jpeg"><span class="hint">A new image must be approved by another person before it prints.</span></div>
      <div class="field wide"><label class="check"><input type="checkbox" name="is_default" value="1" <?= $es && $es['is_default'] ? 'checked' : '' ?>> Default signatory for this client</label></div>
    </div></div>
    <div class="sheet-foot"><button class="btn btn-primary">Save signatory</button></div>
  </form>
  <?php } ?>
</div>
<?php } ?>
<datalist id="rdo-list"><?php foreach (DB::all('SELECT code, name FROM rdo_codes ORDER BY code') as $rd) { ?><option value="<?= e($rd['code']) ?>"><?= e($rd['name']) ?></option><?php } ?></datalist>
<?php View::footer();
