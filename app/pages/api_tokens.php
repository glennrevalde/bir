<?php
Ctx::requirePerm('api.manage');
$scopes = array('records:read' => 'Read records and directory', 'records:write' => 'Create withholding, sales and purchase records', 'files:read' => 'Read file status and download validated files');
$newToken = null;
if (is_post()) {
    try {
        if (input('action') === 'create') {
            $name = trim((string)input('name'));
            $sel = array_values(array_intersect(array_keys($scopes), (array)input('scopes', array())));
            if ($name === '' || !$sel) {
                throw new RuntimeException('Give the token a name and at least one scope.');
            }
            $cid = input_int('client_id') ?: null;
            if ($cid && !Ctx::clientAllowed($cid)) {
                throw new RuntimeException('Client not in your scope.');
            }
            $plain = 'gsb_' . random_token(24);
            $days = input_int('days', 90);
            $id = DB::insert('api_tokens', array('organization_id' => Ctx::orgId(), 'client_id' => $cid, 'user_id' => Auth::id(), 'name' => mb_substr($name, 0, 100),
                'token_hash' => hash('sha256', $plain), 'token_hint' => substr($plain, -6), 'scopes' => implode(',', $sel),
                'expires_at' => $days ? date('Y-m-d H:i:s', time() + $days * 86400) : null, 'created_at' => now()));
            Audit::log('api.token_create', 'api_tokens', $id, 'Created API token ' . $name . ' (' . implode(', ', $sel) . ')');
            $_SESSION['new_token'] = $plain;
        } elseif (input('action') === 'revoke') {
            DB::update('api_tokens', array('revoked_at' => now()), 'id = ? AND organization_id = ?', array(input_int('id'), Ctx::orgId()));
            Audit::log('api.token_revoke', 'api_tokens', input_int('id'), 'Revoked API token');
            flash('success', 'Token revoked.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('api_tokens'));
}
$newToken = isset($_SESSION['new_token']) ? $_SESSION['new_token'] : null;
unset($_SESSION['new_token']);
$rows = DB::all('SELECT t.*, u.full_name, c.registered_name, c.last_name FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN client_entities c ON c.id = t.client_id
    WHERE t.organization_id = ? ORDER BY t.id DESC', array(Ctx::orgId()));
$co = array();
foreach (Ctx::clients() as $c) {
    $co[$c['id']] = Ctx::clientName($c);
}
View::header('API tokens');
View::pageBar('API tokens', 'Let accounting, POS and payroll systems push records');
?>
<?php if ($newToken) { ?><div class="sheet"><div class="sheet-body stack"><strong>Copy this token now. It is shown only once.</strong>
  <div class="inline-form"><div class="field grow"><input id="tok" type="text" class="mono" value="<?= e($newToken) ?>" readonly></div><button type="button" class="btn" data-copy="tok">Copy</button></div></div></div><?php } ?>
<div class="cols cols-main">
  <div class="sheet"><div class="table-wrap"><table class="table table-stack"><thead><tr><th>Name</th><th>Scopes</th><th>Client</th><th>Last used</th><th>Expires</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $t) { ?><tr class="<?= $t['revoked_at'] ? 'void' : '' ?>"><td data-label="Name"><strong><?= e($t['name']) ?></strong><span class="sub mono">...<?= e($t['token_hint']) ?>, by <?= e($t['full_name']) ?></span></td>
    <td data-label="Scopes" class="small"><?= e(str_replace(',', ', ', $t['scopes'])) ?></td><td data-label="Client"><?= e($t['client_id'] ? ($t['registered_name'] ?: $t['last_name']) : 'Any in scope') ?></td>
    <td data-label="Last used"><?= e(fmt_dt($t['last_used_at']) ?: 'Never') ?></td><td data-label="Expires"><?= $t['revoked_at'] ? 'Revoked' : e(fmt_date($t['expires_at']) ?: 'Never') ?></td>
    <td class="actions"><?php if (!$t['revoked_at']) { ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Revoke this token?">Revoke</button></form><?php } ?></td></tr><?php } ?>
  <?php if (!$rows) { ?><tr><td colspan="6" class="muted">No tokens yet.</td></tr><?php } ?></tbody></table></div>
  <div class="sheet-body small prose"><p>Send <code>Authorization: Bearer &lt;token&gt;</code> to <code><?= e(base_url()) ?>/api.php</code>. Endpoints: <code>GET ?r=files</code>, <code>GET ?r=directory</code>, <code>POST ?r=withholding</code>, <code>POST ?r=sales</code>, <code>POST ?r=purchases</code>. Posted rows go through the same validation as manual entry and land with source "api". See docs/API.md.</p></div></div>
  <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="sheet-head"><h2>New token</h2></div>
    <div class="sheet-body stack">
      <div class="field"><label for="name">Name</label><input id="name" name="name" type="text" maxlength="100" placeholder="Payroll system" required></div>
      <div class="field"><label>Scopes</label><?php foreach ($scopes as $k => $l) { ?><label class="check"><input type="checkbox" name="scopes[]" value="<?= e($k) ?>"> <?= e($l) ?></label><?php } ?></div>
      <div class="field"><label for="client_id">Limit to client</label><?= View::select('client_id', $co, '', 'id="client_id"', 'Any client in my scope') ?></div>
      <div class="field"><label for="days">Expires after (days, 0 for never)</label><input id="days" name="days" type="number" min="0" value="90"></div>
    </div>
    <div class="sheet-foot"><button class="btn btn-primary">Create token</button></div>
  </form>
</div>
<?php View::footer();
