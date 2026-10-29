<?php
/**
 * The tax code (ATC) registry without dates on screen: the current version of each code, and adding or
 * changing codes one by one or from an Excel file.
 *
 * Versions are still kept underneath so filed periods never change: a new code applies from the start
 * (2000-01-01); a change to an existing code applies from the day it is made, and earlier periods keep
 * the rate they had. When approvals are turned on, every addition or change waits for a second person.
 */
class TaxCodes
{
    const FROM_START = '2000-01-01';
    const KINDS = 'EWT,FWT,GVAT,GPT';

    public static function kinds()
    {
        return array('EWT' => 'Expanded', 'FWT' => 'Final', 'GVAT' => 'Government VAT', 'GPT' => 'Government PT');
    }

    public static function payeeTypes()
    {
        return array('corporate' => 'Non-individual', 'individual' => 'Individual', 'any' => 'Any');
    }

    public static function payeeLabel($t)
    {
        $p = self::payeeTypes();
        return isset($p[$t]) ? $p[$t] : $t;
    }

    /** The latest version of each code and kind that is not retired (pending changes included), by code. */
    public static function current($q = '', $kind = '')
    {
        $sql = 'SELECT t.*, a.full_name AS approver, s.full_name AS submitter FROM tax_codes t LEFT JOIN users a ON a.id = t.approved_by LEFT JOIN users s ON s.id = t.submitted_by WHERE t.status <> ?';
        $p = array('retired');
        if ($q !== '') {
            $sql .= ' AND (t.atc_code LIKE ? OR t.description LIKE ?)';
            $p[] = '%' . $q . '%';
            $p[] = '%' . $q . '%';
        }
        if ($kind !== '') {
            $sql .= ' AND t.tax_kind = ?';
            $p[] = $kind;
        }
        $out = array();
        foreach (DB::all($sql . ' ORDER BY t.atc_code, t.tax_kind, t.effective_from DESC, t.id DESC', $p) as $r) {
            $k = $r['atc_code'] . '|' . $r['tax_kind'];
            if (!isset($out[$k])) {
                $r['older'] = array();
                $out[$k] = $r;
            } else {
                $out[$k]['older'][] = $r;
            }
        }
        return array_values($out);
    }

    /** The version in force before a pending change, for "replaces" notes. */
    public static function inForce($row)
    {
        foreach ($row['older'] as $o) {
            if ($o['status'] === 'approved') {
                return $o;
            }
        }
        return null;
    }

    /**
     * Check one code. Returns array($data, $errors) where $errors is keyed by field: atc_code, tax_kind,
     * payee_type, rate, description.
     */
    public static function validate($in)
    {
        $e = array();
        $code = strtoupper(preg_replace('/\s+/', '', (string)(isset($in['atc_code']) ? $in['atc_code'] : '')));
        if ($code === '') {
            $e['atc_code'] = 'ATC is required';
        } elseif (!preg_match('/^[A-Z0-9]{3,10}$/', $code)) {
            $e['atc_code'] = 'ATC must be 3 to 10 letters and digits, such as WC158';
        }
        $kind = strtoupper(trim((string)(isset($in['tax_kind']) ? $in['tax_kind'] : '')));
        $kindWords = array('EXPANDED' => 'EWT', 'CREDITABLE' => 'EWT', 'FINAL' => 'FWT', 'GOVERNMENT VAT' => 'GVAT', 'GOVERNMENT PT' => 'GPT');
        $kind = $kind === '' ? 'EWT' : (isset($kindWords[$kind]) ? $kindWords[$kind] : $kind);
        if (!in_array($kind, explode(',', self::KINDS), true)) {
            $e['tax_kind'] = 'KIND must be EWT, FWT, GVAT or GPT';
        }
        $pt = strtolower(trim((string)(isset($in['payee_type']) ? $in['payee_type'] : '')));
        $ptWords = array('' => 'any', 'any' => 'any', 'both' => 'any', 'corporate' => 'corporate', 'corporation' => 'corporate', 'non-individual' => 'corporate',
            'non individual' => 'corporate', 'nonindividual' => 'corporate', 'company' => 'corporate', 'individual' => 'individual', 'person' => 'individual');
        if (isset($ptWords[$pt])) {
            $pt = $ptWords[$pt];
        } else {
            $e['payee_type'] = 'PAYEE TYPE must be Non-individual, Individual or Any';
        }
        $rateRaw = trim(str_replace('%', '', (string)(isset($in['rate']) ? $in['rate'] : '')));
        $units = $rateRaw === '' ? null : Money::rateUnits($rateRaw);
        if ($rateRaw === '') {
            $e['rate'] = 'RATE is required';
        } elseif ($units === null || $units > 1000000) {
            $e['rate'] = 'RATE must be a percentage such as 1, 2 or 0.5';
        }
        $desc = trim(preg_replace('/\s+/', ' ', (string)(isset($in['description']) ? $in['description'] : '')));
        if ($desc === '') {
            $e['description'] = 'DESCRIPTION is required';
        } elseif (mb_strlen($desc) > 255) {
            $e['description'] = 'DESCRIPTION is longer than 255 characters';
        }
        if (!$e && preg_match('/^WI/', $code) && $pt === 'corporate') {
            $e['payee_type'] = 'Codes starting with WI are for individual payees';
        } elseif (!$e && preg_match('/^WC/', $code) && $pt === 'individual') {
            $e['payee_type'] = 'Codes starting with WC are for non-individual payees';
        }
        return array(array('atc_code' => $code, 'tax_kind' => $kind, 'payee_type' => $pt, 'rate' => $units === null ? null : Money::rateString($units),
            'description' => $desc), $e);
    }

