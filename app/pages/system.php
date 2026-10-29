<?php
Ctx::requirePerm('system.manage');
if (is_post()) {
    if (input('action') === 'settings') {
        set_sys_config('registration_open', input('registration_open') ? '1' : '0');
        set_sys_config('enforce_mfa_privileged', input('enforce_mfa_privileged') ? '1' : '0');
        $wasOn = Workflow::approvalsRequired();
        set_sys_config('require_approvals', input('require_approvals') ? '1' : '0');
        if ($wasOn && !Workflow::approvalsRequired()) {
            Audit::log('system.approvals_off', null, null, 'Approval by a second person turned off; waiting items are approved as each organization is opened');
        }
        set_sys_config('mail_mode', input('mail_mode') === 'mail' ? 'mail' : 'outbox');
        set_sys_config('session_idle_minutes', (string)max(5, min(480, input_int('session_idle_minutes', 30))));
        set_sys_config('max_upload_mb', (string)max(1, min(200, input_int('max_upload_mb', 20))));
        set_sys_config('srs_atcs', strtoupper(preg_replace('/[^A-Za-z0-9,]/', '', (string)input('srs_atcs'))));
        set_sys_config('current_validator', mb_substr(trim((string)input('current_validator')), 0, 200));
        set_sys_config('current_validator_patch', mb_substr(trim((string)input('current_validator_patch')), 0, 200));
        set_sys_config('research_checkpoint', (string)input('research_checkpoint'));
        Audit::log('system.settings', null, null, 'Changed platform settings');
        flash('success', 'Settings saved.');
    } elseif (input('action') === 'enter_org') {
        Ctx::switchOrg(input_int('org_id'));
        redirect(url('dashboard'));
    }
    redirect(url('system'));
}
$cfg = function ($k, $d = '') {
    return sys_config($k, $d);
};
$outbox = DB::all('SELECT * FROM mail_outbox ORDER BY id DESC LIMIT 20');
$orgs = DB::all('SELECT o.*, (SELECT COUNT(*) FROM memberships m WHERE m.organization_id = o.id) AS members, (SELECT COUNT(*) FROM client_entities c WHERE c.organization_id = o.id) AS clients FROM organizations o ORDER BY o.name');
$checks = array(
    'PHP ' . PHP_VERSION => version_compare(PHP_VERSION, '7.2', '>='),
    'MySQL ' . DB::val('SELECT VERSION()') => true,
    'zip extension (XLSX, ZIP)' => class_exists('ZipArchive'),
    'openssl (encryption)' => function_exists('openssl_encrypt'),
    'mbstring' => function_exists('mb_strlen'),
    'iconv (Windows-1252 output)' => function_exists('iconv'),
    'app_key configured' => Crypto::keyIsValid(),
    'Storage writable' => is_writable(storage_path()),
    'HTTPS cookies' => (bool)config('secure_cookies'),
);
View::header('Platform settings');
View::pageBar('Platform settings', 'Superadmin only');
?>
<div class="cols cols-main">
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <div class="sheet-head"><h2>Settings</h2></div>
    <div class="sheet-body"><div class="form-grid">
      <div class="field wide"><label class="check"><input type="checkbox" name="registration_open" value="1" <?= $cfg('registration_open') === '1' ? 'checked' : '' ?>> Allow self-service registration</label>
        <label class="check"><input type="checkbox" name="enforce_mfa_privileged" value="1" <?= $cfg('enforce_mfa_privileged') === '1' ? 'checked' : '' ?>> Require two-step sign-in for privileged roles</label>
        <label class="check"><input type="checkbox" name="require_approvals" value="1" <?= $cfg('require_approvals', '0') === '1' ? 'checked' : '' ?>> Require a second person's approval</label>
        <span class="hint">Off: a file the BIR validator passes is approved at once, and new codes, unlocks and voids take effect when saved. On: each of these waits in Approvals for another person.</span></div>
      <div class="field"><label for="mail_mode">Email</label><?= View::select('mail_mode', array('outbox' => 'Outbox only (no SMTP)', 'mail' => 'Send with PHP mail() and keep in outbox'), $cfg('mail_mode'), 'id="mail_mode"') ?></div>
      <div class="field"><label for="session_idle_minutes">Sign out after idle minutes</label><input id="session_idle_minutes" name="session_idle_minutes" type="number" value="<?= e($cfg('session_idle_minutes', 30)) ?>"></div>
      <div class="field"><label for="max_upload_mb">Upload limit (MB)</label><input id="max_upload_mb" name="max_upload_mb" type="number" value="<?= e($cfg('max_upload_mb', 20)) ?>"><span class="hint">PHP upload_max_filesize also applies to single uploads.</span></div>
      <div class="field"><label for="srs_atcs">Default SRS ATCs</label><input id="srs_atcs" name="srs_atcs" type="text" value="<?= e($cfg('srs_atcs')) ?>"></div>
      <div class="field half"><label for="current_validator">Current BIR validator</label><input id="current_validator" name="current_validator" type="text" value="<?= e($cfg('current_validator')) ?>"></div>
      <div class="field half"><label for="current_validator_patch">Current ATC patch</label><input id="current_validator_patch" name="current_validator_patch" type="text" value="<?= e($cfg('current_validator_patch')) ?>"></div>
      <div class="field"><label for="research_checkpoint">Last BIR source review</label><input id="research_checkpoint" name="research_checkpoint" type="date" value="<?= e($cfg('research_checkpoint')) ?>"><span class="hint">Recheck BIR issuances before each release.</span></div>
    </div></div>
    <div class="sheet-foot"><button class="btn btn-primary">Save settings</button></div>
  </form>
  <div>
    <div class="sheet"><div class="sheet-head"><h2>Environment</h2></div><ul class="queue"><?php foreach ($checks as $k => $ok) { ?><li><div class="q-main"><?= e($k) ?></div><?= $ok ? '<span class="st st-ok">OK</span>' : '<span class="st st-bad">Needs attention</span>' ?></li><?php } ?></ul></div>
    <div class="sheet"><div class="sheet-head"><h2>Organizations</h2></div><ul class="queue"><?php foreach ($orgs as $o) { ?>
      <li><div class="q-main"><strong><?= e($o['name']) ?></strong><div class="q-sub"><?= (int)$o['members'] ?> members, <?= (int)$o['clients'] ?> clients</div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enter_org"><input type="hidden" name="org_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm">Enter</button></form></li><?php } ?></ul></div>
  </div>
</div>
<div class="sheet"><div class="sheet-head"><div><h2>Mail outbox</h2><p>Verification, reset and invitation messages. Useful where the server has no SMTP.</p></div></div>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>When</th><th>To</th><th>Subject</th><th>Sent</th><th>Body</th></tr></thead><tbody>
  <?php foreach ($outbox as $m) { ?><tr><td data-label="When"><?= e(fmt_dt($m['created_at'])) ?></td><td data-label="To"><?= e($m['to_email']) ?></td><td data-label="Subject"><?= e($m['subject']) ?></td>
    <td data-label="Sent"><?= $m['sent_ok'] ? 'Yes' : 'Outbox only' ?></td><td data-label="Body" class="small"><details><summary>Show</summary><div style="white-space:pre-wrap" class="mono"><?= e($m['body']) ?></div></details></td></tr><?php } ?>
  </tbody></table></div></div>
<?php View::footer();
