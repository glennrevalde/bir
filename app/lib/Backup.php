<?php
/**
 * Encrypted, tenant-scoped client backups with tested restore, plus a full SQL dump for platform admins.
 * Backup format: GSBK1 + AES-256-GCM( gzip( JSON {meta, tables, files} ) ), key derived from a passphrase.
 */
class Backup
{
    /** Tables restored in this order (parents first). Each: table => [where clause on client, params builder]. */
    private static function plan()
    {
        return array(
            'branches' => 'client_id = :c',
            'signatories' => 'client_id = :c',
            'counterparties' => 'client_id = :c',
            'employees' => 'client_id = :c',
            'sales_entries' => 'client_id = :c',
            'purchase_entries' => 'client_id = :c',
            'importation_entries' => 'client_id = :c',
            'withholding_entries' => 'client_id = :c',
            'compensation_records' => 'client_id = :c',
            'period_locks' => 'client_id = :c',
            'import_jobs' => 'client_id = :c',
            'import_rows' => 'job_id IN (SELECT id FROM import_jobs WHERE client_id = :c)',
            'report_runs' => 'client_id = :c',
            'report_run_items' => 'run_id IN (SELECT id FROM report_runs WHERE client_id = :c)',
            'data_snapshots' => 'run_id IN (SELECT id FROM report_runs WHERE client_id = :c)',
            'dat_files' => 'client_id = :c',
            'validation_runs' => 'dat_file_id IN (SELECT id FROM dat_files WHERE client_id = :c) OR run_id IN (SELECT id FROM report_runs WHERE client_id = :c)',
            'validation_errors' => 'validation_run_id IN (SELECT id FROM validation_runs WHERE dat_file_id IN (SELECT id FROM dat_files WHERE client_id = :c) OR run_id IN (SELECT id FROM report_runs WHERE client_id = :c))',
            'official_validation_evidence' => 'dat_file_id IN (SELECT id FROM dat_files WHERE client_id = :c)',
            'filing_records' => 'client_id = :c',
            'certificates' => 'client_id = :c',
            'approval_requests' => 'client_id = :c',
            'attachments' => 'client_id = :c',
        );
    }

    private static function revisionWhere()
    {
        $parts = array();
        foreach (array('sales_entries', 'purchase_entries', 'importation_entries', 'withholding_entries', 'compensation_records') as $t) {
            $parts[] = "(subject_type = '" . $t . "' AND subject_id IN (SELECT id FROM " . $t . " WHERE client_id = :c))";
        }
        return implode(' OR ', $parts);
    }

    private static function q($where, $clientId)
    {
        $n = substr_count($where, ':c');
        return array(str_replace(':c', '?', $where), array_fill(0, $n, $clientId));
    }

