<?php
/**
 * Resumable chunked upload endpoint (JSON). open -> chunk* -> finish.
 * A client that loses connection calls open again with the same file and resumes from "received".
 */
Ctx::requirePerm('imports.run');
if (!is_post()) {
    json_out(array('ok' => false, 'error' => 'POST only'), 405);
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
    if ($total <= 0 || $total > $max) {
        json_out(array('ok' => false, 'error' => 'The file must be smaller than ' . human_bytes($max) . '.'));
    }
    $existing = DB::row('SELECT * FROM upload_sessions WHERE user_id = ? AND original_filename = ? AND total_bytes = ? AND status = ? AND created_at > ?',
        array(Auth::id(), $name, $total, 'open', date('Y-m-d H:i:s', time() - 86400)));
    if ($existing && is_file(storage_path($existing['temp_path']))) {
        json_out(array('ok' => true, 'token' => $existing['token'], 'received' => (int)filesize(storage_path($existing['temp_path']))));
    }
    $token = random_token(16);
    $rel = 'tmp/up-' . $token . '.part';
    file_put_contents(storage_path($rel), '');
    DB::insert('upload_sessions', array('token' => $token, 'user_id' => Auth::id(), 'original_filename' => $name, 'total_bytes' => $total, 'received_bytes' => 0,
        'temp_path' => $rel, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()));
    json_out(array('ok' => true, 'token' => $token, 'received' => 0));
}
$s = DB::row('SELECT * FROM upload_sessions WHERE token = ? AND user_id = ?', array((string)input('token'), Auth::id()));
if (!$s) {
    json_out(array('ok' => false, 'error' => 'Upload session not found. Start again.'));
}
$abs = storage_path($s['temp_path']);
if ($action === 'chunk') {
    $offset = (int)input('offset');
    $size = is_file($abs) ? filesize($abs) : 0;
    if ($offset !== $size) {
        json_out(array('ok' => true, 'received' => $size));
    }
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        json_out(array('ok' => false, 'error' => 'Chunk upload failed.'));
    }
    if ($size + $_FILES['chunk']['size'] > $s['total_bytes']) {
        json_out(array('ok' => false, 'error' => 'More data than announced.'));
    }
    file_put_contents($abs, file_get_contents($_FILES['chunk']['tmp_name']), FILE_APPEND);
    clearstatcache();
    DB::update('upload_sessions', array('received_bytes' => filesize($abs), 'updated_at' => now()), 'id = ?', array($s['id']));
    json_out(array('ok' => true, 'received' => filesize($abs)));
}
if ($action === 'finish') {
    clearstatcache();
    if (filesize($abs) !== (int)$s['total_bytes']) {
        json_out(array('ok' => false, 'error' => 'The upload is incomplete.'));
    }
    DB::update('upload_sessions', array('status' => 'complete', 'updated_at' => now()), 'id = ?', array($s['id']));
    json_out(array('ok' => true, 'token' => $s['token']));
}
json_out(array('ok' => false, 'error' => 'Unknown action'));
