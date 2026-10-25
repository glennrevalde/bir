<?php
Ctx::requirePerm('backups.manage');
$client = Ctx::requireClient();
$result = null;
if (is_post()) {
    $action = input('action');
    try {
        @set_time_limit(300);
        if ($action === 'create') {
            if ((string)input('passphrase') !== (string)input('passphrase2')) {
                throw new RuntimeException('The passphrases do not match.');
            }
            Backup::createClient($client['id'], (string)input('passphrase'), trim((string)input('notes')));
            flash('success', 'Encrypted backup created. Download it and keep the passphrase somewhere safe.');
        } elseif ($action === 'system') {
            Ctx::requirePerm('system.manage');
            Backup::createSystem((string)input('passphrase'));
            flash('success', 'Full database backup created.');
        } elseif ($action === 'test' || $action === 'restore') {
            if ($action === 'restore') {
                Ctx::requirePerm('backups.restore');
                if (preg_replace('/\D/', '', (string)input('confirm_tin')) !== $client['tin']) {
                    throw new RuntimeException('Type the client TIN to confirm the restore.');
                }
            }
            $blob = null;
            if (input_int('backup_id')) {
                $b = DB::row('SELECT * FROM backups WHERE id = ? AND client_id = ?', array(input_int('backup_id'), $client['id']));
                if (!$b) {
                    throw new RuntimeException('Backup not found.');
                }
                $blob = file_get_contents(storage_path($b['stored_path']));
            } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $blob = file_get_contents($_FILES['file']['tmp_name']);
            } else {
                throw new RuntimeException('Choose a stored backup or upload a .gsbk file.');
            }
            $result = Backup::restoreClient($client['id'], $blob, (string)input('passphrase'), $action === 'test');
            if ($action === 'test') {
                Audit::log('backup.test', 'client_entities', $client['id'], 'Test-restored a backup from ' . $result['meta']['created_at'] . ' (no changes made)');
                $_SESSION['backup_test'] = $result;
                flash('success', 'The backup opened, passed its integrity check and matches this client. No data was changed.');
            } else {
                flash('success', 'Client data restored from the backup created ' . $result['meta']['created_at'] . '.');
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('backups'));
}
if ($dl = input_int('download')) {
    $b = DB::row('SELECT * FROM backups WHERE id = ? AND (client_id = ? OR (scope = ? AND ? = 1))', array($dl, $client['id'], 'system', Ctx::can('system.manage') ? 1 : 0));
    if (!$b) {
        deny('Backup not found.');
    }
    Audit::log('backup.download', 'backups', $b['id'], 'Downloaded backup ' . $b['filename']);
    send_download(storage_path($b['stored_path']), $b['filename']);
}
$test = isset($_SESSION['backup_test']) ? $_SESSION['backup_test'] : null;
unset($_SESSION['backup_test']);
$rows = DB::all('SELECT b.*, u.full_name FROM backups b LEFT JOIN users u ON u.id = b.created_by WHERE b.client_id = ? OR (b.scope = ? AND ? = 1) ORDER BY b.id DESC LIMIT 50',
    array($client['id'], 'system', Ctx::can('system.manage') ? 1 : 0));
View::header('Backup and restore');
View::pageBar('Backup and restore', View::clientContext('encrypted client backups'));
?>
<?php if ($test) { ?><div class="sheet"><div class="sheet-head"><h2>Test restore result</h2><span class="st st-ok">Readable</span></div><div class="sheet-body small">
  Created <?= e($test['meta']['created_at']) ?>, app <?= e($test['meta']['app_version']) ?>, <?= (int)$test['meta']['row_count'] ?> rows and <?= (int)$test['meta']['file_count'] ?> files:
  <?php $parts = array(); foreach ($test['summary'] as $t => $n) { if ($n) { $parts[] = $t . ' ' . $n; } } echo e(implode(', ', $parts)); ?>.</div></div><?php } ?>
<div class="cols cols-main">
  <div class="sheet"><div class="sheet-head"><h2>Backups</h2></div>
    <?php if (!$rows) { echo View::empty_state('No backups yet', 'Create an encrypted backup of this client, download it, and store it off this server.'); } else { ?>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>File</th><th>Scope</th><th class="num">Size</th><th>Created</th><th>Last restored</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $b) { ?><tr><td data-label="File"><strong class="mono small"><?= e($b['filename']) ?></strong><span class="sub"><?= $b['encrypted'] ? 'Encrypted' : 'Not encrypted' ?><?= $b['notes'] ? ', ' . e($b['notes']) : '' ?></span></td>
      <td data-label="Scope"><?= e($b['scope'] === 'system' ? 'Whole database' : 'This client') ?></td><td data-label="Size" class="num"><?= e(human_bytes((int)$b['byte_size'])) ?></td>
      <td data-label="Created"><?= e($b['full_name']) ?><span class="sub"><?= e(fmt_dt($b['created_at'])) ?></span></td><td data-label="Restored"><?= e(fmt_dt($b['last_restored_at']) ?: 'Never') ?></td>
      <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('backups', array('download' => $b['id']))) ?>">Download</a></td></tr><?php } ?>
    </tbody></table></div><?php } ?>
  </div>
  <div>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="sheet-head"><div><h2>Back up this client</h2><p>Records, files, certificates and evidence, encrypted with your passphrase.</p></div></div>
      <div class="sheet-body stack">
        <div class="field"><label for="passphrase">Passphrase (12+ characters)</label><input id="passphrase" name="passphrase" type="password" autocomplete="new-password" minlength="12" required></div>
        <div class="field"><label for="passphrase2">Repeat passphrase</label><input id="passphrase2" name="passphrase2" type="password" autocomplete="new-password" required></div>
        <div class="field"><label for="notes">Note</label><input id="notes" name="notes" type="text" maxlength="200"></div>
        <p class="small muted">The passphrase is never stored. Without it the backup cannot be opened.</p>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary">Create backup</button></div>
    </form>
    <form method="post" enctype="multipart/form-data" class="sheet"><?= csrf_field() ?>
      <div class="sheet-head"><div><h2>Test or restore</h2><p>Test opens and checks a backup without changing anything.</p></div></div>
      <div class="sheet-body stack">
        <div class="field"><label for="backup_id">Stored backup</label><select id="backup_id" name="backup_id"><option value="">Upload a file instead</option><?php foreach ($rows as $b) { if ($b['scope'] === 'client') { ?><option value="<?= (int)$b['id'] ?>"><?= e($b['filename']) ?></option><?php } } ?></select></div>
        <div class="field"><label for="bfile">Backup file (.gsbk)</label><input id="bfile" name="file" type="file" accept=".gsbk"></div>
        <div class="field"><label for="rpass">Passphrase</label><input id="rpass" name="passphrase" type="password" required></div>
        <?php if (Ctx::can('backups.restore')) { ?><div class="field"><label for="confirm_tin">To restore, type this client's TIN</label><input id="confirm_tin" name="confirm_tin" type="text" placeholder="<?= e(Tin::display($client['tin'])) ?>"></div><?php } ?>
      </div>
      <div class="sheet-foot"><button class="btn" name="action" value="test">Test restore</button><?php if (Ctx::can('backups.restore')) { ?><button class="btn btn-danger" name="action" value="restore" data-confirm="Replace all of this client's data with the backup? The audit trail is kept.">Restore</button><?php } ?></div>
    </form>
    <?php if (Ctx::can('system.manage')) { ?>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="system">
      <div class="sheet-head"><h2>Whole database (platform)</h2></div>
      <div class="sheet-body stack"><div class="field"><label for="spass">Passphrase (optional)</label><input id="spass" name="passphrase" type="password"></div>
        <p class="small muted">Produces a gzip SQL dump. Restore it with the mysql command line or phpMyAdmin.</p></div>
      <div class="sheet-foot"><button class="btn">Create database backup</button></div>
    </form>
    <?php } ?>
  </div>
</div>
<?php View::footer();
