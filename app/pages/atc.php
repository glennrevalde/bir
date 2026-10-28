<?php
/**
 * Tax codes (ATC). One row per code: add or change a code, or upload many from Excel (checked and shown
 * before anything is saved). Every addition or change waits for a second person's approval.
 */
Ctx::requirePerm('reference.view');

if ($dl = input('dl')) {
    $withCodes = $dl === 'codes';
    send_bytes(TaxCodes::template($withCodes), $withCodes ? 'ATC-codes-' . date('Ymd') . '.xlsx' : 'ATC-upload-template.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

$upload = isset($_SESSION['atc_upload']) ? $_SESSION['atc_upload'] : null;
$readUpload = function () use ($upload) {
    if (!$upload || !is_file(storage_path($upload['path']))) {
        throw new RuntimeException('The uploaded file is no longer on the server. Upload it again.');
    }
    return TaxCodes::read(storage_path($upload['path']), $upload['ext']);
};

if (is_post()) {
    $action = input('action');
    $back = array('q' => input('q'));
    try {
        if ($action === 'propose') {
            Ctx::requirePerm('reference.manage');
            list($d, $e) = TaxCodes::validate($_POST);
            if ($e) {
                throw new RuntimeException(implode('. ', $e) . '.');
            }
            list($what) = TaxCodes::plan($d);
            if ($what === 'same') {
                flash('info', $d['atc_code'] . ' already has this rate, payee type and description. Nothing changed.');
            } else {
                TaxCodes::propose($d, 'entered on the Tax codes page');
                flash('success', $d['atc_code'] . ($what === 'new' ? ' added' : ' changed') . '. A second person with approval rights must approve it before files use it as approved.');
            }
        } elseif ($action === 'upload') {
            Ctx::requirePerm('reference.manage');
            $f = store_upload(isset($_FILES['file']) ? $_FILES['file'] : null, 'uploads', array('xlsx', 'csv'), 5 * 1024 * 1024);
            $_SESSION['atc_upload'] = array('path' => $f['path'], 'name' => $f['name'], 'ext' => $f['ext']);
            Audit::log('taxcode.upload', 'tax_codes', null, 'Uploaded ' . $f['name'] . ' for checking');
            $back = array('review' => 1);
        } elseif ($action === 'apply') {
            Ctx::requirePerm('reference.manage');
            $res = $readUpload();
            $n = array('new' => 0, 'change' => 0);
            DB::tx(function () use ($res, $upload, &$n) {
                foreach ($res['rows'] as $r) {
                    if (!$r['errors'] && in_array($r['plan'], array('new', 'change'), true)) {
                        TaxCodes::propose($r['data'], 'uploaded from ' . $upload['name']);
                        $n[$r['plan']]++;
                    }
                }
            });
            unset($_SESSION['atc_upload']);
            Audit::log('taxcode.upload_apply', 'tax_codes', null, 'From ' . $upload['name'] . ': ' . $n['new'] . ' new, ' . $n['change'] . ' changed');
            flash($n['new'] + $n['change'] ? 'success' : 'info', $n['new'] + $n['change']
                ? 'Submitted ' . plural($n['new'], 'new code') . ' and ' . plural($n['change'], 'change') . ' for approval.'
                : 'Nothing to submit: every code in the file is already in the system as it is.');
        } elseif ($action === 'cancel_upload') {
            unset($_SESSION['atc_upload']);
        } elseif ($action === 'approve') {
            Ctx::requirePerm('reference.approve');
            $t = TaxCodes::approve(input_int('id'), trim((string)input('note')));
            flash('success', $t['atc_code'] . ' approved.');
        } elseif ($action === 'approve_all') {
            Ctx::requirePerm('reference.approve');
            $ids = DB::col('SELECT id FROM tax_codes WHERE status = ? AND (submitted_by IS NULL OR submitted_by <> ?) ORDER BY atc_code', array('pending_review', Auth::id()));
            $done = 0;
            $skipped = array();
            foreach ($ids as $id) {
                try {
                    TaxCodes::approve((int)$id, 'Approved with all pending codes');
                    $done++;
                } catch (RuntimeException $e) {
                    $skipped[] = $e->getMessage();
                }
            }
            flash($skipped ? 'warning' : 'success', 'Approved ' . plural($done, 'tax code') . '.' . ($skipped ? ' Not approved: ' . implode(' ', array_slice($skipped, 0, 3)) : ''));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        if ($action === 'apply') {
            $back = array('review' => 1);
        }
    }
    redirect(url('atc', $back));
}

$q = trim((string)input('q', ''));
$kind = (string)input('kind', '');
$rows = TaxCodes::current($q, $kind);
$pending = (int)DB::val('SELECT COUNT(*) FROM tax_codes WHERE status = ?', array('pending_review'));
$mine = (int)DB::val('SELECT COUNT(*) FROM tax_codes WHERE status = ? AND submitted_by = ?', array('pending_review', Auth::id()));
$review = null;
if ($upload && input('review')) {
    try {
        $review = $readUpload();
    } catch (RuntimeException $e) {
        unset($_SESSION['atc_upload']);
        flash('error', $e->getMessage());
    }
}
$canManage = Ctx::can('reference.manage');
$canApprove = Ctx::can('reference.approve');
$kinds = TaxCodes::kinds();

$actions = '<a class="btn" href="' . e(url('atc', array('dl' => 'codes'))) . '">' . View::icon('download') . 'Download codes</a>';
if ($canApprove && $pending > $mine) {
    $actions .= '<form method="post" class="inline-form" style="display:inline-flex">' . csrf_field() . '<input type="hidden" name="action" value="approve_all">'
        . '<button class="btn btn-primary" data-confirm="Approve all ' . (int)($pending - $mine) . ' pending tax codes? Check them against the official ATC table first.">Approve all pending (' . (int)($pending - $mine) . ')</button></form>';
}
View::header('Tax codes (ATC)');
View::pageBar('Tax codes (ATC)', 'The codes, rates and descriptions used by every client', $actions);
?>
<?php if ($pending) { ?><div class="notice notice-warning"><?= plural($pending, 'tax code') ?> waiting for approval. Files that use a code waiting for approval stay unvalidated drafts.
  <?= $mine && $canApprove ? ($mine === $pending ? 'You added them, so someone else must approve.' : plural($mine, 'of them was', 'of them were') . ' added by you and need someone else.') : '' ?></div><?php } ?>

<?php if ($review) {
    $count = array('new' => 0, 'change' => 0, 'same' => 0, 'waiting' => 0, 'error' => 0);
    foreach ($review['rows'] as $r) {
        $count[$r['errors'] ? 'error' : $r['plan']]++;
    }
    $ok = $count['new'] + $count['change']; ?>
<div class="sheet atc-review">
  <div class="sheet-head"><div><h2>Check the upload: <?= e($upload['name']) ?></h2>
    <p>Nothing is saved yet. <?= plural($ok, 'code') ?> will be submitted for approval<?= $count['error'] ? '; ' . plural($count['error'], 'row') . ' with errors will be left out' : '' ?>.</p></div></div>
  <?php foreach ($review['problems'] as $pb) { ?><div class="notice notice-error" style="margin:12px 16px 0"><?= e($pb) ?></div><?php } ?>
  <div class="figures">
    <div><strong><?= (int)$count['new'] ?></strong><span>New codes</span></div>
    <div><strong><?= (int)$count['change'] ?></strong><span>Changed codes</span></div>
    <div><strong><?= (int)$count['same'] ?></strong><span>Already up to date</span></div>
    <div><strong><?= (int)$count['waiting'] ?></strong><span>Already waiting for approval</span></div>
    <div><strong><?= (int)$count['error'] ?></strong><span>With errors</span></div>
  </div>
  <?php if ($review['rows']) { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Row</th><th>What happens</th><th>ATC</th><th>Description</th><th class="num">Rate</th><th>Payee type</th></tr></thead>
    <tbody><?php foreach ($review['rows'] as $r) { $d = $r['data']; $cur = $r['current']; ?>
      <tr class="<?= $r['errors'] ? 'row-error' : '' ?>">
        <td data-label="Row"><?= (int)$r['row'] ?></td>
        <td data-label="What happens"><?php if ($r['errors']) { foreach ($r['errors'] as $k => $m) { ?>
            <div class="rmsg rmsg-bad"><?php if ($m['ref']) { ?><span class="rmsg-where"><?= e($m['ref']) ?></span><?php } ?><span class="rmsg-text"><?= e($m['text']) ?></span><span class="rmsg-found">In the file: <?= $m['value'] === '' ? '<em>blank</em>' : e($m['value']) ?></span></div>
          <?php } } elseif ($r['plan'] === 'new') { ?><strong class="ok-text">New code</strong><?php } elseif ($r['plan'] === 'change') { ?><strong>Change from today</strong>
            <span class="sub">Now <?= e(Money::rateFormat(Money::rateUnits($cur['rate']), 'pct2')) ?>%, <?= e(TaxCodes::payeeLabel($cur['payee_type'])) ?>: <?= e(mb_strimwidth($cur['description'], 0, 80, '...')) ?></span>
          <?php } elseif ($r['plan'] === 'waiting') { ?><span class="muted">A change is already waiting for approval</span><?php } else { ?><span class="muted">Already up to date</span><?php } ?></td>
        <td data-label="ATC"><strong><?= e($d['atc_code']) ?></strong><span class="sub"><?= e($d['tax_kind']) ?></span></td>
        <td data-label="Description"><?= e($d['description']) ?></td>
        <td data-label="Rate" class="num"><?= $d['rate'] !== null ? e(Money::rateFormat(Money::rateUnits($d['rate']), 'pct2')) . '%' : '' ?></td>
        <td data-label="Payee type"><?= e(TaxCodes::payeeLabel($d['payee_type'])) ?></td>
      </tr>
    <?php } ?></tbody></table></div>
  <?php } ?>
  <div class="sheet-foot" style="justify-content:space-between">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_upload"><button class="btn btn-quiet">Cancel</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply">
      <button class="btn btn-primary" <?= $ok ? '' : 'disabled' ?>>Submit <?= plural($ok, 'code') ?> for approval</button></form>
  </div>
</div>
<?php } ?>

<div class="cols cols-main">
  <div class="sheet">
    <form class="filters" method="get"><input type="hidden" name="p" value="atc">
      <div class="field grow"><label for="q">ATC or description</label><input id="q" name="q" type="search" value="<?= e($q) ?>"></div>
      <div class="field"><label for="kind">Kind</label><?= View::select('kind', $kinds, $kind, 'id="kind"', 'All') ?></div><button class="btn">Filter</button></form>
    <?php if (!$rows) { echo View::empty_state('No tax codes match', 'Change the filter, add a code, or upload them from Excel.'); } else { ?>
    <div class="table-wrap"><table class="table table-stack atc-table"><thead><tr><th>ATC</th><th>Description</th><th class="num">Rate</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r) { $was = $r['status'] === 'pending_review' ? TaxCodes::inForce($r) : null; ?>
      <tr class="<?= $r['status'] === 'pending_review' ? 'hl' : '' ?>">
        <td data-label="ATC"><strong><?= e($r['atc_code']) ?></strong><span class="sub"><?= e($kinds[$r['tax_kind']] ?? $r['tax_kind']) ?>, <?= e(strtolower(TaxCodes::payeeLabel($r['payee_type']))) ?></span></td>
        <td data-label="Description"><?= e($r['description']) ?><?php if ($was && $was['description'] !== $r['description']) { ?><span class="sub">Until approved: <?= e($was['description']) ?></span><?php } ?></td>
        <td data-label="Rate" class="num"><?= e(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2')) ?>%<?php if ($was && $was['rate'] !== $r['rate']) { ?><span class="sub">Now <?= e(Money::rateFormat(Money::rateUnits($was['rate']), 'pct2')) ?>%</span><?php } ?></td>
        <td data-label="Status"><?= $r['status'] === 'pending_review' ? '<span class="warn-text"><strong>' . ($was ? 'Change waiting' : 'New, waiting') . '</strong></span><span class="sub">Added by ' . e($r['submitter'] ?: 'the system') . '</span>' : View::status($r['status']) . ($r['approver'] ? '<span class="sub">' . e($r['approver']) . '</span>' : '') ?></td>
        <td class="actions"><?php if ($r['status'] === 'pending_review' && $canApprove && (int)$r['submitted_by'] !== Auth::id()) { ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-primary" data-confirm="Approve <?= e($r['atc_code']) ?> at <?= e(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2')) ?>%? Confirm it matches the official ATC table.">Approve</button></form><?php } ?></td></tr>
    <?php } ?></tbody></table></div>
    <div class="sheet-foot small muted"><?= plural(count($rows), 'code') ?>. A change applies from the day it is made; periods before it keep the rate they had.</div>
    <?php } ?>
  </div>
  <?php if ($canManage) { ?>
  <div class="stack">
    <form method="post" enctype="multipart/form-data" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div class="sheet-head"><div><h2>Upload from Excel</h2><p>Columns ATC, KIND, PAYEE TYPE, RATE and DESCRIPTION. You see every row before anything is saved.</p></div></div>
      <div class="sheet-body stack">
        <div class="field"><label for="atc-file">Workbook (.xlsx or .csv)</label><input id="atc-file" name="file" type="file" accept=".xlsx,.csv" required></div>
        <p class="small muted">Start from the <a href="<?= e(url('atc', array('dl' => 'template'))) ?>">blank template</a>, or <a href="<?= e(url('atc', array('dl' => 'codes'))) ?>">download the current codes</a>, edit them and upload them back.</p>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary"><?= View::icon('upload') ?>Upload and check</button></div>
    </form>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="propose">
      <div class="sheet-head"><div><h2>Add or change a code</h2><p>Type an ATC already listed to change its rate, payee type or description.</p></div></div>
      <div class="sheet-body"><div class="form-grid">
        <div class="field"><label for="atc_code">ATC</label><input id="atc_code" name="atc_code" type="text" maxlength="10" required placeholder="WC158" list="atc-known"></div>
        <datalist id="atc-known"><?php foreach ($rows as $r) { ?><option value="<?= e($r['atc_code']) ?>"><?= e($r['description']) ?></option><?php } ?></datalist>
        <div class="field"><label for="tax_kind">Kind</label><?= View::select('tax_kind', $kinds, 'EWT', 'id="tax_kind"') ?></div>
        <div class="field"><label for="payee_type">Payee type</label><?= View::select('payee_type', TaxCodes::payeeTypes(), 'corporate', 'id="payee_type"') ?></div>
        <div class="field"><label for="rate">Rate (%)</label><input id="rate" name="rate" type="text" inputmode="decimal" required placeholder="1"></div>
        <div class="field wide"><label for="description">Description</label><input id="description" name="description" type="text" maxlength="255" required></div>
      </div></div>
      <div class="sheet-foot"><button class="btn btn-primary">Submit for approval</button></div>
    </form>
  </div>
  <?php } ?>
</div>
<?php View::footer();
