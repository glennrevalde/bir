<?php
Ctx::requirePerm('imports.run');
$job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array(input_int('id'), Ctx::clientId()));
if (!$job) {
    json_out(array('ok' => false, 'error' => 'Import not found.'));
}
if ($job['status'] !== 'mapped') {
    json_out(array('ok' => true, 'done' => true, 'processed' => (int)$job['processed_rows'], 'total' => (int)$job['total_rows'],
        'valid' => (int)$job['valid_rows'], 'errors' => (int)$job['error_rows'], 'duplicates' => (int)$job['duplicate_rows']));
}
@set_time_limit(120);
$r = Imports::stageChunk($job['id'], 400);
$r['ok'] = true;
json_out($r);
