<?php
if (Auth::check()) {
    redirect(url('dashboard'));
}
if (sys_config('registration_open', '1') !== '1') {
    flash('info', 'Self-service registration is closed. Ask your administrator for an invitation.');
    redirect(url('login'));
}
$errors = array();
$done = false;
if (is_post()) {
    $name = trim((string)input('full_name'));
    $email = strtolower(trim((string)input('email')));
    $org = trim((string)input('org_name'));
    $type = input('org_type') === 'company' ? 'company' : 'firm';
    $pw = (string)input('password');
    if ($name === '') {
        $errors['full_name'] = 'Enter your name';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email';
    } elseif (DB::val('SELECT id FROM users WHERE email = ?', array($email))) {
        $errors['email'] = 'This email already has an account. Sign in instead.';
    }
    if ($org === '') {
        $errors['org_name'] = 'Enter your firm or company name';
    }
    if ($p = Auth::passwordProblem($pw)) {
        $errors['password'] = $p;
    } elseif ($pw !== (string)input('password2')) {
        $errors['password2'] = 'The passwords do not match';
    }
    if (!input('privacy')) {
        $errors['privacy'] = 'Confirm that you have read the privacy notice';
    }
    if (!$errors) {
        DB::tx(function () use ($name, $email, $org, $type, $pw) {
            $uid = DB::insert('users', array('email' => $email, 'password_hash' => Auth::hash($pw), 'full_name' => $name, 'status' => 'active',
                'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()));
            $oid = DB::insert('organizations', array('name' => $org, 'org_type' => $type, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()));
            DB::insert('memberships', array('user_id' => $uid, 'organization_id' => $oid, 'role_code' => 'firm_owner', 'all_clients' => 1, 'status' => 'active', 'created_at' => now()));
            Audit::log('auth.register', 'user', $uid, 'Registered ' . $email . ' for ' . $org, null, null, null, null, $uid);
            Auth::sendVerification($uid);
        });
        $done = true;
    }
}
View::header('Create an account', array('bare' => true));
?>
<div class="auth" style="max-width:520px">
  <?php include APP_PATH . '/views/auth_brand.php'; ?>
  <div class="sheet"><div class="sheet-body">
    <?php if ($done) { ?>
      <h1>Check your email</h1>
      <p class="lead">We sent a verification link to <?= e(input('email')) ?>. Open it to activate your account, then sign in.</p>
      <p class="small muted">On an installation without email delivery, an administrator can read the link under Platform settings, Mail outbox.</p>
      <a class="btn btn-primary" href="<?= e(url('login')) ?>">Go to sign in</a>
    <?php } else { ?>
      <h1>Create an account</h1>
      <p class="lead">You become the owner of a new workspace. Add clients and invite your team after signing in.</p>
      <form method="post" action="<?= e(url('register')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field wide <?= isset($errors['full_name']) ? 'has-err' : '' ?>"><label for="full_name">Your name</label><input id="full_name" name="full_name" type="text" value="<?= e(input('full_name', '')) ?>" required><?php if (isset($errors['full_name'])) { ?><span class="err"><?= e($errors['full_name']) ?></span><?php } ?></div>
          <div class="field wide <?= isset($errors['email']) ? 'has-err' : '' ?>"><label for="email">Work email</label><input id="email" name="email" type="email" value="<?= e(input('email', '')) ?>" required><?php if (isset($errors['email'])) { ?><span class="err"><?= e($errors['email']) ?></span><?php } ?></div>
          <div class="field wide <?= isset($errors['org_name']) ? 'has-err' : '' ?>"><label for="org_name">Firm or company name</label><input id="org_name" name="org_name" type="text" value="<?= e(input('org_name', '')) ?>" required><?php if (isset($errors['org_name'])) { ?><span class="err"><?= e($errors['org_name']) ?></span><?php } ?></div>
          <div class="field wide"><label for="org_type">This workspace is for</label>
            <?= View::select('org_type', array('firm' => 'An accounting firm serving several clients', 'company' => 'One company and its branches'), input('org_type', 'firm'), 'id="org_type"') ?></div>
          <div class="field <?= isset($errors['password']) ? 'has-err' : '' ?>"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required><span class="hint">At least 10 characters with letters and numbers</span><?php if (isset($errors['password'])) { ?><span class="err"><?= e($errors['password']) ?></span><?php } ?></div>
          <div class="field <?= isset($errors['password2']) ? 'has-err' : '' ?>"><label for="password2">Repeat password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required><?php if (isset($errors['password2'])) { ?><span class="err"><?= e($errors['password2']) ?></span><?php } ?></div>
          <div class="field wide <?= isset($errors['privacy']) ? 'has-err' : '' ?>">
            <label class="check"><input type="checkbox" name="privacy" value="1" <?= input('privacy') ? 'checked' : '' ?>> I have read the privacy notice below and am authorized to process the taxpayer data I will enter.</label>
            <?php if (isset($errors['privacy'])) { ?><span class="err"><?= e($errors['privacy']) ?></span><?php } ?>
            <span class="hint">This workspace stores taxpayer, supplier and employee data to prepare BIR reports, under the Data Privacy Act of 2012 (R.A. 10173). Data stays in this installation, is separated per organization and client, and is never sent to analytics or AI services by the system.</span>
          </div>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;margin-top:6px">Create account</button>
      </form>
    <?php } ?>
  </div>
  <div class="sheet-foot" style="justify-content:center">Already registered? <a href="<?= e(url('login')) ?>">Sign in</a></div>
  </div>
</div>
<?php View::footer();
