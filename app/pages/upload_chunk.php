<?php
/**
 * Resumable chunked upload endpoint (JSON). open -> chunk* -> finish.
 * A client that loses connection calls open again with the same file and resumes from "received".
 * Every write is checked, so a server that cannot save files says so in plain words instead of failing later.
 */
Ctx::requirePerm('imports.run');
if (!is_post()) {
    json_out(array('ok' => false, 'error' => 'POST only'), 405);
}

/** The reason uploads cannot be saved on this server, for the person uploading; the fix for an administrator. */
function gsb_upload_storage_error($what)
{
    $msg = 'The server could not ' . $what . ', so the file was not uploaded.';
    $bad = storage_problems();
    if ($bad) {
        $msg .= ' The folder ' . $bad[0][0] . ' is ' . $bad[0][1] . ' for the web server.';
    }
    $msg .= Ctx::isSuper() || Ctx::can('system.manage') || Ctx::can('users.manage') ? ' ' . storage_fix_hint() : ' Ask your administrator to make the storage folder writable (open check.php to see which folder).';
    error_log('[GSB] Upload: could not ' . $what . ' in ' . storage_path('tmp') . ($bad ? ' (' . $bad[0][0] . ' ' . $bad[0][1] . ')' : ''));
    json_out(array('ok' => false, 'error' => $msg, 'reason' => 'storage'));
}

$action = input('action');
$max = (int)sys_config('max_upload_mb', 20) * 1024 * 1024;
if ($action === 'open') {
    $name = basename((string)input('name'));
    $total = (int)input('total');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array('xlsx', 'csv', 'dat', 'txt'), true)) {
        json_out(array('ok' => false, 'error' => 'Only .xlsx, .csv, .dat or .txt files are accepted. Save .xls workbooks as .xlsx first.'));
    }
    if ($total <= 0) {
        json_out(array('ok' => false, 'error' => 'The file ' . $name . ' is empty. Save it in Excel again and upload it.'));
    }
    if ($total > $max) {
        json_out(array('ok' => false, 'error' => 'The file is ' . human_bytes($total) . '; files must be smaller than ' . human_bytes($max) . '.'));
    }
    $tmpDir = storage_path('tmp');
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0775, true);
    }
    if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
        gsb_upload_storage_error('save the uploaded file in its storage folder');
    }
    $existing = DB::row('SELECT * FROM upload_sessions WHERE user_id = ? AND original_filename = ? AND total_bytes = ? AND status = ? AND created_at > ?',
        array(Auth::id(), $name, $total, 'open', date('Y-m-d H:i:s', time() - 86400)));
    if ($existing && is_file(storage_path($existing['temp_path']))) {
        json_out(array('ok' => true, 'token' => $existing['token'], 'received' => (int)filesize(storage_path($existing['temp_path']))));
    }
    $token = random_token(16);
    $rel = 'tmp/up-' . $token . '.part';
    if (@file_put_contents(storage_path($rel), '') === false) {
        gsb_upload_storage_error('create a temporary file for the upload');
    }
    DB::insert('upload_sessions', array('token' => $token, 'user_id' => Auth::id(), 'original_filename' => $name, 'total_bytes' => $total, 'received_bytes' => 0,
        'temp_path' => $rel, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()));
    json_out(array('ok' => true, 'token' => $token, 'received' => 0));
}
$s = DB::row('SELECT * FROM upload_sessions WHERE token = ? AND user_id = ?', array((string)input('token'), Auth::id()));
if (!$s) {
    json_out(array('ok' => false, 'error' => 'The upload session has expired. Choose the file again and upload it.'));
}
$abs = storage_path($s['temp_path']);
if ($action === 'chunk') {
    clearstatcache();
    if (!is_file($abs)) {
        gsb_upload_storage_error('find the part of the file already received');
    }
    $offset = (int)input('offset');
    $size = (int)filesize($abs);
    if ($offset !== $size) {
        json_out(array('ok' => true, 'received' => $size));
    }
    $err = isset($_FILES['chunk']['error']) ? (int)$_FILES['chunk']['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        $why = array(UPLOAD_ERR_INI_SIZE => 'a piece of the file is larger than the server allows (upload_max_filesize in php.ini must be at least 2M)',
            UPLOAD_ERR_FORM_SIZE => 'a piece of the file is too large', UPLOAD_ERR_PARTIAL => 'the connection dropped during the upload',
            UPLOAD_ERR_NO_FILE => 'no data arrived (post_max_size in php.ini may be too small)', UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary folder on the server',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write to its temporary folder on the server', UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload');
        json_out(array('ok' => false, 'error' => 'The upload failed because ' . (isset($why[$err]) ? $why[$err] : 'of a server error (code ' . $err . ')') . '. Choose the file again to resume.'));
    }
    if ($size + $_FILES['chunk']['size'] > $s['total_bytes']) {
        json_out(array('ok' => false, 'error' => 'More data arrived than the file size announced. Choose the file again and upload it.'));
    }
    $data = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($data === false || @file_put_contents($abs, $data, FILE_APPEND) === false) {
        gsb_upload_storage_error('add the received part to the uploaded file');
    }
    clearstatcache();
    $now = (int)filesize($abs);
    DB::update('upload_sessions', array('received_bytes' => $now, 'updated_at' => now()), 'id = ?', array($s['id']));
    json_out(array('ok' => true, 'received' => $now));
}
if ($action === 'finish') {
    clearstatcache();
    $got = is_file($abs) ? (int)filesize($abs) : 0;
    if ($got !== (int)$s['total_bytes']) {
        json_out(array('ok' => false, 'error' => 'Only ' . human_bytes($got) . ' of ' . human_bytes((int)$s['total_bytes']) . ' arrived. Choose the file again to resume.'));
    }
    DB::update('upload_sessions', array('status' => 'complete', 'updated_at' => now()), 'id = ?', array($s['id']));
    json_out(array('ok' => true, 'token' => $s['token']));
}
json_out(array('ok' => false, 'error' => 'Unknown action'));
