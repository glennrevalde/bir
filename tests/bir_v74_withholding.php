<?php
/**
 * A port of the main line checks of the BIR Alphalist Data Entry and Validation module v7.4 for the QAP
 * (val1601e, val1601f), the MAP (val1600) and the annual 1604-E and 1604-F (val1604e, val1604cf), used by
 * the tests to read generated files the way the BIR validator does: record and form codes, the header
 * against the TIN, branch and period chosen in the validator, the number of columns of each line (excess
 * and insufficient columns), digits, names, ATCs, zero amounts, income x rate against the tax, and the
 * control totals where the module compares them (tolerance 2.00).
 *
 * $period is MM/YYYY for the QAP and MAP and 12/31/YYYY for the annual forms.
 * Returns a list of array(line, message).
 */
function bir_v74_withholding($bytes, $form, $tin, $branch, $period)
{
    static $atcs = null;
    if ($atcs === null) {
        $atcs = array_flip(require dirname(__DIR__) . '/app/specs/bir_v74_atc.php');
    }
    // Per form: header (code field 1, form field 2 or null, field count), then detail and control lines by code:
    // array(form code in field 2, field count, column roles). Roles: tin, branch, seq, period, names, status,
    // atc, rate, income, tax, fringe, monetary, blank.
    $qapNames = array(6, 7, 8, 9);
    $L = array(
        '1601EQ' => array('head' => array('HQAP', 'H1601EQ', 7), 'key' => 'qap', 'totals' => array('C1' => array(7, 'D1', 'tax'), 'C2' => array(6, 'D2', 'income')), 'lines' => array(
            'D1' => array(14, array('seq' => 3, 'tin' => 4, 'branch' => 5, 'names' => $qapNames, 'period' => 10, 'atc' => 11, 'rate' => 12, 'income' => 13, 'tax' => 14, 'nonzero_tax' => 1)),
            'D2' => array(12, array('seq' => 3, 'tin' => 4, 'branch' => 5, 'names' => $qapNames, 'period' => 10, 'atc' => 11, 'income' => 12)),
            'C1' => array(7, array()), 'C2' => array(6, array()))),
        '1601FQ' => array('head' => array('HQAP', 'H1601FQ', 7), 'key' => 'qap', 'totals' => array(), 'lines' => array(
            'D1' => array(14, array('tin' => 3, 'branch' => 4, 'names' => array(5, 6, 7, 8), 'period' => 9, 'seq' => 10, 'atc' => 11, 'rate' => 12, 'income' => 13, 'tax' => 14)),
            'D2' => array(13, array('tin' => 3, 'branch' => 4, 'names' => array(5, 6, 7), 'period' => 8, 'seq' => 9, 'atc' => 10, 'fringe' => 11, 'monetary' => 12, 'tax' => 13)),
            'D3' => array(13, array('tin' => 3, 'branch' => 4, 'names' => array(5, 6, 7, 8), 'period' => 9, 'seq' => 10, 'status' => 11, 'atc' => 12, 'income' => 13)),
            'C1' => array(7, array()), 'C2' => array(8, array()), 'C3' => array(6, array()))),
        '1604E' => array('head' => array('H1604E', null, 4), 'key' => 'annual', 'totals' => array('C3' => array(6, 'D3', 'tax'), 'C4' => array(6, 'D4', 'income')), 'lines' => array(
            'D3' => array(16, array('seq' => 6, 'tin' => 7, 'branch' => 8, 'names' => array(9, 10, 11, 12), 'atc' => 13, 'income' => 14, 'rate' => 15, 'tax' => 16, 'nonzero_tax' => 1)),
            'D4' => array(14, array('seq' => 6, 'tin' => 7, 'branch' => 8, 'names' => array(9, 10, 11, 12), 'atc' => 13, 'income' => 14)),
            'C3' => array(6, array()), 'C4' => array(6, array()))),
        '1604F' => array('head' => array('H1604F', null, 4), 'key' => 'annual', 'totals' => array(), 'lines' => array(
            'D4' => array(17, array('seq' => 6, 'tin' => 7, 'branch' => 8, 'names' => array(9, 10, 11, 12), 'status' => 13, 'atc' => 14, 'income' => 15, 'rate' => 16, 'tax' => 17)),
            'D5' => array(15, array('seq' => 6, 'tin' => 7, 'branch' => 8, 'names' => array(9, 10, 11), 'atc' => 12, 'fringe' => 13, 'monetary' => 14, 'tax' => 15)),
            'D6' => array(15, array('seq' => 6, 'tin' => 7, 'branch' => 8, 'names' => array(9, 10, 11, 12), 'status' => 13, 'atc' => 14, 'income' => 15)),
            'C4' => array(7, array()), 'C5' => array(8, array()), 'C6' => array(6, array()))),
    );
    foreach (array('1600VT', '1600PT') as $mf) {
        $L[$mf] = array('head' => array('HMAP', 'H' . $mf, 7), 'key' => 'map', 'totals' => array('CMAP' => array(7, 'DMAP', 'tax'), 'CMAP_INC' => array(6, 'DMAP', 'income')), 'lines' => array(
            'DMAP' => array(15, array('seq' => 3, 'tin' => 4, 'branch' => 5, 'names' => $qapNames, 'period' => 10, 'atc' => 11, 'blank' => 12, 'rate' => 13, 'income' => 14, 'tax' => 15)),
            'CMAP' => array(7, array())));
    }
    $def = $L[$form];
    $out = array();
    $w = function ($line, $msg) use (&$out) {
        $out[] = array($line, $msg);
    };
    $digits = function ($v) {
        return $v === '' || ctype_digit($v);
    };
    $amount = function ($v) {
        return $v === '' || (bool)preg_match('/^-?\d*\.?\d*$/', $v);
    };
    $badName = function ($v) {
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $o = ord($v[$i]);
            if ($o < 32 || $o > 126 || in_array($o, array(34, 38, 39, 44, 124), true)) {
                return true;
            }
        }
        return false;
    };
    $lines = preg_split('/\r\n/', rtrim($bytes, "\r\n"));
    $header = null;
    $sums = array();
    $controls = array();
    $details = 0;
    foreach ($lines as $i => $raw) {
        $ln = $i + 1;
        $d = array_map('trim', str_getcsv($raw));
        array_unshift($d, null);
        $count = count($d) - 1;
        $code = strtoupper($d[1]);
        if ($code === $def['head'][0]) {
            if ($header !== null) {
                $w($ln, 'File has more than 1 Header record.');
            }
            $header = $d;
            if ($def['head'][1] !== null && strtoupper($d[2]) !== $def['head'][1]) {
                $w($ln, 'Invalid Form Type Code.');
            }
            $o = $def['head'][1] !== null ? 3 : 2;
            if ($d[$o] !== $tin || !ctype_digit($d[$o]) || strlen($d[$o]) !== 9) {
                $w($ln, 'Invalid TIN of Withholding Agent.');
            }
            if ($d[$o + 1] !== $branch) {
                $w($ln, 'Invalid Branch Code of Withholding Agent.');
            }
            $per = $def['key'] === 'annual' ? $d[4] : $d[6];
            if ($per !== $period) {
                $w($ln, 'Specified Return Period not the same!');
            }
            if ($def['key'] !== 'annual' && $badName($d[5])) {
                $w($ln, 'Invalid character on field Registered Name');
            }
            if ($def['key'] === 'qap' && strlen($d[7]) !== 3) {
                $w($ln, 'Invalid length of RDO Code.');
            }
            if ($count > $def['head'][2]) {
                $w($ln, 'Header Excess Column');
            }
            continue;
        }
        if (!isset($def['lines'][$code])) {
            $w($ln, 'Schedule Number is Invalid.');
            continue;
        }
        list($want, $roles) = $def['lines'][$code];
        $formOk = $def['key'] === 'map' ? strtoupper($d[2]) === substr($code, 0, 1) . $form : $d[2] === $form;
        if (!$formOk) {
            $w($ln, 'Form Type Code is Invalid.');
        }
        if ($count > $want) {
            $w($ln, ($code[0] === 'D' ? 'Detail' : 'Control') . ' Excess Column');
        } elseif ($count < $want || $d[$want] === '') {
            $w($ln, ($code[0] === 'D' ? 'Detail' : 'Control') . ' Insufficient Column');
        }
        if ($def['key'] === 'annual' && $header && ($d[3] !== $header[2] || $d[4] !== $header[3] || $d[5] !== $header[4])) {
            $w($ln, 'TIN, branch or period not equal to Header.');
        }
        if ($code[0] === 'C') {
            $controls[$code] = $d;
            continue;
        }
        $details++;
        if (isset($roles['period']) && $d[$roles['period']] !== $period) {
            $w($ln, 'Return Period not equal to Header or invalid Return Period.');
        }
        if (isset($roles['seq']) && !$digits($d[$roles['seq']])) {
            $w($ln, 'Invalid Characters in Sequence Number.');
        }
        $t = $d[$roles['tin']];
        if ($t !== '' && (strlen($t) !== 9 || !ctype_digit($t) || $t === '999999999' || $t === '111111111')) {
            $w($ln, 'Invalid Payees TIN.');
        }
        if ($d[$roles['branch']] !== '' && (strlen($d[$roles['branch']]) !== 4 || !ctype_digit($d[$roles['branch']]))) {
            $w($ln, 'Invalid Payees Branch Code.');
        }
        $nm = '';
        foreach ($roles['names'] as $k) {
            $nm .= $d[$k];
            if ($badName($d[$k])) {
                $w($ln, 'Invalid character on field name: ' . $d[$k]);
            }
        }
        if ($nm === '') {
            $w($ln, 'Name of Payee is empty.');
        }
        if (isset($roles['status']) && !preg_match('/^[A-H]$/', $d[$roles['status']])) {
            $w($ln, 'Status Code is Invalid.');
        }
        if (!isset($atcs[strtoupper($d[$roles['atc']])])) {
            $w($ln, 'ATC is invalid.');
        }
        if (isset($roles['blank']) && $d[$roles['blank']] !== '') {
            $w($ln, 'Nature of income is not empty.');
        }
        foreach (array('rate', 'income', 'tax', 'fringe', 'monetary') as $k) {
            if (isset($roles[$k]) && !$amount($d[$roles[$k]])) {
                $w($ln, 'Invalid Characters in ' . $k . '.');
            }
            if (isset($roles[$k])) {
                $sums[$code][$k] = (isset($sums[$code][$k]) ? $sums[$code][$k] : 0) + (float)$d[$roles[$k]];
            }
        }
        foreach (array('income', 'fringe', 'monetary') as $k) {
            if (isset($roles[$k]) && (float)$d[$roles[$k]] == 0) {
                $w($ln, 'Amount of ' . $k . ' is empty or zero.');
            }
        }
        if (isset($roles['rate'])) {
            $rate = (float)$d[$roles['rate']];
            $inc = (float)$d[$roles['income']];
            $tax = (float)$d[$roles['tax']];
            if ($rate > 99) {
                $w($ln, 'Invalid Rate of Tax.');
            }
            $skip = in_array($form, array('1601FQ', '1604F'), true) && in_array(strtoupper($d[$roles['atc']]), array('WI320', 'WI750'), true);
            if (!$skip && (int)($inc * $rate / 100) != (int)$tax && round($inc * $rate / 100, 2) != round($tax, 2)) {
                $w($ln, 'Tax Withheld is not equal to Income Payment x Tax Rate.');
            }
            if (!empty($roles['nonzero_tax']) && $tax == 0) {
                $w($ln, 'Amount of Tax Withheld is empty or zero.');
            }
        }
    }
    if ($header === null) {
        $w(0, 'No Header record found in file!');
    }
    if (!$details) {
        $w(count($lines), 'No Detail Record found in file!');
    }
    if (!$out) {
        foreach ($def['totals'] as $c => $t) {
            list($col, $dcode, $key) = $t;
            $c = $c === 'CMAP_INC' ? 'CMAP' : $c;
            if (!isset($controls[$c]) && !empty($sums[$dcode])) {
                $w(0, 'No Control record found for ' . $dcode . '.');
            } elseif (isset($controls[$c])) {
                $have = (float)$controls[$c][$col];
                $sum = isset($sums[$dcode][$key]) ? $sums[$dcode][$key] : 0;
                if (round($have, 2) != round($sum, 2) && abs($have - $sum) > 2) {
                    $w(0, 'Control Totals not equal to sum of Details (' . $c . ' ' . $key . ').');
                }
            }
        }
    }
    return $out;
}