    /**
     * What saving $d would do: 'new', 'change' (with the version it replaces), 'same' (nothing to do) or
     * 'waiting' (a change to this code is already waiting for approval).
     */
    public static function plan($d)
    {
        $rows = DB::all('SELECT * FROM tax_codes WHERE atc_code = ? AND tax_kind = ? AND status <> ? ORDER BY effective_from DESC, id DESC', array($d['atc_code'], $d['tax_kind'], 'retired'));
        if (!$rows) {
            return array('new', null);
        }
        $latest = $rows[0];
        if ($latest['status'] === 'pending_review' && $latest['effective_from'] >= today()) {
            return array('waiting', $latest);
        }
        if (Money::rateUnits($latest['rate']) === Money::rateUnits($d['rate']) && $latest['payee_type'] === $d['payee_type'] && $latest['description'] === $d['description']) {
            return array('same', $latest);
        }
        return array('change', $latest);
    }

    /** Save one checked code for approval. Returns the new tax_codes id, or null when nothing changed. */
    public static function propose($d, $why)
    {
        list($what) = self::plan($d);
        if ($what === 'same') {
            return null;
        }
        if ($what === 'waiting') {
            throw new RuntimeException('A change to ' . $d['atc_code'] . ' is already waiting for approval.');
        }
        $from = $what === 'new' ? self::FROM_START : today();
        if (DB::val('SELECT id FROM tax_codes WHERE atc_code = ? AND effective_from = ?', array($d['atc_code'], $from))) {
            throw new RuntimeException($d['atc_code'] . ' was already changed today. Approve or wait for that change first.');
        }
        $id = DB::insert('tax_codes', array('atc_code' => $d['atc_code'], 'tax_kind' => $d['tax_kind'], 'payee_type' => $d['payee_type'], 'description' => mb_substr($d['description'], 0, 255),
            'rate' => $d['rate'], 'effective_from' => $from, 'effective_to' => null, 'version_tag' => null, 'source_ref' => null,
            'status' => Workflow::approvalsRequired() ? 'pending_review' : 'approved', 'approved_by' => Workflow::approvalsRequired() ? null : Auth::id(),
            'approved_at' => Workflow::approvalsRequired() ? null : now(), 'submitted_by' => Auth::id(), 'created_at' => now()));
        if (!Workflow::approvalsRequired()) {
            Audit::log('taxcode.save', 'tax_codes', $id, ($what === 'new' ? 'Added ATC ' : 'Changed ATC ') . $d['atc_code'] . ' ' . $d['rate'] . '%' . ($why ? ' (' . $why . ')' : ''));
        } elseif (Ctx::orgId()) {
            Workflow::request('tax_codes', $id, 'approve_tax_code', ($what === 'new' ? 'New ATC ' : 'Changed ATC ') . $d['atc_code'] . ($why ? ' (' . $why . ')' : ''));
        } else {
            Audit::log('taxcode.propose', 'tax_codes', $id, ($what === 'new' ? 'New ATC ' : 'Changed ATC ') . $d['atc_code'] . ($why ? ' (' . $why . ')' : ''));
        }
        return $id;
    }

