<?php
/**
 * Layout registry access: load, resolve by form/schedule/period, build filenames, seed.
 */
class Specs
{
    private static $cache = array();

    public static function load($id)
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $s = DB::row('SELECT * FROM layout_specs WHERE id = ?', array($id));
        if (!$s) {
            return null;
        }
        $s['records'] = DB::all('SELECT * FROM spec_records WHERE spec_id = ? ORDER BY sort_order, id', array($id));
        foreach ($s['records'] as &$r) {
            $r['fields'] = DB::all('SELECT * FROM spec_fields WHERE spec_record_id = ? ORDER BY position', array($r['id']));
        }
        unset($r);
        self::$cache[$id] = $s;
        return $s;
    }

    public static function forget($id = null)
    {
        if ($id === null) {
            self::$cache = array();
        } else {
            unset(self::$cache[$id]);
        }
    }

    public static function statusRank($s)
    {
        $r = array('approved' => 3, 'pending_verification' => 2, 'draft' => 1, 'retired' => 0);
        return isset($r[$s]) ? $r[$s] : 0;
    }

    /** Pick the best layout effective for the period: approved > pending > draft, then newest. */
    public static function resolve($form, $schedule, $variant, $periodFrom, $periodTo)
    {
        $rows = DB::all('SELECT * FROM layout_specs WHERE form_code = ? AND (schedule_code = ? OR schedule_code IS NULL) AND status <> ?
            AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC',
            array($form, $schedule, 'retired', $periodTo, $periodFrom));
        $best = null;
        foreach ($rows as $r) {
            $variantOk = ($r['variant_key'] === null || $r['variant_key'] === '') ? 1 : ($r['variant_key'] === $variant ? 2 : 0);
            if (!$variantOk) {
                continue;
            }
            $score = $variantOk * 10 + self::statusRank($r['status']);
            if (!$best || $score > $best[0]) {
                $best = array($score, $r);
            }
        }
        return $best ? self::load($best[1]['id']) : null;
    }

    public static function filename($spec, $agent, $period, $vars = array())
    {
        $to = $period['to'];
        $sem = (int)substr($to, 5, 2) <= 6 ? '1' : '2';
        $map = array(
            '{TIN}' => $agent['tin'], '{BRANCH}' => $agent['branch'], '{MM}' => substr($to, 5, 2), '{YYYY}' => substr($to, 0, 4),
            '{MMDDYYYY}' => substr($to, 5, 2) . substr($to, 8, 2) . substr($to, 0, 4), '{SEM}' => $sem,
            '{SAWT_FORM}' => isset($vars['sawt_form']) ? $vars['sawt_form'] : '',
        );
        return strtr($spec['filename_pattern'], $map);
    }

    /** Regex that recognises filenames made from a pattern; named groups for the tokens. */
    public static function filenameRegex($pattern)
    {
        $parts = preg_split('/(\{[A-Z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $tok = array('{TIN}' => '(?P<tin>\d{9})', '{BRANCH}' => '(?P<branch>\d{4,5})', '{MM}' => '(?P<mm>\d{2})', '{YYYY}' => '(?P<yyyy>\d{4})',
            '{MMDDYYYY}' => '(?P<mmddyyyy>\d{8})', '{SEM}' => '(?P<sem>[12])', '{SAWT_FORM}' => '(?P<sawt>[0-9]{4}[A-Z]?)');
        $re = '';
        foreach ($parts as $p) {
            $re .= isset($tok[$p]) ? $tok[$p] : preg_quote($p, '/');
        }
        return '/^' . $re . '$/i';
    }

    public static function confidenceLabel($c)
    {
        $m = array('official' => 'Official BIR source', 'validated' => 'Validator-passed sample', 'medium' => 'Two or more sources agree',
            'low' => 'Single secondary source', 'analog' => 'Unsourced draft', 'high' => 'Official BIR source');
        return isset($m[$c]) ? $m[$c] : ucfirst($c);
    }

    /** Insert seed layouts (used by installer and the SQL builder). */
    public static function seed($userId = null)
    {
        require_once APP_PATH . '/specs/layouts.php';
        $n = 0;
        foreach (gsb_layouts() as $L) {
            self::insertLayout($L, $userId);
            $n++;
        }
        return $n;
    }

    /** Insert one layout definition from app/specs/layouts.php; returns the new layout id. */
    public static function insertLayout($L, $userId = null)
    {
        $id = DB::insert('layout_specs', array(
            'form_code' => $L['form_code'], 'schedule_code' => $L['schedule_code'], 'variant_key' => $L['variant_key'], 'version_label' => $L['version_label'],
            'validator_version' => $L['validator_version'], 'effective_from' => $L['effective_from'], 'effective_to' => null, 'status' => $L['status'],
            'confidence' => $L['confidence'], 'filename_pattern' => $L['filename_pattern'], 'delimiter' => $L['delimiter'], 'line_ending' => $L['line_ending'],
            'trailing_newline' => $L['trailing_newline'], 'encoding' => $L['encoding'], 'column_header_row' => $L['column_header_row'], 'text_policy' => 'bir_upper',
            'source_ref' => $L['source_ref'], 'notes' => $L['notes'], 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ));
        foreach ($L['records'] as $i => $R) {
            $rid = DB::insert('spec_records', array('spec_id' => $id, 'record_code' => $R['record_code'], 'role' => $R['role'], 'label' => $R['label'], 'sort_order' => $i + 1));
            foreach ($R['fields'] as $pos => $F) {
                DB::insert('spec_fields', array_merge($F, array('spec_record_id' => $rid, 'position' => $pos + 1)));
            }
        }
        return $id;
    }

    /**
     * Bring the 1604-C layouts of an existing installation in line with the BIR Alphalist Data Entry and
     * Validation module v7.4: Schedule 1 writes the actual amount withheld in its "tax withheld as adjusted"
     * column, and Schedule 2 gets the module's 59-field D2 and 45-field C2 lines, replacing the unsourced draft.
     * Returns the number of changes.
     */
    public static function update1604cLayouts()
    {
        require_once APP_PATH . '/specs/layouts.php';
        $n = 0;
        $n += DB::q("UPDATE spec_fields f JOIN spec_records r ON r.id = f.spec_record_id JOIN layout_specs s ON s.id = r.spec_id
            SET f.source_expr = 'row.actual_withheld', f.label = 'Amount of tax withheld as adjusted (actual amount withheld)'
            WHERE s.form_code = '1604C' AND s.schedule_code = 'S1' AND f.field_key = 'tax_withheld_adjusted' AND f.source_expr = 'row.tax_withheld_adjusted'")->rowCount();
        $n += DB::q("UPDATE spec_fields f JOIN spec_records r ON r.id = f.spec_record_id JOIN layout_specs s ON s.id = r.spec_id
            SET f.source_expr = 'sum.actual_withheld', f.label = 'Total actual amount withheld'
            WHERE s.form_code = '1604C' AND s.schedule_code = 'S1' AND f.field_key = 'tax_withheld_adjusted' AND f.source_expr = 'sum.tax_withheld_adjusted'")->rowCount();
        foreach (gsb_layouts() as $L) {
            if ($L['form_code'] !== '1604C' || $L['schedule_code'] !== 'S2') {
                continue;
            }
            if (!DB::val('SELECT id FROM layout_specs WHERE form_code = ? AND schedule_code = ? AND version_label = ?', array('1604C', 'S2', $L['version_label']))) {
                $id = self::insertLayout($L, null);
                DB::q("UPDATE layout_specs SET status = 'retired', updated_at = ? WHERE form_code = '1604C' AND schedule_code = 'S2' AND id <> ? AND status IN ('draft', 'pending_verification')
                    AND version_label = 'Analog draft (unsourced)'", array(now(), $id));
                $n++;
            }
        }
        self::forget();
        return $n;
    }

    /**
     * Migration 6: the SAWT layout of the BIR Alphalist module v7.4 replaces the earlier generic layout and
     * its 1702Q variant, which came from secondary sources. Files already generated keep their own layout.
     */
    public static function updateSawtLayouts()
    {
        require_once APP_PATH . '/specs/layouts.php';
        $n = 0;
        foreach (gsb_layouts() as $L) {
            if ($L['form_code'] !== 'SAWT') {
                continue;
            }
            $id = DB::val('SELECT id FROM layout_specs WHERE form_code = ? AND schedule_code = ? AND version_label = ?', array('SAWT', 'S1', $L['version_label']));
            if (!$id) {
                $id = self::insertLayout($L, null);
                $n++;
            }
            $n += DB::q("UPDATE layout_specs SET status = 'retired', updated_at = ? WHERE form_code = 'SAWT' AND id <> ? AND status <> 'retired'
                AND version_label IN ('Alphalist v7.x SAWT', 'Alphalist v7.x SAWT (1702Q shape)')", array(now(), $id))->rowCount();
        }
        self::forget();
        return $n;
    }

    /**
     * QAP, MAP, 1604-E and 1604-F in the format of the BIR Alphalist module v7.4: adds the module's layouts
     * and retires the earlier secondary-source and analog layouts they replace. Files already generated
     * keep their own layout. Layouts a user cloned or edited are left as they are.
     */
    public static function updateV74WithholdingLayouts()
    {
        require_once APP_PATH . '/specs/layouts.php';
        $forms = array('1601EQ', '1601FQ', '1600VT', '1600PT', '1604E', '1604F');
        $n = 0;
        foreach (gsb_layouts() as $L) {
            if (!in_array($L['form_code'], $forms, true)) {
                continue;
            }
            $id = DB::val('SELECT id FROM layout_specs WHERE form_code = ? AND schedule_code = ? AND version_label = ?', array($L['form_code'], $L['schedule_code'], $L['version_label']));
            if (!$id) {
                $id = self::insertLayout($L, null);
                $n++;
            }
            $n += DB::q("UPDATE layout_specs SET status = 'retired', updated_at = ? WHERE form_code = ? AND schedule_code = ? AND id <> ? AND status <> 'retired'
                AND version_label IN ('Alphalist v7.x QAP', 'Alphalist v7.x MAP', 'Analog draft (unsourced)')", array(now(), $L['form_code'], $L['schedule_code'], $id))->rowCount();
        }
        self::forget();
        return $n;
    }

    /** Copy a layout into a new draft version for editing. */
    public static function cloneAsDraft($id, $label)
    {
        $s = self::load($id);
        return DB::tx(function () use ($s, $label) {
            $nid = DB::insert('layout_specs', array(
                'form_code' => $s['form_code'], 'schedule_code' => $s['schedule_code'], 'variant_key' => $s['variant_key'], 'version_label' => $label,
                'validator_version' => $s['validator_version'], 'effective_from' => $s['effective_from'], 'effective_to' => null, 'status' => 'draft',
                'confidence' => $s['confidence'], 'filename_pattern' => $s['filename_pattern'], 'delimiter' => $s['delimiter'], 'line_ending' => $s['line_ending'],
                'trailing_newline' => $s['trailing_newline'], 'encoding' => $s['encoding'], 'column_header_row' => $s['column_header_row'], 'text_policy' => $s['text_policy'],
                'source_ref' => $s['source_ref'], 'notes' => 'Copied from layout #' . $s['id'] . '. ' . $s['notes'], 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ));
            foreach ($s['records'] as $r) {
                $rid = DB::insert('spec_records', array('spec_id' => $nid, 'record_code' => $r['record_code'], 'role' => $r['role'], 'label' => $r['label'], 'sort_order' => $r['sort_order']));
                foreach ($r['fields'] as $f) {
                    unset($f['id']);
                    $f['spec_record_id'] = $rid;
                    DB::insert('spec_fields', $f);
                }
            }
            Audit::log('spec.clone', 'layout_specs', $nid, 'Created draft layout from #' . $s['id']);
            return $nid;
        });
    }

    /**
     * Forms whose schedules the BIR system keeps in one file. Their files are generated with the combined
     * schedule code, holding each schedule's detail lines followed by that schedule's control line. The
     * BIR Alphalist module v7.4 writes the 1601-EQ, 1601-FQ, 1604-C, 1604-E and 1604-F this way.
     */
    const COMBINED_CODE = 'S1+S2';

    /** form => array(combined code, schedules, label, note shown on the Generate page) */
    public static function combinedForms()
    {
        return array(
            '1604C' => array('S1+S2', array('S1', 'S2'), 'Schedules 1 and 2', 'The BIR system keeps Schedule 1 and Schedule 2 (minimum wage earners) in one 1604-C file, so both are written together.'),
            '1601EQ' => array('S1+S2', array('S1', 'S2'), 'Schedules 1 and 2', 'The BIR module writes the month\'s Schedule 1 (expanded withholding) and Schedule 2 (payees exempt from withholding) in one 1601-EQ file.'),
            '1601FQ' => array('S1-3', array('S1', 'S2', 'S3'), 'Schedules 1 to 3', 'The BIR module writes the month\'s Schedule 1 (final withholding), Schedule 2 (fringe benefits) and Schedule 3 (exempt payees) in one 1601-FQ file.'),
            '1604E' => array('S3+S4', array('S3', 'S4'), 'Schedules 3 and 4', 'The BIR module writes Schedule 3 (expanded withholding) and Schedule 4 (payees exempt from withholding) in one 1604-E file.'),
            '1604F' => array('S4-6', array('S4', 'S5', 'S6'), 'Schedules 4 to 6', 'The BIR module writes Schedule 4 (final withholding), Schedule 5 (fringe benefits) and Schedule 6 (exempt payees) in one 1604-F file.'),
        );
    }

    public static function combinedParts($form)
    {
        $m = self::combinedForms();
        return isset($m[$form]) ? $m[$form][1] : null;
    }

    /** The schedule code of a form's combined file (S1+S2, S1-3, S3+S4 or S4-6), or null. */
    public static function combinedCode($form)
    {
        $m = self::combinedForms();
        return isset($m[$form]) ? $m[$form][0] : null;
    }

    public static function combinedNote($form)
    {
        $m = self::combinedForms();
        return isset($m[$form]) ? $m[$form][3] : '';
    }

    public static function isCombined($form, $schedule)
    {
        $code = self::combinedCode($form);
        return $code !== null && $schedule === $code;
    }

    /**
     * One layout made of several schedule layouts: the first layout's header, then each layout's detail and
     * control records, tagged with the part they belong to. With one part this is that layout unchanged.
     * The status is the weakest of the parts, so the file is submission-ready only when every part is approved.
     */
    public static function composite($specs)
    {
        $specs = array_values(array_filter($specs));
        if (!$specs) {
            return null;
        }
        if (count($specs) === 1) {
            return $specs[0];
        }
        $c = $specs[0];
        $c['composite'] = true;
        $c['parts'] = array();
        $c['records'] = array();
        foreach ($specs[0]['records'] as $r) {
            if ($r['role'] === 'header') {
                $c['records'][] = $r;
            }
        }
        foreach ($specs as $p => $s) {
            $c['parts'][] = array('id' => (int)$s['id'], 'schedule_code' => $s['schedule_code'], 'status' => $s['status'], 'version_label' => $s['version_label'],
                'confidence' => $s['confidence']);
            if (self::statusRank($s['status']) < self::statusRank($c['status'])) {
                $c['status'] = $s['status'];
            }
            foreach ($s['records'] as $r) {
                if ($r['role'] !== 'header') {
                    $r['part'] = $p;
                    $c['records'][] = $r;
                }
            }
        }
        return $c;
    }

    /** The layouts of a combined file, one per schedule part (empty for an ordinary layout). */
    public static function partsOf($spec)
    {
        if (!$spec) {
            return array();
        }
        return !empty($spec['composite']) ? $spec['parts'] : array(array('id' => (int)$spec['id'], 'schedule_code' => $spec['schedule_code'], 'status' => $spec['status'],
            'version_label' => $spec['version_label'], 'confidence' => $spec['confidence']));
    }

    /** Index of the part a dataset row belongs to (by its schedule code), or 0 for an ordinary layout. */
    public static function partOf($spec, $row)
    {
        if (empty($spec['composite'])) {
            return 0;
        }
        $code = isset($row['schedule_code']) ? $row['schedule_code'] : null;
        foreach ($spec['parts'] as $i => $p) {
            if ($p['schedule_code'] === $code) {
                return $i;
            }
        }
        return null;
    }

    /**
     * The layout for a form and schedule. For a combined schedule, the layouts of the schedules that have
     * rows (all of them when $rows is null) joined into one.
     */
    public static function resolveFor($form, $schedule, $variant, $from, $to, $rows = null)
    {
        if (!self::isCombined($form, $schedule)) {
            return self::resolve($form, $schedule, $variant, $from, $to);
        }
        $used = array();
        if ($rows !== null) {
            foreach ($rows as $r) {
                if (isset($r['schedule_code'])) {
                    $used[$r['schedule_code']] = true;
                }
            }
        }
        $specs = array();
        foreach (self::combinedParts($form) as $code) {
            if ($rows !== null && $rows && !isset($used[$code])) {
                continue;
            }
            $s = self::resolve($form, $code, $variant, $from, $to);
            if (!$s) {
                return null;
            }
            $specs[] = $s;
        }
        return self::composite($specs);
    }

    /** The layout a stored file was written or read with; combined files list their parts in parse_summary. */
    public static function forFile($f)
    {
        if (empty($f['spec_id'])) {
            return null;
        }
        if (self::isCombined($f['form_code'], $f['schedule_code'])) {
            $ps = json_decode((string)(isset($f['parse_summary']) ? $f['parse_summary'] : ''), true);
            $ids = !empty($ps['parts']) ? $ps['parts'] : array((int)$f['spec_id']);
            return self::composite(array_map(array('Specs', 'load'), $ids));
        }
        return self::load($f['spec_id']);
    }

    /** A layout chosen on the upload page: a layout id, or "c:12,13" for layouts joined for a combined file. */
    public static function byKey($key)
    {
        $key = (string)$key;
        if (strpos($key, 'c:') === 0) {
            $ids = array_filter(array_map('intval', explode(',', substr($key, 2))));
            $specs = array();
            foreach ($ids as $id) {
                $s = self::load($id);
                if (!$s) {
                    return null;
                }
                $specs[] = $s;
            }
            return self::composite($specs);
        }
        return self::load((int)$key);
    }

    /** "1604C S1+S2" reads as "1604C Schedules 1 and 2", "1601FQ S1-3" as "1601FQ Schedules 1 to 3". */
    public static function scheduleLabel($schedule)
    {
        foreach (self::combinedForms() as $c) {
            if ($schedule === $c[0]) {
                return $c[2];
            }
        }
        return (string)$schedule;
    }

    /**
     * A counterparty's or employee's TIN and its branch code may be blank in a DAT file: the BIR validator
     * accepts a line without a TIN, so the system writes and reads it without calling it an error.
     */
    public static function tinMayBeBlank($f)
    {
        return isset($f['source_expr']) && in_array($f['source_expr'], array('row.tin', 'row.branch', 'row.tin_branch'), true);
    }
}
