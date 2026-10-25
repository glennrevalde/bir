<?php
$u = Auth::user();
$tab = input('tab', 'profile');
if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'profile') {
            $name = trim((string)input('full_name'));
            if ($name === '') {
                throw new RuntimeException('Enter your name.');
            }
            DB::update('users', array('full_name' => mb_substr($name, 0, 150), 'updated_at' => now()), 'id = ?', array($u['id']));
            Audit::log('user.profile', 'user', $u['id'], 'Updated profile');
            flash('success', 'Profile saved.');
        } elseif ($action === 'password') {
            if (!password_verify((string)input('current'), $u['password_hash'])) {
                throw new RuntimeException('The current password is incorrect.');
            }
            $pw = (string)input('password');
            if ($p = Auth::passwordProblem($pw)) {
                throw new RuntimeException($p);
            }
            if ($pw !== (string)input('password2')) {
                throw new RuntimeException('The new passwords do not match.');
            }
            if (password_verify($pw, $u['password_hash'])) {
                throw new RuntimeException('Choose a password different from the current one.');
            }
            DB::update('users', array('password_hash' => Auth::hash($pw), 'must_change_password' => 0, 'password_changed_at' => now(), 'updated_at' => now()), 'id = ?', array($u['id']));
            session_regenerate_id(true);
            Audit::log('user.password', 'user', $u['id'], 'Changed password');
            flash('success', 'Password changed.');
            $tab = 'password';
        } elseif ($action === 'mfa_start') {
            $_SESSION['mfa_enroll'] = Totp::newSecret();
            $tab = 'mfa';
        } elseif ($action === 'mfa_confirm') {
            $secret = isset($_SESSION['mfa_enroll']) ? $_SESSION['mfa_enroll'] : null;
            if (!$secret || !Totp::verify($secret, (string)input('code'))) {
                throw new RuntimeException('That code did not match. Check the time on your phone and try the current code.');
            }
            DB::update('users', array('mfa_secret' => Crypto::encrypt($secret), 'mfa_enabled' => 1, 'updated_at' => now()), 'id = ?', array($u['id']));
            unset($_SESSION['mfa_enroll']);
            Audit::log('user.mfa_on', 'user', $u['id'], 'Turned on two-step sign-in');
            flash('success', 'Two-step sign-in is on.');
            $tab = 'mfa';
        } elseif ($action === 'mfa_off') {
            $secret = $u['mfa_secret'] ? Crypto::decrypt($u['mfa_secret']) : null;
            if (!password_verify((string)input('current'), $u['password_hash']) || !$secret || !Totp::verify($secret, (string)input('code'))) {
                throw new RuntimeException('Enter your password and a current code to turn two-step sign-in off.');
            }
            if (sys_config('enforce_mfa_privileged', '0') === '1' && Auth::isPrivileged($u)) {
                throw new RuntimeException('Your role requires two-step sign-in, so it cannot be turned off.');
            }
            DB::update('users', array('mfa_secret' => null, 'mfa_enabled' => 0, 'updated_at' => now()), 'id = ?', array($u['id']));
            Audit::log('user.mfa_off', 'user', $u['id'], 'Turned off two-step sign-in');
            flash('success', 'Two-step sign-in is off.');
            $tab = 'mfa';
        }
        redirect(url('settings', array('tab' => $tab)));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        $map = array('profile' => 'profile', 'password' => 'password', 'mfa_start' => 'mfa', 'mfa_confirm' => 'mfa', 'mfa_off' => 'mfa');
        redirect(url('settings', array('tab' => isset($map[$action]) ? $map[$action] : 'profile')));
    }
}
$u = Auth::user();
View::header('Account and security');
View::pageBar('Account and security', e($u['email']));
$tabs = array('profile' => 'Profile', 'password' => 'Password', 'mfa' => 'Two-step sign-in', 'sessions' => 'Recent sign-ins');
?>
<div class="cols cols-side">
  <div class="sheet"><div class="sheet-body help-list" style="padding:8px">
    <?php foreach ($tabs as $k => $l) { ?><a class="<?= $tab === $k ? 'is-current' : '' ?>" href="<?= e(url('settings', array('tab' => $k))) ?>"><?= e($l) ?></a><?php } ?>
  </div></div>
  <div>
  <?php if ($tab === 'profile') { ?>
    <div class="sheet"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="sheet-head"><h2>Profile</h2></div>
      <div class="sheet-body"><div class="form-grid">
        <div class="field"><label for="full_name">Name</label><input id="full_name" name="full_name" type="text" value="<?= e($u['full_name']) ?>" required></div>
        <div class="field"><label>Email</label><input type="text" value="<?= e($u['email']) ?>" readonly><span class="hint">Ask an administrator to change your sign-in email.</span></div>
        <div class="field"><label>Role</label><input type="text" value="<?= e(Ctx::roleName()) ?>" readonly></div>
        <div class="field"><label>Last sign-in</label><input type="text" value="<?= e(fmt_dt($u['last_login_at'])) ?>" readonly></div>
      </div></div>
      <div class="sheet-foot"><button class="btn btn-primary">Save profile</button></div>
    </form></div>
  <?php } elseif ($tab === 'password') { ?>
    <div class="sheet"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="sheet-head"><div><h2>Change password</h2><p>Last changed <?= e(fmt_dt($u['password_changed_at']) ?: 'never') ?></p></div></div>
      <div class="sheet-body">
        <?php if ((int)$u['must_change_password'] === 1) { ?><div class="callout warn" style="margin-bottom:14px">You are using a temporary password. Choose your own to continue.</div><?php } ?>
        <div class="form-grid">
          <div class="field"><label for="current">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
          <div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" required><span class="hint">At least 10 characters with letters and numbers</span></div>
          <div class="field"><label for="password2">Repeat new password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
        </div>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary">Change password</button></div>
    </form></div>
  <?php } elseif ($tab === 'mfa') { ?>
    <div class="sheet">
      <div class="sheet-head"><div><h2>Two-step sign-in</h2><p>A code from an authenticator app is asked after your password.</p></div><?= (int)$u['mfa_enabled'] ? View::status('active') : '<span class="st st-wait">Off</span>' ?></div>
      <div class="sheet-body">
        <?php if ((int)$u['mfa_enabled'] === 1) { ?>
          <p>Two-step sign-in is on for this account.</p>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mfa_off">
            <div class="field"><label for="cur2">Password</label><input id="cur2" name="current" type="password" required></div>
            <div class="field"><label for="code2">Current code</label><input id="code2" name="code" inputmode="numeric" maxlength="7" required></div>
            <button class="btn btn-danger" data-confirm="Turn off two-step sign-in?">Turn off</button>
          </form>
        <?php } elseif (!empty($_SESSION['mfa_enroll'])) { $secret = $_SESSION['mfa_enroll']; $uri = Totp::uri($secret, $u['email']); ?>
          <ol class="prose">
            <li>Open Google Authenticator, Microsoft Authenticator or a similar app and add an account.</li>
            <li>Scan the code, or type this key: <code id="mfa-key"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></li>
            <li>Enter the 6-digit code the app shows.</li>
          </ol>
          <div id="qr" style="background:#fff;padding:10px;display:inline-block;border-radius:8px;margin:6px 0 14px" data-uri="<?= e($uri) ?>"></div>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mfa_confirm">
            <div class="field"><label for="code">Code from the app</label><input id="code" name="code" inputmode="numeric" maxlength="7" required autofocus></div>
            <button class="btn btn-primary">Confirm and turn on</button>
          </form>
          <script src="<?= e(asset('js/vendor/qrcode.js')) ?>"></script>
          <script>(function(){var el=document.getElementById('qr');if(!el||typeof qrcode!=='function'){return;}var q=qrcode(0,'M');q.addData(el.getAttribute('data-uri'));q.make();el.innerHTML=q.createSvgTag(4,0);})();</script>
        <?php } else { ?>
          <p>Use two-step sign-in for every account that can approve files, manage members or change reference data.</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mfa_start"><button class="btn btn-primary">Set up two-step sign-in</button></form>
        <?php } ?>
      </div>
    </div>
  <?php } else {
      $rows = DB::all('SELECT * FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 20', array($u['email'])); ?>
    <div class="sheet"><div class="sheet-head"><h2>Recent sign-ins</h2></div>
      <div class="table-wrap"><table class="table table-stack"><thead><tr><th>When</th><th>IP address</th><th>Result</th></tr></thead><tbody>
      <?php foreach ($rows as $r) { ?><tr><td data-label="When"><?= e(fmt_dt($r['created_at'])) ?></td><td data-label="IP"><?= e($r['ip']) ?></td><td data-label="Result"><?= $r['success'] ? View::status('pass') : View::status('fail') ?></td></tr><?php } ?>
      </tbody></table></div></div>
  <?php } ?>
  </div>
</div>
<?php View::footer();
