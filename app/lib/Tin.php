<?php
/**
 * TIN and branch code handling. TINs are always strings; leading zeros are kept.
 */
class Tin
{
    /**
     * Normalize a TIN as typed or imported.
     * Accepts 123-456-789, 123456789, 123-456-789-0000, 1234567890000 and numeric cells.
     * Returns array('ok', 'tin', 'branch' (null when not supplied), 'error', 'warning')
     */
    public static function parse($raw, $fromNumericCell = false)
    {
        $res = array('ok' => false, 'tin' => '', 'branch' => null, 'error' => null, 'warning' => null);
        if ($raw === null) {
            $res['error'] = 'TIN is blank';
            return $res;
        }
        $s = trim((string)$raw);
        if ($s === '') {
            $res['error'] = 'TIN is blank';
            return $res;
        }
        if (preg_match('/^\d+(\.\d+)?[eE]\+?\d+$/', $s)) {
            $res['error'] = 'TIN was stored as a number in scientific notation and its digits may be lost. Format the column as Text and re-enter the TIN.';
            return $res;
        }
        $digits = preg_replace('/\D/', '', $s);
        if (preg_match('/[A-Za-z]/', $s)) {
            $res['error'] = 'TIN contains letters';
            return $res;
        }
        $len = strlen($digits);
        if ($len === 9) {
            $res['tin'] = $digits;
        } elseif ($len === 12) {
            $res['tin'] = substr($digits, 0, 9);
            $res['branch'] = '0' . substr($digits, 9, 3);
            $res['warning'] = 'A 3-digit branch suffix was read; it was converted to the 4-digit branch code ' . $res['branch'] . '.';
        } elseif ($len === 13 || $len === 14) {
            $res['tin'] = substr($digits, 0, 9);
            $res['branch'] = substr($digits, 9);
            if ($len === 14) {
                $res['warning'] = 'A 5-digit branch suffix was read. Alphalist modules expect 4 digits; check the branch code.';
            }
        } elseif ($len === 8 && $fromNumericCell) {
            $res['error'] = 'TIN has 8 digits; a leading zero was probably dropped by Excel. Format the column as Text and enter all 9 digits.';
            return $res;
        } else {
            $res['error'] = 'TIN must have 9 digits (or 9 digits plus a branch code); found ' . $len;
            return $res;
        }
        if ($res['tin'] === '000000000') {
            $res['error'] = 'TIN cannot be all zeros';
            return $res;
        }
        $res['ok'] = true;
        return $res;
    }

    /** Branch codes are digit strings, padded to 4 (alphalist) unless 5 are given. */
    public static function branch($raw, $width = 4)
    {
        $d = preg_replace('/\D/', '', (string)$raw);
        if ($d === '') {
            return str_repeat('0', $width);
        }
        if (strlen($d) > 5) {
            return null;
        }
        return strlen($d) >= $width ? $d : str_pad($d, $width, '0', STR_PAD_LEFT);
    }

    public static function display($tin, $branch = null)
    {
        $t = (string)$tin;
        if (strlen($t) !== 9) {
            return $t;
        }
        $s = substr($t, 0, 3) . '-' . substr($t, 3, 3) . '-' . substr($t, 6, 3);
        if ($branch !== null && $branch !== '') {
            $s .= '-' . $branch;
        }
        return $s;
    }

    public static function mask($tin)
    {
        $t = (string)$tin;
        if (strlen($t) !== 9) {
            return $t;
        }
        return '***-***-' . substr($t, 6, 3);
    }

    /** Segmented TIN display used across the UI (the form-box look). */
    public static function boxes($tin, $branch = null, $masked = false)
    {
        $t = (string)$tin;
        if (strlen($t) !== 9) {
            return '<span class="tin-boxes tin-empty">' . e($t !== '' ? $t : 'No TIN') . '</span>';
        }
        $groups = array(substr($t, 0, 3), substr($t, 3, 3), substr($t, 6, 3));
        if ($masked) {
            $groups[0] = '•••';
            $groups[1] = '•••';
        }
        $html = '<span class="tin-boxes" aria-label="TIN ' . e(self::display($t, $branch)) . '">';
        foreach ($groups as $g) {
            $html .= '<span>' . e($g) . '</span>';
        }
        if ($branch !== null && $branch !== '') {
            $html .= '<span class="tin-branch">' . e($branch) . '</span>';
        }
        return $html . '</span>';
    }
}
