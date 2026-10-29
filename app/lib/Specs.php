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
        $m = array('official' => 'Official annex', 'validated' => 'Validator-passed sample', 'medium' => 'Two or more sources agree',
            'low' => 'Single secondary source', 'analog' => 'Unsourced draft', 'high' => 'Official annex');
        return isset($m[$c]) ? $m[$c] : ucfirst($c);
    }

    /** Insert seed layouts (used by installer and the SQL builder). */
    public static function seed($userId = null)
    {
        require_once APP_PATH . '/specs/layouts.php';
        $n = 0;
        foreach (gsb_layouts() as $L) {
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
            $n++;
        }
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
     * schedule code, holding each schedule's detail lines followed by that schedule's control line.
     */
    const COMBINED_CODE = 'S1+S2';

    public static function combinedParts($form)
    {
        $m = array('1604C' => array('S1', 'S2'));
        return isset($m[$form]) ? $m[$form] : null;
    }

    public static function isCombined($form, $schedule)
    {
        return $schedule === self::COMBINED_CODE && self::combinedParts($form) !== null;
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

    /** "1604C S1+S2" reads as "1604C Schedules 1 and 2". */
    public static function scheduleLabel($schedule)
    {
        return $schedule === self::COMBINED_CODE ? 'Schedules 1 and 2' : (string)$schedule;
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
