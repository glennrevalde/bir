<?php
if (is_post()) {
    $u = DB::row('SELECT id, email_verified_at FROM users WHERE email = ?', array(strtolower(trim((string)input('email')))));
    if ($u && !$u['email_verified_at'] && !Auth::isThrottled((string)input('email'))) {
        Auth::sendVerification($u['id']);
    }
}
flash('info', 'If the account exists and is not yet verified, a new verification link was sent.');
redirect(url('login'));
