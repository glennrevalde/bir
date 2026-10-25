<?php
$token = (string)input('token');
$valid = preg_match('/^[a-f0-9]{48}$/', $token) && DB::val('SELECT id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?', array(hash('sha256', $token), 'reset', now()));
$error = null;
if (is_post() && $valid) {
    $pw = (string)input('password');
    if ($p = Auth::passwordProblem($pw)) {
        $error = $p;
    } elseif ($pw !== (string)input('password2')) {
        $error = 'The passwords do not match.';
    } else {
        $uid = Auth::consumeToken($token, 'reset');
        if ($uid) {
            DB::update('users', array('password_hash' => Auth::hash($pw), 'password_changed_at' => now(), 'must_change_password' => 0, 'failed_logins' => 0, 'updated_at' => now(),
                'email_verified_at' => DB::val('SELECT COALESCE(email_verified_at, ?) FROM users WHERE id = ?', array(now(), $uid))), 'id = ?', array($uid));
            Audit::log('auth.reset', 'user', $uid, 'Password reset by email link', null, null, null, null, $uid);
            flash('success', 'Your password was changed. Sign in with the new password.');
            redirect(url('login'));
        }
        $error = 'The link expired. Request a new one.';
    }
}
View::header('Choose a new password', array('bare' => true));
?>
<div class="auth">
  <?php include APP_PATH . '/views/auth_brand.php'; ?>
  <div class="sheet"><div class="sheet-body">
    <h1>Choose a new password</h1>
    <?php if (!$valid) { ?>
      <p class="lead">This reset link is invalid or expired.</p>
      <a class="btn" href="<?= e(url('forgot')) ?>">Request a new link</a>
    <?php } else { ?>
      <?php if ($error) { ?><div class="notice notice-error"><?= e($error) ?></div><?php } ?>
      <form method="post" action="<?= e(url('reset', array('token' => $token))) ?>"><?= csrf_field() ?>
        <div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" required><span class="hint">At least 10 characters with letters and numbers</span></div>
        <div class="field"><label for="password2">Repeat new password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%">Save password</button>
      </form>
    <?php } ?>
  </div></div>
</div>
<?php View::footer();
