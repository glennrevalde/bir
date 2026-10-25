<?php
/**
 * Level 3: official BIR validator evidence. The system never decides on its own that a file
 * passed the BIR validator; it records the validator's own report, cross-checks it against
 * the stored file, and requires a second person to review it.
 */
class Evidence
{
    /** Parse the plain-text report the Alphalist validation module writes. */
    public static function parseReport($text)
    {
        $text = str_replace("\r\n", "\n", $text);
        $out = array('tin' => null, 'branch' => null, 'form' => null, 'period' => null, 'no_errors' => false, 'errors' => array(), 'recognized' => false);
        if (preg_match('/TIN[^:\n]*:\s*(\d{3}-?\d{3}-?\d{3})-?(\d{4,5})?/i', $text, $m)) {
            $out['tin'] = preg_replace('/\D/', '', $m[1]);
            $out['branch'] = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
            $out['recognized'] = true;
        }
        if (preg_match('/(?:Alphalist\s+Form|Form\s+Type|Form)\s*:\s*([0-9A-Z]+)/i', $text, $m)) {
            $out['form'] = strtoupper($m[1]);
        }
        if (preg_match('/(?:Taxable\s+Year|Return\s+Period|Period)\s*:\s*([0-9\/]+)/i', $text, $m)) {
            $out['period'] = $m[1];
        }
        if (preg_match('/No\s+Errors?\s+Encountered/i', $text)) {
            $out['no_errors'] = true;
            $out['recognized'] = true;
        }
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(\d{6,10})\s+(\S{1,6})?\s{2,}(.+)$/', $line, $m) && !preg_match('/No\s+Errors?/i', $m[3])) {
                $out['errors'][] = array('line' => (int)$m[1], 'schedule' => trim($m[2]), 'message' => trim($m[3]));
            }
        }
        return $out;
    }

    public static function record($fileId, $in, $reportFile, $datFile)
    {
        $f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array($fileId, Ctx::clientId()));
        if (!$f) {
            throw new RuntimeException('File not found.');
        }
        if (in_array($f['status'], array('superseded', 'submitted'), true)) {
            throw new RuntimeException('Evidence cannot be added to a ' . $f['status'] . ' file.');
        }
        $version = trim((string)$in['validator_version']);
        if ($version === '') {
            throw new RuntimeException('Enter the validator version shown in the BIR module (for example 7.4).');
        }
        $executed = Records::parseDate(isset($in['executed_on']) ? $in['executed_on'] : '');
        if (!$executed) {
            throw new RuntimeException('Enter the date you ran the validator.');
        }
        $stored = store_upload($reportFile, 'evidence', array('txt', 'pdf', 'png', 'jpg', 'jpeg'));
        $parsed = null;
        $outcome = isset($in['outcome']) ? $in['outcome'] : '';
        $issues = array();
        if ($stored['ext'] === 'txt') {
            $parsed = self::parseReport(file_get_contents(storage_path($stored['path'])));
            if (!$parsed['recognized']) {
                throw new RuntimeException('The text file does not look like a BIR validation report. Upload the .TXT the module created, or a screenshot with the outcome selected.');
            }
            $outcome = $parsed['no_errors'] && !$parsed['errors'] ? 'success' : 'failure';
            foreach ($parsed['errors'] as $e) {
                $issues[] = array('severity' => 'error', 'code' => 'BIR', 'record_no' => $e['line'], 'field_key' => $e['schedule'] ?: null, 'message' => $e['message']);
            }
            if ($parsed['tin'] && $parsed['tin'] !== DB::val('SELECT tin FROM client_entities WHERE id = ?', array($f['client_id']))) {
                throw new RuntimeException('The report is for TIN ' . Tin::display($parsed['tin']) . ', not this client. Check that you uploaded the right report.');
            }
            if ($parsed['form'] && !in_array($f['form_code'], array('SLS', 'SLP', 'SLI'), true)) {
                $pf = str_replace('-', '', $parsed['form']);
                $ff = $f['form_code'];
                $okForm = strpos($pf, $ff) !== false || strpos($ff, $pf) !== false || ($f['variant_key'] && strpos($pf, $f['variant_key']) !== false);
                if (!$okForm) {
                    throw new RuntimeException('The report is for form ' . $parsed['form'] . ', but this file is ' . $f['form_code'] . '.');
                }
            }
        } elseif (!in_array($outcome, array('success', 'failure'), true)) {
            throw new RuntimeException('For a screenshot or PDF, choose whether the validator reported success or errors.');
        }
        $checksumMatch = 0;
        $validatedSha = null;
        if ($datFile && isset($datFile['error']) && $datFile['error'] === UPLOAD_ERR_OK) {
            $validatedSha = hash_file('sha256', $datFile['tmp_name']);
            $checksumMatch = hash_equals($f['sha256'], $validatedSha) ? 1 : 0;
            if (!$checksumMatch) {
                throw new RuntimeException('The DAT you uploaded is not byte-identical to this stored file (SHA-256 differs). Validate the exact file downloaded from this page.');
            }
        }
        return DB::tx(function () use ($f, $version, $in, $executed, $stored, $parsed, $outcome, $issues, $checksumMatch, $validatedSha) {
            $vr = Checks::record($f['id'], $f['run_id'], 3, $issues, 'Official BIR validator ' . $version . ': ' . ($outcome === 'success' ? 'no errors' : count($issues) . ' errors reported'));
            DB::update('validation_runs', array('result' => $outcome === 'success' ? 'pass' : 'fail'), 'id = ?', array($vr));
            $eid = DB::insert('official_validation_evidence', array(
                'dat_file_id' => $f['id'], 'validation_run_id' => $vr, 'validator_version' => mb_substr($version, 0, 30),
                'patch_date' => !empty($in['patch_date']) ? Records::parseDate($in['patch_date']) : null, 'report_type' => mb_substr((string)$in['report_type'], 0, 40) ?: null,
                'executed_at' => $executed . ' 00:00:00', 'outcome' => $outcome, 'dat_sha256_validated' => $validatedSha, 'checksum_match' => $checksumMatch,
                'evidence_filename' => $stored['name'], 'evidence_path' => $stored['path'], 'evidence_sha256' => $stored['sha256'],
                'parsed_tin' => $parsed ? $parsed['tin'] : null, 'parsed_form' => $parsed ? $parsed['form'] : null, 'parsed_period' => $parsed ? $parsed['period'] : null,
                'parsed_summary' => $parsed ? json_encode($parsed) : null, 'notes' => mb_substr((string)$in['notes'], 0, 255) ?: null,
                'uploaded_by' => Auth::id(), 'created_at' => now(),
            ));
            DB::update('dat_files', array('status' => $outcome === 'success' ? 'bir_validated' : 'bir_failed', 'updated_at' => now()), 'id = ?', array($f['id']));
            DB::update('report_runs', array('status' => $outcome === 'success' ? 'bir_validated' : 'bir_failed', 'updated_at' => now()), 'id = ?', array((int)$f['run_id']));
            Audit::log('validation.official', 'dat_files', $f['id'], 'Recorded BIR validator result: ' . $outcome . ' (' . $version . ')', null,
                array('evidence' => $eid, 'checksum_match' => $checksumMatch));
            Workflow::notifyPerm('validation.review', 'Validator evidence to review', $f['filename'] . ': ' . $outcome, url('file_view', array('id' => $f['id'])));
            return $eid;
        });
    }

    public static function review($evidenceId, $decision, $note)
    {
        $ev = DB::row('SELECT e.*, d.client_id, d.id AS file_id, d.status AS file_status FROM official_validation_evidence e JOIN dat_files d ON d.id = e.dat_file_id
            WHERE e.id = ? AND d.client_id = ?', array($evidenceId, Ctx::clientId()));
        if (!$ev) {
            throw new RuntimeException('Evidence not found.');
        }
        if ((int)$ev['uploaded_by'] === Auth::id()) {
            throw new RuntimeException('A different person must review evidence you uploaded.');
        }
        if ($ev['reviewed_at']) {
            throw new RuntimeException('This evidence was already reviewed.');
        }
        DB::update('official_validation_evidence', array('reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'review_decision' => $decision === 'accept' ? 'accepted' : 'rejected',
            'notes' => trim(($ev['notes'] ? $ev['notes'] . ' | ' : '') . 'Review: ' . $note, ' |')), 'id = ?', array($evidenceId));
        if ($decision !== 'accept' && in_array($ev['file_status'], array('bir_validated', 'bir_failed'), true)) {
            DB::update('dat_files', array('status' => 'pending_bir', 'updated_at' => now()), 'id = ?', array($ev['file_id']));
        }
        Audit::log('validation.review', 'official_validation_evidence', $evidenceId, ($decision === 'accept' ? 'Accepted' : 'Rejected') . ' validator evidence', null, null, $note);
    }

    public static function latest($fileId)
    {
        return DB::row('SELECT e.*, u.full_name AS uploader, r.full_name AS reviewer FROM official_validation_evidence e
            LEFT JOIN users u ON u.id = e.uploaded_by LEFT JOIN users r ON r.id = e.reviewed_by WHERE e.dat_file_id = ? ORDER BY e.id DESC LIMIT 1', array($fileId));
    }
}
