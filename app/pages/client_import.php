<?php
Ctx::requirePerm('clients.manage');
if (!Ctx::orgId()) {
    flash('info', 'Open an organization first. Clients always belong to an organization.');
    redirect(url('orgs'));
}
if ($dl = input('template')) {
    send_bytes(ClientImport::template($dl === 'sample'), $dl === 'sample' ? 'GSB-CLIENTS-sample.csv' : 'GSB-CLIENTS.csv', 'text/csv; charset=utf-8');
}
$id = input_int('id');
$batch = $id ? ClientImport::batch($id) : null;
if ($id && !$batch) {
    deny('This upload was not found in your organization.');
}

if (is_post()) {
    $action = (string)input('action');
    $back = $batch ? array('id' => $batch['id']) : array();
    try {
        if ($action === 'upload') {
            $up = isset($_FILES['file']) ? $_FILES['file'] : null;
            if ($up && !empty($up['name']) && strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new RuntimeException('Upload a .csv file. In Excel, choose File, Save As, and the type CSV (Comma delimited), then upload that file.');
            }
            $f = store_upload($up, 'uploads/clients', array('csv'));
            $new = ClientImport::stage(storage_path($f['path']), $f['name'], $f['path']);
            $c = ClientImport::counts($new);
            flash($c['error'] ? 'warning' : 'success', 'Read ' . plural($c['total'], 'client') . ' from ' . $f['name'] . '. '
                . ($c['error'] ? plural($c['error'], 'row needs', 'rows need') . ' correcting before the list can be saved.' : 'No row has an error; check the list and save it.'));
            redirect(url('client_import', array('id' => $new)));
        }
        if (!$batch) {
            throw new RuntimeException('Open an upload first.');
        }
        if ($action === 'edit_row') {
            $rowId = input_int('row_id');
            $r = ClientImport::updateRow($batch['id'], $rowId, $_POST);
            $errs = json_decode((string)$r['errors_json'], true) ?: array();
            if ($errs) {
                flash('warning', 'Row ' . (int)$r['row_no'] . ' saved, but ' . plural(count($errs), 'problem remains', 'problems remain') . '.');
                redirect(url('client_import', array('id' => $batch['id'], 'edit' => $rowId, 'show' => input('show'))) . '#edit');
            }
            flash('success', 'Row ' . (int)$r['row_no'] . ' corrected.');
            redirect(url('client_import', array('id' => $batch['id'], 'show' => input('show'))) . '#r' . $rowId);
        } elseif ($action === 'delete_row') {
            $n = ClientImport::deleteRows($batch['id'], array(input_int('row_id')));
            flash('success', $n ? 'Row deleted. It will not be added as a client.' : 'That row was already deleted.');
            $back['show'] = input('show');
        } elseif ($action === 'delete_errors') {
            $n = ClientImport::deleteRows($batch['id'], 'errors');
            flash('success', plural($n, 'row') . ' with errors deleted.');
        } elseif ($action === 'save') {
            $n = ClientImport::save($batch['id']);
            flash('success', 'Saved ' . plural($n, 'new client') . '. Each starts with its head office, branch 0000.');
            redirect(url('clients'));
        } elseif ($action === 'discard') {
            ClientImport::discard($batch['id']);
            flash('success', 'Upload discarded. No client was added.');
            redirect(url('client_import'));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('client_import', $back));
}

$labels = ClientImport::labels();
$msgs = function ($r) use ($labels) {
    $h = '';
    foreach (array('bad' => $r['errors'], 'warn' => $r['warnings']) as $kind => $list) {
        foreach ($list as $k => $m) {
            $h .= '<div class="rmsg rmsg-' . $kind . '"><span class="rmsg-where">' . e(isset($labels[$k]) ? $labels[$k] : $k) . '</span><span class="rmsg-text">' . e($m) . '</span>'
                . ($kind === 'bad' ? '<span class="rmsg-found">In the file: ' . (isset($r['data'][$k]) && $r['data'][$k] !== '' ? e($r['data'][$k]) : '<em>blank</em>') . '</span>' : '') . '</div>';
        }
    }
    return $h;
};

if (!$batch) {
    // ------------------------------------------------------------ upload
    $batches = DB::all('SELECT b.*, u.full_name, (SELECT COUNT(*) FROM client_import_rows r WHERE r.batch_id = b.id AND r.status = ?) AS errs
        FROM client_import_batches b LEFT JOIN users u ON u.id = b.created_by WHERE b.organization_id = ? ORDER BY b.id DESC LIMIT 30', array('error', Ctx::orgId()));
    View::header('Upload clients', array('page' => 'client_import'));
    View::pageBar('Upload clients', 'New clients from a CSV file, checked before they are saved', '<a class="btn btn-quiet" href="' . e(url('clients')) . '">All clients</a>');
    $storageBad = storage_problems();
    ?>
<?php if ($storageBad) { ?><div class="notice notice-error"><strong>Uploads will fail on this server.</strong> The web server cannot write to the storage folder. <?= e(storage_fix_hint()) ?></div><?php } ?>
<div class="cols cols-main">
  <div class="stack-lg">
    <form method="post" enctype="multipart/form-data" class="sheet cimp-upload"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div class="sheet-head"><div><h2>Upload a CSV file</h2><p>Nothing is added to your clients until you have reviewed the list and saved it.</p></div></div>
      <div class="sheet-body">
        <ol class="cimp-steps">
          <li><strong>Fill in the template.</strong> One client per row, under the column titles of the template. Save it as CSV (comma delimited).</li>
          <li><strong>Upload it here.</strong> Every row is checked: TIN, names, RDO, classification, VAT status, accounting period and contact details, and TINs already used by a client or by another row.</li>
          <li><strong>Correct or delete the flagged rows,</strong> then save the list. Each client is added with its head office, branch 0000.</li>
        </ol>
        <div class="field cimp-file"><label for="file">CSV file (up to <?= (int)sys_config('max_upload_mb', 20) ?> MB, <?= number_format(ClientImport::MAX_ROWS) ?> clients)</label>
          <input id="file" name="file" type="file" accept=".csv,text/csv" required></div>
      </div>
      <div class="sheet-foot">
        <a class="btn btn-quiet" href="<?= e(url('client_import', array('template' => 'sample'))) ?>"><?= View::icon('download') ?>Template with two sample clients</a>
        <a class="btn" href="<?= e(url('client_import', array('template' => 'blank'))) ?>"><?= View::icon('download') ?>Blank template</a>
        <button class="btn btn-primary" type="submit"><?= View::icon('upload') ?>Upload and check</button>
      </div>
    </form>

    <div class="sheet">
      <div class="sheet-head"><h2>Uploads</h2></div>
      <?php if (!$batches) { echo View::empty_state('No uploads yet', 'Uploaded lists wait here until they are saved or discarded.'); } else { ?>
      <div class="table-wrap"><table class="table table-stack">
        <thead><tr><th>File</th><th class="num">Clients</th><th class="num">With errors</th><th>Status</th><th>By</th><th></th></tr></thead>
        <tbody><?php foreach ($batches as $b) { ?>
          <tr><td data-label="File"><strong><?= e($b['original_filename']) ?></strong><span class="sub"><?= e(fmt_dt($b['created_at'])) ?></span></td>
            <td data-label="Clients" class="num"><?= $b['status'] === 'saved' ? (int)$b['saved_rows'] : (int)$b['total_rows'] ?></td>
            <td data-label="With errors" class="num"><?= $b['status'] === 'review' ? (int)$b['errs'] : '' ?></td>
            <td data-label="Status"><?= $b['status'] === 'saved' ? 'Saved ' . e(fmt_dt($b['saved_at'])) : ($b['status'] === 'review' ? '<strong>Waiting for review</strong>' : 'Discarded') ?></td>
            <td data-label="By"><?= e($b['full_name']) ?></td>
            <td class="actions"><?php if ($b['status'] !== 'discarded') { ?><a class="btn btn-sm <?= $b['status'] === 'review' ? '' : 'btn-quiet' ?>" href="<?= e(url('client_import', array('id' => $b['id']))) ?>"><?= $b['status'] === 'review' ? 'Continue review' : 'Open' ?></a><?php } ?></td></tr>
        <?php } ?></tbody></table></div>
      <?php } ?>
    </div>
  </div>

  <div class="sheet cimp-cols">
    <div class="sheet-head"><div><h2>Columns of the file</h2><p>Only TIN and the name are required. Columns may be in any order; other columns are left out.</p></div></div>
    <dl class="cimp-dl">
      <?php foreach (ClientImport::columns() as $k => $c) { ?>
        <div><dt><?= e($c[0]) ?></dt><dd><?= e($c[2]) ?></dd></div>
      <?php } ?>
    </dl>
  </div>
</div>
<?php
    View::footer();
    return;
}

// ------------------------------------------------------------ review
$open = $batch['status'] === 'review';
$cnt = ClientImport::counts($batch['id']);
$show = in_array(input('show'), array('error', 'warning', 'ok', 'saved'), true) ? input('show') : 'all';
$all = ClientImport::rows($batch['id'], $show);
$per = 100;
$page = max(1, input_int('page', 1));
$rows = array_slice($all, ($page - 1) * $per, $per);
$edit = null;
if ($open && input_int('edit')) {
    foreach (ClientImport::rows($batch['id']) as $r) {
        if ((int)$r['id'] === input_int('edit') && $r['status'] !== 'saved') {
            $edit = $r;
        }
    }
}
$track = array('Uploaded', 'Checked and corrected', 'Saved to clients');
$at = $batch['status'] === 'saved' ? 3 : 1;
View::header('Review uploaded clients', array('page' => 'client_import'));
View::pageBar($open ? 'Review uploaded clients' : 'Uploaded clients', e($batch['original_filename']) . ', uploaded by ' . e($batch['uploader']) . ' ' . e(fmt_dt($batch['created_at'])),
    '<a class="btn btn-quiet" href="' . e(url('client_import')) . '">All uploads</a>');
?>
<div class="sheet">
  <ol class="track" aria-label="Upload progress"><?php foreach ($track as $i => $t) { ?><li class="<?= $i < $at ? 'done' : ($i === $at ? 'now' : '') ?>"><?= e($t) ?></li><?php } ?></ol>
  <div class="figures">
    <?php if ($open) { ?>
    <div><strong><?= (int)$cnt['total'] ?></strong><span>Clients in the list</span></div>
    <div><strong><?= (int)$cnt['ok'] ?></strong><span>Ready to save</span></div>
    <div class="<?= $cnt['error'] ? 'fig-bad' : '' ?>"><strong><?= (int)$cnt['error'] ?></strong><span>With errors</span></div>
    <div><strong><?= (int)$cnt['warning'] ?></strong><span>Ready, with warnings</span></div>
    <?php } else { ?>
    <div><strong><?= (int)$batch['saved_rows'] ?></strong><span>Clients saved</span></div>
    <div><strong><?= e(fmt_dt($batch['saved_at'])) ?></strong><span>Saved by <?= e($batch['saver']) ?></span></div>
    <?php } ?>
  </div>
</div>

<?php if ($batch['ignored_columns']) { ?>
<div class="notice notice-warning">These columns of the file were not read: <?= e($batch['ignored_columns']) ?>. If one of them holds client details, rename it to the template's column title and upload the file again.</div>
<?php } ?>

<?php if ($edit) { list($clean) = ClientImport::check($edit['data']); $d = $edit['data'];
    $fv = function ($k) use ($d) { return isset($d[$k]) ? $d[$k] : ''; };
    $fe = function ($k) use ($edit) { return isset($edit['errors'][$k]) ? '<span class="err">' . e($edit['errors'][$k]) . '</span>' : (isset($edit['warnings'][$k]) ? '<span class="hint warn-text">' . e($edit['warnings'][$k]) . '</span>' : ''); };
    $fc = function ($k) use ($edit) { return isset($edit['errors'][$k]) ? ' has-err' : ''; };
    // A select shows the value it understood (blank means the default); one it did not understand stays unchosen so it is picked on purpose.
    $sel = function ($k) use ($edit, $clean) { return isset($edit['errors'][$k]) ? '' : $clean[$k]; }; ?>
<form method="post" class="sheet cimp-edit" id="edit"><?= csrf_field() ?><input type="hidden" name="action" value="edit_row"><input type="hidden" name="row_id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="show" value="<?= e($show === 'all' ? '' : $show) ?>">
  <div class="sheet-head"><div><h2>Correct row <?= (int)$edit['row_no'] ?></h2>
    <p><?= $edit['errors'] ? plural(count($edit['errors']), 'field needs', 'fields need') . ' correcting. The list is checked again when you save the row.' : 'This row has no error. Change what you need and save the row.' ?></p></div></div>
  <div class="sheet-body"><div class="form-grid">
    <div class="field<?= $fc('taxpayer_type') ?>"><label for="e_type">Taxpayer type</label><?= View::select('taxpayer_type', ClientImport::typeOptions(), $sel('taxpayer_type'), 'id="e_type"', 'Choose') ?><?= $fe('taxpayer_type') ?></div>
    <div class="field<?= $fc('tin') ?>"><label for="e_tin">TIN *</label><input id="e_tin" name="tin" type="text" inputmode="numeric" value="<?= e($fv('tin')) ?>" placeholder="123-456-789"><?= $fe('tin') ?></div>
    <div class="field<?= $fc('rdo_code') ?>"><label for="e_rdo">RDO code</label><input id="e_rdo" name="rdo_code" type="text" maxlength="4" list="rdo-list" value="<?= e($fv('rdo_code')) ?>" placeholder="081"><?= $fe('rdo_code') ?></div>
    <div class="field half<?= $fc('registered_name') ?>"><label for="e_reg">Registered name (non-individual)</label><input id="e_reg" name="registered_name" type="text" value="<?= e($fv('registered_name')) ?>"><?= $fe('registered_name') ?></div>
    <div class="field<?= $fc('last_name') ?>"><label for="e_last">Last name (individual)</label><input id="e_last" name="last_name" type="text" value="<?= e($fv('last_name')) ?>"><?= $fe('last_name') ?></div>
    <div class="field<?= $fc('first_name') ?>"><label for="e_first">First name</label><input id="e_first" name="first_name" type="text" value="<?= e($fv('first_name')) ?>"><?= $fe('first_name') ?></div>
    <div class="field<?= $fc('middle_name') ?>"><label for="e_mid">Middle name</label><input id="e_mid" name="middle_name" type="text" value="<?= e($fv('middle_name')) ?>"><?= $fe('middle_name') ?></div>
    <div class="field half<?= $fc('trade_name') ?>"><label for="e_trade">Trade name</label><input id="e_trade" name="trade_name" type="text" value="<?= e($fv('trade_name')) ?>"><?= $fe('trade_name') ?></div>
    <div class="field half<?= $fc('address1') ?>"><label for="e_a1">Registered address</label><input id="e_a1" name="address1" type="text" value="<?= e($fv('address1')) ?>" placeholder="Unit, street, barangay"><?= $fe('address1') ?></div>
    <div class="field<?= $fc('address2') ?>"><label for="e_a2">City or municipality</label><input id="e_a2" name="address2" type="text" value="<?= e($fv('address2')) ?>"><?= $fe('address2') ?></div>
    <div class="field<?= $fc('zip_code') ?>"><label for="e_zip">ZIP code</label><input id="e_zip" name="zip_code" type="text" inputmode="numeric" maxlength="6" value="<?= e($fv('zip_code')) ?>"><?= $fe('zip_code') ?></div>
    <div class="field<?= $fc('classification') ?>"><label for="e_cls">Classification</label><?= View::select('classification', ClientImport::classOptions(), $sel('classification'), 'id="e_cls"', 'Choose') ?><?= $fe('classification') ?></div>
    <div class="field<?= $fc('vat_status') ?>"><label for="e_vat">VAT status</label><?= View::select('vat_status', ClientImport::vatOptions(), $sel('vat_status'), 'id="e_vat"', 'Choose') ?><?= $fe('vat_status') ?></div>
    <div class="field<?= $fc('reporting_cycle') ?>"><label for="e_cyc">Accounting period</label><?= View::select('reporting_cycle', ClientImport::cycleOptions(), $sel('reporting_cycle'), 'id="e_cyc"', 'Choose') ?><?= $fe('reporting_cycle') ?></div>
    <div class="field<?= $fc('fiscal_month_end') ?>"><label for="e_fm">Fiscal year ends (month)</label><input id="e_fm" name="fiscal_month_end" type="text" value="<?= e($fv('fiscal_month_end')) ?>" placeholder="1 to 12"><?= $fe('fiscal_month_end') ?></div>
    <div class="field<?= $fc('email') ?>"><label for="e_email">Email</label><input id="e_email" name="email" type="text" inputmode="email" value="<?= e($fv('email')) ?>"><?= $fe('email') ?></div>
    <div class="field<?= $fc('phone') ?>"><label for="e_phone">Phone</label><input id="e_phone" name="phone" type="text" value="<?= e($fv('phone')) ?>"><?= $fe('phone') ?></div>
  </div></div>
  <div class="sheet-foot">
    <button class="btn btn-danger" name="action" value="delete_row" formnovalidate data-confirm="Delete row <?= (int)$edit['row_no'] ?>? It will not be added as a client.">Delete this row</button>
    <a class="btn btn-quiet" href="<?= e(url('client_import', array('id' => $batch['id'], 'show' => $show === 'all' ? null : $show))) ?>#r<?= (int)$edit['id'] ?>">Cancel</a>
    <button class="btn btn-primary">Save row</button>
  </div>
</form>
<?php } ?>

<?php if ($open && $cnt['error']) { ?>
<div class="notice notice-error cimp-errnote"><div><strong><?= plural($cnt['error'], 'row has', 'rows have') ?> errors.</strong> Correct each one with <b>Edit</b>, or delete it so it is left out. The list can be saved once no row has an error.</div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_errors"><button class="btn btn-sm btn-danger" data-confirm="Delete the <?= (int)$cnt['error'] ?> rows with errors? They will not be added as clients.">Delete the <?= plural($cnt['error'], 'row') ?> with errors</button></form></div>
<?php } ?>

<div class="sheet">
  <div class="sheet-head"><h2><?= $open ? 'Clients in the list' : 'Clients saved' ?></h2>
    <?php if ($open) { ?><div class="btn-row"><?php foreach (array('all' => 'All ' . $cnt['total'], 'error' => 'Errors ' . $cnt['error'], 'warning' => 'Warnings ' . $cnt['warning'], 'ok' => 'Ready ' . $cnt['ok']) as $k => $l) { ?>
      <a class="btn btn-sm <?= $show === $k ? 'btn-primary' : 'btn-quiet' ?>" href="<?= e(url('client_import', array('id' => $batch['id'], 'show' => $k === 'all' ? null : $k))) ?>"><?= e($l) ?></a><?php } ?></div><?php } ?></div>
  <?php if (!$rows) { ?><div class="empty"><p><?= $show === 'error' ? 'No row has an error.' : ($show === 'warning' ? 'No row has a warning.' : 'No rows in this group.') ?></p></div><?php } else { ?>
  <div class="table-wrap"><table class="table table-stack cimp-table">
    <thead><tr><th>Row</th><th>Client</th><th>TIN</th><th>Tax profile</th><th>RDO</th><th>Address</th><th>Contact</th><th><?= $open ? 'Checks' : 'Saved as' ?></th><?php if ($open) { ?><th></th><?php } ?></tr></thead>
    <tbody><?php foreach ($rows as $r) { $d = $r['data']; list($c) = ClientImport::check($d); $nm = ClientImport::rowName($d);
      $clsText = array('regular' => 'regular', 'top_withholding_agent' => 'top withholding agent', 'large_taxpayer' => 'large taxpayer', 'government' => 'government');
      $profile = ($c['taxpayer_type'] === 'individual' ? 'Individual' : 'Non-individual') . ', ' . $clsText[$c['classification']] . ', ' . ($c['vat_status'] === 'vat' ? 'VAT' : 'non-VAT')
          . ', ' . ($c['reporting_cycle'] === 'fiscal' ? 'fiscal year to ' . date('F', mktime(0, 0, 0, (int)$c['fiscal_month_end'], 1)) : 'calendar year'); ?>
      <tr id="r<?= (int)$r['id'] ?>" class="<?= $r['status'] === 'error' ? 'row-error' : '' ?><?= $edit && (int)$edit['id'] === (int)$r['id'] ? ' hl' : '' ?>">
        <td data-label="Row" class="nowrap"><?= (int)$r['row_no'] ?><?= $r['edited'] ? '<span class="sub">Edited</span>' : '' ?></td>
        <td data-label="Client"><strong><?= $nm !== '' ? e($nm) : '<span class="muted">No name</span>' ?></strong><?= $d['trade_name'] !== '' ? '<span class="sub">' . e($d['trade_name']) . '</span>' : '' ?></td>
        <td data-label="TIN" class="nowrap"><?= !empty($c['tin']) ? e(Tin::display($c['tin'])) : e($d['tin']) ?></td>
        <td data-label="Tax profile" class="small"><?= e($profile) ?></td>
        <td data-label="RDO"><?= e($c['rdo_code'] ?: $d['rdo_code']) ?></td>
        <td data-label="Address" class="small"><?= e(trim(implode(', ', array_filter(array($d['address1'], $d['address2']), 'strlen')) . ' ' . $d['zip_code'])) ?></td>
        <td data-label="Contact" class="small cimp-contact"><?= e($d['email']) ?><?= $d['phone'] !== '' ? '<span class="sub">' . e($d['phone']) . '</span>' : '' ?></td>
        <td data-label="<?= $open ? 'Checks' : 'Saved as' ?>" class="small cimp-checks"><?php if ($r['status'] === 'saved') { ?><a href="<?= e(url('client_form', array('id' => $r['client_id']))) ?>">Client details</a><?php } else { ?><?= $msgs($r) ?: '<span class="ok-text">Ready</span>' ?><?php } ?></td>
        <?php if ($open) { ?><td class="actions">
          <a class="btn btn-sm <?= $r['status'] === 'error' ? '' : 'btn-quiet' ?>" href="<?= e(url('client_import', array('id' => $batch['id'], 'edit' => $r['id'], 'show' => $show === 'all' ? null : $show))) ?>#edit">Edit</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_row"><input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="show" value="<?= e($show === 'all' ? '' : $show) ?>">
            <button class="btn btn-sm btn-quiet btn-danger" data-confirm="Delete row <?= (int)$r['row_no'] ?>? It will not be added as a client.">Delete</button></form></td><?php } ?>
      </tr>
    <?php } ?></tbody></table></div>
  <?= View::pager(count($all), $page, $per, array('id' => $batch['id'], 'show' => $show === 'all' ? null : $show)) ?>
  <?php } ?>
</div>

<?php if ($open) { ?>
<div class="sheet cimp-save">
  <div class="sheet-body cimp-save-body">
    <div><h2><?= $cnt['error'] ? 'Correct the flagged rows to save' : 'Save ' . plural($cnt['ok'], 'client') ?></h2>
      <p class="small muted"><?= $cnt['error'] ? plural($cnt['error'], 'row still has', 'rows still have') . ' errors. Saving adds every row at once, so it stays off until each is corrected or deleted.'
          : 'Each client is added with its head office, branch 0000, and can be opened under Clients and branches to add other branches and signatories.' . ($cnt['warning'] ? ' Rows with warnings are saved as they are.' : '') ?></p></div>
    <div class="btn-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-quiet btn-danger" data-confirm="Discard this upload? No client will be added.">Discard upload</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><button class="btn btn-primary" <?= $cnt['error'] || !$cnt['ok'] ? 'disabled' : '' ?> data-confirm="Add <?= plural($cnt['ok'], 'client') ?> to <?= e(Ctx::org()['name']) ?>?"><?= View::icon('check') ?>Save <?= plural($cnt['ok'], 'client') ?></button></form>
    </div>
  </div>
</div>
<?php } ?>
<datalist id="rdo-list"><?php foreach (DB::all('SELECT code, name FROM rdo_codes ORDER BY code') as $rd) { ?><option value="<?= e($rd['code']) ?>"><?= e($rd['name']) ?></option><?php } ?></datalist>
<?php View::footer();
