<?php
if (is_post()) {
    Auth::logout();
    flash('info', 'You are signed out.');
}
redirect(url('login'));
