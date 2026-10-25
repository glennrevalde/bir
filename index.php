<?php
/**
 * G-SMaRT BIR Integration System front controller.
 * Every page is app/pages/<name>.php, reached as index.php?p=<name>.
 */
require __DIR__ . '/app/bootstrap.php';

$page = isset($_GET['p']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['p'])) : 'dashboard';
if ($page === '') {
    $page = 'dashboard';
}
$public = array('login', 'register', 'verify', 'forgot', 'reset', 'mfa', 'resend');

if (is_post()) {
    csrf_check();
}

if (!in_array($page, $public, true)) {
    if (!Auth::check()) {
        $next = isset($_SERVER['REQUEST_URI']) && !is_post() ? $_SERVER['REQUEST_URI'] : '';
        redirect(url('login', $next ? array('next' => $next) : array()));
    }
    if (!Auth::touch()) {
        flash('info', 'You were signed out after a period of inactivity.');
        redirect(url('login'));
    }
    $u = Auth::user();
    $allowedWhenForced = array('settings', 'logout', 'help', 'manual');
    if ((int)$u['must_change_password'] === 1 && !in_array($page, $allowedWhenForced, true)) {
        flash('warning', 'Set a new password before continuing.');
        redirect(url('settings'));
    }
    if (sys_config('enforce_mfa_privileged', '0') === '1' && (int)$u['mfa_enabled'] !== 1 && Auth::isPrivileged($u) && !in_array($page, $allowedWhenForced, true)) {
        flash('warning', 'Your role requires two-step sign-in. Set it up to continue.');
        redirect(url('settings', array('tab' => 'mfa')));
    }
    if (!Ctx::org()) {
        // Client records always belong to an organization. A superadmin without one can only reach platform pages.
        $platform = array_merge($allowedWhenForced, array('org_new', 'orgs'));
        if (Ctx::isSuper()) {
            $platform = array_merge($platform, array('system', 'specs', 'spec_view', 'atc', 'reference', 'notifications'));
        }
        if (!in_array($page, $platform, true)) {
            if (Ctx::isSuper()) {
                flash('info', 'Open an organization first, or create one. Clients and records always belong to an organization.');
                redirect(url('orgs'));
            }
            redirect(url('org_new'));
        }
    }
}

$file = APP_PATH . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    View::render('errors/notfound', array(), 'Page not found');
    exit;
}
require $file;
