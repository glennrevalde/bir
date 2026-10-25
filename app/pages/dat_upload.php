<?php
Ctx::requirePerm('dat.upload');
$client = Ctx::requireClient();
$stage = isset($_SESSION['dat_upload']) ? $_SESSION['dat_upload'] : null;

/** Period from the filename tokens and the spec's form frequency. */
function gsb_period_from_tokens($spec, $tok)
{
    $form = DB::row('SELECT * FROM form_types WHERE code = ?', array($spec['form_code']));
    if (!empty($tok['mmddyyyy'])) {
        $to = substr($tok['mmddyyyy'], 4, 4) . '-' . substr($tok['mmddyyyy'], 0, 2) . '-' . substr($tok['mmddyyyy'], 2, 2);
        return array(substr($to, 0, 4) . '-01-01', $to, 'Year ' . substr($to, 0, 4));
    }
    if (!empty($tok['mm']) && !empty($tok['yyyy'])) {
        $ym = $tok['yyyy'] . '-' . $tok['mm'];
        if ($form['frequency'] === 'return' && !empty($tok['sawt'])) {
            $sf = Datasets::sawtForms();
            $fq = isset($sf[$tok['sawt']]) ? $sf[$tok['sawt']][1] : 'quarterly';
            if ($fq === 'quarterly') {
                return array(date('Y-m-01', strtotime($ym . '-01 -2 months')), last_day($ym), 'Q' . ceil((int)$tok['mm'] / 3) . ' ' . $tok['yyyy']);
            }
            if ($fq === 'annual') {
                return array($tok['yyyy'] . '-01-01', last_day($ym), 'Year ' . $tok['yyyy']);
            }
        }
        return array($ym . '-01', last_day($ym), month_label($ym));
    }
    return array(null, null, 'Unknown period');
}