    public static function createClient($clientId, $passphrase, $notes = '')
    {
        if (strlen($passphrase) < 12) {
            throw new RuntimeException('Use a passphrase of at least 12 characters. Keep it safe: it is not stored and the backup cannot be opened without it.');
        }
        $client = DB::row('SELECT * FROM client_entities WHERE id = ? AND organization_id = ?', array($clientId, Ctx::orgId()));
        if (!$client) {
            throw new RuntimeException('Client not found.');
        }
        $tables = array('client_entities' => array($client));
        $count = 0;
        foreach (self::plan() as $t => $where) {
            list($w, $p) = self::q($where, $clientId);
            $tables[$t] = DB::all('SELECT * FROM ' . $t . ' WHERE ' . $w, $p);
            $count += count($tables[$t]);
        }
        list($w, $p) = self::q(self::revisionWhere(), $clientId);
        $tables['revisions'] = DB::all('SELECT * FROM revisions WHERE ' . $w, $p);
        $files = array();
        foreach (array('dat_files' => 'storage_path', 'certificates' => 'pdf_path', 'official_validation_evidence' => 'evidence_path', 'filing_records' => 'ack_path', 'signatories' => 'signature_path') as $t => $col) {
            foreach ($tables[$t] as $r) {
                if (!empty($r[$col]) && is_file(storage_path($r[$col]))) {
                    $files[$r[$col]] = base64_encode(file_get_contents(storage_path($r[$col])));
                }
            }
        }
        $meta = array('format' => 'gsmart-bir-client-backup', 'version' => 1, 'app_version' => GSB_VERSION, 'client_id' => (int)$clientId, 'tin' => $client['tin'],
            'organization_id' => Ctx::orgId(), 'created_at' => now(), 'created_by' => Auth::id(), 'row_count' => $count, 'file_count' => count($files));
        $json = json_encode(array('meta' => $meta, 'tables' => $tables, 'files' => $files));
        $sha = hash('sha256', $json);
        $meta['payload_sha256'] = $sha;
        $blob = Crypto::encryptWithPassphrase(gzencode(json_encode(array('meta' => $meta, 'sha' => $sha)) . "\n" . $json, 6), $passphrase);
        $name = 'client-' . $client['tin'] . '-' . date('Ymd-His') . '.gsbk';
        $rel = 'backups/' . $name;
        file_put_contents(storage_path($rel), $blob);
        $id = DB::insert('backups', array('organization_id' => Ctx::orgId(), 'client_id' => $clientId, 'scope' => 'client', 'filename' => $name, 'stored_path' => $rel,
            'byte_size' => strlen($blob), 'sha256' => hash('sha256', $blob), 'encrypted' => 1, 'record_count' => $count, 'notes' => mb_substr($notes, 0, 255) ?: null,
            'created_by' => Auth::id(), 'created_at' => now()));
        Audit::log('backup.create', 'backups', $id, 'Created encrypted backup of ' . Ctx::clientName($client) . ' (' . $count . ' rows, ' . count($files) . ' files)', null, null, null, $clientId);
        return $id;
    }

    /** Decrypt and verify; returns array(meta, payload). */
    public static function open($blob, $passphrase)
    {
        $gz = Crypto::decryptWithPassphrase($blob, $passphrase);
        if ($gz === null) {
            throw new RuntimeException('The passphrase is wrong or the file is not a G-SMaRT BIR backup.');
        }
        $raw = gzdecode($gz);
        $nl = strpos($raw, "\n");
        $head = json_decode(substr($raw, 0, $nl), true);
        $json = substr($raw, $nl + 1);
        if (!$head || hash('sha256', $json) !== $head['sha']) {
            throw new RuntimeException('The backup failed its integrity check.');
        }
        return array($head['meta'], json_decode($json, true));
    }

