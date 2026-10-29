<?php
/**
 * Report lifecycle: preview, generate (snapshot + DAT + Level 1 + Level 2), regenerate, supersede.
 *
 * File status:  structure_failed | pending_bir | bir_validated | bir_failed | approved | submitted | superseded
 * File label:   submission_ready only when Level 1 and Level 2 pass, the layout is approved and every ATC
 *               is approved; otherwise unvalidated_draft. Official validation is tracked separately
 *               and only an uploaded validator report can set bir_validated.
 */
class Reports
{
    /**
     * $in = array('form','schedule','branch_id','year','part','sawt_form','amended','source'=>'database'|'files','file_ids'=>[], 'srs_atcs'=>[])
     */
    public static function context($in, $override = null)
    {
        $client = Ctx::client();
        $sched = Datasets::schedule($in['form'], $in['schedule']);
        if (!$sched) {
            throw new RuntimeException('Choose a form and schedule.');
        }
        $branch = null;
        if ($sched['family'] !== 'RELIEF') {
            $branch = Ctx::branchAllowed($in['branch_id']);
            if (!$branch) {
                throw new RuntimeException('Choose a branch you can access.');
            }
        } else {
            $branch = Ctx::headOffice();
        }
        $sawt = $in['form'] === 'SAWT' ? strtoupper((string)$in['sawt_form']) : null;
        $sawtForms = Datasets::sawtForms();
        if ($in['form'] === 'SAWT' && !isset($sawtForms[$sawt])) {
            throw new RuntimeException('Choose the return the SAWT is attached to.');
        }
        list($from, $to, $label) = Datasets::period($sched['frequency'], $in['year'], $in['part'], $sawt);
        $vars = array();
        if ($sawt) {
            $vars['sawt_form'] = $sawt;
        }
        if ($in['form'] === 'SRS') {
            $vars['srs_atcs'] = !empty($in['srs_atcs']) ? $in['srs_atcs'] : explode(',', sys_config('srs_atcs', 'WI158,WC158,WI160,WC160'));
        }
        $agent = Datasets::agent($client, $branch);
        if ($override) {
            $ds = array('rows' => $override['rows'], 'source_table' => 'mixed', 'source_count' => $override['source_count']);
            $sourceMode = $override['source_mode'];
        } elseif (!empty($in['source']) && $in['source'] === 'files' && !empty($in['file_ids'])) {
            $ds = Datasets::fromDatFiles($in['file_ids'], $in['form'] === 'SRS' ? $vars['srs_atcs'] : null);
            $sourceMode = 'uploaded_dat';
        } else {
            $ds = Datasets::build(array('form' => $in['form'], 'schedule' => $in['schedule'], 'client' => $client, 'branch' => $sched['family'] === 'RELIEF' ? null : $branch,
                'from' => $from, 'to' => $to, 'vars' => $vars));
            $sourceMode = 'database';
        }
        // A combined schedule (1604-C Schedules 1 and 2) is written with the layouts of the schedules that have rows.
        $spec = Specs::resolveFor($in['form'], $in['schedule'], $sawt, $from, $to, $ds['rows']);
        if (!empty($spec['composite'])) {
            $vars['parts'] = array_map(function ($p) { return $p['id']; }, $spec['parts']);
        }
        $period = array('from' => $from, 'to' => $to, 'label' => $label);
        $dq = Checks::dataQuality($in['form'], $in['schedule'], $client, $agent, $ds['rows'], $spec, $period);
        $preview = $spec ? DatWriter::make($spec, array('agent' => $agent, 'period' => $period, 'rows' => $ds['rows'], 'vars' => $vars)) : null;
        if ($preview) {
            foreach ($preview['issues'] as $i) {
                $dq[] = $i;
            }
        }
        return array('client' => $client, 'branch' => $branch, 'sched' => $sched, 'spec' => $spec, 'agent' => $agent, 'period' => $period, 'vars' => $vars,
            'dataset' => $ds, 'dq' => $dq, 'preview' => $preview, 'source_mode' => $sourceMode, 'amended' => !empty($in['amended']) ? 1 : 0, 'in' => $in);
    }

