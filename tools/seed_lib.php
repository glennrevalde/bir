<?php
/**
 * Install-time seeding shared by the CLI installer and the SQL builder.
 */
require_once dirname(__DIR__) . '/app/specs/reference.php';
require_once dirname(__DIR__) . '/app/specs/rdo_codes.php';

function gsb_run_sql_file($path)
{
    $sql = file_get_contents($path);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        if ($stmt !== '') {
            DB::pdo()->exec($stmt);
        }
    }
}

function gsb_seed_reference($superEmail = 'admin@gsmart.local', $superPassword = 'ChangeMe@2026')
{
    $R = gsb_reference();
    foreach ($R['perms'] as $code => $p) {
        DB::insert('permissions', array('code' => $code, 'name' => $p[0], 'group_name' => $p[1]));
    }
    foreach ($R['roles'] as $code => $r) {
        DB::insert('roles', array('code' => $code, 'name' => $r[0], 'description' => $r[1], 'rank_order' => $r[2], 'is_privileged' => $r[3]));
        foreach ($r[4] as $perm) {
            DB::insert('role_permissions', array('role_code' => $code, 'permission_code' => $perm));
        }
    }
    foreach ($R['forms'] as $f) {
        DB::insert('form_types', array('code' => $f[0], 'family' => $f[1], 'name' => $f[2], 'frequency' => $f[3], 'output_format' => $f[4], 'description' => $f[5], 'sort_order' => $f[6], 'is_active' => 1));
    }
    foreach ($R['schedules'] as $s) {
        DB::insert('report_schedules', array('form_code' => $s[0], 'schedule_code' => $s[1], 'name' => $s[2], 'dataset_key' => $s[3], 'sort_order' => $s[4]));
    }
    foreach ($R['tax_codes'] as $t) {
        DB::insert('tax_codes', array('atc_code' => $t[0], 'tax_kind' => $t[1], 'payee_type' => $t[2], 'description' => $t[3], 'rate' => $t[4],
            'effective_from' => '2018-01-01', 'effective_to' => null, 'version_tag' => '1601EQ-2019-AnnexA', 'source_ref' => $t[5], 'status' => 'pending_review',
            'notes' => 'Seeded at install. Approve after checking the ATC table of the installed validator.', 'created_at' => now()));
    }
    foreach ($R['config'] as $k => $v) {
        DB::insert('system_config', array('k' => $k, 'v' => $v));
    }
    foreach (gsb_rdo_codes() as $r) {
        DB::insert('rdo_codes', array('code' => $r[0], 'name' => $r[1]));
    }
    Specs::seed(null);
    $uid = DB::insert('users', array('email' => $superEmail, 'password_hash' => Auth::hash($superPassword), 'full_name' => 'Platform Administrator',
        'is_superadmin' => 1, 'email_verified_at' => now(), 'must_change_password' => 1, 'status' => 'active', 'password_changed_at' => now(),
        'created_at' => now(), 'updated_at' => now()));
    return $uid;
}