    public static function restoreClient($clientId, $blob, $passphrase, $dryRun = false)
    {
        list($meta, $payload) = self::open($blob, $passphrase);
        $client = DB::row('SELECT * FROM client_entities WHERE id = ? AND organization_id = ?', array($clientId, Ctx::orgId()));
        if (!$client) {
            throw new RuntimeException('Client not found.');
        }
        if ((int)$meta['client_id'] !== (int)$clientId || $meta['tin'] !== $client['tin']) {
            throw new RuntimeException('This backup belongs to client TIN ' . Tin::display($meta['tin']) . ' (#' . $meta['client_id'] . '), not the selected client.');
        }
        $summary = array();
        foreach ($payload['tables'] as $t => $rows) {
            $summary[$t] = count($rows);
        }
        if ($dryRun) {
            return array('meta' => $meta, 'summary' => $summary);
        }
        DB::tx(function () use ($clientId, $payload) {
            $plan = self::plan();
            list($w, $p) = self::q(self::revisionWhere(), $clientId);
            DB::q('DELETE FROM revisions WHERE ' . $w, $p);
            DB::q('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse(array_keys($plan)) as $t) {
                list($w, $p) = self::q($plan[$t], $clientId);
                if (in_array($t, array('validation_errors', 'import_rows', 'report_run_items', 'data_snapshots', 'official_validation_evidence', 'validation_runs'), true)) {
                    $ids = DB::col('SELECT id FROM ' . $t . ' WHERE ' . $w, $p);
                    if ($ids) {
                        DB::q('DELETE FROM ' . $t . ' WHERE id IN (' . DB::in($ids) . ')', $ids);
                    }
                } else {
                    DB::q('DELETE FROM ' . $t . ' WHERE ' . $w, $p);
                }
            }
            $c = $payload['tables']['client_entities'][0];
            unset($c['id']);
            DB::update('client_entities', $c, 'id = ?', array($clientId));
            foreach (array_merge(array_keys($plan), array('revisions')) as $t) {
                foreach (isset($payload['tables'][$t]) ? $payload['tables'][$t] : array() as $row) {
                    DB::insert($t, $row);
                }
            }
            DB::q('SET FOREIGN_KEY_CHECKS = 1');
        });
        foreach ($payload['files'] as $rel => $b64) {
            if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || preg_match('/^[a-z]:/i', $rel)) {
                continue;
            }
            $abs = storage_path($rel);
            if (!is_dir(dirname($abs))) {
                mkdir(dirname($abs), 0775, true);
            }
            file_put_contents($abs, base64_decode($b64));
        }
        DB::q('UPDATE backups SET last_restored_at = ? WHERE client_id = ? AND sha256 = ?', array(now(), $clientId, hash('sha256', $blob)));
        Audit::log('backup.restore', 'client_entities', $clientId, 'Restored client data from backup created ' . $meta['created_at'], null, $summary, null, $clientId);
        return array('meta' => $meta, 'summary' => $summary);
    }

    /** SQL dump of the whole database (data and, optionally, structure). */
    public static function sqlDump($withSchema = true, $tables = null, $skip = array())
    {
        $out = "-- G-SMaRT BIR Integration System SQL dump\n-- Generated " . date('Y-m-d H:i:s') . "\nSET NAMES utf8;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        $tables = $tables ?: DB::col('SHOW TABLES');
        foreach ($tables as $t) {
            if (in_array($t, $skip, true)) {
                continue;
            }
            if ($withSchema) {
                $create = DB::row('SHOW CREATE TABLE `' . $t . '`');
                $sql = $create['Create Table'];
                $sql = str_replace(array('utf8mb3_general_ci', 'utf8mb3'), array('utf8_general_ci', 'utf8'), $sql);
                $sql = preg_replace('/ COLLATE[= ]utf8_general_ci/', '', $sql);
                $out .= "DROP TABLE IF EXISTS `$t`;\n" . $sql . ";\n\n";
            }
            $st = DB::q('SELECT * FROM `' . $t . '`');
            $batch = array();
            $bytes = 0;
            $cols = null;
            while ($r = $st->fetch()) {
                if ($cols === null) {
                    $cols = '`' . implode('`,`', array_keys($r)) . '`';
                }
                $vals = array();
                foreach ($r as $v) {
                    $vals[] = $v === null ? 'NULL' : DB::pdo()->quote($v);
                }
                $row = '(' . implode(',', $vals) . ')';
                $batch[] = $row;
                $bytes += strlen($row);
                // Stay well under MySQL 5.5's default 1 MB max_allowed_packet.
                if (count($batch) >= 100 || $bytes > 400000) {
                    $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
                    $batch = array();
                    $bytes = 0;
                }
            }
            if ($batch) {
                $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
            }
            $out .= "\n";
        }
        return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    public static function createSystem($passphrase = '')
    {
        $sql = self::sqlDump(true);
        $gz = gzencode($sql, 6);
        $enc = $passphrase !== '';
        $blob = $enc ? Crypto::encryptWithPassphrase($gz, $passphrase) : $gz;
        $name = 'system-' . date('Ymd-His') . ($enc ? '.sql.gz.gsbk' : '.sql.gz');
        $rel = 'backups/' . $name;
        file_put_contents(storage_path($rel), $blob);
        $id = DB::insert('backups', array('organization_id' => null, 'client_id' => null, 'scope' => 'system', 'filename' => $name, 'stored_path' => $rel,
            'byte_size' => strlen($blob), 'sha256' => hash('sha256', $blob), 'encrypted' => $enc ? 1 : 0, 'record_count' => 0, 'notes' => null,
            'created_by' => Auth::id(), 'created_at' => now()));
        Audit::log('backup.system', 'backups', $id, 'Created full database backup' . ($enc ? ' (encrypted)' : ''));
        return $id;
    }
}
