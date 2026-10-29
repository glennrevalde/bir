<?php
/**
 * Level 1 (data quality) and Level 2 (DAT structure) checks.
 * Level 3 is the official BIR validator; see Evidence.
 */
class Checks
{
    /** Arithmetic consistency of a compensation record (amounts as "0.00" strings or cents). Returns list of messages. */
    public static function compArithmetic($d)
    {
        $c = function ($k) use ($d) {
            return is_int($d[$k]) ? $d[$k] : Money::fromDb($d[$k]);
        };
        $msgs = array();
        foreach (array('prev', 'pres') as $p) {
            $nt = $c($p . '_nt_smw') + $c($p . '_nt_13th') + $c($p . '_nt_deminimis') + $c($p . '_nt_contrib') + $c($p . '_nt_other');
            $tx = $c($p . '_tx_basic') + $c($p . '_tx_13th') + $c($p . '_tx_other');
            $who = $p === 'prev' ? 'Previous employer' : 'Present employer';
            if ($nt !== $c($p . '_nt_total')) {
                $msgs[] = $who . ': non-taxable items add up to ' . Money::fmt($nt) . ' but total non-taxable is ' . Money::fmt($c($p . '_nt_total'));
            }
            if ($tx !== $c($p . '_tx_total')) {
                $msgs[] = $who . ': taxable items add up to ' . Money::fmt($tx) . ' but total taxable is ' . Money::fmt($c($p . '_tx_total'));
            }
            if ($c($p . '_nt_total') + $c($p . '_tx_total') !== $c($p . '_gross')) {
                $msgs[] = $who . ': gross ' . Money::fmt($c($p . '_gross')) . ' differs from non-taxable plus taxable (' . Money::fmt($c($p . '_nt_total') + $c($p . '_tx_total')) . ')';
            }
        }
        if ($c('prev_tx_total') + $c('pres_tx_total') !== $c('taxable_comp_total')) {
            $msgs[] = 'Taxable compensation, present and previous, should be ' . Money::fmt($c('prev_tx_total') + $c('pres_tx_total'));
        }
        $adj = $c('pres_withheld_jan_nov') + $c('pres_withheld_dec') - $c('over_withheld_refunded');
        if ($adj !== $c('tax_withheld_adjusted')) {
            $msgs[] = 'Tax withheld as adjusted should equal January to November plus December less refunds (' . Money::fmt($adj) . ')';
        }
        if ($c('prev_tax_withheld') + $c('tax_withheld_adjusted') !== $c('tax_due') && $c('tax_due') > 0) {
            $msgs[] = 'Total withheld (previous plus present as adjusted, ' . Money::fmt($c('prev_tax_withheld') + $c('tax_withheld_adjusted')) . ') does not equal tax due (' . Money::fmt($c('tax_due')) . ')';
        }
        return $msgs;
    }

