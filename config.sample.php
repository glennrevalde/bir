<?php
/**
 * G-SMaRT BIR Integration System configuration.
 * Copy this file to config.php and adjust. Never commit config.php.
 */
return array(
    'app_name' => 'G-SMaRT BIR Integration System',

    // Base URL of the app folder, without trailing slash. Leave empty to auto-detect.
    'base_url' => '',

    'db' => array(
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gsmart_bir',
        'user' => 'root',
        'pass' => '',
    ),

    // 64 hex characters (32 bytes). Encrypts MFA secrets and backups.
    // Generate one with: php -r "echo bin2hex(random_bytes(32));"
    // Changing it later makes existing MFA enrolments and encrypted backups unreadable.
    'app_key' => 'CHANGE_ME_TO_64_HEX_CHARACTERS_0000000000000000000000000000000000',

    // Storage folder for uploads, generated DAT/PDF files, evidence and backups.
    // Best placed outside the web root, e.g. 'C:/gsmart-bir-storage' or '/var/lib/gsmart-bir'.
    'storage_path' => __DIR__ . '/storage',

    'timezone' => 'Asia/Manila',

    // true only on a development machine
    'debug' => false,

    // Send cookies over HTTPS only. Turn on in production behind TLS.
    'secure_cookies' => false,

    'mail' => array(
        'from' => 'no-reply@example.com',
        'from_name' => 'G-SMaRT BIR',
    ),
);
