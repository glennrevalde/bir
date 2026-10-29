<?php
Ctx::requirePerm('users.manage');
$orgId = Ctx::orgId();
$roles = array();
foreach (DB::all('SELECT * FROM roles ORDER BY rank_order') as $r) {
    $roles[$r['code']] = $r['name'];
}
$myRank = Ctx::isSuper() ? 0 : (int)DB::val('SELECT rank_order FROM roles WHERE code = ?', array(Ctx::roleCode()));
$assignable = array();
foreach (DB::all('SELECT * FROM roles WHERE rank_order >= ? ORDER BY rank_order', array($myRank)) as $r) {
    $assignable[$r['code']] = $r['name'];
}
$clients = DB::all('SELECT id, tin, registered_name, last_name, first_name, middle_name, taxpayer_type FROM client_entities WHERE organization_id = ? AND deleted_at IS NULL ORDER BY registered_name, last_name', array($orgId));

/**
 * Whether the signed-in person may change another member's sign-in details (email, temporary password).
 * Returns the reason they may not, or null. The platform administrator may change anyone's.
 */
$accountGuard = function ($m) use ($orgId, $assignable) {
    if ((int)$m['user_id'] === Auth::id()) {
        return 'Change your own sign-in details under Account and security.';
    }
    if (Ctx::isSuper()) {
        return null;
    }
    if ((int)$m['is_superadmin'] === 1 || !isset($assignable[$m['role_code']])) {
        return 'You cannot change the sign-in details of a member with a higher role.';
    }
    if (DB::val('SELECT id FROM memberships WHERE user_id = ? AND organization_id <> ? AND status = ? LIMIT 1', array($m['user_id'], $orgId, 'active'))) {
        return 'This person also belongs to another organization, so only the platform administrator can change their sign-in details.';
    }
    return null;
};

