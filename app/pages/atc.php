<?php
Ctx::requirePerm('reference.view');
if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'propose') {
            Ctx::requirePerm('reference.manage');
            $code = strtoupper(preg_replace('/\s+/', '', (string)input('atc_code')));
            if (!preg_match('/^[A-Z0-9]{3,10}$/', $code)) {
                throw new RuntimeException('Enter a valid ATC.');
            }
            $kind = in_array(input('tax_kind'), array('EWT', 'FWT', 'GVAT', 'GPT'), true) ? input('tax_kind') : null;
            $u = Money::rateUnits(input('rate'));
            $from = Records::parseDate(input('effective_from'));
            if (!$kind || $u === null || !$from || trim((string)input('description')) === '' || trim((string)input('source_ref')) === '') {
                throw new RuntimeException('Kind, rate, effective date, description and the BIR source are all required.');
            }
            if (DB::val('SELECT id FROM tax_codes WHERE atc_code = ? AND effective_from = ?', array($code, $from))) {
                throw new RuntimeException('A version of ' . $code . ' starting ' . $from . ' already exists.');
            }
            $id = DB::insert('tax_codes', array('atc_code' => $code, 'tax_kind' => $kind, 'payee_type' => in_array(input('payee_type'), array('individual', 'corporate'), true) ? input('payee_type') : 'any',
                'description' => mb_substr(trim(input('description')), 0, 255), 'rate' => Money::rateString($u), 'effective_from' => $from,
                'effective_to' => Records::parseDate(input('effective_to')) ?: null, 'version_tag' => mb_substr((string)input('version_tag'), 0, 40) ?: null,
                'source_ref' => mb_substr(trim(input('source_ref')), 0, 255), 'status' => 'pending_review', 'submitted_by' => Auth::id(), 'created_at' => now()));
            Workflow::request('tax_codes', $id, 'approve_tax_code', 'New or changed ATC ' . $code . ' from ' . $from);
            flash('success', $code . ' proposed. A second person with approval rights must approve it.');
        } elseif ($action === 'approve') {
            Ctx::requirePerm('reference.approve');
            $t = DB::row('SELECT * FROM tax_codes WHERE id = ?', array(input_int('id')));
            if (!$t || $t['status'] !== 'pending_review') {
                throw new RuntimeException('Nothing to approve.');
            }
            if ((int)$t['submitted_by'] === Auth::id()) {
                throw new RuntimeException('Someone other than the proposer must approve.');
            }
            $req = DB::val('SELECT id FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND decision = ?', array('tax_codes', $t['id'], 'pending'));
            if ($req) {
                Workflow::decide($req, true, 'Approved from the tax code registry');
            } else {
                DB::update('tax_codes', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()), 'id = ?', array($t['id']));
                Audit::log('taxcode.approve', 'tax_codes', $t['id'], 'Approved ATC ' . $t['atc_code'] . ' ' . $t['rate'] . '%', null, null, trim((string)input('note')));
            }
            flash('success', $t['atc_code'] . ' approved.');
        } elseif ($action === 'retire') {
            Ctx::requirePerm('reference.approve');
            $t = DB::row('SELECT * FROM tax_codes WHERE id = ?', array(input_int('id')));
            $to = Records::parseDate(input('effective_to'));
            if (!$t || !$to) {
                throw new RuntimeException('Give the last effective date.');
            }
            DB::update('tax_codes', array('effective_to' => $to, 'status' => $to < today() ? 'retired' : $t['status']), 'id = ?', array($t['id']));
            Audit::log('taxcode.retire', 'tax_codes', $t['id'], 'Set ' . $t['atc_code'] . ' to end on ' . $to);
            flash('success', 'Effective end date saved. Historical files keep using the old version for their periods.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('atc', array('q' => input('q'))));
}
$q = trim((string)input('q', ''));
$kind = (string)input('kind', '');
$where = '1=1';
$p = array();
if ($q !== '') {
    $where .= ' AND (atc_code LIKE ? OR description LIKE ?)';
    $p[] = '%' . $q . '%';
    $p[] = '%' . $q . '%';
}
if ($kind !== '') {
    $where .= ' AND tax_kind = ?';
    $p[] = $kind;
}
$rows = DB::all('SELECT t.*, a.full_name AS approver, s.full_name AS submitter FROM tax_codes t LEFT JOIN users a ON a.id = t.approved_by LEFT JOIN users s ON s.id = t.submitted_by
    WHERE ' . $where . ' ORDER BY t.atc_code, t.effective_from DESC', $p);
$pending = 0;
foreach ($rows as $r) {
    $pending += $r['status'] === 'pending_review' ? 1 : 0;
}
View::header('Tax codes (ATC)');
View::pageBar('Tax codes (ATC)', 'Versioned registry; files use the version effective for their period');
?>
<?php if ($pending) { ?><div class="notice notice-warning"><?= plural($pending, 'tax code') ?> pending review. Files using them stay unvalidated drafts. Approve each after checking the ATC table of the installed validator (<?= e(sys_config('current_validator_patch', '')) ?>).</div><?php } ?>
<div class="cols cols-main">
  <div class="sheet">
    <form class="filters" method="get"><input type="hidden" name="p" value="atc">
      <div class="field grow"><label for="q">ATC or description</label><input id="q" name="q" type="search" value="<?= e($q) ?>"></div>
      <div class="field"><label for="kind">Kind</label><?= View::select('kind', array('EWT' => 'Expanded', 'FWT' => 'Final', 'GVAT' => 'Government VAT', 'GPT' => 'Government PT'), $kind, 'id="kind"', 'All') ?></div><button class="btn">Filter</button></form>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>ATC</th><th>Description</th><th class="num">Rate</th><th>Effective</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r) { ?>
      <tr class="<?= $r['status'] === 'pending_review' ? 'hl' : ($r['status'] === 'retired' ? 'void' : '') ?>"><td data-label="ATC"><strong><?= e($r['atc_code']) ?></strong><span class="sub"><?= e($r['tax_kind']) ?>, <?= e($r['payee_type']) ?></span></td>
        <td data-label="Description"><?= e($r['description']) ?></td><td data-label="Rate" class="num"><?= e(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2')) ?>%</td>
        <td data-label="Effective"><?= e(fmt_date($r['effective_from'], 'm/d/Y')) ?><?= $r['effective_to'] ? ' to ' . e(fmt_date($r['effective_to'], 'm/d/Y')) : ' onward' ?></td>
        <td data-label="Source" class="small"><?= e($r['source_ref']) ?></td>
        <td data-label="Status"><?= View::status($r['status']) ?><?= $r['approver'] ? '<span class="sub">' . e($r['approver']) . '</span>' : '' ?></td>
        <td class="actions"><?php if ($r['status'] === 'pending_review' && Ctx::can('reference.approve') && (int)$r['submitted_by'] !== Auth::id()) { ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-primary" data-confirm="Approve <?= e($r['atc_code']) ?> at <?= e(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2')) ?>%? Confirm it matches the official ATC table.">Approve</button></form><?php } ?></td></tr>
    <?php } ?></tbody></table></div>
  </div>
  <?php if (Ctx::can('reference.manage')) { ?>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="propose">
    <div class="sheet-head"><div><h2>Propose a code or new version</h2><p>Adding a new effective date keeps history intact.</p></div></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field"><label for="atc_code">ATC</label><input id="atc_code" name="atc_code" type="text" maxlength="10" required></div>
      <div class="field"><label for="tax_kind">Kind</label><?= View::select('tax_kind', array('EWT' => 'Expanded', 'FWT' => 'Final', 'GVAT' => 'Government VAT', 'GPT' => 'Government PT'), 'EWT', 'id="tax_kind"') ?></div>
      <div class="field"><label for="payee_type">Payee type</label><?= View::select('payee_type', array('individual' => 'Individual', 'corporate' => 'Non-individual', 'any' => 'Any'), 'any', 'id="payee_type"') ?></div>
      <div class="field"><label for="rate">Rate (%)</label><input id="rate" name="rate" type="text" inputmode="decimal" required></div>
      <div class="field"><label for="effective_from">Effective from</label><input id="effective_from" name="effective_from" type="date" required></div>
      <div class="field"><label for="effective_to">Effective until</label><input id="effective_to" name="effective_to" type="date"></div>
      <div class="field wide"><label for="description">Description</label><input id="description" name="description" type="text" maxlength="255" required></div>
      <div class="field wide"><label for="source_ref">BIR source (RR, RMC or patch)</label><input id="source_ref" name="source_ref" type="text" maxlength="255" required></div>
      <div class="field wide"><label for="version_tag">Version tag</label><input id="version_tag" name="version_tag" type="text" maxlength="40" placeholder="ATC patch Nov 2025"></div>
    </div></div>
    <div class="sheet-foot"><button class="btn btn-primary">Submit for approval</button></div>
  </form>
  <?php } ?>
</div>
<?php View::footer();