    public static function totals($rows)
    {
        $base = 0;
        $tax = 0;
        foreach ($rows as $r) {
            foreach (array('income', 'taxable', 'pres_gross', 'landed_cost', 'other_goods') as $k) {
                if (isset($r[$k]) && is_int($r[$k])) {
                    $base += $r[$k];
                    break;
                }
            }
            foreach (array('tax', 'output_vat', 'tax_withheld_adjusted', 'input_tax', 'vat') as $k) {
                if (isset($r[$k]) && is_int($r[$k])) {
                    $tax += $r[$k];
                    break;
                }
            }
        }
        return array($base, $tax);
    }

    /** Generate and store the file. $allowDraft lets a permitted user export despite Level 1 errors. */
    public static function generate($ctx, $allowDraft = false, $notes = null)
    {
        Ctx::requireActive();
        if (!$ctx['spec']) {
            throw new RuntimeException('No layout is registered for this form and period.');
        }
        list($dqErr, $dqWarn) = Checks::counts($ctx['dq']);
        if ($dqErr && !$allowDraft) {
            throw new RuntimeException('Data quality found ' . $dqErr . ' errors. Fix them, or (if permitted) generate a diagnostic draft.');
        }
        if ($dqErr && !Ctx::can('dat.export_draft')) {
            throw new RuntimeException('Your role cannot create diagnostic drafts.');
        }
        if (!$ctx['dataset']['rows']) {
            throw new RuntimeException('There are no records to write for this period.');
        }
        $w = $ctx['preview'];
        list($base, $tax) = self::totals($ctx['dataset']['rows']);
        return DB::tx(function () use ($ctx, $w, $dqErr, $dqWarn, $base, $tax, $notes) {
            $client = $ctx['client'];
            $runId = DB::insert('report_runs', array(
                'organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => $ctx['branch'] ? $ctx['branch']['id'] : null,
                'form_code' => $ctx['sched']['form_code'], 'schedule_code' => $ctx['sched']['schedule_code'], 'variant_key' => isset($ctx['vars']['sawt_form']) ? $ctx['vars']['sawt_form'] : null,
                'period_from' => $ctx['period']['from'], 'period_to' => $ctx['period']['to'], 'period_label' => $ctx['period']['label'], 'amended' => $ctx['amended'],
                'source_mode' => $ctx['source_mode'], 'spec_id' => $ctx['spec']['id'], 'status' => 'prepared', 'row_count' => count($ctx['dataset']['rows']),
                'total_base' => Money::toPlain($base), 'total_tax' => Money::toPlain($tax), 'dq_errors' => $dqErr, 'dq_warnings' => $dqWarn, 'notes' => $notes,
                'prepared_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ));
            foreach ($ctx['dataset']['rows'] as $i => $r) {
                $src = isset($r['source_ids']) ? implode(',', array_slice($r['source_ids'], 0, 40)) : null;
                DB::insert('report_run_items', array('run_id' => $runId, 'item_no' => $i + 1, 'payload_json' => json_encode($r), 'source_refs' => $src ? mb_substr($src, 0, 255) : null));
            }
            $snap = array('agent' => $ctx['agent'], 'period' => $ctx['period'], 'vars' => $ctx['vars'], 'rows' => $ctx['dataset']['rows'], 'in' => $ctx['in']);
            $json = json_encode($snap);
            DB::insert('data_snapshots', array('run_id' => $runId, 'snapshot_gz' => base64_encode(gzcompress($json, 6)), 'sha256' => hash('sha256', $json), 'created_at' => now()));
            Checks::record(null, $runId, 1, $ctx['dq'], 'Data quality: ' . $dqErr . ' errors, ' . $dqWarn . ' warnings');
            $fileId = self::storeFile($runId, $ctx, $w, $dqErr, null);
            Audit::log('report.generate', 'dat_files', $fileId, 'Generated ' . $w['filename'] . ' (' . $ctx['period']['label'] . ')', null,
                array('run' => $runId, 'rows' => count($ctx['dataset']['rows']), 'layout' => $ctx['spec']['id']));
            return $fileId;
        });
    }

    private static function storeFile($runId, $ctx, $w, $dqErr, $supersedes)
    {
        $client = $ctx['client'];
        $rel = 'dat/' . $client['id'] . '/' . $ctx['sched']['form_code'] . '/' . $runId . '-' . $w['filename'];
        $abs = storage_path($rel);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        file_put_contents($abs, $w['bytes']);
        $structure = Checks::structure($ctx['spec'], $w['bytes'], $w['filename'], $ctx['agent'], $ctx['period'], $ctx['dataset']['rows'], $ctx['vars']);
        list($sErr, $sWarn) = Checks::counts($structure['issues']);
        $atcPending = 0;
        foreach ($ctx['dq'] as $i) {
            if ($i['code'] === 'ATC_UNAPPROVED') {
                $atcPending++;
            }
        }
        $ready = !$dqErr && !$sErr && $ctx['spec']['status'] === 'approved' && !$atcPending;
        list($base, $tax) = self::totals($ctx['dataset']['rows']);
        $branchId = $ctx['branch'] ? $ctx['branch']['id'] : null;
        $variant = isset($ctx['vars']['sawt_form']) ? $ctx['vars']['sawt_form'] : null;
        // A combined file also replaces the separate Schedule 1 and Schedule 2 files made for the same period before.
        $codes = Specs::isCombined($ctx['sched']['form_code'], $ctx['sched']['schedule_code'])
            ? array_merge(array(Specs::COMBINED_CODE), Specs::combinedParts($ctx['sched']['form_code'])) : array($ctx['sched']['schedule_code']);
        $prev = DB::all('SELECT id, status FROM dat_files WHERE client_id = ? AND form_code = ? AND schedule_code IN (' . DB::in($codes) . ') AND period_from = ? AND period_to = ?
            AND COALESCE(branch_id, 0) = ? AND COALESCE(variant_key, \'\') = ? AND superseded_by IS NULL AND origin = ? AND status <> ? ORDER BY id DESC',
            array_merge(array($client['id'], $ctx['sched']['form_code']), $codes, array($ctx['period']['from'], $ctx['period']['to'], (int)$branchId, (string)$variant, 'generated', 'superseded')));
        $fileId = DB::insert('dat_files', array(
            'organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => $branchId, 'run_id' => $runId,
            'form_code' => $ctx['sched']['form_code'], 'schedule_code' => $ctx['sched']['schedule_code'], 'variant_key' => $variant,
            'period_label' => $ctx['period']['label'], 'period_from' => $ctx['period']['from'], 'period_to' => $ctx['period']['to'],
            'filename' => $w['filename'], 'storage_path' => $rel, 'byte_size' => strlen($w['bytes']), 'sha256' => hash('sha256', $w['bytes']),
            'spec_id' => $ctx['spec']['id'], 'origin' => 'generated', 'amended' => $ctx['amended'],
            'status' => $sErr ? 'structure_failed' : 'pending_bir', 'file_label' => $ready ? 'submission_ready' : 'unvalidated_draft',
            'record_count' => count($ctx['dataset']['rows']), 'total_base' => Money::toPlain($base), 'total_tax' => Money::toPlain($tax),
            'parse_summary' => !empty($ctx['spec']['composite']) ? json_encode(array('parts' => array_map(function ($p) { return $p['id']; }, $ctx['spec']['parts']))) : null,
            'supersedes_id' => $supersedes ?: ($prev ? (int)$prev[0]['id'] : null), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ));
        foreach ($prev as $p) {
            if ($p['status'] === 'submitted') {
                continue;
            }
            DB::update('dat_files', array('status' => 'superseded', 'superseded_by' => $fileId, 'updated_at' => now()), 'id = ?', array($p['id']));
        }
        DB::q('UPDATE validation_runs SET dat_file_id = ? WHERE run_id = ? AND level = 1 AND dat_file_id IS NULL', array($fileId, $runId));
        Checks::record($fileId, $runId, 2, $structure['issues'], 'Structure: ' . $sErr . ' errors, ' . $sWarn . ' warnings');
        DB::update('report_runs', array('status' => $sErr ? 'structure_failed' : 'pending_bir', 'updated_at' => now()), 'id = ?', array($runId));
        return $fileId;
    }

    public static function snapshot($runId)
    {
        $s = DB::row('SELECT * FROM data_snapshots WHERE run_id = ? ORDER BY id DESC LIMIT 1', array($runId));
        if (!$s) {
            return null;
        }
        $json = gzuncompress(base64_decode($s['snapshot_gz']));
        if (hash('sha256', $json) !== $s['sha256']) {
            throw new RuntimeException('Snapshot checksum mismatch for run #' . $runId . '.');
        }
        return json_decode($json, true);
    }

    /**
     * Bytes of a stored DAT file. When the file is missing from storage (for example after
     * importing an SQL dump without the storage folder) a generated file is rebuilt from its
     * frozen snapshot with the layout it was made with, and restored only if the rebuilt bytes
     * hash to the recorded SHA-256. Returns null when the original bytes cannot be produced.
     */
    public static function storedBytes($f)
    {
        $abs = storage_path($f['storage_path']);
        if (is_file($abs)) {
            return file_get_contents($abs);
        }
        if (!$f['run_id'] || !$f['spec_id']) {
            return null;
        }
        try {
            $snap = self::snapshot($f['run_id']);
            if (!$snap) {
                return null;
            }
            $w = DatWriter::make(Specs::forFile($f), array('agent' => $snap['agent'], 'period' => $snap['period'], 'rows' => $snap['rows'], 'vars' => $snap['vars']));
        } catch (Exception $e) {
            return null;
        }
        if (hash('sha256', $w['bytes']) !== $f['sha256']) {
            return null;
        }
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        @file_put_contents($abs, $w['bytes']);
        Audit::log('dat.restore', 'dat_files', $f['id'], 'Restored missing ' . $f['filename'] . ' from its snapshot (checksum matched)');
        return $w['bytes'];
    }

    /** Re-serialize a run's frozen snapshot with the current best layout (new file, supersedes the old one). */
    public static function regenerate($fileId)
    {
        $f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array($fileId, Ctx::clientId()));
        if (!$f || !$f['run_id']) {
            throw new RuntimeException('Only generated files can be re-processed.');
        }
        if ($f['status'] === 'submitted') {
            throw new RuntimeException('Submitted files are frozen. Generate an amended file instead.');
        }
        $run = DB::row('SELECT * FROM report_runs WHERE id = ?', array($f['run_id']));
        $snap = self::snapshot($run['id']);
        $spec = Specs::resolveFor($run['form_code'], $run['schedule_code'], $run['variant_key'], $run['period_from'], $run['period_to'], $snap['rows']);
        if (!$spec) {
            throw new RuntimeException('No layout is registered for this form and period.');
        }
        if (!empty($spec['composite'])) {
            $snap['vars']['parts'] = array_map(function ($p) { return $p['id']; }, $spec['parts']);
        } else {
            unset($snap['vars']['parts']);
        }
        $sched = Datasets::schedule($run['form_code'], $run['schedule_code']);
        $branch = $run['branch_id'] ? DB::row('SELECT * FROM branches WHERE id = ?', array($run['branch_id'])) : null;
        $ctx = array('client' => Ctx::client(), 'branch' => $branch, 'sched' => $sched, 'spec' => $spec, 'agent' => $snap['agent'], 'period' => $snap['period'],
            'vars' => $snap['vars'], 'dataset' => array('rows' => $snap['rows']), 'amended' => (int)$run['amended'], 'in' => $snap['in'], 'source_mode' => $run['source_mode']);
        $ctx['dq'] = Checks::dataQuality($run['form_code'], $run['schedule_code'], $ctx['client'], $ctx['agent'], $snap['rows'], $spec, $snap['period']);
        $w = DatWriter::make($spec, array('agent' => $snap['agent'], 'period' => $snap['period'], 'rows' => $snap['rows'], 'vars' => $snap['vars']));
        foreach ($w['issues'] as $i) {
            $ctx['dq'][] = $i;
        }
        list($dqErr) = Checks::counts($ctx['dq']);
        return DB::tx(function () use ($ctx, $w, $dqErr, $run, $f) {
            Checks::record(null, $run['id'], 1, $ctx['dq'], 'Data quality (re-process): ' . $dqErr . ' errors');
            $id = self::storeFile($run['id'], $ctx, $w, $dqErr, $f['id']);
            Audit::log('report.regenerate', 'dat_files', $id, 'Re-processed ' . $f['filename'] . ' with layout #' . $ctx['spec']['id']);
            return $id;
        });
    }

    public static function fileStatusLine($f)
    {
        $parts = array(View::status($f['status']));
        if ($f['status'] !== 'bir_validated' && $f['status'] !== 'approved' && $f['status'] !== 'submitted') {
            $parts[] = View::status($f['file_label']);
        }
        return implode(' ', $parts);
    }
}
