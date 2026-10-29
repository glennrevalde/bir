<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$c = DB::row('SELECT c.*, u.full_name, s.full_name AS signer FROM certificates c LEFT JOIN users u ON u.id = c.generated_by LEFT JOIN signatories s ON s.id = c.signatory_id
    WHERE c.id = ? AND c.client_id = ?', array(input_int('id'), $client['id']));
if (!$c) {
    deny('Certificate not found for this client.');
}
if (input('pdf')) {
    $abs = storage_path($c['pdf_path']);
    $bytes = $c['status'] === 'void' ? Certificates::renderStored(array($c['id']), true) : (is_file($abs) ? file_get_contents($abs) : Certificates::renderStored(array($c['id']), true));
    Audit::log('certificate.download', 'certificates', $c['id'], 'Downloaded ' . $c['cert_no']);
    send_bytes($bytes, $c['cert_no'] . '-r' . $c['revision'] . '.pdf', 'application/pdf', input('inline') === '1');
}
if (is_post()) {
    try {
        if (input('action') === 'void') {
            Ctx::requirePerm('approvals.request');
            $reason = trim((string)input('reason'));
            if ($reason === '') {
                throw new RuntimeException('Give a reason.');
            }
            Workflow::request('certificates', $c['id'], 'void_certificate', $reason, array('reason' => $reason));
            flash('success', Workflow::approvalsRequired() ? 'Void requested. Another person must approve it.' : 'Certificate voided.');
        } elseif (input('action') === 'reissue') {
            Ctx::requirePerm('certificates.void');
            if ($c['status'] !== 'issued') {
                throw new RuntimeException('Only the current revision can be reissued.');
            }
            $branch = $c['branch_id'] ? DB::row('SELECT * FROM branches WHERE id = ?', array($c['branch_id'])) : Ctx::headOffice();
            $payload = $c['form_code'] === '2316'
                ? Certificates::build2316($client, $branch, json_decode($c['payload_json'], true)['comp_id'])
                : Certificates::buildWithholding($c['form_code'], $client, $branch, $c['counterparty_id'], $c['period_from'], $c['period_to']);
            $old = json_decode($c['payload_json'], true);
            $nid = Certificates::issue($payload, array('signatory_id' => $c['signatory_id'], 'use_signature' => $c['use_signature'], 'paper' => $c['paper_size'],
                'offset_x' => isset($old['offset_x']) ? $old['offset_x'] : 0, 'offset_y' => isset($old['offset_y']) ? $old['offset_y'] : 0, 'reissue_of' => $c['id'], 'branch_id' => $c['branch_id']));
            flash('success', 'Reissued as revision ' . DB::val('SELECT revision FROM certificates WHERE id = ?', array($nid)) . ' from current records.');
            redirect(url('cert_view', array('id' => $nid)));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('cert_view', array('id' => $c['id'])));
}
$p = json_decode($c['payload_json'], true);
$revs = DB::all('SELECT id, revision, status, created_at FROM certificates WHERE client_id = ? AND form_code = ? AND cert_no = ? ORDER BY revision DESC', array($client['id'], $c['form_code'], $c['cert_no']));
View::header($c['cert_no'], array('page' => 'cert_view', 'help' => 'certificates'));
View::pageBar($c['cert_no'], View::clientContext('BIR Form ' . e($c['form_code']) . ', revision ' . (int)$c['revision']),
    '<a class="btn btn-primary" href="' . e(url('cert_view', array('id' => $c['id'], 'pdf' => 1))) . '">' . View::icon('download') . 'Download PDF</a>'
    . '<a class="btn" target="_blank" rel="noopener" href="' . e(url('cert_view', array('id' => $c['id'], 'pdf' => 1, 'inline' => 1))) . '">Print preview</a>');
?>
<?php if ($c['status'] === 'void') { ?><div class="notice notice-error">Void: <?= e($c['void_reason']) ?></div><?php } ?>
<div class="cols cols-main">
  <div class="sheet"><div class="sheet-head"><h2>Preview</h2><span class="small muted"><?= e($c['template_revision']) ?></span></div>
    <iframe title="Certificate PDF" src="<?= e(url('cert_view', array('id' => $c['id'], 'pdf' => 1, 'inline' => 1))) ?>" style="width:100%;height:760px;border:0;background:#fff"></iframe></div>
  <div>
    <div class="sheet"><div class="sheet-head"><h2>Details</h2><?= View::status($c['status']) ?></div><div class="sheet-body"><dl class="kv">
      <dt>Period</dt><dd><?= e(fmt_date($c['period_from'], 'm/d/Y') . ' to ' . fmt_date($c['period_to'], 'm/d/Y')) ?></dd>
      <dt><?= $c['form_code'] === '2316' ? 'Employee' : 'Payee' ?></dt><dd><?= e($c['form_code'] === '2316' ? $p['employee']['name'] : $p['payee']['name']) ?></dd>
      <dt>Income</dt><dd><?= Money::fmtDb($c['total_income']) ?></dd><dt>Tax</dt><dd><?= Money::fmtDb($c['total_tax']) ?></dd>
      <dt>Signatory</dt><dd><?= e($c['signer'] ?: 'None') ?><?= $c['use_signature'] ? ', signature image applied' : '' ?></dd>
      <dt>Paper</dt><dd><?= e($c['paper_size']) ?></dd><dt>Issued</dt><dd><?= e($c['full_name']) ?>, <?= e(fmt_dt($c['created_at'])) ?></dd>
      <dt>SHA-256</dt><dd class="mono small"><?= e($c['sha256']) ?></dd>
      <?php if (!empty($p['source_ids'])) { ?><dt>Source records</dt><dd><?= count($p['source_ids']) ?> withholding entries</dd><?php } ?>
    </dl></div>
    <?php if ($c['status'] === 'issued') { ?><div class="sheet-foot">
      <?php if (Ctx::can('certificates.void')) { ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reissue"><button class="btn" data-confirm="Reissue from current records as the next revision? This revision will be marked replaced.">Reissue</button></form><?php } ?>
      <?php if (Ctx::can('approvals.request')) { ?><form method="post" data-prompt="Reason for voiding this certificate"><?= csrf_field() ?><input type="hidden" name="action" value="void"><button class="btn btn-danger"><?= Workflow::approvalsRequired() ? 'Request void' : 'Void certificate' ?></button></form><?php } ?>
    </div><?php } ?></div>
    <div class="sheet"><div class="sheet-head"><h2>Revisions</h2></div><ul class="queue"><?php foreach ($revs as $r) { ?><li><div class="q-main"><a href="<?= e(url('cert_view', array('id' => $r['id']))) ?>">Revision <?= (int)$r['revision'] ?></a><div class="q-sub"><?= View::status($r['status']) ?> <?= e(fmt_dt($r['created_at'])) ?></div></div></li><?php } ?></ul></div>
  </div>
</div>
<?php View::footer();
