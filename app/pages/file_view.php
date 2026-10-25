<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$f = DB::row('SELECT d.*, b.branch_code, b.name AS branch_name, u.full_name AS creator, a.full_name AS approver FROM dat_files d LEFT JOIN branches b ON b.id = d.branch_id
    LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users a ON a.id = d.approved_by WHERE d.id = ? AND d.client_id = ?', array(input_int('id'), $client['id']));
if (!$f) {
    deny('File not found for this client.');
}
$id = (int)$f['id'];
if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'evidence') {
            Ctx::requirePerm('validation.record');
            Evidence::record($id, $_POST, isset($_FILES['report']) ? $_FILES['report'] : null, isset($_FILES['validated_dat']) ? $_FILES['validated_dat'] : null);
            flash('success', 'Validator result recorded. A second person must review the evidence.');
        } elseif ($action === 'review') {
            Ctx::requirePerm('validation.review');
            Evidence::review(input_int('evidence_id'), input('decision') === 'accept' ? 'accept' : 'reject', trim((string)input('note')));
            flash('success', 'Review saved.');
        } elseif ($action === 'request_approval') {
            Ctx::requirePerm('approvals.request');
            Workflow::request('dat_files', $id, 'approve_file', trim((string)input('reason')) ?: 'Ready for filing', array('filename' => $f['filename']));
            flash('success', 'Approval requested.');
        } elseif ($action === 'regenerate') {
            Ctx::requirePerm('reports.generate');
            $nid = Reports::regenerate($id);
            flash('success', 'Re-processed with the current layout. The previous file is superseded and its validation no longer applies.');
            redirect(url('file_view', array('id' => $nid)));
        } elseif ($action === 'filing') {
            Ctx::requirePerm('filings.record');
            Workflow::recordFiling($id, $_POST, isset($_FILES['ack']) ? $_FILES['ack'] : null);
            flash('success', 'Submission recorded.');
        } elseif ($action === 'promote_spec') {
            Ctx::requirePerm('reference.manage');
            $ev = Evidence::latest($id);
            if (!$ev || $ev['outcome'] !== 'success' || $ev['review_decision'] !== 'accepted') {
                throw new RuntimeException('Layout approval needs accepted official validator evidence for this file.');
            }
            Workflow::request('layout_specs', $f['spec_id'], 'approve_spec', 'Validator ' . $ev['validator_version'] . ' accepted ' . $f['filename'], array('evidence_id' => $ev['id'], 'file_id' => $id));
            flash('success', 'Layout approval requested with this evidence.');
        } elseif ($action === 'import_rows') {
            Ctx::requirePerm('records.edit');
            $n = gsb_import_dat_rows($f, $client);
            flash('success', 'Imported ' . plural($n, 'withholding record') . ' from this file.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('file_view', array('id' => $id)));
}

/** Copy detail rows of an uploaded QAP/MAP/SAWT file into withholding records (provenance prior_dat). */
function gsb_import_dat_rows($f, $client)
{
    if ($f['origin'] !== 'uploaded') {
        throw new RuntimeException('Only uploaded files can be imported into records.');
    }
    if (DB::val('SELECT COUNT(*) FROM withholding_entries WHERE client_id = ? AND source = ? AND reference_no = ?', array($client['id'], 'prior_dat', 'DAT#' . $f['id']))) {
        throw new RuntimeException('Rows from this file were already imported.');
    }
    $map = array('1601EQ' => array('agent', 'EWT'), '1601FQ' => array('agent', 'FWT'), '1600VT' => array('agent', 'GVAT'), '1600PT' => array('agent', 'GPT'), 'SAWT' => array('payee', 'EWT'));
    if (!isset($map[$f['form_code']])) {
        throw new RuntimeException('Only QAP, MAP and SAWT files can be imported into withholding records.');
    }
    list($dir, $kind) = $map[$f['form_code']];
    $spec = Specs::load($f['spec_id']);
    $parsed = DatReader::parse((string)Reports::storedBytes($f), $spec);
    $branchId = $f['branch_id'] ?: (int)Ctx::headOffice()['id'];
    $tag = $f['schedule_code'] === 'S3' ? 'C' : ($f['schedule_code'] === 'S2' ? 'B' : 'A');
    $month = substr($f['period_to'], 0, 7);
    if (Records::lockedPeriod($client['id'], $month, 'withholding')) {
        throw new RuntimeException(month_label($month) . ' is locked.');
    }
    return DB::tx(function () use ($parsed, $client, $dir, $kind, $branchId, $tag, $month, $f) {
        $n = 0;
        foreach ($parsed['rows'] as $r) {
            $p = array('tin' => $r['tin'] ?: null, 'branch_code' => $r['branch'] ?: '0000', 'entity_type' => !empty($r['registered_name']) ? 'non_individual' : 'individual',
                'registered_name' => !empty($r['registered_name']) ? $r['registered_name'] : null, 'last_name' => !empty($r['last_name']) ? $r['last_name'] : null,
                'first_name' => !empty($r['first_name']) ? $r['first_name'] : null, 'middle_name' => !empty($r['middle_name']) ? $r['middle_name'] : null);
            $cp = Records::resolveCounterparty($client['id'], $p, $dir === 'payee' ? 'payor' : 'payee', 'prior_dat', null);
            $d = array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => $branchId, 'counterparty_id' => $cp, 'direction' => $dir, 'tax_kind' => $kind,
                'atc_code' => $r['atc'], 'payment_date' => last_day($month), 'period_month' => $month, 'income_amount' => Money::toPlain((int)$r['income']),
                'tax_rate' => $r['rate'], 'tax_withheld' => Money::toPlain((int)$r['tax']), 'schedule_tag' => $tag, 'reference_no' => 'DAT#' . $f['id'],
                'nature_of_payment' => 'Imported from ' . $f['filename'], 'status' => 'posted', 'source' => 'prior_dat', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now());
            $d['row_hash'] = sha1('prior|' . $f['id'] . '|' . $n);
            DB::insert('withholding_entries', $d);
            $n++;
        }
        Audit::log('dat.import_rows', 'dat_files', $f['id'], 'Imported ' . $n . ' rows into withholding records');
        return $n;
    });
}

