<?php
if (Auth::check()) {
    redirect(url('dashboard'));
}
$error = null;
$unverified = null;
$next = input('next', '');
if (is_post()) {
    $email = (string)input('email');
    $res = Auth::attempt($email, (string)input('password'));
    if ($res['status'] === 'ok') {
        $dest = ($next && str_starts($next, base_url() . '/index.php')) ? $next : url('dashboard');
        redirect($dest);
    } elseif ($res['status'] === 'mfa') {
        redirect(url('mfa', $next ? array('next' => $next) : array()));
    } elseif ($res['status'] === 'unverified') {
        $unverified = $res['user_id'];
        $error = $res['message'];
    } else {
        $error = $res['message'];
    }
}
View::header('Sign in', array('bare' => true));
?>
<div class="auth">
  <?php include APP_PATH . '/views/auth_brand.php'; ?>
  <div class="sheet">
    <div class="sheet-body">
      <h1>Sign in</h1>
      <p class="lead">Prepare, check and track BIR alphalists, RELIEF lists and certificates.</p>
      <?php if ($error) { ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php } ?>
      <?php if ($unverified) { ?>
        <form method="post" action="<?= e(url('resend')) ?>" class="callout" style="margin-bottom:14px"><?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= e(input('email')) ?>">
          Did not get the email? <button class="link-btn" type="submit">Send the verification link again</button>
        </form>
      <?php } ?>
      <form method="post" action="<?= e(url('login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= e(input('email', '')) ?>" autofocus></div>
        <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%">Sign in</button>
      </form>
      <p class="alt"><a href="<?= e(url('forgot')) ?>">Forgot your password?</a></p>
    </div>
    <?php if (sys_config('registration_open', '1') === '1') { ?>
    <div class="sheet-foot" style="justify-content:center">New firm or company? <a href="<?= e(url('register')) ?>">Create an account</a></div>
    <?php } ?>
  </div>
</div>
<?php View::footer();
