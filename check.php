<?php
/**
 * Requirements check. Open in a browser after copying files, before importing the SQL.
 * Answers only requests made on the server itself. Delete it once installed.
 */
$remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
if (PHP_SAPI !== 'cli' && !in_array($remote, array('127.0.0.1', '::1'), true)) {
    http_response_code(403);
    exit('Open check.php on the server itself, for example http://localhost/gsmart-bir/check.php');
}
$checks = array();
$checks[] = array('PHP 7.2 or newer (found ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '7.2.0', '>='));
foreach (array('pdo_mysql' => 'PDO MySQL driver', 'zip' => 'zip (Excel import and export, ZIP downloads)', 'openssl' => 'openssl (encryption)', 'mbstring' => 'mbstring', 'iconv' => 'iconv (Windows-1252 DAT output)',
             'xmlreader' => 'xmlreader (Excel import)', 'simplexml' => 'simplexml (Excel import)', 'zlib' => 'zlib (PDF and backups)', 'json' => 'json') as $ext => $label) {
    $checks[] = array($label, extension_loaded($ext));
}
$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
$checks[] = array('config.php exists', (bool)$cfg);
$db = null;
if ($cfg) {
    $checks[] = array('app_key is 64 hex characters', (bool)preg_match('/^[0-9a-f]{64}$/i', $cfg['app_key']) && strpos($cfg['app_key'], 'CHANGE_ME') === false);
    try {
        $pdo = new PDO('mysql:host=' . $cfg['db']['host'] . ';port=' . (int)$cfg['db']['port'] . ';dbname=' . $cfg['db']['name'] . ';charset=utf8', $cfg['db']['user'], $cfg['db']['pass']);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        $checks[] = array('Database connection (MySQL ' . $ver . ')', true);
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
        $checks[] = array('Tables imported (' . $n . ' found, 51 expected)', $n >= 51);
        if ($n >= 51) {
            $hasRate = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'purchase_entries' AND column_name = 'vat_rate'")->fetchColumn();
            $sawt = $pdo->query("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'withholding_entries' AND column_name = 'payment_date'")->fetchColumn() === 'YES';
            $checks[] = array($hasRate ? 'Purchases table is up to date (R_Purchases format)' : 'Database needs an update: run database/migrations/2026-10-25_purchases_r_purchases_format.sql', (bool)$hasRate);
            $checks[] = array($sawt ? 'Withholding table is up to date (SAWT layout)' : 'Database needs an update: run database/migrations/2026-10-25b_sawt_layout.sql', $sawt);
            $salesRate = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_entries' AND column_name = 'vat_rate'")->fetchColumn();
            $checks[] = array($salesRate ? 'Sales table is up to date (VAT sales format)' : 'Database needs an update: run database/migrations/2026-10-25c_vat_sales_format.sql', (bool)$salesRate);
        }
        $pk = (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
        $checks[] = array('MySQL max_allowed_packet at least 16M (found ' . round($pk / 1048576, 1) . 'M' . ($pk >= 16 * 1048576 ? ')' : '; set max_allowed_packet = 16M under [mysqld] in my.ini and restart MySQL)'), $pk >= 16 * 1048576);
    } catch (Exception $e) {
        $checks[] = array('Database connection: ' . $e->getMessage(), false);
    }
    $st = isset($cfg['storage_path']) ? $cfg['storage_path'] : __DIR__ . '/storage';
    if (!is_dir($st)) {
        @mkdir($st, 0775, true);
    }
    $checks[] = array('Storage folder writable (' . $st . ')', is_writable($st));
}
$ok = true;
foreach ($checks as $c) {
    $ok = $ok && $c[1];
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Requirements check</title>
<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#191c29;background:#e6e9ef}
.box{background:#fff;border:1px solid #d6dae3;border-radius:12px;padding:20px 24px;box-shadow:0 14px 28px -18px rgba(22,26,44,.45)}li{margin:6px 0}.ok{color:#1b7446}.bad{color:#b02424;font-weight:700}</style></head>
<body><div class="box"><h1>G-SMaRT BIR requirements</h1><ul>
<?php foreach ($checks as $c) { ?><li class="<?= $c[1] ? 'ok' : 'bad' ?>"><?= $c[1] ? 'OK' : 'Missing' ?>: <?= htmlspecialchars($c[0]) ?></li><?php } ?>
</ul><p><?= $ok ? 'Everything is ready. <a href="index.php">Open the application</a>.' : 'Fix the items marked Missing, then reload.' ?></p></div></body></html>
