<?php
if (empty($_SESSION['mfa_pending_uid'])) {
    redirect(url('login'));
}
$error = null;
if (is_post()) {
    $r = Auth::verifyMfa((string)input('code'));
    if ($r['ok']) {
        $next = input('next', '');
        redirect(($next && str_starts($next, base_url() . '/index.php')) ? $next : url('dashboard'));
    }
    $error = $r['message'];
}
View::header('Two-step sign-in', array('bare' => true));
?>
<div class="auth">
  <?php include APP_PATH . '/views/auth_brand.php'; ?>
  <div class="sheet"><div class="sheet-body">
    <h1>Two-step sign-in</h1>
    <p class="lead">Enter the 6-digit code from your authenticator app.</p>
    <?php if ($error) { ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php } ?>
    <form method="post" action="<?= e(url('mfa')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e(input('next', '')) ?>">
      <div class="field"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></div>
      <button class="btn btn-primary" type="submit" style="width:100%">Verify</button>
    </form>
    <p class="alt"><a href="<?= e(url('login')) ?>">Use a different account</a></p>
  </div></div>
</div>
<?php View::footer();
