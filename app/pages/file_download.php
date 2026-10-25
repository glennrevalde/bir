<?php
Ctx::requirePerm('records.view');
$f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array(input_int('id'), Ctx::clientId()));
if (!$f) {
    deny('File not found for this client.');
}
if ($evId = input_int('evidence')) {
    $ev = DB::row('SELECT * FROM official_validation_evidence WHERE id = ? AND dat_file_id = ?', array($evId, $f['id']));
    if (!$ev) {
        deny('Evidence not found.');
    }
    Audit::log('download.evidence', 'official_validation_evidence', $evId, 'Downloaded validator evidence for ' . $f['filename']);
    send_download(storage_path($ev['evidence_path']), $ev['evidence_filename']);
}
if ($ackId = input_int('ack')) {
    $fr = DB::row('SELECT * FROM filing_records WHERE id = ? AND dat_file_id = ?', array($ackId, $f['id']));
    if (!$fr || !$fr['ack_path']) {
        deny('Acknowledgment not found.');
    }
    Audit::log('download.ack', 'filing_records', $ackId, 'Downloaded acknowledgment for ' . $f['filename']);
    send_download(storage_path($fr['ack_path']), $fr['ack_filename']);
}
$draft = $f['file_label'] !== 'submission_ready' && !in_array($f['status'], array('bir_validated', 'approved', 'submitted'), true);
Ctx::requirePerm($draft ? 'dat.export_draft' : 'dat.download');
$bytes = Reports::storedBytes($f);
if ($bytes === null || hash('sha256', $bytes) !== $f['sha256']) {
    Audit::log('download.integrity', 'dat_files', $f['id'], 'Stored bytes do not match the recorded checksum');
    deny('The stored file does not match its recorded checksum. It was not sent. Restore it from backup or regenerate it.');
}
Audit::log('download.dat', 'dat_files', $f['id'], 'Downloaded ' . $f['filename'] . ($draft ? ' (UNVALIDATED DRAFT)' : ''));
send_bytes($bytes, $f['filename'], 'application/octet-stream');
