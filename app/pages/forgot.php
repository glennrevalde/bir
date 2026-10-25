<?php
$sent = false;
if (is_post()) {
    $email = (string)input('email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && !Auth::isThrottled($email)) {
        Auth::sendReset($email);
    }
    $sent = true;
}
View::header('Reset your password', array('bare' => true));
?>
<div class="auth">
  <?php include APP_PATH . '/views/auth_brand.php'; ?>
  <div class="sheet"><div class="sheet-body">
    <h1>Reset your password</h1>
    <?php if ($sent) { ?>
      <p class="lead">If an account uses that email, a reset link is on its way. The link works for 60 minutes.</p>
      <a class="btn" href="<?= e(url('login')) ?>">Back to sign in</a>
    <?php } else { ?>
      <p class="lead">Enter your account email and we will send a link to choose a new password.</p>
      <form method="post" action="<?= e(url('forgot')) ?>"><?= csrf_field() ?>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autofocus></div>
        <button class="btn btn-primary" type="submit" style="width:100%">Send reset link</button>
      </form>
      <p class="alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
    <?php } ?>
  </div></div>
</div>
<?php View::footer();
