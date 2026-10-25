<?php
/**
 * Maker-checker approvals, period locks, filing evidence and notifications.
 */
class Workflow
{
    public static function actions()
    {
        return array(
            'approve_file' => 'Approve file for filing',
            'unlock_period' => 'Unlock a reporting period',
            'approve_tax_code' => 'Approve a tax code',
            'approve_spec' => 'Approve a file layout',
            'void_certificate' => 'Void a certificate',
        );
    }

    public static function lockScopeForForm($form)
    {
        if ($form === 'SLS') {
            return 'sales';
        }
        if (in_array($form, array('SLP', 'SLI'), true)) {
            return 'purchases';
        }
        if ($form === '1604C') {
            return 'compensation';
        }
        return 'withholding';
    }

    public static function notify($userIds, $title, $body, $link)
    {
        foreach (array_unique($userIds) as $uid) {
            if ((int)$uid === (int)Auth::id()) {
                continue;
            }
            DB::insert('notifications', array('organization_id' => Ctx::orgId() ?: null, 'user_id' => $uid, 'title' => mb_substr($title, 0, 150),
                'body' => mb_substr((string)$body, 0, 255), 'link' => $link, 'created_at' => now()));
        }
    }

    public static function notifyPerm($perm, $title, $body, $link)
    {
        $ids = DB::col('SELECT DISTINCT m.user_id FROM memberships m JOIN role_permissions rp ON rp.role_code = m.role_code
            WHERE m.organization_id = ? AND m.status = ? AND rp.permission_code = ?', array(Ctx::orgId(), 'active', $perm));
        self::notify($ids, $title, $body, $link);
    }

    public static function request($subjectType, $subjectId, $action, $reason, $payload = null)
    {
        $dup = DB::val('SELECT id FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND action = ? AND decision = ?', array($subjectType, $subjectId, $action, 'pending'));
        if ($dup) {
            throw new RuntimeException('An approval request for this is already pending.');
        }
        $id = DB::insert('approval_requests', array('organization_id' => Ctx::orgId(), 'client_id' => Ctx::clientId() ?: null, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'action' => $action, 'reason' => mb_substr((string)$reason, 0, 255), 'payload_json' => $payload ? json_encode($payload) : null,
            'requested_by' => Auth::id(), 'requested_at' => now()));
        $acts = self::actions();
        Audit::log('approval.request', $subjectType, $subjectId, 'Requested: ' . $acts[$action], null, $payload, $reason);
        $perm = in_array($action, array('approve_tax_code', 'approve_spec'), true) ? 'reference.approve' : 'approvals.decide';
        self::notifyPerm($perm, 'Approval requested: ' . $acts[$action], $reason, url('approvals'));
        return $id;
    }

    public static function decide($reqId, $approve, $note)
    {
        $r = DB::row('SELECT * FROM approval_requests WHERE id = ? AND organization_id = ?', array($reqId, Ctx::orgId()));
        if (!$r || $r['decision'] !== 'pending') {
            throw new RuntimeException('This request is no longer pending.');
        }
        if ((int)$r['requested_by'] === Auth::id()) {
            throw new RuntimeException('Someone other than the requester must decide (maker-checker).');
        }
        $perm = in_array($r['action'], array('approve_tax_code', 'approve_spec'), true) ? 'reference.approve' : 'approvals.decide';
        Ctx::requirePerm($perm);
        if ($r['client_id'] && !Ctx::clientAllowed($r['client_id'])) {
            throw new RuntimeException('You do not have access to this client.');
        }
        return DB::tx(function () use ($r, $approve, $note) {
            if ($approve) {
                self::execute($r);
            }
            DB::update('approval_requests', array('decision' => $approve ? 'approved' : 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(),
                'decision_note' => mb_substr((string)$note, 0, 255)), 'id = ?', array($r['id']));
            Audit::log('approval.' . ($approve ? 'approve' : 'reject'), $r['subject_type'], $r['subject_id'], ($approve ? 'Approved' : 'Rejected') . ' request #' . $r['id'], null, null, $note);
            self::notify(array($r['requested_by']), 'Request ' . ($approve ? 'approved' : 'rejected'), $note, url('approvals'));
        });
    }

    private static function execute($r)
    {
        switch ($r['action']) {
            case 'approve_file':
                $f = DB::row('SELECT * FROM dat_files WHERE id = ?', array($r['subject_id']));
                if (!$f || $f['status'] !== 'bir_validated') {
                    throw new RuntimeException('Only files with an official BIR validation success can be approved.');
                }
                $ev = Evidence::latest($f['id']);
                if (!$ev || $ev['outcome'] !== 'success' || $ev['review_decision'] !== 'accepted') {
                    throw new RuntimeException('The validator evidence must be reviewed and accepted before approval.');
                }
                DB::update('dat_files', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'updated_at' => now()), 'id = ?', array($f['id']));
                DB::update('report_runs', array('status' => 'approved', 'updated_at' => now()), 'id = ?', array((int)$f['run_id']));
                $scope = self::lockScopeForForm($f['form_code']);
                $m = substr($f['period_from'], 0, 7);
                $end = substr($f['period_to'], 0, 7);
                while ($m <= $end) {
                    self::lock($f['client_id'], $m, $scope, 'Locked when ' . $f['filename'] . ' was approved');
                    $m = date('Y-m', strtotime($m . '-01 +1 month'));
                }
                break;
            case 'unlock_period':
                DB::update('period_locks', array('unlocked_by' => Auth::id(), 'unlocked_at' => now()), 'id = ? AND unlocked_at IS NULL', array($r['subject_id']));
                break;
            case 'approve_tax_code':
                DB::update('tax_codes', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()), 'id = ?', array($r['subject_id']));
                break;
            case 'approve_spec':
                $p = json_decode((string)$r['payload_json'], true);
                DB::update('layout_specs', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(),
                    'approval_evidence_id' => isset($p['evidence_id']) ? (int)$p['evidence_id'] : null, 'updated_at' => now()), 'id = ?', array($r['subject_id']));
                Specs::forget($r['subject_id']);
                break;
            case 'void_certificate':
                $p = json_decode((string)$r['payload_json'], true);
                Certificates::void($r['subject_id'], isset($p['reason']) ? $p['reason'] : $r['reason']);
                break;
        }
    }

    public static function lock($clientId, $month, $scope, $reason)
    {
        $exists = DB::val('SELECT id FROM period_locks WHERE client_id = ? AND period_month = ? AND unlocked_at IS NULL AND (scope = ? OR scope = ?)', array($clientId, $month, $scope, 'all'));
        if ($exists) {
            return (int)$exists;
        }
        $id = DB::insert('period_locks', array('organization_id' => Ctx::orgId(), 'client_id' => $clientId, 'period_month' => $month, 'scope' => $scope,
            'reason' => mb_substr($reason, 0, 255), 'locked_by' => Auth::id(), 'locked_at' => now()));
        Audit::log('period.lock', 'period_locks', $id, 'Locked ' . $scope . ' for ' . month_label($month), null, null, $reason, $clientId);
        return $id;
    }

    public static function recordFiling($fileId, $in, $ack)
    {
        $f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array($fileId, Ctx::clientId()));
        if (!$f || $f['status'] !== 'approved') {
            throw new RuntimeException('Record filing evidence after the file is approved.');
        }
        $channels = array('eSubmission', 'eFPS', 'eBIRForms', 'eAFS', 'manual');
        if (!in_array($in['channel'], $channels, true)) {
            throw new RuntimeException('Choose how the file was submitted.');
        }
        $date = Records::parseDate($in['filed_on']);
        if (!$date) {
            throw new RuntimeException('Enter the submission date.');
        }
        $stored = null;
        if ($ack && isset($ack['error']) && $ack['error'] !== UPLOAD_ERR_NO_FILE) {
            $stored = store_upload($ack, 'evidence', array('pdf', 'png', 'jpg', 'jpeg', 'txt', 'eml'));
        }
        $id = DB::insert('filing_records', array('organization_id' => Ctx::orgId(), 'client_id' => $f['client_id'], 'dat_file_id' => $f['id'], 'channel' => $in['channel'],
            'filed_at' => $date . ' 00:00:00', 'reference_no' => mb_substr((string)$in['reference_no'], 0, 80) ?: null,
            'ack_filename' => $stored ? $stored['name'] : null, 'ack_path' => $stored ? $stored['path'] : null, 'ack_sha256' => $stored ? $stored['sha256'] : null,
            'notes' => mb_substr((string)$in['notes'], 0, 255) ?: null, 'recorded_by' => Auth::id(), 'created_at' => now()));
        DB::update('dat_files', array('status' => 'submitted', 'updated_at' => now()), 'id = ?', array($f['id']));
        DB::update('report_runs', array('status' => 'submitted', 'updated_at' => now()), 'id = ?', array((int)$f['run_id']));
        Audit::log('filing.record', 'dat_files', $f['id'], 'Recorded submission via ' . $in['channel'] . ($in['reference_no'] ? ' ref ' . $in['reference_no'] : ''));
        return $id;
    }
}
