<?php
/**
 * Append-only audit trail. Each event stores the hash of the previous event,
 * so any edit or deletion of history breaks the chain and shows up in verify().
 */
class Audit
{
    public static function log($action, $subjectType, $subjectId, $summary, $before = null, $after = null, $reason = null, $clientId = null, $userId = null)
    {
        $orgId = class_exists('Ctx') && Auth::id() ? Ctx::orgId() : null;
        if ($clientId === null && $orgId && Auth::id()) {
            $clientId = Ctx::clientId() ?: null;
        }
        $userId = $userId !== null ? $userId : Auth::id();
        $orgId = $orgId ?: null;
        $clientId = $clientId ?: null;
        $action = substr($action, 0, 40);
        $summary = mb_substr((string)$summary, 0, 255);
        $reason = ($reason === null || $reason === '') ? null : mb_substr($reason, 0, 255);
        $b = $before === null ? null : self::redact(json_encode($before));
        $a = $after === null ? null : self::redact(json_encode($after));
        $created = now();
        $prev = DB::val('SELECT event_hash FROM audit_events ORDER BY id DESC LIMIT 1');
        $hash = self::hashOf($prev, $orgId, $clientId, $userId, $action, $subjectType, $subjectId, $summary, $b, $a, $reason, $created);
        DB::insert('audit_events', array(
            'organization_id' => $orgId, 'client_id' => $clientId, 'user_id' => $userId,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'summary' => $summary, 'before_json' => $b, 'after_json' => $a,
            'reason' => $reason, 'ip' => client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'cli',
            'prev_hash' => $prev, 'event_hash' => $hash, 'created_at' => $created,
        ));
    }

    private static function hashOf($prev, $org, $client, $user, $action, $st, $sid, $summary, $b, $a, $reason, $created)
    {
        return hash('sha256', implode('|', array((string)$prev, (string)$org, (string)$client, (string)$user, $action, (string)$st,
            (string)$sid, $summary, (string)$b, (string)$a, (string)$reason, $created)));
    }

    /** Remove secrets from logged payloads. */
    private static function redact($json)
    {
        if ($json === false || $json === null) {
            return null;
        }
        return preg_replace('/"(password|password_hash|mfa_secret|token|token_hash|passphrase)":"[^"]*"/i', '"$1":"[redacted]"', $json);
    }

    /** Recompute the chain. Returns array('ok'=>bool, 'checked'=>n, 'broken_at'=>id|null). */
    public static function verify()
    {
        $prev = null;
        $n = 0;
        $st = DB::q('SELECT * FROM audit_events ORDER BY id');
        while ($r = $st->fetch()) {
            $n++;
            $calc = self::hashOf($r['prev_hash'], $r['organization_id'], $r['client_id'], $r['user_id'], $r['action'], $r['subject_type'],
                $r['subject_id'], $r['summary'], $r['before_json'], $r['after_json'], $r['reason'], $r['created_at']);
            if ($r['prev_hash'] !== $prev || $calc !== $r['event_hash']) {
                return array('ok' => false, 'checked' => $n, 'broken_at' => (int)$r['id']);
            }
            $prev = $r['event_hash'];
        }
        return array('ok' => true, 'checked' => $n, 'broken_at' => null);
    }
}
