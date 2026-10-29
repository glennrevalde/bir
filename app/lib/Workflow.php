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

    const AUTO_NOTE = 'Approved automatically: approval by a second person is turned off';

    /**
     * Whether a second person must approve requests and review validator evidence. Off by default: every
     * request is approved the moment it is made, and a file is approved as soon as the BIR validator passes it.
     * Platform settings, "Require a second person's approval", turns the maker-checker steps back on.
     */
    public static function approvalsRequired()
    {
        return sys_config('require_approvals', '0') === '1';
    }

    public static function request($subjectType, $subjectId, $action, $reason, $payload = null)
    {
        if (!self::approvalsRequired()) {
            // Deemed approved: the request is recorded as approved by the person who made it, and carried out now.
            return DB::tx(function () use ($subjectType, $subjectId, $action, $reason, $payload) {
                $id = DB::insert('approval_requests', array('organization_id' => Ctx::orgId() ?: 0, 'client_id' => Ctx::clientId() ?: null, 'subject_type' => $subjectType,
                    'subject_id' => $subjectId, 'action' => $action, 'reason' => mb_substr((string)$reason, 0, 255), 'payload_json' => $payload ? json_encode($payload) : null,
                    'requested_by' => Auth::id(), 'requested_at' => now(), 'decision' => 'approved', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => self::AUTO_NOTE));
                self::execute(DB::row('SELECT * FROM approval_requests WHERE id = ?', array($id)));
                $acts = self::actions();
                Audit::log('approval.auto', $subjectType, $subjectId, 'Approved automatically: ' . $acts[$action], null, $payload, $reason);
                return $id;
            });
        }
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
                if (!$ev || $ev['outcome'] !== 'success' || ($ev['review_decision'] !== 'accepted' && self::approvalsRequired())) {
                    throw new RuntimeException('The validator evidence must be reviewed and accepted before approval.');
                }
                self::approveFile($f);
                break;
            case 'unlock_period':
                DB::update('period_locks', array('unlocked_by' => Auth::id(), 'unlocked_at' => now()), 'id = ? AND unlocked_at IS NULL', array($r['subject_id']));
                break;
            case 'approve_tax_code':
                DB::update('tax_codes', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()), 'id = ?', array($r['subject_id']));
                break;
            case 'approve_spec':
                $p = json_decode((string)$r['payload_json'], true);
                self::approveSpec($r['subject_id'], isset($p['evidence_id']) ? (int)$p['evidence_id'] : null);
                break;
            case 'void_certificate':
                $p = json_decode((string)$r['payload_json'], true);
                Certificates::void($r['subject_id'], isset($p['reason']) ? $p['reason'] : $r['reason']);
                break;
        }
    }

    /** Approve a file the BIR validator passed and lock the months it covers. */
    public static function approveFile($f)
    {
        DB::update('dat_files', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'updated_at' => now()), 'id = ?', array($f['id']));
        DB::update('report_runs', array('status' => 'approved', 'updated_at' => now()), 'id = ?', array((int)$f['run_id']));
        $scope = self::lockScopeForForm($f['form_code']);
        $m = substr($f['period_from'], 0, 7);
        $end = substr($f['period_to'], 0, 7);
        while ($m <= $end) {
            self::lock($f['client_id'], $m, $scope, 'Locked when ' . $f['filename'] . ' was approved', $f['organization_id']);
            $m = date('Y-m', strtotime($m . '-01 +1 month'));
        }
    }

    /** Mark a layout approved, citing the validator evidence that proved it when there is one. */
    public static function approveSpec($specId, $evidenceId = null)
    {
        DB::update('layout_specs', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(),
            'approval_evidence_id' => $evidenceId ?: null, 'updated_at' => now()), 'id = ?', array($specId));
        Specs::forget($specId);
    }

    /**
     * When approvals are off, a validator result needs no second person: the evidence is accepted, a file
     * the validator passed is approved, and the layout it proves is approved too.
     */
    public static function autoAcceptEvidence($evidenceId)
    {
        $ev = DB::row('SELECT e.*, d.status AS file_status, d.spec_id FROM official_validation_evidence e JOIN dat_files d ON d.id = e.dat_file_id WHERE e.id = ?', array($evidenceId));
        if (!$ev || $ev['reviewed_at']) {
            return;
        }
        DB::update('official_validation_evidence', array('reviewed_by' => $ev['uploaded_by'] ?: Auth::id(), 'reviewed_at' => now(), 'review_decision' => 'accepted',
            'notes' => trim(($ev['notes'] ? $ev['notes'] . ' | ' : '') . 'Accepted automatically', ' |')), 'id = ?', array($ev['id']));
        $f = DB::row('SELECT * FROM dat_files WHERE id = ?', array($ev['dat_file_id']));
        if ($ev['outcome'] === 'success' && $f && $f['status'] === 'bir_validated') {
            self::approveFile($f);
            Audit::log('approval.auto', 'dat_files', $f['id'], 'Approved automatically after the BIR validator passed ' . $f['filename'], null, null, null, $f['client_id']);
            if ($ev['spec_id'] && DB::val('SELECT status FROM layout_specs WHERE id = ?', array($ev['spec_id'])) !== 'approved') {
                self::approveSpec($ev['spec_id'], $ev['id']);
                Audit::log('approval.auto', 'layout_specs', $ev['spec_id'], 'Layout approved automatically by the validator result for ' . $f['filename']);
            }
        }
    }

    /**
     * Bring older pending items in line once approvals are off: requests still waiting are carried out,
     * validator evidence waiting for review is accepted (approving files the validator passed), and tax
     * codes and signature images waiting for approval are approved. Runs once per request at most, and only finds work after
     * the setting changes or on data from before this version.
     */
    public static function settle()
    {
        if (self::approvalsRequired() || !Auth::id()) {
            return 0;
        }
        $n = 0;
        $org = Ctx::orgId();
        if ($org && DB::val('SELECT id FROM approval_requests WHERE organization_id = ? AND decision = ? LIMIT 1', array($org, 'pending'))) {
            foreach (DB::all('SELECT * FROM approval_requests WHERE organization_id = ? AND decision = ? ORDER BY id', array($org, 'pending')) as $r) {
                try {
                    DB::tx(function () use ($r) {
                        Workflow::runApproved($r);
                    });
                    $n++;
                } catch (Exception $e) {
                    DB::update('approval_requests', array('decision' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(),
                        'decision_note' => mb_substr('Could not be carried out: ' . $e->getMessage(), 0, 255)), 'id = ?', array($r['id']));
                }
            }
        }
        if ($org) {
            foreach (DB::col('SELECT e.id FROM official_validation_evidence e JOIN dat_files d ON d.id = e.dat_file_id JOIN client_entities c ON c.id = d.client_id
                WHERE c.organization_id = ? AND e.reviewed_at IS NULL ORDER BY e.id', array($org)) as $eid) {
                DB::tx(function () use ($eid) {
                    Workflow::autoAcceptEvidence($eid);
                });
                $n++;
            }
        }
        if ($org && DB::val('SELECT id FROM signatories WHERE organization_id = ? AND signature_path IS NOT NULL AND signature_approved_at IS NULL LIMIT 1', array($org))) {
            DB::q('UPDATE signatories SET signature_approved_by = ?, signature_approved_at = ? WHERE organization_id = ? AND signature_path IS NOT NULL AND signature_approved_at IS NULL',
                array(Auth::id(), now(), $org));
            Audit::log('approval.auto', 'signatories', null, 'Approved waiting signature images automatically');
            $n++;
        }
        $pendingCodes = DB::col('SELECT id FROM tax_codes WHERE status = ?', array('pending_review'));
        if ($pendingCodes) {
            DB::q('UPDATE tax_codes SET status = ?, approved_by = ?, approved_at = ? WHERE status = ?', array('approved', Auth::id(), now(), 'pending_review'));
            Audit::log('approval.auto', 'tax_codes', null, 'Approved ' . count($pendingCodes) . ' waiting tax codes automatically');
            $n += count($pendingCodes);
        }
        return $n;
    }

    /** Carry out a waiting request as approved (used when approvals are turned off). */
    public static function runApproved($r)
    {
        self::execute($r);
        DB::update('approval_requests', array('decision' => 'approved', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => self::AUTO_NOTE), 'id = ?', array($r['id']));
        Audit::log('approval.auto', $r['subject_type'], $r['subject_id'], 'Approved automatically: request #' . $r['id']);
    }

    public static function lock($clientId, $month, $scope, $reason, $orgId = null)
    {
        $exists = DB::val('SELECT id FROM period_locks WHERE client_id = ? AND period_month = ? AND unlocked_at IS NULL AND (scope = ? OR scope = ?)', array($clientId, $month, $scope, 'all'));
        if ($exists) {
            return (int)$exists;
        }
        $id = DB::insert('period_locks', array('organization_id' => $orgId ?: Ctx::orgId(), 'client_id' => $clientId, 'period_month' => $month, 'scope' => $scope,
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