if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'invite') {
            $email = strtolower(trim((string)input('email')));
            $name = trim((string)input('full_name'));
            $role = (string)input('role_code');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                throw new RuntimeException('Enter a name and a valid email.');
            }
            if (!isset($assignable[$role])) {
                throw new RuntimeException('You cannot assign that role.');
            }
            $all = input('scope') === 'selected' ? 0 : 1;
            $sel = array_map('intval', (array)input('clients', array()));
            if (!$all && !$sel) {
                throw new RuntimeException('Choose at least one client for a limited member.');
            }
            $tempPw = (string)input('temp_password');
            if ($tempPw !== '' && ($p = Auth::passwordProblem($tempPw))) {
                throw new RuntimeException('Temporary password: ' . $p);
            }
            $isNew = DB::tx(function () use ($email, $name, $role, $all, $sel, $orgId, $tempPw) {
                $u = DB::row('SELECT * FROM users WHERE email = ?', array($email));
                $newUser = false;
                if (!$u) {
                    // With a temporary password the account works at once; the person sets their own password at first sign-in.
                    $uid = DB::insert('users', array('email' => $email, 'password_hash' => Auth::hash($tempPw !== '' ? $tempPw : random_token(24)), 'full_name' => $name, 'status' => 'active',
                        'must_change_password' => $tempPw !== '' ? 1 : 0, 'email_verified_at' => $tempPw !== '' ? now() : null, 'password_changed_at' => $tempPw !== '' ? now() : null,
                        'created_at' => now(), 'updated_at' => now()));
                    $newUser = true;
                } else {
                    $uid = (int)$u['id'];
                    if (DB::val('SELECT id FROM memberships WHERE user_id = ? AND organization_id = ?', array($uid, $orgId))) {
                        throw new RuntimeException('That person is already a member.');
                    }
                }
                $mid = DB::insert('memberships', array('user_id' => $uid, 'organization_id' => $orgId, 'role_code' => $role, 'all_clients' => $all, 'status' => 'active',
                    'invited_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()));
                foreach ($sel as $cid) {
                    if (DB::val('SELECT id FROM client_entities WHERE id = ? AND organization_id = ?', array($cid, $orgId))) {
                        DB::insert('membership_scopes', array('membership_id' => $mid, 'client_id' => $cid, 'branch_id' => null));
                    }
                }
                if ($newUser && $tempPw !== '') {
                    Mailer::send($email, 'You were added to ' . Ctx::org()['name'] . ' on G-SMaRT BIR',
                        "Hello $name,\n\n" . Auth::user()['full_name'] . " added you to " . Ctx::org()['name'] . " in G-SMaRT BIR.\nThey will give you a temporary password. Sign in at "
                        . absolute_url('login') . " with " . $email . " and that password, then choose your own password.\n");
                } elseif ($newUser) {
                    $tok = Auth::issueToken($uid, 'reset', 60 * 48);
                    Mailer::send($email, 'You were invited to ' . Ctx::org()['name'] . ' on G-SMaRT BIR',
                        "Hello $name,\n\n" . Auth::user()['full_name'] . " invited you to " . Ctx::org()['name'] . ".\nSet your password here (valid for 48 hours):\n" . absolute_url('reset', array('token' => $tok)) . "\n");
                } else {
                    Mailer::send($email, 'You were added to ' . Ctx::org()['name'], "Hello $name,\n\nYou now have access to " . Ctx::org()['name'] . " in G-SMaRT BIR. Sign in with your existing account.\n");
                }
                Audit::log('member.invite', 'memberships', $mid, 'Invited ' . $email . ' as ' . $role . ($newUser && $tempPw !== '' ? ' with a temporary password' : ''));
                return $newUser;
            });
            if ($tempPw !== '' && $isNew) {
                flash('success', $name . ' was added. Give them these details: email ' . $email . ', password ' . $tempPw . '. They must choose their own password when they first sign in.');
            } elseif ($tempPw !== '') {
                flash('success', $name . ' already has an account, so it was added to this organization and the temporary password was not used. They sign in with their existing password.');
            } else {
                flash('success', 'Invitation sent to ' . $email . '.');
            }
        } elseif ($action === 'update') {
            $m = DB::row('SELECT * FROM memberships WHERE id = ? AND organization_id = ?', array(input_int('membership_id'), $orgId));
            if (!$m) {
                throw new RuntimeException('Member not found.');
            }
            if ((int)$m['user_id'] === Auth::id()) {
                throw new RuntimeException('You cannot change your own role, scope or status.');
            }
            $role = (string)input('role_code');
            if (!isset($assignable[$role]) || !isset($assignable[$m['role_code']])) {
                throw new RuntimeException('You cannot manage members with a higher role.');
            }
            $all = input('scope') === 'selected' ? 0 : 1;
            $sel = array_map('intval', (array)input('clients', array()));
            DB::tx(function () use ($m, $role, $all, $sel, $orgId) {
                DB::update('memberships', array('role_code' => $role, 'all_clients' => $all, 'status' => input('status') === 'disabled' ? 'disabled' : 'active', 'updated_at' => now()), 'id = ?', array($m['id']));
                DB::q('DELETE FROM membership_scopes WHERE membership_id = ?', array($m['id']));
                if (!$all) {
                    foreach ($sel as $cid) {
                        if (DB::val('SELECT id FROM client_entities WHERE id = ? AND organization_id = ?', array($cid, $orgId))) {
                            DB::insert('membership_scopes', array('membership_id' => $m['id'], 'client_id' => $cid, 'branch_id' => null));
                        }
                    }
                }
                Audit::log('member.update', 'memberships', $m['id'], 'Changed member to ' . $role . ($all ? ', all clients' : ', ' . count($sel) . ' clients'), array('role' => $m['role_code'], 'all' => $m['all_clients']), array('role' => $role, 'all' => $all));
            });
            flash('success', 'Member updated.');
        } elseif ($action === 'email' || $action === 'temp_password') {
            $m = DB::row('SELECT m.*, u.is_superadmin, u.email, u.full_name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND m.organization_id = ?', array(input_int('membership_id'), $orgId));
            if (!$m) {
                throw new RuntimeException('Member not found.');
            }
            if ($why = $accountGuard($m)) {
                throw new RuntimeException($why);
            }
            if ($action === 'email') {
                $new = Auth::changeEmail($m['user_id'], input('email'), trim((string)input('reason')));
                flash('success', 'Sign-in email changed to ' . $new . '. Both addresses were told.');
            } else {
                $pw = (string)input('temp_password');
                Auth::setTemporaryPassword($m['user_id'], $pw, (bool)input('mfa_off'), trim((string)input('reason')));
                if ($m['status'] !== 'active') {
                    flash('warning', 'This membership is disabled. Set its status to Active so ' . $m['full_name'] . ' can sign in.');
                }
                flash('success', 'Temporary password set. Give ' . $m['full_name'] . ' these details: email ' . $m['email'] . ', password ' . $pw . '. They must choose their own password when they first sign in.');
            }
        } elseif ($action === 'support') {
            $m = DB::row('SELECT * FROM memberships WHERE id = ? AND organization_id = ? AND role_code = ?', array(input_int('membership_id'), $orgId, 'support'));
            if (!$m) {
                throw new RuntimeException('Support grants apply to members with the support role.');
            }
            $hours = max(1, min(72, input_int('hours', 4)));
            DB::insert('support_grants', array('organization_id' => $orgId, 'user_id' => $m['user_id'], 'granted_by' => Auth::id(), 'reason' => mb_substr(trim((string)input('reason')) ?: 'Support session', 0, 255),
                'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600), 'created_at' => now()));
            Audit::log('support.grant', 'memberships', $m['id'], 'Granted support access for ' . $hours . ' hours');
            flash('success', 'Support access granted for ' . $hours . ' hours.');
        } elseif ($action === 'revoke_support') {
            DB::q('UPDATE support_grants SET revoked_at = ? WHERE organization_id = ? AND user_id = ? AND revoked_at IS NULL', array(now(), $orgId, input_int('user_id')));
            Audit::log('support.revoke', 'users', input_int('user_id'), 'Revoked support access');
            flash('success', 'Support access revoked.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('users'));
}
$members = DB::all('SELECT m.*, u.email, u.full_name, u.last_login_at, u.mfa_enabled, u.email_verified_at, u.must_change_password, r.name AS role_name,
    (SELECT MAX(expires_at) FROM support_grants g WHERE g.user_id = u.id AND g.organization_id = m.organization_id AND g.revoked_at IS NULL) AS support_until
    FROM memberships m JOIN users u ON u.id = m.user_id JOIN roles r ON r.code = m.role_code WHERE m.organization_id = ? ORDER BY r.rank_order, u.full_name', array($orgId));
$edit = input_int('edit');
View::header('Members and roles');
View::pageBar('Members and roles', e(Ctx::org()['name']));
$clientChecks = function ($selected) use ($clients) {
    $h = '<div class="stack" style="max-height:180px;overflow:auto">';
    foreach ($clients as $c) {
        $h .= '<label class="check"><input type="checkbox" name="clients[]" value="' . (int)$c['id'] . '"' . (in_array((int)$c['id'], $selected, true) ? ' checked' : '') . '> ' . e(Ctx::clientName($c)) . '</label>';
    }
    return $h . '</div>';
};
?>
<div class="cols cols-main">
  <div class="sheet"><div class="table-wrap"><table class="table table-stack"><thead><tr><th>Member</th><th>Role</th><th>Clients</th><th>Security</th><th>Last sign-in</th><th></th></tr></thead><tbody>
  <?php foreach ($members as $m) { $scope = $m['all_clients'] ? 'All clients' : plural((int)DB::val('SELECT COUNT(*) FROM membership_scopes WHERE membership_id = ?', array($m['id'])), 'client'); ?>
    <tr class="<?= $m['status'] !== 'active' ? 'void' : '' ?>"><td data-label="Member"><strong><?= e($m['full_name']) ?></strong><span class="sub"><?= e($m['email']) ?></span></td>
      <td data-label="Role"><?= e($m['role_name']) ?><?= $m['role_code'] === 'support' ? '<span class="sub">' . ($m['support_until'] && $m['support_until'] > now() ? 'Access until ' . e(fmt_dt($m['support_until'])) : 'No active access') . '</span>' : '' ?></td>
      <td data-label="Clients"><?= e($scope) ?></td>
      <td data-label="Security"><?= $m['mfa_enabled'] ? '<span class="st st-ok">Two-step on</span>' : '<span class="st st-wait">Password only</span>' ?><?= $m['email_verified_at'] ? ((int)$m['must_change_password'] === 1 ? '<span class="sub">Temporary password, not yet changed</span>' : '') : '<span class="sub">Invitation not accepted</span>' ?></td>
      <td data-label="Last sign-in"><?= e(fmt_dt($m['last_login_at']) ?: 'Never') ?></td>
      <td class="actions"><?php if ((int)$m['user_id'] !== Auth::id()) { ?><a class="btn btn-sm btn-quiet" href="<?= e(url('users', array('edit' => $m['id']))) ?>">Edit</a><?php } else { ?><span class="small muted">You</span><?php } ?></td></tr>
  <?php } ?></tbody></table></div></div>
  <div>
  <?php if ($edit && ($em = DB::row('SELECT m.*, u.full_name, u.email, u.is_superadmin, u.mfa_enabled, u.must_change_password FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND m.organization_id = ?', array($edit, $orgId)))) {
      $sel = array_map('intval', DB::col('SELECT client_id FROM membership_scopes WHERE membership_id = ?', array($em['id']))); ?>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="membership_id" value="<?= (int)$em['id'] ?>">
      <div class="sheet-head"><h2>Edit <?= e($em['full_name']) ?></h2></div>
      <div class="sheet-body stack">
        <div class="field"><label for="erole">Role</label><?= View::select('role_code', $assignable, $em['role_code'], 'id="erole"') ?></div>
        <div class="field"><label for="escope">Clients</label><?= View::select('scope', array('all' => 'All clients', 'selected' => 'Only the selected clients'), $em['all_clients'] ? 'all' : 'selected', 'id="escope"') ?></div>
        <?= $clientChecks($sel) ?>
        <div class="field"><label for="estatus">Status</label><?= View::select('status', array('active' => 'Active', 'disabled' => 'Disabled'), $em['status'], 'id="estatus"') ?></div>
      </div>
      <div class="sheet-foot"><a class="btn btn-quiet" href="<?= e(url('users')) ?>">Cancel</a><button class="btn btn-primary">Save member</button></div>
    </form>
    <?php $guard = $accountGuard($em); ?>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="temp_password"><input type="hidden" name="membership_id" value="<?= (int)$em['id'] ?>">
      <div class="sheet-head"><div><h2>Temporary password</h2><p><?= $em['must_change_password'] ? 'A temporary password is waiting to be replaced' : 'For signing in without the email link' ?></p></div></div>
      <?php if (!$guard) { ?>
      <div class="sheet-body stack">
        <div class="field"><label for="temp_pw">Temporary password</label>
          <div class="input-btn"><input id="temp_pw" name="temp_password" type="text" minlength="10" maxlength="200" required autocomplete="off" spellcheck="false" class="mono" data-temp-pw>
            <button type="button" class="btn" data-gen-pw="#temp_pw">Generate</button></div>
          <span class="hint">At least 10 characters with letters and numbers. Give it to <?= e($em['full_name']) ?> yourself; it is not emailed.</span></div>
        <?php if ($em['mfa_enabled']) { ?><label class="check"><input type="checkbox" name="mfa_off" value="1"> Also turn off two-step sign-in (for a lost phone)</label><?php } ?>
        <div class="field"><label for="preason">Reason</label><input id="preason" name="reason" type="text" maxlength="200" placeholder="For example, forgot password"></div>
        <p class="small muted"><?= e($em['full_name']) ?> signs in with <?= e($em['email']) ?> and this password, then must choose their own password before doing anything else.</p>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary">Set temporary password</button></div>
      <?php } else { ?><div class="sheet-body small muted"><?= e($guard) ?></div><?php } ?>
    </form>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="membership_id" value="<?= (int)$em['id'] ?>">
      <div class="sheet-head"><div><h2>Sign-in email</h2><p>Now <?= e($em['email']) ?></p></div></div>
      <?php if (!$guard) { ?>
      <div class="sheet-body stack">
        <div class="field"><label for="new_email">New email</label><input id="new_email" name="email" type="email" maxlength="190" required autocomplete="off"></div>
        <div class="field"><label for="ereason">Reason</label><input id="ereason" name="reason" type="text" maxlength="200" placeholder="For example, moved to a company address"></div>
        <p class="small muted">The password and two-step sign-in stay the same. The old and the new address are both told about the change.</p>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary">Change email</button></div>
      <?php } else { ?><div class="sheet-body small muted"><?= e($guard) ?></div><?php } ?>
    </form>
    <?php if ($em['role_code'] === 'support') { ?>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="support"><input type="hidden" name="membership_id" value="<?= (int)$em['id'] ?>">
      <div class="sheet-head"><h2>Time-limited support access</h2></div>
      <div class="sheet-body stack"><div class="field"><label for="hours">Hours</label><input id="hours" name="hours" type="number" min="1" max="72" value="4"></div>
        <div class="field"><label for="sreason">Reason</label><input id="sreason" name="reason" type="text" maxlength="200"></div></div>
      <div class="sheet-foot"><button class="btn btn-danger" name="action" value="revoke_support" formnovalidate>Revoke now</button><input type="hidden" name="user_id" value="<?= (int)$em['user_id'] ?>"><button class="btn btn-primary">Grant access</button></div>
    </form>
    <?php } ?>
  <?php } else { ?>
    <form method="post" class="sheet"><?= csrf_field() ?><input type="hidden" name="action" value="invite">
      <div class="sheet-head"><h2>Add a member</h2></div>
      <div class="sheet-body stack">
        <div class="field"><label for="full_name">Name</label><input id="full_name" name="full_name" type="text" required></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
        <div class="field"><label for="role_code">Role</label><?= View::select('role_code', $assignable, 'preparer', 'id="role_code"') ?></div>
        <div class="field"><label for="scope">Clients</label><?= View::select('scope', array('all' => 'All clients', 'selected' => 'Only the selected clients'), 'all', 'id="scope"') ?></div>
        <?= $clientChecks(array()) ?>
        <div class="field"><label for="itemp_pw">Temporary password (optional)</label>
          <div class="input-btn"><input id="itemp_pw" name="temp_password" type="text" maxlength="200" autocomplete="off" spellcheck="false" class="mono">
            <button type="button" class="btn" data-gen-pw="#itemp_pw">Generate</button></div>
          <span class="hint">Leave blank to email a link for setting a password. With a temporary password the person can sign in at once with the email and password you give them, and chooses their own on first sign-in.</span></div>
      </div>
      <div class="sheet-foot"><button class="btn btn-primary">Add member</button></div>
    </form>
  <?php } ?>
    <div class="sheet"><div class="sheet-head"><h2>What each role can do</h2></div><div class="sheet-body small">
      <?php foreach (DB::all('SELECT * FROM roles ORDER BY rank_order') as $r) { ?><p><strong><?= e($r['name']) ?></strong>: <?= e($r['description']) ?><?= $r['is_privileged'] ? ' (privileged)' : '' ?></p><?php } ?>
    </div></div>
  </div>
</div>
<?php View::footer();
