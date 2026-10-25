<?php
if (is_post() && Ctx::switchClient(input_int('client_id'))) {
    $back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : url('dashboard');
    // Detail pages (ids) belong to the previous client; return to the section list instead.
    if (preg_match('/[?&]id=\d+/', $back)) {
        $back = url('dashboard');
    }
    redirect($back);
}
flash('error', 'That client is not available to you.');
redirect(url('dashboard'));
