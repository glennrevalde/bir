<?php
/**
 * Rebuilds the two importable SQL files shipped in database/:
 *   database/gsmart_bir_clean.sql  schema + reference data + superadmin, no tenants
 *   database/gsmart_bir_demo.sql   the same plus the demo firm, clients and records
 *
 *   php tools/build_sql.php          builds both files
 *   php tools/build_sql.php demo     builds one (clean or demo)
 *
 * Works in a scratch database named <config db name>_build, which it drops afterwards.
 * Your configured database is not touched.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
define('GSB_NO_SESSION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/seed_lib.php';
require __DIR__ . '/demo_lib.php';

$buildDb = config('db.name') . '_build';
$root = new PDO('mysql:host=' . config('db.host') . ';port=' . (int)config('db.port', 3306) . ';charset=utf8', config('db.user'), config('db.pass'),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$GLOBALS['GSB_CONFIG']['storage_path'] = sys_get_temp_dir() . '/gsb-build-' . getmypid();
foreach (array('', '/uploads', '/dat', '/pdf', '/evidence', '/backups', '/logs', '/tmp', '/signatures') as $sub) {
    @mkdir($GLOBALS['GSB_CONFIG']['storage_path'] . $sub, 0775, true);
}

$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$schema = preg_replace('/\s*SET FOREIGN_KEY_CHECKS = 1;\s*$/', "\n", $schema);

function gsb_build_one($root, $buildDb, $schema, $demo, $target)
{
    $root->exec('DROP DATABASE IF EXISTS `' . $buildDb . '`');
    $root->exec('CREATE DATABASE `' . $buildDb . '` CHARACTER SET utf8');
    $GLOBALS['GSB_CONFIG']['db']['name'] = $buildDb;
    DB::reset();
    gsb_run_sql_file(dirname(__DIR__) . '/database/schema.sql');
    gsb_seed_reference('admin@gsmart.local', 'ChangeMe@2026');
    if ($demo) {
        gsb_seed_demo();
    }
    $tables = DB::col('SHOW TABLES');
    $data = Backup::sqlDump(false, $tables);
    $data = preg_replace('/^-- G-SMaRT BIR Integration System SQL dump\n-- Generated [^\n]*\nSET NAMES utf8;\nSET FOREIGN_KEY_CHECKS = 0;\n\n/', '', $data);
    $head = "-- ============================================================================\n"
        . "-- G-SMaRT BIR Integration System " . GSB_VERSION . ($demo ? ': schema and DEMO data' : ': schema and reference data (clean install)') . "\n"
        . "-- Import into an empty MySQL 5.5+ database, for example with phpMyAdmin or:\n"
        . "--   mysql -u root -p gsmart_bir < database/" . basename($target) . "\n"
        . "-- Superadmin: admin@gsmart.local / ChangeMe@2026 (a new password is required at first sign-in)\n"
        . ($demo ? "-- Demo users (password Demo@123456): owner@demo.local, preparer@demo.local,\n--   reviewer@demo.local, encoder@demo.local, auditor@demo.local\n"
            . "-- Demo DAT files are rebuilt from their frozen snapshots on first open; certificates\n--   are redrawn from their stored payload. No BIR validation evidence is included.\n" : '')
        . "-- ============================================================================\n\n";
    file_put_contents($target, $head . $schema . "\n-- ---------------------------------------------------------------------------\n-- Data\n-- ---------------------------------------------------------------------------\n" . $data);
    echo basename($target) . ': ' . count($tables) . ' tables, ' . number_format(strlen($data)) . " bytes of data\n";
}

$dir = dirname(__DIR__) . '/database';
$variant = isset($argv[1]) ? $argv[1] : '';
if ($variant === '') {
    // Each variant runs in its own process so no static state leaks between them.
    foreach (array('clean', 'demo') as $v) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $v, $code);
        if ($code !== 0) {
            exit($code);
        }
    }
    foreach (array('/uploads', '/dat', '/pdf', '/evidence', '/backups', '/logs', '/tmp', '/signatures', '') as $sub) {
        @rmdir($GLOBALS['GSB_CONFIG']['storage_path'] . $sub);
    }
    echo "Done.\n";
    exit(0);
}
gsb_build_one($root, $buildDb, $schema, $variant === 'demo', $dir . '/gsmart_bir_' . ($variant === 'demo' ? 'demo' : 'clean') . '.sql');
$root->exec('DROP DATABASE IF EXISTS `' . $buildDb . '`');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($GLOBALS['GSB_CONFIG']['storage_path'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($GLOBALS['GSB_CONFIG']['storage_path']);
