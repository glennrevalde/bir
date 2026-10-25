<?php
/**
 * Exact money arithmetic using integer centavos (no floating point anywhere).
 * Rates are integer units of 1/10000 of a percent (1.0000% = 10000).
 */
class Money
{
    /**
     * Parse user/spreadsheet input into centavos.
     * Returns array('ok'=>bool, 'cents'=>int, 'rounded'=>bool, 'error'=>string)
     */
    public static function parse($v)
    {
        if ($v === null) {
            return array('ok' => true, 'cents' => 0, 'rounded' => false, 'error' => null, 'blank' => true);
        }
        if (is_int($v)) {
            return array('ok' => true, 'cents' => $v * 100, 'rounded' => false, 'error' => null, 'blank' => false);
        }
        if (is_float($v)) {
            $v = self::floatToString($v);
        }
        $s = trim((string)$v);
        if ($s === '' || $s === '-') {
            return array('ok' => true, 'cents' => 0, 'rounded' => false, 'error' => null, 'blank' => true);
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $neg = true;
            $s = trim($m[1]);
        }
        $s = str_replace(array(',', ' ', 'PHP', 'Php', '₱', 'P'), '', $s);
        if ($s !== '' && $s[0] === '-') {
            $neg = !$neg;
            $s = substr($s, 1);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)[eE]([+-]?\d+)$/', $s, $m)) {
            $s = self::expandExponent($m[1], (int)$m[2]);
        }
        if (!preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[1] === '' && (!isset($m[2]) || $m[2] === ''))) {
            return array('ok' => false, 'cents' => 0, 'rounded' => false, 'error' => 'not a number', 'blank' => false);
        }
        $int = $m[1] === '' ? '0' : ltrim($m[1], '0');
        if ($int === '') {
            $int = '0';
        }
        if (strlen($int) > 13) {
            return array('ok' => false, 'cents' => 0, 'rounded' => false, 'error' => 'amount is too large', 'blank' => false);
        }
        $frac = isset($m[2]) ? $m[2] : '';
        $rounded = false;
        $cents = (int)$int * 100 + (int)str_pad(substr($frac, 0, 2), 2, '0');
        if (strlen($frac) > 2) {
            $rest = substr($frac, 2);
            if (trim($rest, '0') !== '') {
                $rounded = true;
                if ((int)$rest[0] >= 5) {
                    $cents += 1;
                }
            }
        }
        return array('ok' => true, 'cents' => $neg ? -$cents : $cents, 'rounded' => $rounded, 'error' => null, 'blank' => false);
    }

    private static function expandExponent($mant, $exp)
    {
        $parts = explode('.', $mant);
        $digits = $parts[0] . (isset($parts[1]) ? $parts[1] : '');
        $point = strlen($parts[0]) + $exp;
        if ($point <= 0) {
            return '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= strlen($digits)) {
            return $digits . str_repeat('0', $point - strlen($digits));
        }
        return substr($digits, 0, $point) . '.' . substr($digits, $point);
    }

    public static function floatToString($f)
    {
        $s = sprintf('%.6F', $f);
        return rtrim(rtrim($s, '0'), '.');
    }

    /** Centavos from a DB DECIMAL string ("1234.50"). */
    public static function fromDb($s)
    {
        $r = self::parse($s);
        return $r['cents'];
    }

    /** "1234.56" style, as stored in DB and written to DAT. */
    public static function toPlain($cents)
    {
        $neg = $cents < 0;
        $c = abs((int)$cents);
        return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Whole number when there are no centavos, else two decimals ("1000", "1000.50"). */
    public static function toTrim($cents)
    {
        $c = (int)$cents;
        if ($c % 100 === 0) {
            return (string)intdiv($c, 100);
        }
        $s = self::toPlain($c);
        return $s;
    }

    /** "1,234.56" for display. */
    public static function fmt($cents, $blankZero = false)
    {
        $c = (int)$cents;
        if ($blankZero && $c === 0) {
            return '';
        }
        $neg = $c < 0;
        $c = abs($c);
        return ($neg ? '-' : '') . number_format(intdiv($c, 100)) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function fmtDb($s, $blankZero = false)
    {
        return self::fmt(self::fromDb($s), $blankZero);
    }

    /** Rate string ("1.0000", "10") to integer units of 1/10000 percent. */
    public static function rateUnits($rate)
    {
        $s = trim((string)$rate);
        if ($s === '') {
            return 0;
        }
        $s = rtrim($s, '%');
        if (!preg_match('/^(\d+)(?:\.(\d{0,6}))?$/', $s, $m)) {
            return null;
        }
        $frac = isset($m[2]) ? $m[2] : '';
        return (int)$m[1] * 10000 + (int)str_pad(substr($frac, 0, 4), 4, '0');
    }

    public static function rateString($units)
    {
        return intdiv($units, 10000) . '.' . str_pad((string)($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    /** Percent with 2 decimals ("1.00") or fraction ("0.01"). */
    public static function rateFormat($units, $fmt)
    {
        if ($fmt === 'frac2') {
            // units/1000000 as a fraction, two decimals, half-up
            $hund = intdiv($units * 1 + 5000, 10000); // percent rounded to integer = fraction with 2 decimals
            return intdiv($hund, 100) . '.' . str_pad((string)($hund % 100), 2, '0', STR_PAD_LEFT);
        }
        $c = intdiv($units + 50, 100); // percent with 2 decimals, half-up
        return intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** tax = base x rate, half away from zero, overflow-safe. */
    public static function applyRate($cents, $units)
    {
        $neg = $cents < 0;
        $c = abs((int)$cents);
        $q = intdiv($c, 1000000);
        $r = $c % 1000000;
        $partial = $r * $units;
        $tax = $q * $units + intdiv($partial + 500000, 1000000);
        return $neg ? -$tax : $tax;
    }
}