    /** Approve one pending version (maker-checker: not by the person who proposed it). */
    public static function approve($id, $note = '')
    {
        $t = DB::row('SELECT * FROM tax_codes WHERE id = ?', array($id));
        if (!$t || $t['status'] !== 'pending_review') {
            throw new RuntimeException('Nothing to approve.');
        }
        if ((int)$t['submitted_by'] === Auth::id()) {
            throw new RuntimeException('Someone other than the person who added ' . $t['atc_code'] . ' must approve it.');
        }
        $req = DB::val('SELECT id FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND decision = ?', array('tax_codes', $t['id'], 'pending'));
        if ($req) {
            Workflow::decide($req, true, $note ?: 'Approved from the tax code registry');
        } else {
            DB::update('tax_codes', array('status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()), 'id = ?', array($t['id']));
            Audit::log('taxcode.approve', 'tax_codes', $t['id'], 'Approved ATC ' . $t['atc_code'] . ' ' . $t['rate'] . '%', null, null, $note);
        }
        return $t;
    }

    // ------------------------------------------------------------------ Excel upload
    public static function columns()
    {
        return array(
            array('key' => 'atc_code', 'title' => 'ATC', 'format' => 'text', 'width' => 11, 'help' => 'Alphanumeric tax code, for example WC158.'),
            array('key' => 'tax_kind', 'title' => 'KIND', 'format' => 'text', 'width' => 8, 'help' => 'EWT (expanded), FWT (final), GVAT or GPT. Blank means EWT.', 'list' => array('EWT', 'FWT', 'GVAT', 'GPT')),
            array('key' => 'payee_type', 'title' => 'PAYEE TYPE', 'format' => 'text', 'width' => 16, 'help' => 'Non-individual, Individual or Any. Blank means Any.', 'list' => array('Non-individual', 'Individual', 'Any')),
            array('key' => 'rate', 'title' => 'RATE', 'format' => 'text', 'width' => 9, 'help' => 'Percent, for example 1 for 1% or 0.5 for 0.5%.'),
            array('key' => 'description', 'title' => 'DESCRIPTION', 'format' => 'text', 'width' => 80, 'help' => 'The description of the income payment, as in the BIR ATC table.'),
        );
    }

    /** The upload template; with $withCodes it lists every current code, to edit and upload back. */
    public static function template($withCodes)
    {
        $rows = array();
        if ($withCodes) {
            foreach (self::current() as $r) {
                $rows[] = array($r['atc_code'], $r['tax_kind'], self::payeeLabel($r['payee_type']), rtrim(rtrim(Money::rateFormat(Money::rateUnits($r['rate']), 'pct2'), '0'), '.'), $r['description']);
            }
        }
        $cols = self::columns();
        $lists = array();
        foreach ($cols as $i => $c) {
            if (!empty($c['list'])) {
                $lists[$i] = $c['list'];
            }
        }
        $fields = array();
        foreach ($cols as $c) {
            $fields[] = array($c['title'], $c['key'] === 'atc_code' || $c['key'] === 'rate' || $c['key'] === 'description' ? 'Yes' : 'No', $c['help']);
        }
        return XlsxWriter::build(array(
            array('name' => 'Tax codes', 'columns' => $cols, 'rows' => $rows, 'freeze' => true, 'lists' => $lists),
            array('name' => 'Fields', 'columns' => array(array('title' => 'Column', 'width' => 16, 'format' => 'text'), array('title' => 'Required', 'width' => 10, 'format' => 'text'),
                array('title' => 'How to fill', 'width' => 80, 'format' => 'text')), 'rows' => array_merge($fields, array(array('', '', ''),
                array('Rules', '', 'One code per row. A new code is added; a code already in the system with a different rate, payee type or description becomes a change from today, and earlier periods keep the old rate. Unchanged codes are skipped. Everything waits for a second person to approve.'))),
                'freeze' => true),
        ), array('title' => 'Tax codes (ATC) upload'));
    }

    /**
     * Read an uploaded workbook or CSV. Returns array('rows' => [...], 'problems' => [...]) where each row has
     * row, data, errors (keyed by field, each message with its cell), plan and current.
     */
    public static function read($absPath, $ext)
    {
        $sheetRows = $ext === 'csv' ? CsvReader::rows($absPath, 5001) : (function () use ($absPath) {
            $r = new XlsxReader($absPath);
            $names = $r->sheetNames();
            return $r->rows(in_array('Tax codes', $names, true) ? 'Tax codes' : null, 5001);
        })();
        $problems = array();
        // Find the header in the first rows: the row with ATC and RATE.
        $headerRow = null;
        $map = array();
        $aliases = array('atc_code' => array('ATC', 'ATCCODE', 'CODE', 'TAXCODE'), 'tax_kind' => array('KIND', 'TAXKIND', 'TYPE'),
            'payee_type' => array('PAYEETYPE', 'PAYEE', 'TAXPAYERTYPE', 'APPLIESTO'), 'rate' => array('RATE', 'TAXRATE', 'RATE%', 'RATEPCT'),
            'description' => array('DESCRIPTION', 'NATUREOFINCOMEPAYMENT', 'NATUREOFPAYMENT', 'NATURE'));
        foreach ($sheetRows as $rn => $cells) {
            if ($rn > 10) {
                break;
            }
            $found = array();
            foreach ($cells as $i => $c) {
                $h = preg_replace('/[^A-Z0-9%]/', '', strtoupper((string)$c['v']));
                foreach ($aliases as $k => $names) {
                    if (!isset($found[$k]) && in_array($h, $names, true)) {
                        $found[$k] = $i;
                    }
                }
            }
            if (isset($found['atc_code']) && isset($found['rate'])) {
                $headerRow = $rn;
                $map = $found;
                break;
            }
        }
        if ($headerRow === null) {
            return array('rows' => array(), 'problems' => array('The file has no header row with the columns ATC and RATE. Use the template: ATC, KIND, PAYEE TYPE, RATE, DESCRIPTION.'));
        }
        foreach (array('description' => 'DESCRIPTION') as $k => $t) {
            if (!isset($map[$k])) {
                $problems[] = 'The file has no ' . $t . ' column, so every row will be refused. Add it and upload again.';
            }
        }
        $out = array();
        $seen = array();
        foreach ($sheetRows as $rn => $cells) {
            if ($rn <= $headerRow) {
                continue;
            }
            $in = array();
            $blank = true;
            foreach ($map as $k => $i) {
                $c = isset($cells[$i]) ? $cells[$i] : array('v' => '', 'num' => false);
                $v = $c['num'] ? Imports::excelNumber($c['v'], true) : trim((string)$c['v']);
                $in[$k] = $v;
                if ($v !== '') {
                    $blank = false;
                }
            }
            if ($blank) {
                continue;
            }
            list($d, $e) = self::validate($in);
            $located = array();
            foreach ($e as $k => $m) {
                $col = isset($map[$k]) ? XlsxWriter::colLetter($map[$k]) . $rn : '';
                $located[$k] = array('ref' => $col, 'text' => $m, 'value' => isset($in[$k]) ? $in[$k] : '');
            }
            $key = $d['atc_code'] . '|' . $d['tax_kind'];
            if (!$e && isset($seen[$key])) {
                $located['atc_code'] = array('ref' => isset($map['atc_code']) ? XlsxWriter::colLetter($map['atc_code']) . $rn : '', 'text' => $d['atc_code'] . ' (' . $d['tax_kind'] . ') is also on row ' . $seen[$key] . ' of this file. Keep one row per code.', 'value' => $in['atc_code']);
            }
            $seen[$key] = $rn;
            $plan = array(null, null);
            if (!$located) {
                $plan = self::plan($d);
            }
            $out[] = array('row' => $rn, 'data' => $d, 'errors' => $located, 'plan' => $plan[0], 'current' => $plan[1]);
            if (count($out) >= 5000) {
                $problems[] = 'Only the first 5,000 rows were read.';
                break;
            }
        }
        if (!$out) {
            $problems[] = 'The file has a header but no codes under it.';
        }
        return array('rows' => $out, 'problems' => $problems);
    }
}
