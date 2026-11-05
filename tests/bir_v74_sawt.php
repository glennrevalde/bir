<?php
/**
 * A port of the SAWT checks of the BIR Alphalist Data Entry and Validation module v7.4
 * ("Alphalist Validation.exe", form "Validate SAWT", procedure valsawt), used by the tests to read
 * generated SAWT files the way the BIR validator does.
 *
 * $form is the attached return chosen in the validator (1702Q, 2550Q, ...), $period is MM/YYYY.
 * Returns a list of array(line, message) in the order the module writes them.
 */
function bir_v74_sawt($bytes, $tin, $branch, $form, $period)
{
    static $atc = null;
    if ($atc === null) {
        $atc = array_flip(require dirname(__DIR__) . '/app/specs/bir_v74_atc.php');
    }
    $out = array();
    $w = function ($line, $msg) use (&$out) {
        $out[] = array($line, $msg);
    };
    // digitchk(x, 1): digits only; (x, 2): an amount. An empty value passes.
    $dc = function ($v, $type) {
        $v = trim((string)$v);
        if ($v === '') {
            return true;
        }
        if ($type === 1) {
            return ctype_digit($v);
        }
        return (bool)preg_match('/^-?\d*\.?\d*$/', $v) && preg_match('/\d/', $v);
    };
    // valname: no control characters, no characters above 126, and none of " & ' , |
    $name = function ($v, $line, $col) use ($w) {
        $bad = '';
        $v = trim((string)$v);
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $o = ord($v[$i]);
            if ($o < 32 || $o > 126 || in_array($o, array(34, 38, 39, 44, 124), true)) {
                $bad .= $v[$i];
            }
        }
        if (trim($bad) !== '') {
            $w($line, 'Invalid character on field ' . $col . ':' . $bad);
        }
    };
    $badTin = function ($t) {
        return strlen($t) !== 9 || !ctype_digit($t[0]) || $t === '999999999' || $t === '111111111';
    };
    $lines = preg_split('/\r\n|\n|\r/', rtrim($bytes, "\r\n"));
    $hctr = 0;
    $dctr = 0;
    $cctr = 0;
    $sumInc = 0.0;
    $sumTax = 0.0;
    $totInc = 0.0;
    $totTax = 0.0;
    $eform = '';
    $ereturn = '';
    $cline = 0;
    foreach ($lines as $i => $raw) {
        $ln = $i + 1;
        // The module appends the file DELIMITED into a 20-column table: quotes delimit text, commas split.
        $d = array_pad(array_map('trim', str_getcsv($raw)), 20, '');
        array_unshift($d, null);
        $t = strtoupper(substr($d[1], 0, 1));
        if (!in_array($t, array('H', 'D', 'C', 'V'), true)) {
            $w($ln, 'Invalid Header/Detail/Control Code.');
        }
        $ft = strtoupper(substr($d[2], 1));
        if ($t === 'H') {
            $hctr++;
            if (strtoupper($d[1]) !== 'HSAWT') {
                $w($ln, 'Invalid Type of Alpha List. Value must be HSAWT.');
            }
            if ($hctr > 1) {
                $w($ln, 'File has more than 1 Header record.');
            } else {
                if ($ft !== $form || $d[2] === '') {
                    $w($ln, 'Invalid Form Type Code.');
                } else {
                    $eform = $ft;
                }
                if ($d[3] === '') {
                    $w($ln, 'TIN of Payee is Empty.');
                } else {
                    if ($d[3] !== $tin || $badTin($d[3])) {
                        $w($ln, 'Invalid TIN of Payee.');
                    }
                    if (!$dc($d[3], 1)) {
                        $w($ln, 'Invalid Characters in Field TIN of Payee.');
                    }
                    if (strlen($d[3]) !== 9) {
                        $w($ln, 'Invalid Length in TIN of Payee.');
                    }
                }
                if ($d[4] === '') {
                    $w($ln, 'Branch Code of Payee is Empty.');
                } elseif (!$dc($d[4], 1) || strlen($d[4]) !== 4 || $d[4] !== $branch) {
                    $w($ln, 'Invalid Branch of Payee.');
                }
                if ($d[5] . $d[6] . $d[7] . $d[8] === '') {
                    $w($ln, 'Name of Payee is empty');
                } else {
                    $name($d[5], $ln, 'Registered Name');
                    $name($d[6], $ln, 'Last Name');
                    $name($d[7], $ln, 'First Name');
                    $name($d[8], $ln, 'Middle Name');
                }
                if ($d[9] === '') {
                    $w($ln, 'Return Period is Empty.');
                } elseif (!preg_match('#^(0[1-9]|1[0-2])/\d{4}$#', $d[9])) {
                    $w($ln, 'Invalid Date/Characters in Field Return Period.');
                } elseif ($d[9] !== $period) {
                    $w($ln, 'Specified Return Period not the same!');
                } else {
                    $ereturn = $d[9];
                }
                if ($d[10] === '') {
                    $w($ln, 'Payee RDO Code is empty.');
                } elseif (strlen($d[10]) !== 3) {
                    $w($ln, 'Invalid length of Payee RDO Code.');
                }
            }
            if ($d[11] . $d[12] . $d[13] . $d[14] !== '') {
                $w($ln, 'Header Excess Column');
            }
        }
        if ($t === 'D' || $t === 'C') {
            if ($ft !== $form) {
                $w($ln, 'Form Type Code is Invalid.');
            } elseif ($hctr >= 1 && $eform !== '' && $ft !== $eform) {
                $w($ln, 'Form type Code not equal to Header or invalid form type code.');
            }
            if ($t === 'D' && $hctr >= 1 && $ereturn !== '' && $d[10] !== $ereturn) {
                $w($ln, 'Return Period not equal to Header or invalid Return Period.');
            }
        }
        if ($t === 'D') {
            if (strtoupper($d[1]) !== 'DSAWT') {
                $w($ln, 'Invalid Type of Alpha List. Value must be DSAWT.');
            }
            if ($d[4] !== '' && $badTin($d[4])) {
                $w($ln, 'Invalid WA TIN.');
            }
            if ($d[5] !== '' && strlen($d[5]) !== 4) {
                $w($ln, 'Invalid length of WA Agent Branch Code.');
            }
            if ($ft === $form) {
                $dctr++;
                $sumInc += (float)$d[14];
                $sumTax += (float)$d[15];
                foreach (array(3 => 'Sequence Number', 4 => 'TIN', 5 => 'Branch Code') as $k => $l) {
                    if (!$dc($d[$k], 1)) {
                        $w($ln, 'Invalid Characters in ' . $l . ' of WA.');
                    }
                }
                if (!$dc($d[13], 2)) {
                    $w($ln, 'Invalid Characters in Rate of Tax of WA.');
                }
                if (!$dc($d[15], 2)) {
                    $w($ln, 'Invalid Characters in Amount of Tax Withheld of WA.');
                }
                if ($d[6] . $d[7] . $d[8] . $d[9] === '') {
                    $w($ln, 'Name of Withholding Agent is empty.');
                } else {
                    $name($d[6], $ln, 'Registered Name');
                    $name($d[7], $ln, 'Last Name');
                    $name($d[8], $ln, 'First Name');
                    $name($d[9], $ln, 'Middle Name');
                }
                $name($d[11], $ln, 'Nature of Income');
                if ($d[12] === '') {
                    $w($ln, 'ATC is empty.');
                } elseif (!isset($atc[strtoupper($d[12])])) {
                    $w($ln, 'ATC is invalid.');
                }
                if (!$dc($d[14], 2)) {
                    $w($ln, 'Invalid Characters in Amount of Income Payment of WA.');
                } elseif ((float)$d[14] == 0) {
                    $w($ln, 'Amount of Income Payment is empty or zero.');
                }
                if ($d[16] . $d[17] . $d[18] . $d[19] . $d[20] !== '') {
                    $w($ln, 'Detail Excess Column');
                }
            }
        }
        if ($t === 'C') {
            if (strtoupper($d[1]) !== 'CSAWT') {
                $w($ln, 'Invalid Type of Alpha List. Value must be CSAWT.');
            }
            if ($ft !== $form || $d[2] === '') {
                $w($ln, 'Invalid Form Type Code.');
            }
            if ($d[3] === '') {
                $w($ln, 'TIN of Payee is Empty.');
            } elseif ($d[3] !== $tin || $badTin($d[3]) || !$dc($d[3], 1)) {
                $w($ln, 'Invalid TIN of Payee.');
            }
            if ($d[4] === '' || $d[4] !== $branch) {
                $w($ln, 'Invalid Branch of Payee.');
            }
            if ($d[5] === '') {
                $w($ln, 'Return Period is Empty.');
            } elseif ($d[5] !== $period) {
                $w($ln, 'Specified Return Period not the same!');
            }
            if ($ft === $form) {
                $cctr++;
                $cline = $ln;
                $totInc += (float)$d[6];
                $totTax += (float)$d[7];
                if (!$dc($d[7], 2)) {
                    $w($ln, 'Invalid Characters in Total Amount of Tax Withheld.');
                }
                if ($cctr > 1) {
                    $w($ln, 'File has more than 1 Control Record on file.');
                }
                if ($d[8] . $d[9] . $d[10] . $d[11] !== '') {
                    $w($ln, 'Control Excess Column');
                }
            }
        }
    }
    if ($hctr <= 0) {
        $w(0, 'No Header record found in file!');
    }
    if ($dctr <= 0) {
        $w(count($lines), 'No Detail Record found in file!');
    }
    if (!$out) {
        // V_THOLD: the module lets the totals differ by up to 2 pesos.
        if (round($totInc, 2) != round($sumInc, 2) && abs($totInc - $sumInc) > 2) {
            $w($cline, 'Control Totals not equal to sum of Details - Income Payment Amount');
        }
        if (round($totTax, 2) != round($sumTax, 2) && abs($totTax - $sumTax) > 2) {
            $w($cline, 'Control Totals not equal to sum of Details - Amount of Tax Withheld');
        }
    }
    return $out;
}