$spec = $f['spec_id'] ? Specs::load($f['spec_id']) : null;
$bytes = (string)Reports::storedBytes($f);
$parsed = $spec ? DatReader::parse($bytes, $spec) : null;
$levels = array();
foreach (DB::all('SELECT * FROM validation_runs WHERE dat_file_id = ? ORDER BY id DESC', array($id)) as $vr) {
    if (!isset($levels[$vr['level']])) {
        $vr['issues'] = DB::all('SELECT * FROM validation_errors WHERE validation_run_id = ? ORDER BY severity, id LIMIT 300', array($vr['id']));
        $levels[$vr['level']] = $vr;
    }
}
$evidence = DB::all('SELECT e.*, u.full_name AS uploader, r.full_name AS reviewer FROM official_validation_evidence e LEFT JOIN users u ON u.id = e.uploaded_by
    LEFT JOIN users r ON r.id = e.reviewed_by WHERE e.dat_file_id = ? ORDER BY e.id DESC', array($id));
$filings = DB::all('SELECT fr.*, u.full_name FROM filing_records fr LEFT JOIN users u ON u.id = fr.recorded_by WHERE fr.dat_file_id = ? ORDER BY fr.id DESC', array($id));
$pendingAppr = DB::row('SELECT * FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND decision = ? LIMIT 1', array('dat_files', $id, 'pending'));
$latestEv = $evidence ? $evidence[0] : null;
$run = $f['run_id'] ? DB::row('SELECT r.*, u.full_name FROM report_runs r LEFT JOIN users u ON u.id = r.prepared_by WHERE r.id = ?', array($f['run_id'])) : null;

$steps = array('Prepared', 'Structure checked', 'Pending BIR validation', 'Officially validated', 'Approved', 'Submitted');
// $at is the step in progress (earlier steps are done); a failed step is marked instead.
$pos = array('structure_failed' => 2, 'pending_bir' => 3, 'bir_failed' => 4, 'bir_validated' => 5, 'approved' => 6, 'submitted' => 7, 'superseded' => 3);
$at = isset($pos[$f['status']]) ? $pos[$f['status']] : 2;
$failAt = $f['status'] === 'structure_failed' ? 2 : ($f['status'] === 'bir_failed' ? 4 : -1);

$isDraft = $f['file_label'] !== 'submission_ready' && !in_array($f['status'], array('bir_validated', 'approved', 'submitted'), true);
$canDl = $isDraft ? Ctx::can('dat.export_draft') : Ctx::can('dat.download');
$actions = '';
if ($canDl && $bytes !== '') {
    $actions .= '<a class="btn btn-primary" href="' . e(url('file_download', array('id' => $id))) . '"' . ($isDraft ? ' onclick="return confirm(\'This file is an unvalidated draft. Download it for inspection or for running the official validator?\')"' : '') . '>' . View::icon('download') . 'Download DAT</a>';
}
View::header($f['filename'], array('page' => 'file_view'));
View::pageBar($f['filename'], View::clientContext(e($f['form_code'] . ' ' . $f['schedule_code'] . ($f['variant_key'] ? ' (' . $f['variant_key'] . ')' : '') . ', ' . $f['period_label'] . ($f['branch_code'] ? ', branch ' . $f['branch_code'] : ''))), $actions);
?>
<?php if ($f['status'] === 'superseded') { ?><div class="notice notice-warning">This file was superseded<?= $f['superseded_by'] ? ' by <a href="' . e(url('file_view', array('id' => $f['superseded_by']))) . '">file #' . (int)$f['superseded_by'] . '</a>' : '' ?>. Any validation recorded here does not apply to the newer file.</div><?php } ?>
<?php if ($isDraft && $f['status'] !== 'superseded') { ?><div class="notice notice-warning"><div><strong>Unvalidated draft.</strong> <?= $spec && $spec['status'] !== 'approved' ? 'Its layout is ' . e(str_replace('_', ' ', $spec['status'])) . '. ' : '' ?>Run it through the official BIR validator and record the result below. Only the validator's report can mark it officially validated.</div></div><?php } ?>

<div class="sheet"><ol class="track" aria-label="File status">
  <?php foreach ($steps as $i => $s) { $n = $i + 1; $cls = $n === $failAt ? 'fail' : ($n < $at ? 'done' : ($n === $at ? 'now' : '')); ?><li class="<?= $cls ?>"><?= e($s) ?></li><?php } ?>
</ol></div>

<div class="cols cols-main">
<div>
  <?php foreach (array(1 => 'Level 1: data quality', 2 => 'Level 2: DAT structure', 3 => 'Level 3: official BIR validator') as $lv => $title) { $vr = isset($levels[$lv]) ? $levels[$lv] : null; ?>
    <div class="sheet">
      <div class="sheet-head"><div><h2><?= e($title) ?></h2><p><?= $vr ? e($vr['summary']) . ', ' . e(fmt_dt($vr['created_at'])) : ($lv === 3 ? 'No validator report recorded yet' : 'Not run') ?></p></div><?= $vr ? View::status($vr['result']) : ($lv === 3 ? View::status('pending') : '') ?></div>
      <?php if ($vr && $vr['issues']) { ?>
        <details <?= $vr['error_count'] ? 'open' : '' ?>><summary class="sheet-body small" style="cursor:pointer;padding:10px 18px"><?= plural($vr['error_count'], 'error') ?>, <?= plural($vr['warning_count'], 'warning') ?></summary>
          <ul class="issues"><?php foreach ($vr['issues'] as $i) { ?><li class="<?= e($i['severity']) ?>"><?= e($i['message']) ?><?php if ($i['record_no']) { ?><span class="where"><?= $lv === 3 ? 'line' : 'record' ?> <?= (int)$i['record_no'] ?></span><?php } ?></li><?php } ?></ul></details>
      <?php } ?>
    </div>
  <?php } ?>

  <div class="sheet">
    <div class="sheet-head"><h2>Contents</h2><div class="btn-row"><button type="button" class="btn btn-sm btn-primary" data-toggle-view="raw">Raw bytes</button><button type="button" class="btn btn-sm" data-toggle-view="fields">Fields</button></div></div>
    <div data-view="raw"><div class="dat-raw"><?php foreach (preg_split('/\r\n|\n/', rtrim($bytes, "\r\n")) as $ln) { $c = substr($ln, 0, 1); ?><div class="<?= $c === 'H' ? 'd-h' : ($c === 'C' ? 'd-c' : '') ?>"><?= e($ln) ?></div><?php } ?></div></div>
    <div data-view="fields" hidden>
      <?php if ($parsed) { foreach (array_slice($parsed['records'], 0, 60) as $rec) { $sr = null; foreach ($spec['records'] as $x) { if ($x['record_code'] === $rec['record_code']) { $sr = $x; } } ?>
        <details class="sheet-body" style="border-bottom:1px solid var(--line)"><summary><strong>Line <?= (int)$rec['line'] ?></strong> <?= e($rec['record_code']) ?> (<?= e($rec['role']) ?>), <?= (int)$rec['field_count'] ?> fields</summary>
          <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Field</th><th>Value</th></tr></thead><tbody>
          <?php foreach ($sr['fields'] as $i => $fd) { ?><tr><td><?= (int)$fd['position'] ?></td><td><?= e($fd['label']) ?></td><td class="mono"><?= e(isset($rec['fields'][$fd['field_key']]) ? $rec['fields'][$fd['field_key']] : '') ?></td></tr><?php } ?>
          </tbody></table></div></details>
      <?php } if (count($parsed['records']) > 60) { echo '<p class="sheet-body small muted">Showing the first 60 records.</p>'; } } ?>
    </div>
  </div>
</div>

<div>
  <div class="sheet">
    <div class="sheet-head"><h2>Official validation</h2></div>
    <div class="sheet-body small prose">
      <ol><li>Download this exact file. Do not open and save it in Excel.</li>
        <li>Validate it in the BIR module (<?= e(sys_config('current_validator', 'current module')) ?>, <?= e(sys_config('current_validator_patch', 'latest patch')) ?>).</li>
        <li>Upload the .TXT report the module writes. Adding the DAT you validated proves byte identity.</li></ol>
      <p><a href="<?= e(url('manual', array('s' => 'guide_validator'))) ?>">Step-by-step guide</a></p>
    </div>
    <?php if (Ctx::can('validation.record') && !in_array($f['status'], array('superseded', 'submitted', 'approved'), true)) { ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="evidence">
      <div class="sheet-body" style="border-top:1px solid var(--line)"><div class="form-grid">
        <div class="field"><label for="validator_version">Validator version *</label><input id="validator_version" name="validator_version" type="text" value="7.4" required></div>
        <div class="field"><label for="patch_date">ATC patch date</label><input id="patch_date" name="patch_date" type="date"></div>
        <div class="field"><label for="executed_on">Validated on *</label><input id="executed_on" name="executed_on" type="date" value="<?= e(today()) ?>" required></div>
        <div class="field"><label for="report_type">Report type</label><input id="report_type" name="report_type" type="text" maxlength="40" placeholder="Validation report"></div>
        <div class="field wide"><label for="report">Validator report (.TXT, or a screenshot/PDF) *</label><input id="report" name="report" type="file" accept=".txt,.pdf,.png,.jpg,.jpeg" required></div>
        <div class="field wide"><label for="outcome">Outcome (only for screenshots or PDFs)</label><?= View::select('outcome', array('success' => 'No errors encountered', 'failure' => 'Errors were reported'), '', 'id="outcome"', 'Read it from the .TXT report') ?></div>
        <div class="field wide"><label for="validated_dat">The DAT you validated (optional)</label><input id="validated_dat" name="validated_dat" type="file" accept=".dat,.DAT"></div>
        <div class="field wide"><label for="notes">Notes</label><input id="notes" name="notes" type="text" maxlength="255"></div>
      </div></div>
      <div class="sheet-foot"><button class="btn btn-primary">Record validator result</button></div>
    </form>
    <?php } ?>
  </div>

  <?php if ($evidence) { ?>
  <div class="sheet"><div class="sheet-head"><h2>Validator evidence</h2></div>
    <ul class="queue"><?php foreach ($evidence as $ev) { ?>
      <li><div class="q-main">
        <strong><?= $ev['outcome'] === 'success' ? '<span class="st st-ok">No errors encountered</span>' : '<span class="st st-bad">Errors reported</span>' ?></strong>
        <div class="q-sub">Validator <?= e($ev['validator_version']) ?><?= $ev['patch_date'] ? ', patch ' . e(fmt_date($ev['patch_date'])) : '' ?>, run <?= e(fmt_date($ev['executed_at'])) ?>, uploaded by <?= e($ev['uploader']) ?></div>
        <div class="q-sub"><?= $ev['checksum_match'] ? 'Byte identity proven (SHA-256 matches)' : 'Byte identity not proven (validated DAT not uploaded)' ?>. <a href="<?= e(url('file_download', array('id' => $id, 'evidence' => $ev['id']))) ?>"><?= e($ev['evidence_filename']) ?></a></div>
        <div class="q-sub"><?= $ev['reviewed_at'] ? ($ev['review_decision'] === 'accepted' ? '<span class="st st-ok">Accepted</span>' : '<span class="st st-bad">Rejected</span>') . ' by ' . e($ev['reviewer']) . ', ' . e(fmt_dt($ev['reviewed_at'])) : '<span class="st st-wait">Waiting for review</span>' ?></div>
        <?php if (!$ev['reviewed_at'] && Ctx::can('validation.review') && (int)$ev['uploaded_by'] !== Auth::id()) { ?>
          <form method="post" class="inline-form" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="evidence_id" value="<?= (int)$ev['id'] ?>">
            <div class="field grow"><label for="note<?= (int)$ev['id'] ?>">Review note</label><input id="note<?= (int)$ev['id'] ?>" name="note" type="text" maxlength="200"></div>
            <button class="btn btn-sm btn-primary" name="decision" value="accept">Accept</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
        <?php } ?>
      </div></li>
    <?php } ?></ul></div>
  <?php } ?>

  <div class="sheet">
    <div class="sheet-head"><h2>Next steps</h2></div>
    <div class="sheet-body stack">
      <?php if ($f['status'] === 'bir_validated' && $latestEv && $latestEv['review_decision'] === 'accepted' && Ctx::can('approvals.request')) { ?>
        <?php if ($pendingAppr) { ?><p><?= View::status('pending') ?> Approval requested on <?= e(fmt_dt($pendingAppr['requested_at'])) ?>.</p>
        <?php } else { ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="request_approval"><div class="field grow"><label for="reason">Note for the approver</label><input id="reason" name="reason" type="text" maxlength="200"></div><button class="btn btn-primary">Request approval</button></form><?php } ?>
      <?php } ?>
      <?php if ($f['status'] === 'approved' && Ctx::can('filings.record')) { ?>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="filing">
          <div class="form-grid">
            <div class="field"><label for="channel">Submitted through</label><?= View::select('channel', array('eSubmission' => 'eSubmission (email)', 'eFPS' => 'eFPS', 'eBIRForms' => 'eBIRForms', 'eAFS' => 'eAFS', 'manual' => 'Manual / RDO'), 'eSubmission', 'id="channel"') ?></div>
            <div class="field"><label for="filed_on">Date submitted</label><input id="filed_on" name="filed_on" type="date" value="<?= e(today()) ?>"></div>
            <div class="field"><label for="reference_no">Reference or confirmation no.</label><input id="reference_no" name="reference_no" type="text" maxlength="80"></div>
            <div class="field"><label for="ack">Acknowledgment (PDF, image or email)</label><input id="ack" name="ack" type="file"></div>
            <div class="field wide"><label for="fnotes">Notes</label><input id="fnotes" name="notes" type="text" maxlength="255"></div>
          </div>
          <div class="btn-row" style="margin-top:10px"><button class="btn btn-primary">Record submission</button></div>
        </form>
      <?php } ?>
      <?php if ($f['origin'] === 'generated' && !in_array($f['status'], array('submitted', 'superseded'), true) && Ctx::can('reports.generate')) { ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><button class="btn" data-confirm="Re-process the frozen snapshot with the current layout? This creates a new file and the current one becomes superseded.">Re-process with current layout</button></form>
      <?php } ?>
      <?php if ($f['origin'] === 'uploaded' && in_array($f['form_code'], array('1601EQ', '1601FQ', '1600VT', '1600PT', 'SAWT'), true) && Ctx::can('records.edit')) { ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import_rows"><button class="btn" data-confirm="Copy the detail rows of this file into withholding records?">Import rows into records</button></form>
      <?php } ?>
      <?php if ($spec && $spec['status'] !== 'approved' && $latestEv && $latestEv['outcome'] === 'success' && $latestEv['review_decision'] === 'accepted' && Ctx::can('reference.manage')) { ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="promote_spec"><button class="btn">Request approval of layout #<?= (int)$spec['id'] ?> with this evidence</button></form>
      <?php } ?>
    </div>
  </div>

  <div class="sheet"><div class="sheet-head"><h2>Details</h2></div>
    <div class="sheet-body"><dl class="kv">
      <dt>Status</dt><dd><?= Reports::fileStatusLine($f) ?></dd>
      <dt>SHA-256</dt><dd class="mono small"><?= e($f['sha256']) ?></dd>
      <dt>Size</dt><dd><?= e(human_bytes((int)$f['byte_size'])) ?>, <?= (int)$f['record_count'] ?> detail records</dd>
      <dt>Totals</dt><dd>Base <?= Money::fmtDb($f['total_base']) ?>, tax <?= Money::fmtDb($f['total_tax']) ?></dd>
      <?php if ($spec) { ?><dt>Layout</dt><dd><a href="<?= e(url('spec_view', array('id' => $spec['id']))) ?>">#<?= (int)$spec['id'] ?> <?= e($spec['version_label']) ?></a> <?= View::status($spec['status']) ?></dd><?php } ?>
      <dt>Origin</dt><dd><?= $f['origin'] === 'uploaded' ? 'Uploaded' : 'Generated from ' . ($run ? e($run['source_mode']) . ' (run #' . (int)$run['id'] . ')' : 'records') ?></dd>
      <dt>Created</dt><dd><?= e($f['creator']) ?>, <?= e(fmt_dt($f['created_at'])) ?></dd>
      <?php if ($f['approved_at']) { ?><dt>Approved</dt><dd><?= e($f['approver']) ?>, <?= e(fmt_dt($f['approved_at'])) ?></dd><?php } ?>
      <?php if ($f['supersedes_id']) { ?><dt>Replaces</dt><dd><a href="<?= e(url('file_view', array('id' => $f['supersedes_id']))) ?>">File #<?= (int)$f['supersedes_id'] ?></a></dd><?php } ?>
      <?php if ($run && $run['notes']) { ?><dt>Note</dt><dd><?= e($run['notes']) ?></dd><?php } ?>
    </dl></div></div>

  <?php if ($filings) { ?><div class="sheet"><div class="sheet-head"><h2>Submissions</h2></div><ul class="queue"><?php foreach ($filings as $fr) { ?>
    <li><div class="q-main"><?= e($fr['channel']) ?> on <?= e(fmt_date($fr['filed_at'])) ?><?= $fr['reference_no'] ? ', ref ' . e($fr['reference_no']) : '' ?><div class="q-sub">Recorded by <?= e($fr['full_name']) ?><?= $fr['ack_path'] ? ', <a href="' . e(url('file_download', array('id' => $id, 'ack' => $fr['id']))) . '">' . e($fr['ack_filename']) . '</a>' : '' ?></div></div></li>
  <?php } ?></ul></div><?php } ?>
</div>
</div>
<?php View::footer();
