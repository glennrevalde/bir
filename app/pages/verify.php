<?php
$uid = Auth::consumeToken((string)input('token'), 'verify');
if ($uid) {
    DB::update('users', array('email_verified_at' => now(), 'updated_at' => now()), 'id = ? AND email_verified_at IS NULL', array($uid));
    Audit::log('auth.verified', 'user', $uid, 'Email verified', null, null, null, null, $uid);
    flash('success', 'Your email is verified. Sign in to continue.');
} else {
    flash('error', 'That verification link is invalid or expired. Sign in to request a new one.');
}
redirect(url('login'));
