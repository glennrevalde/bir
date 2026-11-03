<?php
/**
 * A port of the 1604-C checks of the BIR Alphalist Data Entry and Validation module v7.4
 * ("Alphalist Validation.exe", form "Validate 1604C", procedure val1604cf), used by the tests to read
 * generated files the way the BIR validator does. Control records are not checked: the module runs with
 * control_on = .F.
 *
 * Returns a list of array(line, schedule, message) in the order the module writes them.
 */
function bir_v74_1604c($bytes, $tin, $branch, $year)
{
    $out = array();
    $w = function ($sched, $line, $msg) use (&$out) {
        $out[] = array($line, $sched, $msg);
    };
    // digitchk(x, 1): digits only; (x, 2): an amount (digits, one point, a leading minus); (x, 3): MM/DD/YYYY.
    // An empty value passes, as the module's report shows for missing columns.
    $dc = function ($v, $type) {
        $v = trim((string)$v);
        if ($v === '') {
            return true;
        }
        if ($type === 1) {
            return ctype_digit($v);
        }
        if ($type === 3) {
            return (bool)preg_match('#^\d{2}/\d{2}/\d{4}$#', $v);
        }
        return (bool)preg_match('/^-?\d*\.?\d*$/', $v) && preg_match('/\d/', $v);
    };
    $val = function ($v) {
        return (float)trim((string)$v);
    };
    $ne = function ($a, $b) {
        // the module flags a formula only when it fails both in whole pesos and to the centavo
        return (int)$a != (int)$b && round($a, 2) != round($b, 2);
    };
    $taxOk = function ($net, $due) {
        $net = round($net, 2);
        if ($net <= 250000) {
            $want = 0;
        } elseif ($net <= 400000) {
            $want = ($net - 250000) * 0.15;
        } elseif ($net <= 800000) {
            $want = 22500 + ($net - 400000) * 0.20;
        } elseif ($net <= 2000000) {
            $want = 102500 + ($net - 800000) * 0.25;
        } elseif ($net <= 8000000) {
            $want = 402500 + ($net - 2000000) * 0.30;
        } else {
            $want = 2202500 + ($net - 8000000) * 0.35;
        }
        return (int)$due == (int)$want || round($due, 2) == round($want, 2);
    };
    $regions = array('I', 'II', 'III', 'IV-A', 'IV-B', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'NCR', 'ARMM', 'CAR',
        '1', '2', '3', '4-A', '4-B', '5', '6', '7', '8', '9', '10', '11', '12', '13');
    $badName = function ($s) {
        return preg_match('/[\x00-\x1f\x7f-\xff"&\',|]/', $s);
    };
    $hctr = 0;
    $dctr = 0;
    $e = array('form' => null, 'tin' => null, 'branch' => null, 'ret' => null);
    $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $bytes), "\n"));
    foreach ($lines as $i => $line) {
        $ctr = $i + 1;
        list($f) = DatReader::splitLine($line, ',');
        $d = array();
        for ($k = 1; $k <= 66; $k++) {
            $d[$k] = isset($f[$k - 1]) ? trim($f[$k - 1]) : '';
        }
        $c0 = strtoupper(substr($d[1], 0, 1));
        if (!in_array($c0, array('H', 'D', 'C', 'V'), true)) {
            $w(0, $ctr, 'Invalid Header/Detail/Control Code');
        }
        if ($c0 === 'H') {
            $hctr++;
            if ($hctr > 1) {
                $w(0, $ctr, 'File has more than 1 Header record');
            } else {
                if (substr(strtoupper($d[1]), 1) !== '1604C' || strlen($d[1]) !== 6) {
                    $w(0, $ctr, 'Invalid Form Type Code');
                } else {
                    $e['form'] = '1604C';
                }
                if ($d[2] !== $tin || !ctype_digit($d[2]) || strlen($d[2]) !== 9) {
                    $w(0, $ctr, 'Invalid TIN of Withholding Agent');
                } else {
                    $e['tin'] = $d[2];
                }
                if ($d[3] !== $branch || strlen($d[3]) !== 4) {
                    $w(0, $ctr, 'Specified Branch not the same!');
                } else {
                    $e['branch'] = $d[3];
                }
                if ($d[4] !== '12/31/' . $year) {
                    $w(0, $ctr, 'Specified year/End Date not the same!');
                } else {
                    $e['ret'] = $d[4];
                }
            }
            for ($k = 8; $k <= 14; $k++) {
                if ($d[$k] !== '') {
                    $w(0, $ctr, 'Header Excess Column');
                    break;
                }
            }
        }
        if ($c0 === 'D' || $c0 === 'C') {
            $sn = substr($d[1], 1);
            $sched = in_array($sn, array('1', '1.0', '2', '2.0'), true) ? (int)$sn : 0;
            if (!$sched) {
                $w(0, $ctr, 'Schedule Number is Invalid');
            }
            if ($hctr >= 1) {
                if ($e['form'] && strtoupper($d[2]) !== $e['form']) {
                    $w($sched, $ctr, 'Form type code not equal to Header or invalid form type code');
                }
                if ($e['tin'] && $d[3] !== $e['tin']) {
                    $w($sched, $ctr, 'TIN of WA not equal to Header or invalid TIN of WA');
                }
                if ($e['branch'] && $d[4] !== $e['branch']) {
                    $w($sched, $ctr, 'Branch of WA not equal to Header or invalid Branch of WA');
                }
                if ($e['ret'] && $d[5] !== $e['ret']) {
                    $w($sched, $ctr, 'Return Period not equal to Header or invalid Return Period');
                }
            }
        }
        if ($c0 !== 'D') {
            continue;
        }
        $dctr++;
        if ($d[7] !== '' && (strlen($d[7]) !== 9 || !ctype_digit($d[7]) || $d[7] === '999999999' || $d[7] === '111111111')) {
            $w($sched, $ctr, 'Invalid Payees TIN');
        }
        if ($d[8] !== '' && strlen($d[8]) !== 4) {
            $w($sched, $ctr, 'Invalid length of Payees Branch Code');
        }
        $v = function ($k) use ($d, $val) {
            return $val($d[$k]);
        };
        if ($sched === 1) {
            if ((int)$v(13) != 0 && $ne($v(13), $v(19) + $v(23))) {
                $w(1, $ctr, 'Wrong Computation in Non-Taxable Gross Compensation Income from Previous Employer of Payee');
            }
            if ($ne($v(26), $v(32) + $v(36))) {
                $w(1, $ctr, 'Wrong Computation in Non-Taxable Gross Compensation Income from Present Employer of Payee');
            }
            if ($ne($v(19), $v(14) + $v(15) + $v(16) + $v(17) + $v(18))) {
                $w(1, $ctr, 'Wrong Computation in Total Non-Taxable/Exempt Compensation Income of Previous Employer of Payee');
            }
            if ($ne($v(32), $v(27) + $v(28) + $v(29) + $v(30) + $v(31))) {
                $w(1, $ctr, 'Wrong Computation in Total Non-Taxable/Exempt Compensation Income from Present Employer of Payee');
            }
            if ($ne($v(23), $v(20) + $v(21) + $v(22))) {
                $w(1, $ctr, 'Wrong Computation in Total Taxable from Previous Employer of Payee');
            }
            if ($ne($v(36), $v(33) + $v(34) + $v(35))) {
                $w(1, $ctr, 'Wrong Computation in Total Taxable from Present Employer of Payee');
            }
            if ($ne($v(37), $v(23) + $v(36))) {
                $w(1, $ctr, 'Wrong Computation in Present and Previous Taxable Compensation Income of Payee');
            }
            if ($ne($v(37), $v(38))) {
                $w(1, $ctr, 'Wrong Computation: "Net Taxable Compensation Income" should be equal to the "Present and Previous Taxable Compensation Income of Payee"');
            }
            if ($ne($v(44), $v(39))) {
                $w(1, $ctr, 'Wrong Computation: "Tax Due" should be equal to the "Amount of Tax withheld as adjusted"');
            }
            if ((int)$v(42) != 0 && $ne($v(42), $v(39) - ($v(40) + $v(41)) - $v(49))) {
                $w(1, $ctr, 'Wrong Computation in Amount Withheld and Paid for in December');
            }
            $over = $v(40) + $v(41) + $v(49) - $v(39);
            if ($ne($v(43), $over) && (int)$over >= 0 && round($over, 2) >= 0) {
                $w(1, $ctr, 'Wrong Computation in Overheld Tax Refunded');
            }
            if ($ne($v(44), $v(40) + $v(41) + $v(49) - $v(43)) && $ne($v(44), $v(40) + $v(41) + $v(49) + $v(42))) {
                $w(1, $ctr, 'Wrong Computation in Amount of Tax withheld as adjusted');
            }
            if (!$taxOk($v(38), $v(39))) {
                $w(1, $ctr, 'Wrong Computation of Tax Due');
            }
            $amountCols = array_merge(range(13, 23), range(26, 44), array(49));
            $last = 49;
            $max = 49;
            $opt = array(45 => 'Invalid Characters in Nationality', 46 => 'Invalid Option was selected in Employment Status',
                47 => 'Invalid Option was selected in Reason of Separation', 48 => 'Invalid Option was selected in Substituted Filing');
            $dates = array(24, 25);
            if ($v(15) > 90000) {
                $w(1, $ctr, '13th Month Pay Should Not Be Greater Than 90000');
            }
        } elseif ($sched === 2) {
            if ((int)$v(13) != 0 && $ne($v(13), $v(23) + $v(26))) {
                $w(2, $ctr, 'Wrong Computation in Non-Taxable Gross Compensation Income from Previous Employer of Payee');
            }
            if ($ne($v(29), $v(42) + $v(45))) {
                $w(2, $ctr, 'Wrong Computation in Non-Taxable Gross Compensation Income from Present Employer of Payee');
            }
            if ($ne($v(23), $v(14) + $v(15) + $v(16) + $v(17) + $v(18) + $v(19) + $v(20) + $v(21) + $v(22))) {
                $w(2, $ctr, 'Wrong Computation in Total Non-Taxable/Exempt Compensation Income of Previous Employer of Payee');
            }
            if ($ne($v(42), $v(34) + $v(35) + $v(36) + $v(37) + $v(38) + $v(39) + $v(40) + $v(41) + $v(59))) {
                $w(2, $ctr, 'Wrong Computation in Total Non-Taxable/Exempt Compensation Income from Present Employer of Payee');
            }
            if ($ne($v(26), $v(24) + $v(25))) {
                $w(2, $ctr, 'Wrong Computation in Total Taxable from Previous Employer of Payee');
            }
            if ($ne($v(45), $v(43) + $v(44))) {
                $w(2, $ctr, 'Wrong Computation in Total Taxable from Present Employer of Payee');
            }
            if ($ne($v(46), $v(45) + $v(26))) {
                $w(2, $ctr, 'Wrong Computation in Present and Previous Taxable Compensation Income of Payee');
            }
            if ($ne($v(46), $v(47))) {
                $w(2, $ctr, 'Wrong Computation: "Net Taxable Compensation Income" should be equal to the "Present and Previous Taxable Compensation Income of Payee"');
            }
            if ($ne($v(48), $v(53))) {
                $w(2, $ctr, 'Wrong Computation: "Tax Due" should be equal to the "Amount of Tax withheld as adjusted"');
            }
            if ((int)$v(51) != 0 && $ne($v(51), $v(48) - ($v(49) + $v(50)) - $v(58))) {
                $w(2, $ctr, 'Wrong Computation in Amount Withheld and Paid for in December');
            }
            $over = $v(49) + $v(50) + $v(58) - $v(48);
            if ($ne($v(52), $over) && (int)$over >= 0 && round($over, 2) >= 0) {
                $w(2, $ctr, 'Wrong Computation in Overheld Tax Refunded');
            }
            if ($ne($v(53), $v(49) + $v(50) + $v(58) - $v(52)) && $ne($v(53), $v(49) + $v(50) + $v(58) + $v(51))) {
                $w(2, $ctr, 'Wrong Computation in Amount of Tax withheld as adjusted');
            }
            if (!$taxOk($v(47), $v(48))) {
                $w(1, $ctr, 'Wrong Computation of Tax Due');
            }
            if ($dc($d[57], 2)) {
                $w(2, $ctr, 'Invalid Option was selected in Substituted Filing');
            }
            if ($d[30] === '') {
                $w(2, $ctr, 'Basic/Statutory Minimum Wage Per Day of Payee from Present Employer is Missing/Null/Blank');
            }
            foreach (array(19, 38) as $k) {
                if ($v($k) > 90000) {
                    $w(2, $ctr, '13th Month Pay Should Not Be Greater Than 90000');
                }
            }
            $amountCols = array_merge(range(13, 26), range(29, 53), array(58, 59));
            $last = 59;
            $max = 59;
            $opt = array();
            $dates = array(27, 28);
        } else {
            continue;
        }
        if (!$dc($d[6], 1)) {
            $w($sched, $ctr, 'Invalid Characters in Sequence Number of Payee');
        }
        if (!$dc($d[8], 1)) {
            $w($sched, $ctr, 'Invalid Characters in Branch Code of Payee');
        }
        if ($d[9] . $d[10] . $d[11] === '') {
            $w($sched, $ctr, 'Name of Payee is empty');
        } else {
            foreach (array(9 => 'Last Name', 10 => 'First Name', 11 => 'Middle Name') as $k => $n) {
                if ($badName($d[$k])) {
                    $w($sched, $ctr, 'Invalid character on field ' . $n);
                }
            }
        }
        if ($d[12] === '') {
            $w($sched, $ctr, 'Region No. Where Assigned is empty');
        } elseif (!in_array(strtoupper($d[12]), $regions, true)) {
            $w($sched, $ctr, 'Region No. Where Assigned is Invalid');
        }
        foreach ($amountCols as $k) {
            if (!$dc($d[$k], 2)) {
                $w($sched, $ctr, 'Invalid Characters in column ' . $k);
            }
        }
        if (!$dc($d[$dates[0]], 3) || !$dc($d[$dates[1]], 3) || $d[$dates[0]] === '' || $d[$dates[1]] === '' || strcmp($d[$dates[0]], $d[$dates[1]]) > 0) {
            $w($sched, $ctr, 'Employment date/s is invalid');
        }
        foreach ($opt as $k => $msg) {
            if ($dc($d[$k], 2)) {
                $w($sched, $ctr, $msg);
            }
        }
        if ($d[$last] === '') {
            $w($sched, $ctr, 'Detail Schedule ' . $sched . ' Insufficient Column');
        }
        for ($k = $max + 1; $k <= 66; $k++) {
            if ($d[$k] !== '') {
                $w($sched, $ctr, 'Detail Schedule ' . $sched . ' Excess Column');
                break;
            }
        }
    }
    if ($hctr <= 0) {
        $w(0, 0, 'No Header record found in file!');
    }
    if ($dctr <= 0) {
        $w(0, 0, 'No Detail Record found in file!');
    }
    return $out;
}
