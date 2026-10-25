<?php
Ctx::requirePerm('imports.run');
$job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array(input_int('id'), Ctx::clientId()));
if (!$job) {
    deny('Import not found for this client.');
}
if (input('what') === 'original') {
    Audit::log('import.download', 'import_jobs', $job['id'], 'Downloaded original upload');
    send_download(storage_path($job['stored_path']), $job['original_filename']);
}
$bytes = Imports::correctionsWorkbook($job['id']);
send_bytes($bytes, 'corrections-import-' . $job['id'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
