<?php
/**
 * RFC 6238 time-based one-time passwords (Google Authenticator, Microsoft Authenticator, Authy).
 */
class Totp
{
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret($len = 32)
    {
        $s = '';
        $bytes = random_bytes($len);
        for ($i = 0; $i < $len; $i++) {
            $s .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $s;
    }

    public static function base32Decode($b32)
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $b32[$i])), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }

    public static function code($secret, $timeSlice = null)
    {
        $timeSlice = $timeSlice === null ? (int)floor(time() / 30) : $timeSlice;
        $key = self::base32Decode($secret);
        $msg = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $msg, $key, true);
        $off = ord(substr($hm, -1)) & 0x0F;
        $part = substr($hm, $off, 4);
        $val = unpack('N', $part);
        $val = $val[1] & 0x7FFFFFFF;
        return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify($secret, $code, $window = 1)
    {
        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== 6) {
            return false;
        }
        $slice = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $slice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function uri($secret, $email, $issuer = 'G-SMaRT BIR')
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }
}