    /**
     * Level 1: data quality on a dataset before serialization.
     * Returns list of issues (severity, code, record_no, field_key, message).
     */
    public static function dataQuality($form, $schedule, $client, $agent, $rows, $spec, $period)
    {
        $issues = array();
        $add = function ($sev, $code, $rec, $field, $msg) use (&$issues) {
            $issues[] = array('severity' => $sev, 'code' => $code, 'record_no' => $rec, 'field_key' => $field, 'message' => $msg);
        };
        if (!$spec) {
            $add('error', 'NO_LAYOUT', null, null, 'No file layout is registered for ' . $form . ' ' . $schedule . ' covering this period.');
        } elseif ($spec['status'] === 'draft') {
            $add('warning', 'LAYOUT_DRAFT', null, null, 'Layout #' . $spec['id'] . ' is an unsourced draft. The file will be labelled UNVALIDATED DRAFT.');
        } elseif ($spec['status'] === 'pending_verification') {
            $add('warning', 'LAYOUT_PENDING', null, null, 'Layout #' . $spec['id'] . ' is pending verification. Record an official validator result to confirm it.');
        }
        if (!preg_match('/^\d{9}$/', $agent['tin'])) {
            $add('error', 'AGENT_TIN', null, 'tin', 'Client TIN must be 9 digits.');
        }
        if (!preg_match('/^\d{3}$/', (string)$agent['rdo'])) {
            $add('error', 'AGENT_RDO', null, 'rdo', 'RDO code must be 3 digits for this layout (found "' . $agent['rdo'] . '"). ' . (preg_match('/[A-Z]$/', (string)$agent['rdo']) ? 'How codes with a letter (such as 21B) are written in this file is not confirmed; check with your RDO or the validator before filing.' : 'Set it under Admin, Clients.'));
        }
        if (!preg_match('/^\d{4,5}$/', $agent['branch'])) {
            $add('error', 'AGENT_BRANCH', null, 'branch', 'Branch code must be 4 digits.');
        }
        if ($agent['taxpayer_type'] === 'individual' ? !$agent['last_name'] : !$agent['registered_name']) {
            $add('error', 'AGENT_NAME', null, 'registered_name', 'The client name is incomplete.');
        }
        if (!$rows) {
            $add('error', 'NO_ROWS', null, null, 'No records were found for this period, branch and schedule.');
        }
        $isWh = in_array($form, array('1601EQ', '1601FQ', '1600VT', '1600PT', '1604E', '1604F', 'SAWT', 'SRS'), true);
        $kind = in_array($form, array('1601FQ', '1604F'), true) ? 'FWT' : ($form === '1600VT' ? 'GVAT' : ($form === '1600PT' ? 'GPT' : 'EWT'));
        $names = array();
        foreach ($rows as $i => $r) {
            $n = $i + 1;
            if (isset($r['tin'])) {
                if ($r['tin'] === '' || $r['tin'] === null) {
                    // The BIR validator accepts a line without a TIN, so a missing TIN is noted, not an error.
                    $add('warning', 'ROW_TIN', $n, 'tin', 'Row ' . $n . ' (' . party_name($r) . ') has no TIN; the line is written without one.');
                } elseif (!preg_match('/^\d{9}$/', $r['tin'])) {
                    $add('error', 'ROW_TIN', $n, 'tin', 'Row ' . $n . ' TIN "' . $r['tin'] . '" is not 9 digits.');
                }
                if ($r['tin'] !== '' && $r['tin'] !== null) {
                    $nm = strtoupper(party_name($r));
                    $tk = $r['tin'] . '-' . (isset($r['branch']) ? $r['branch'] : (isset($r['tin_branch']) ? $r['tin_branch'] : ''));
                    if (isset($names[$tk]) && $names[$tk] !== $nm) {
                        $add('warning', 'NAME_MISMATCH', $n, 'registered_name', 'TIN ' . Tin::display($r['tin']) . ' appears with two names: "' . $names[$tk] . '" and "' . $nm . '".');
                    }
                    $names[$tk] = $nm;
                }
            }
            if ($isWh) {
                $tc = Records::taxCode($r['atc'], $kind, $period['to']);
                if (!$tc) {
                    $add('error', 'ATC_UNKNOWN', $n, 'atc', 'ATC ' . $r['atc'] . ' is not registered for ' . $kind . ' in this period.');
                } elseif ($tc['status'] !== 'approved') {
                    $add('error', 'ATC_UNAPPROVED', $n, 'atc', 'ATC ' . $r['atc'] . ' is pending review in the tax code registry. A reviewer must approve it before the file can be submission-ready.');
                } elseif (Money::rateUnits($tc['rate']) !== Money::rateUnits($r['rate'])) {
                    $add('warning', 'ATC_RATE', $n, 'rate', 'Row ' . $n . ' uses ' . Money::rateFormat(Money::rateUnits($r['rate']), 'pct2') . '% but ATC ' . $r['atc'] . ' is registered at ' . Money::rateFormat(Money::rateUnits($tc['rate']), 'pct2') . '%. Source entries carry a reason.');
                }
                $expected = Money::applyRate($r['income'], Money::rateUnits($r['rate']));
                if (abs($expected - $r['tax']) > max(1, count($r['source_ids']))) {
                    $add('warning', 'TAX_MATH', $n, 'tax', 'Row ' . $n . ': tax ' . Money::fmt($r['tax']) . ' differs from ' . Money::rateFormat(Money::rateUnits($r['rate']), 'pct2') . '% of ' . Money::fmt($r['income']) . ' (' . Money::fmt($expected) . ').');
                }
                if ($r['income'] <= 0) {
                    $add('warning', 'ZERO_AMOUNT', $n, 'income', 'Row ' . $n . ' has a zero or negative income payment.');
                }
            }
            if ($form === '1604C') {
                foreach (self::compArithmetic($r) as $m) {
                    $add('error', 'COMP_MATH', $n, null, 'Row ' . $n . ' (' . party_name($r) . '): ' . $m);
                }
                if ($schedule === 'S1' && $r['pera_credit'] > 0) {
                    $add('warning', 'PERA', $n, 'pera_credit', 'Row ' . $n . ' has a PERA credit; this case is not covered by the validated sample.');
                }
                if ($r['over_withheld_refunded'] > 0 || $r['prev_gross'] > 0) {
                    $add('warning', 'UNVERIFIED_CASE', $n, null, 'Row ' . $n . ' has previous-employer or refund amounts; confirm with the validator.');
                }
            }
            if (in_array($form, array('SLS', 'SLP'), true)) {
                $vals = array_intersect_key($r, array_flip(array('exempt', 'zero_rated', 'taxable', 'output_vat', 'services', 'capital_goods', 'other_goods', 'input_tax')));
                if (array_sum($vals) === 0) {
                    $add('warning', 'ZERO_AMOUNT', $n, null, 'Row ' . $n . ' (' . party_name($r) . ') totals zero.');
                }
                foreach ($vals as $k => $v) {
                    if ($v < 0) {
                        $add('warning', 'NEGATIVE', $n, $k, 'Row ' . $n . ' has a negative ' . str_replace('_', ' ', $k) . ' amount.');
                    }
                }
                if ($form === 'SLS' && $r['taxable'] > 0) {
                    $vat = Money::applyRate($r['taxable'], 120000);
                    if (abs($vat - $r['output_vat']) > max(100, count($r['source_ids']))) {
                        $add('warning', 'VAT_MATH', $n, 'output_vat', 'Row ' . $n . ': output VAT ' . Money::fmt($r['output_vat']) . ' is not 12% of taxable sales ' . Money::fmt($r['taxable']) . '.');
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * Level 2: parse the generated bytes independently and compare with the dataset.
     */
    public static function structure($spec, $bytes, $filename, $agent, $period, $rows, $vars = array())
    {
        $parsed = DatReader::parse($bytes, $spec);
        $issues = $parsed['issues'];
        $add = function ($sev, $code, $rec, $field, $msg) use (&$issues) {
            $issues[] = array('severity' => $sev, 'code' => $code, 'record_no' => $rec, 'field_key' => $field, 'message' => $msg);
        };
        $expectedName = Specs::filename($spec, $agent, $period, $vars);
        if ($filename !== $expectedName) {
            $add('error', 'FILENAME', null, null, 'Filename "' . $filename . '" differs from the layout pattern result "' . $expectedName . '".');
        }
        if (!preg_match(Specs::filenameRegex($spec['filename_pattern']), $filename)) {
            $add('error', 'FILENAME', null, null, 'Filename does not match pattern ' . $spec['filename_pattern'] . '.');
        }
        $wantEol = $spec['line_ending'];
        if ($parsed['line_ending'] !== $wantEol && $parsed['line_count'] > 0) {
            $add('error', 'LINE_ENDING', null, null, 'Line endings are ' . $parsed['line_ending'] . '; the layout requires ' . $wantEol . '.');
        }
        if ((int)$spec['trailing_newline'] === 1 && !$parsed['ends_with_newline']) {
            $add('error', 'TRAILING_EOL', null, null, 'The last line must end with a line break.');
        }
        if ($parsed['non_ascii']) {
            $add('warning', 'NON_ASCII', null, null, 'The file contains characters outside plain ASCII.');
        }
        if (count($parsed['rows']) !== count($rows)) {
            $add('error', 'ROW_COUNT', null, null, 'Parsed ' . count($parsed['rows']) . ' detail records; the dataset has ' . count($rows) . '.');
        }
        // Round-trip: compare each parsed row with the dataset row on identifiers and amounts.
        $cmpKeys = array();
        foreach ($spec['records'] as $r) {
            if ($r['role'] === 'detail') {
                foreach ($r['fields'] as $f) {
                    if (strpos($f['source_expr'], 'row.') === 0 && in_array($f['data_type'], array('amount', 'digits', 'code', 'date'), true)) {
                        $cmpKeys[substr($f['source_expr'], 4)] = $f['data_type'];
                    }
                }
            }
        }
        foreach ($rows as $i => $want) {
            if (!isset($parsed['rows'][$i])) {
                break;
            }
            $got = $parsed['rows'][$i];
            foreach ($cmpKeys as $k => $type) {
                $w = isset($want[$k]) ? $want[$k] : '';
                $g = isset($got[$k]) ? $got[$k] : '';
                if ($type === 'amount') {
                    $w = (int)$w;
                    $g = (int)$g;
                } elseif ($type === 'code') {
                    $w = strtoupper((string)$w);
                }
                if ((string)$w !== (string)$g) {
                    $add('error', 'ROUND_TRIP', $i + 1, $k, 'Detail ' . ($i + 1) . ' field ' . $k . ': wrote "' . $g . '" but the dataset has "' . $w . '".');
                }
            }
        }
        // Control and header totals must equal the sum of detail records as parsed.
        foreach ($spec['records'] as $r) {
            if ($r['role'] === 'detail') {
                continue;
            }
            $assoc = $r['role'] === 'header' ? $parsed['header'] : (isset($parsed['controls'][0]) ? $parsed['controls'][0] : null);
            if (!$assoc) {
                continue;
            }
            foreach ($r['fields'] as $f) {
                if (strpos($f['source_expr'], 'sum.') !== 0) {
                    continue;
                }
                $k = substr($f['source_expr'], 4);
                $sum = 0;
                foreach ($parsed['rows'] as $pr) {
                    $sum += isset($pr[$k]) ? (int)$pr[$k] : 0;
                }
                if ($k === 'creditable_input' || !self::detailHas($spec, $k)) {
                    $sum = 0;
                    foreach ($rows as $dr) {
                        $sum += isset($dr[$k]) ? (int)$dr[$k] : 0;
                    }
                }
                $got = Money::fromDb(isset($assoc[$f['field_key']]) && $assoc[$f['field_key']] !== '' ? $assoc[$f['field_key']] : '0');
                if ($got !== $sum) {
                    $add('error', 'CONTROL_TOTAL', null, $f['field_key'], $r['label'] . ': ' . $f['label'] . ' is ' . Money::fmt($got) . ' but detail records add up to ' . Money::fmt($sum) . '.');
                }
            }
        }
        return array('issues' => $issues, 'parsed' => $parsed);
    }

    private static function detailHas($spec, $key)
    {
        foreach ($spec['records'] as $r) {
            if ($r['role'] === 'detail') {
                foreach ($r['fields'] as $f) {
                    if ($f['source_expr'] === 'row.' . $key) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function counts($issues)
    {
        $e = 0;
        $w = 0;
        foreach ($issues as $i) {
            if ($i['severity'] === 'error') {
                $e++;
            } else {
                $w++;
            }
        }
        return array($e, $w);
    }

    public static function record($datFileId, $runId, $level, $issues, $summary)
    {
        list($e, $w) = self::counts($issues);
        $vr = DB::insert('validation_runs', array('dat_file_id' => $datFileId, 'run_id' => $runId, 'level' => $level, 'result' => $e ? 'fail' : ($w ? 'warn' : 'pass'),
            'error_count' => $e, 'warning_count' => $w, 'summary' => $summary, 'executed_by' => Auth::id(), 'created_at' => now()));
        $n = 0;
        foreach ($issues as $i) {
            if (++$n > 2000) {
                DB::insert('validation_errors', array('validation_run_id' => $vr, 'severity' => 'warning', 'code' => 'TRUNCATED', 'record_no' => null, 'field_key' => null,
                    'message' => 'More than 2000 issues; only the first 2000 are stored.'));
                break;
            }
            DB::insert('validation_errors', array('validation_run_id' => $vr, 'severity' => $i['severity'], 'code' => $i['code'], 'record_no' => $i['record_no'],
                'field_key' => $i['field_key'], 'message' => mb_substr($i['message'], 0, 500)));
        }
        return $vr;
    }
}
