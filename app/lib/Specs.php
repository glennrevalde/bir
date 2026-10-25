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
}
