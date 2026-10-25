<?php
/**
 * AES-256-GCM encryption for secrets at rest (MFA seeds) and backup files.
 */
class Crypto
{
    private static function appKey()
    {
        $hex = (string)config('app_key', '');
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new RuntimeException('config.php app_key must be 64 hexadecimal characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        return hex2bin($hex);
    }

    public static function keyIsValid()
    {
        return (bool)preg_match('/^[0-9a-fA-F]{64}$/', (string)config('app_key', '')) && strpos((string)config('app_key'), 'CHANGE_ME') === false;
    }

    public static function encrypt($plain, $key = null)
    {
        $key = $key ?: self::appKey();
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($blob, $key = null)
    {
        $key = $key ?: self::appKey();
        if (strpos($blob, 'v1:') !== 0) {
            return null;
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $p = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $p === false ? null : $p;
    }

    /** Binary file encryption with a passphrase (backups). Format: GSBK1 | salt16 | iv12 | tag16 | ct */
    public static function encryptWithPassphrase($data, $passphrase)
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 32, true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'GSBK1' . $salt . $iv . $tag . $ct;
    }

    public static function decryptWithPassphrase($blob, $passphrase)
    {
        if (substr($blob, 0, 5) !== 'GSBK1' || strlen($blob) < 49) {
            return null;
        }
        $salt = substr($blob, 5, 16);
        $iv = substr($blob, 21, 12);
        $tag = substr($blob, 33, 16);
        $ct = substr($blob, 49);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 200000, 32, true);
        $p = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $p === false ? null : $p;
    }
}
