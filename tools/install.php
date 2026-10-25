<?php
/**
 * Command-line installer.
 *   php tools/install.php                 create tables and seed reference data in the configured database
 *   php tools/install.php --demo          also load demo tenants and records
 *   php tools/install.php --admin=me@x --password=Secret12345
 * It refuses to run on a database that already has tables unless --force is given.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/seed_lib.php';

$opts = getopt('', array('demo', 'force', 'admin::', 'password::'));
$tables = DB::col('SHOW TABLES');
if ($tables && !isset($opts['force'])) {
    fwrite(STDERR, "The database already has " . count($tables) . " tables. Use --force to drop and reinstall.\n");
    exit(1);
}
echo "Creating tables...\n";
gsb_run_sql_file(dirname(__DIR__) . '/database/schema.sql');
echo "Seeding reference data and file layouts...\n";
$admin = isset($opts['admin']) && $opts['admin'] ? $opts['admin'] : 'admin@gsmart.local';
$pw = isset($opts['password']) && $opts['password'] ? $opts['password'] : 'ChangeMe@2026';
gsb_seed_reference($admin, $pw);
echo "Superadmin: $admin / $pw (you will be asked to change it)\n";
if (isset($opts['demo'])) {
    require __DIR__ . '/demo_lib.php';
    echo "Loading demo data...\n";
    gsb_seed_demo();
    echo "Demo users (password Demo@123456): owner@demo.local, preparer@demo.local, reviewer@demo.local, encoder@demo.local, auditor@demo.local\n";
}
echo "Done.\n";
