<?php
Ctx::requirePerm('reference.view');
$spec = Specs::load(input_int('id'));
if (!$spec) {
    deny('Layout not found.');
}
$id = (int)$spec['id'];
$isDraft = $spec['status'] === 'draft';
if (is_post()) {
    $action = input('action');
    try {
        Ctx::requirePerm('reference.manage');
        if ($action === 'clone') {
            $nid = Specs::cloneAsDraft($id, trim((string)input('label')) ?: $spec['version_label'] . ' (revised)');
            flash('success', 'Draft copy created. Edit its fields, then validate a file made with it.');
            redirect(url('spec_view', array('id' => $nid)));
        }
        if (!$isDraft && !in_array($action, array('retire', 'request'), true)) {
            throw new RuntimeException('Only draft layouts can be edited. Make a draft copy first.');
        }
        if ($action === 'meta') {
            $d = array('version_label' => mb_substr(trim((string)input('version_label')), 0, 40), 'filename_pattern' => mb_substr(trim((string)input('filename_pattern')), 0, 120),
                'line_ending' => input('line_ending') === 'LF' ? 'LF' : 'CRLF', 'trailing_newline' => input('trailing_newline') ? 1 : 0,
                'encoding' => in_array(input('encoding'), array('Windows-1252', 'ASCII', 'UTF-8'), true) ? input('encoding') : 'Windows-1252',
                'effective_from' => Records::parseDate(input('effective_from')) ?: $spec['effective_from'], 'effective_to' => Records::parseDate(input('effective_to')) ?: null,
                'confidence' => in_array(input('confidence'), array('official', 'validated', 'medium', 'low', 'analog'), true) ? input('confidence') : $spec['confidence'],
                'source_ref' => trim((string)input('source_ref')), 'notes' => trim((string)input('notes')), 'updated_at' => now());
            if ($d['filename_pattern'] === '') {
                throw new RuntimeException('Filename pattern is required.');
            }
            DB::update('layout_specs', $d, 'id = ?', array($id));
            Audit::log('spec.update', 'layout_specs', $id, 'Edited layout settings', null, $d);
        } elseif ($action === 'field') {
            $fid = input_int('field_id');
            $rid = input_int('record_id');
            if (!DB::val('SELECT id FROM spec_records WHERE id = ? AND spec_id = ?', array($rid, $id))) {
                throw new RuntimeException('Record not found.');
            }
            $d = array('field_key' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)input('field_key'))), 'label' => mb_substr(trim((string)input('label')), 0, 120),
                'source_expr' => trim((string)input('source_expr')), 'data_type' => in_array(input('data_type'), array('text', 'code', 'digits', 'amount', 'rate', 'date', 'int', 'raw'), true) ? input('data_type') : 'raw',
                'max_len' => input('max_len') === '' ? null : input_int('max_len'), 'required' => input('required') ? 1 : 0,
                'quote_mode' => in_array(input('quote_mode'), array('always', 'nonempty', 'never'), true) ? input('quote_mode') : 'never',
                'fmt' => trim((string)input('fmt')) ?: null, 'confidence' => in_array(input('confidence'), array('official', 'validated', 'medium', 'low', 'analog'), true) ? input('confidence') : 'low',
                'notes' => mb_substr(trim((string)input('notes')), 0, 255) ?: null);
            if ($d['field_key'] === '' || $d['source_expr'] === '') {
                throw new RuntimeException('Field key and source expression are required.');
            }
            if (!preg_match('/^(lit:.*|tpl:.+|agent\.\w+|row\.\w+|sum\.\w+|period\.(mm_yyyy|mm_dd_yyyy_end|yyyy|mm)|seq|blank|ctx\.\w+)$/', $d['source_expr'])) {
                throw new RuntimeException('Source expression not recognised. Use lit:, tpl:, agent., row., sum., period., seq or blank.');
            }
            if ($fid) {
                DB::update('spec_fields', $d, 'id = ? AND spec_record_id = ?', array($fid, $rid));
            } else {
                $d['spec_record_id'] = $rid;
                $d['position'] = (int)DB::val('SELECT COALESCE(MAX(position), 0) + 1 FROM spec_fields WHERE spec_record_id = ?', array($rid));
                $after = input('after_position');
                if ($after !== null && $after !== '') {
                    $pos = (int)$after + 1;
                    DB::q('UPDATE spec_fields SET position = position + 1000 WHERE spec_record_id = ? AND position >= ?', array($rid, $pos));
                    DB::q('UPDATE spec_fields SET position = position - 999 WHERE spec_record_id = ? AND position >= ?', array($rid, $pos + 1000));
                    $d['position'] = $pos;
                }
                DB::insert('spec_fields', $d);
            }
            Audit::log('spec.field', 'layout_specs', $id, ($fid ? 'Edited' : 'Added') . ' field ' . $d['field_key'], null, $d);
        } elseif ($action === 'delete_field') {
            $f = DB::row('SELECT f.* FROM spec_fields f JOIN spec_records r ON r.id = f.spec_record_id WHERE f.id = ? AND r.spec_id = ?', array(input_int('field_id'), $id));
            if ($f) {
                DB::q('DELETE FROM spec_fields WHERE id = ?', array($f['id']));
                DB::q('UPDATE spec_fields SET position = position - 1 WHERE spec_record_id = ? AND position > ? ORDER BY position', array($f['spec_record_id'], $f['position']));
                Audit::log('spec.field_delete', 'layout_specs', $id, 'Removed field ' . $f['field_key']);
            }
        } elseif ($action === 'request') {
            $note = trim((string)input('reason'));
            if ($note === '') {
                throw new RuntimeException('Cite the official validator evidence or BIR annex that supports this layout.');
            }
            DB::update('layout_specs', array('status' => $spec['status'] === 'draft' ? 'pending_verification' : $spec['status'], 'updated_at' => now()), 'id = ?', array($id));
            Workflow::request('layout_specs', $id, 'approve_spec', $note);
            flash('success', 'Approval requested.');
        } elseif ($action === 'retire') {
            Ctx::requirePerm('reference.approve');
            DB::update('layout_specs', array('status' => 'retired', 'effective_to' => Records::parseDate(input('effective_to')) ?: today(), 'updated_at' => now()), 'id = ?', array($id));
            Audit::log('spec.retire', 'layout_specs', $id, 'Retired layout');
        }
        Specs::forget($id);
        if (!isset($_SESSION['_flash'])) {
            flash('success', 'Saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('spec_view', array('id' => $id)));
}
$editField = input_int('edit');
$can = Ctx::can('reference.manage');
$files = DB::all('SELECT id, filename, status FROM dat_files WHERE spec_id = ? ORDER BY id DESC LIMIT 10', array($id));
$approver = $spec['approved_by'] ? DB::val('SELECT full_name FROM users WHERE id = ?', array($spec['approved_by'])) : null;
View::header('Layout #' . $id, array('page' => 'spec_view'));
View::pageBar($spec['form_code'] . ' ' . $spec['schedule_code'] . ($spec['variant_key'] ? ' ' . $spec['variant_key'] : '') . ': ' . $spec['version_label'], 'Layout #' . $id . ', ' . View::status($spec['status']),
    '<a class="btn btn-quiet" href="' . e(url('specs')) . '">All layouts</a>');
?>
<?php if ($spec['confidence'] === 'analog') { ?><div class="notice notice-warning">Research pending: no published field table backed this layout at build time. It exists so the workflow can be exercised; files made with it are always unvalidated drafts.</div><?php } ?>
<div class="cols cols-main">
<div>
  <?php foreach ($spec['records'] as $rec) { ?>
  <div class="sheet">
    <div class="sheet-head"><div><h2><?= e($rec['record_code']) ?>: <?= e($rec['label']) ?></h2><p><?= e(ucfirst($rec['role'])) ?> record, <?= count($rec['fields']) ?> fields</p></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Field</th><th>Source</th><th>Type</th><th class="num">Max</th><th>Quote</th><th>Evidence</th><?= $isDraft && $can ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($rec['fields'] as $f) { ?>
      <tr><td><?= (int)$f['position'] ?></td><td><strong><?= e($f['label']) ?></strong><span class="sub mono"><?= e($f['field_key']) ?><?= (int)$f['required'] ? ', required' : '' ?></span><?= $f['notes'] ? '<span class="sub">' . e($f['notes']) . '</span>' : '' ?></td>
        <td class="mono small"><?= e($f['source_expr']) ?></td><td><?= e($f['data_type']) ?><?= $f['fmt'] ? '<span class="sub">' . e($f['fmt']) . '</span>' : '' ?></td>
        <td class="num"><?= $f['max_len'] !== null ? (int)$f['max_len'] : '' ?></td><td><?= e($f['quote_mode']) ?></td>
        <td class="conf conf-<?= e($f['confidence']) ?>"><?= e(Specs::confidenceLabel($f['confidence'])) ?></td>
        <?php if ($isDraft && $can) { ?><td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('spec_view', array('id' => $id, 'edit' => $f['id']))) ?>#fieldform">Edit</a></td><?php } ?></tr>
    <?php } ?></tbody></table></div>
  </div>
  <?php } ?>
  <?php if ($isDraft && $can) { $ef = $editField ? DB::row('SELECT f.* FROM spec_fields f JOIN spec_records r ON r.id = f.spec_record_id WHERE f.id = ? AND r.spec_id = ?', array($editField, $id)) : null;
      $recOpts = array(); foreach ($spec['records'] as $r) { $recOpts[$r['id']] = $r['record_code'] . ' (' . $r['role'] . ')'; } ?>
  <form method="post" class="sheet" id="fieldform"><?= csrf_field() ?><input type="hidden" name="action" value="field"><input type="hidden" name="field_id" value="<?= $ef ? (int)$ef['id'] : 0 ?>">
    <div class="sheet-head"><h2><?= $ef ? 'Edit field ' . e($ef['field_key']) : 'Add a field' ?></h2></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field"><label for="record_id">Record</label><?= View::select('record_id', $recOpts, $ef ? $ef['spec_record_id'] : '', 'id="record_id"') ?></div>
      <?php if (!$ef) { ?><div class="field"><label for="after_position">Insert after position</label><input id="after_position" name="after_position" type="number" min="0" placeholder="End of record"></div><?php } ?>
      <div class="field"><label for="field_key">Key</label><input id="field_key" name="field_key" type="text" value="<?= e($ef ? $ef['field_key'] : '') ?>" required></div>
      <div class="field half"><label for="label">Label</label><input id="label" name="label" type="text" value="<?= e($ef ? $ef['label'] : '') ?>" required></div>
      <div class="field half"><label for="source_expr">Source expression</label><input id="source_expr" name="source_expr" type="text" class="mono" value="<?= e($ef ? $ef['source_expr'] : '') ?>" required><span class="hint">lit:X, tpl:H{sawt_form}, agent.tin, row.income, sum.tax, period.mm_yyyy, seq, blank</span></div>
      <div class="field"><label for="data_type">Type</label><?= View::select('data_type', array_combine(array('text', 'code', 'digits', 'amount', 'rate', 'date', 'int', 'raw'), array('text', 'code', 'digits', 'amount', 'rate', 'date', 'int', 'raw')), $ef ? $ef['data_type'] : 'raw', 'id="data_type"') ?></div>
      <div class="field"><label for="fmt">Format</label><input id="fmt" name="fmt" type="text" value="<?= e($ef ? $ef['fmt'] : '') ?>" placeholder="2dp, trim, pct2, frac2, name, addr"></div>
      <div class="field"><label for="max_len">Max length</label><input id="max_len" name="max_len" type="number" value="<?= e($ef ? $ef['max_len'] : '') ?>"></div>
      <div class="field"><label for="quote_mode">Quote</label><?= View::select('quote_mode', array('never' => 'Never', 'always' => 'Always', 'nonempty' => 'When not empty'), $ef ? $ef['quote_mode'] : 'never', 'id="quote_mode"') ?></div>
      <div class="field"><label for="fconf">Evidence</label><?= View::select('confidence', array('official' => 'Official annex', 'validated' => 'Validator-passed sample', 'medium' => 'Two or more sources', 'low' => 'Single source', 'analog' => 'Unsourced'), $ef ? $ef['confidence'] : 'low', 'id="fconf"') ?></div>
      <div class="field half"><label for="fnotes">Notes</label><input id="fnotes" name="notes" type="text" value="<?= e($ef ? $ef['notes'] : '') ?>"></div>
      <div class="field"><label class="check"><input type="checkbox" name="required" value="1" <?= $ef && $ef['required'] ? 'checked' : '' ?>> Required</label></div>
    </div></div>
    <div class="sheet-foot"><?php if ($ef) { ?><button class="btn btn-danger" name="action" value="delete_field" data-confirm="Remove this field?" formnovalidate>Remove field</button><a class="btn btn-quiet" href="<?= e(url('spec_view', array('id' => $id))) ?>">Cancel</a><?php } ?><button class="btn btn-primary">Save field</button></div>
  </form>
  <?php } ?>
</div>
<div>
  <div class="sheet"><div class="sheet-head"><h2>Layout</h2></div><div class="sheet-body"><dl class="kv">
    <dt>Status</dt><dd><?= View::status($spec['status']) ?><?= $approver ? ', approved by ' . e($approver) : '' ?></dd>
    <dt>Evidence</dt><dd class="conf conf-<?= e($spec['confidence']) ?>"><?= e(Specs::confidenceLabel($spec['confidence'])) ?></dd>
    <dt>Filename</dt><dd class="mono small"><?= e($spec['filename_pattern']) ?></dd>
    <dt>Bytes</dt><dd><?= e($spec['encoding']) ?>, <?= e($spec['line_ending']) ?>, delimiter "<?= e($spec['delimiter']) ?>"<?= (int)$spec['trailing_newline'] ? ', ends with a line break' : '' ?><?= (int)$spec['column_header_row'] ? ', column titles row' : '' ?></dd>
    <dt>Effective</dt><dd><?= e(fmt_date($spec['effective_from'], 'm/d/Y')) ?><?= $spec['effective_to'] ? ' to ' . e(fmt_date($spec['effective_to'], 'm/d/Y')) : ' onward' ?></dd>
    <dt>Validator</dt><dd><?= e($spec['validator_version']) ?></dd>
    <dt>Sources</dt><dd class="small"><?= e($spec['source_ref']) ?></dd>
    <?php if ($spec['notes']) { ?><dt>Notes</dt><dd class="small"><?= e($spec['notes']) ?></dd><?php } ?>
  </dl></div>
  <?php if ($can) { ?><div class="sheet-foot">
    <form method="post" class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="clone"><button class="btn">Make a draft copy</button></form>
    <?php if ($spec['status'] !== 'approved' && $spec['status'] !== 'retired') { ?><form method="post" data-prompt="Cite the official validator evidence or annex for this layout"><?= csrf_field() ?><input type="hidden" name="action" value="request"><button class="btn btn-primary">Request approval</button></form><?php } ?>
  </div><?php } ?></div>
  <?php if ($isDraft && $can) { ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="meta">
    <div class="sheet-head"><h2>Settings</h2></div>
    <div class="sheet-body stack">
      <div class="field"><label for="version_label">Version label</label><input id="version_label" name="version_label" type="text" value="<?= e($spec['version_label']) ?>"></div>
      <div class="field"><label for="filename_pattern">Filename pattern</label><input id="filename_pattern" name="filename_pattern" class="mono" type="text" value="<?= e($spec['filename_pattern']) ?>"><span class="hint">{TIN} {BRANCH} {MM} {YYYY} {MMDDYYYY} {SAWT_FORM} {SEM}</span></div>
      <div class="field"><label for="encoding">Encoding</label><?= View::select('encoding', array('Windows-1252' => 'Windows-1252', 'ASCII' => 'ASCII', 'UTF-8' => 'UTF-8'), $spec['encoding'], 'id="encoding"') ?></div>
      <div class="field"><label for="line_ending">Line ending</label><?= View::select('line_ending', array('CRLF' => 'CRLF (Windows)', 'LF' => 'LF'), $spec['line_ending'], 'id="line_ending"') ?></div>
      <label class="check"><input type="checkbox" name="trailing_newline" value="1" <?= (int)$spec['trailing_newline'] ? 'checked' : '' ?>> Last line ends with a line break</label>
      <div class="field"><label for="effective_from">Effective from</label><input id="effective_from" name="effective_from" type="date" value="<?= e($spec['effective_from']) ?>"></div>
      <div class="field"><label for="effective_to">Effective until</label><input id="effective_to" name="effective_to" type="date" value="<?= e($spec['effective_to']) ?>"></div>
      <div class="field"><label for="sconf">Overall evidence</label><?= View::select('confidence', array('official' => 'Official annex', 'validated' => 'Validator-passed sample', 'medium' => 'Two or more sources', 'low' => 'Single source', 'analog' => 'Unsourced'), $spec['confidence'], 'id="sconf"') ?></div>
      <div class="field"><label for="source_ref">Sources</label><textarea id="source_ref" name="source_ref"><?= e($spec['source_ref']) ?></textarea></div>
      <div class="field"><label for="snotes">Notes</label><textarea id="snotes" name="notes"><?= e($spec['notes']) ?></textarea></div>
    </div>
    <div class="sheet-foot"><button class="btn btn-primary">Save settings</button></div>
  </form>
  <?php } ?>
  <div class="sheet"><div class="sheet-head"><h2>Files made with it</h2></div><ul class="queue">
    <?php foreach ($files as $f) { ?><li><div class="q-main"><a class="mono" href="<?= e(url('file_view', array('id' => $f['id']))) ?>"><?= e($f['filename']) ?></a><div class="q-sub"><?= View::status($f['status']) ?></div></div></li><?php } ?>
    <?php if (!$files) { ?><li class="muted">None yet.</li><?php } ?></ul></div>
</div>
</div>
<?php View::footer();