if (is_post()) {
    try {
        if (input('action') === 'upload') {
            $up = store_upload(isset($_FILES['dat']) ? $_FILES['dat'] : null, 'uploads/dat', array('dat', 'txt', 'csv'));
            $bytes = file_get_contents(storage_path($up['path']));
            $cands = DatReader::detect($up['name'], $bytes);
            $_SESSION['dat_upload'] = array('path' => $up['path'], 'name' => $up['name'], 'sha' => $up['sha256'], 'size' => $up['size'],
                'candidates' => array_map(function ($c) { return array('spec_id' => (int)$c['spec']['id'], 'tokens' => $c['tokens'], 'errors' => $c['errors']); }, $cands));
        } elseif (input('action') === 'confirm' && $stage) {
            $spec = Specs::load(input_int('spec_id'));
            if (!$spec) {
                throw new RuntimeException('Choose a layout.');
            }
            $bytes = file_get_contents(storage_path($stage['path']));
            $tok = array();
            preg_match(Specs::filenameRegex($spec['filename_pattern']), $stage['name'], $tok);
            if (!empty($tok['tin']) && $tok['tin'] !== $client['tin']) {
                throw new RuntimeException('The filename carries TIN ' . Tin::display($tok['tin']) . ', not this client (' . Tin::display($client['tin']) . '). Switch to the right client first.');
            }
            $parsed = DatReader::parse($bytes, $spec);
            $hdrTin = null;
            foreach (array('tin_wa', 'tin') as $k) {
                if (isset($parsed['header'][$k])) {
                    $hdrTin = trim($parsed['header'][$k], '"');
                }
            }
            if ($hdrTin && $hdrTin !== $client['tin']) {
                throw new RuntimeException('The header record is for TIN ' . Tin::display($hdrTin) . ', not this client.');
            }
            $branch = null;
            if (!empty($tok['branch'])) {
                $branch = DB::row('SELECT * FROM branches WHERE client_id = ? AND branch_code = ?', array($client['id'], $tok['branch']));
                if (!$branch) {
                    throw new RuntimeException('Branch ' . $tok['branch'] . ' does not exist for this client. Add it under Clients first.');
                }
            }
            list($from, $to, $label) = gsb_period_from_tokens($spec, $tok);
            if (!$from) {
                throw new RuntimeException('The period could not be read from the filename.');
            }
            $base = 0;
            $tax = 0;
            foreach ($parsed['rows'] as $r) {
                foreach (array('income', 'taxable', 'pres_gross') as $k) {
                    if (isset($r[$k])) {
                        $base += (int)$r[$k];
                        break;
                    }
                }
                foreach (array('tax', 'output_vat', 'input_tax', 'tax_withheld_adjusted') as $k) {
                    if (isset($r[$k])) {
                        $tax += (int)$r[$k];
                        break;
                    }
                }
            }
            $dup = DB::val('SELECT id FROM dat_files WHERE client_id = ? AND sha256 = ?', array($client['id'], $stage['sha']));
            if ($dup) {
                throw new RuntimeException('This exact file is already in the library as file #' . $dup . '.');
            }
            $issues = $parsed['issues'];
            list($e) = Checks::counts($issues);
            $rel = 'dat/' . $client['id'] . '/' . $spec['form_code'] . '/up-' . random_token(6) . '-' . basename($stage['name']);
            if (!is_dir(dirname(storage_path($rel)))) {
                mkdir(dirname(storage_path($rel)), 0775, true);
            }
            rename(storage_path($stage['path']), storage_path($rel));
            $fid = DB::insert('dat_files', array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => $branch ? $branch['id'] : null, 'run_id' => null,
                'form_code' => $spec['form_code'], 'schedule_code' => $spec['schedule_code'], 'variant_key' => !empty($tok['sawt']) ? $tok['sawt'] : null,
                'period_label' => $label, 'period_from' => $from, 'period_to' => $to, 'filename' => $stage['name'], 'storage_path' => $rel, 'byte_size' => $stage['size'],
                'sha256' => $stage['sha'], 'spec_id' => $spec['id'], 'origin' => 'uploaded', 'amended' => input('amended') ? 1 : 0,
                'status' => $e ? 'structure_failed' : 'pending_bir', 'file_label' => 'unvalidated_draft', 'record_count' => count($parsed['rows']),
                'total_base' => Money::toPlain($base), 'total_tax' => Money::toPlain($tax), 'parse_summary' => json_encode(array('lines' => $parsed['line_count'], 'eol' => $parsed['line_ending'])),
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()));
            Checks::record($fid, null, 2, $issues, 'Structure of uploaded file: ' . $e . ' errors');
            Audit::log('dat.upload', 'dat_files', $fid, 'Uploaded ' . $stage['name'] . ' as ' . $spec['form_code'] . ' ' . $spec['schedule_code']);
            unset($_SESSION['dat_upload']);
            flash($e ? 'warning' : 'success', 'Uploaded ' . $stage['name'] . ($e ? ' with ' . $e . ' structure errors.' : '. Structure checks passed.'));
            redirect(url('file_view', array('id' => $fid)));
        } elseif (input('action') === 'cancel') {
            if ($stage && is_file(storage_path($stage['path']))) {
                @unlink(storage_path($stage['path']));
            }
            unset($_SESSION['dat_upload']);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('dat_upload'));
}
$specs = DB::all('SELECT id, form_code, schedule_code, variant_key, version_label, status FROM layout_specs WHERE status <> ? ORDER BY form_code, schedule_code, id', array('retired'));
View::header('Upload an existing DAT');
View::pageBar('Upload an existing DAT', View::clientContext('files made earlier or by other software'));
?>
<div class="cols cols-main">
<?php if (!$stage) { ?>
  <form method="post" enctype="multipart/form-data" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="sheet-head"><div><h2>Choose a file</h2><p>Keep the original filename; the TIN, branch and period are read from it.</p></div></div>
    <div class="sheet-body"><div class="field"><label for="dat">DAT file</label><input id="dat" name="dat" type="file" accept=".dat,.DAT,.txt,.csv" required></div></div>
    <div class="sheet-foot"><button class="btn btn-primary"><?= View::icon('upload') ?>Upload and detect layout</button></div>
  </form>
<?php } else { ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
    <div class="sheet-head"><div><h2><?= e($stage['name']) ?></h2><p><?= e(human_bytes($stage['size'])) ?>, SHA-256 <?= e(substr($stage['sha'], 0, 16)) ?>...</p></div></div>
    <div class="sheet-body stack">
      <?php if ($stage['candidates']) { ?>
        <p>Matching layouts, best first:</p>
        <?php foreach ($stage['candidates'] as $i => $c) { $s = Specs::load($c['spec_id']); ?>
          <label class="check"><input type="radio" name="spec_id" value="<?= (int)$s['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>> <?= e($s['form_code'] . ' ' . $s['schedule_code'] . ($s['variant_key'] ? ' ' . $s['variant_key'] : '') . ': ' . $s['version_label']) ?>
            <span class="small muted"><?= $c['errors'] ? $c['errors'] . ' structure errors' : 'parses cleanly' ?></span></label>
        <?php } ?>
      <?php } else { ?>
        <div class="callout warn">No layout recognised the filename and first record. Pick one to parse it anyway.</div>
        <div class="field"><label for="spec_id">Layout</label><select id="spec_id" name="spec_id"><?php foreach ($specs as $s) { ?><option value="<?= (int)$s['id'] ?>"><?= e($s['form_code'] . ' ' . $s['schedule_code'] . ' ' . $s['variant_key'] . ': ' . $s['version_label'] . ' (' . $s['status'] . ')') ?></option><?php } ?></select></div>
      <?php } ?>
      <label class="check"><input type="checkbox" name="amended" value="1"> This is an amended file</label>
    </div>
    <div class="sheet-foot"><button class="btn" name="action" value="cancel" formnovalidate>Cancel</button><button class="btn btn-primary">Add to library</button></div>
  </form>
<?php } ?>
  <div class="sheet"><div class="sheet-body prose small">
    <p>Uploaded files are parsed with the registered layout and checked for structure. They can then be used as sources for the SRS and 1604-E Schedule 4 builder, compared with your records, or imported into withholding records.</p>
    <p>An uploaded file never counts as officially validated until you record the BIR validator report for it.</p>
  </div></div>
</div>
<?php View::footer();
