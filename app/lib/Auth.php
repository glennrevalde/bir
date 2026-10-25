<?php
/**
 * Authentication: passwords, rate limiting, sessions, verification and reset tokens, MFA.
 */
class Auth
{
    private static $user = false;

    const MAX_EMAIL_FAILS = 5;
    const MAX_IP_FAILS = 25;
    const WINDOW_MIN = 15;

    public static function user()
    {
        if (self::$user === false) {
            self::$user = null;
            if (!empty($_SESSION['uid'])) {
                $u = DB::row('SELECT * FROM users WHERE id = ? AND status = ?', array($_SESSION['uid'], 'active'));
                self::$user = $u ?: null;
                if (!$u) {
                    self::logout();
                }
            }
        }
        return self::$user;
    }

    public static function id()
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check()
    {
        return self::user() !== null;
    }

    public static function setUserForCli($user)
    {
        self::$user = $user;
    }

    public static function passwordProblem($pw)
    {
        if (strlen($pw) < 10) {
            return 'Use at least 10 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Use letters and numbers.';
        }
        if (strlen($pw) > 200) {
            return 'Use at most 200 characters.';
        }
        return null;
    }

    public static function hash($pw)
    {
        return password_hash($pw, PASSWORD_DEFAULT);
    }

    public static function isThrottled($email)
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        $byEmail = (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= ?', array(strtolower($email), $since));
        $byIp = (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', array(client_ip(), $since));
        return $byEmail >= self::MAX_EMAIL_FAILS || $byIp >= self::MAX_IP_FAILS;
    }

    private static function recordAttempt($email, $ok)
    {
        DB::insert('login_attempts', array('email' => strtolower($email), 'ip' => client_ip(), 'success' => $ok ? 1 : 0, 'created_at' => now()));
    }

    /**
     * Returns array('status' => 'ok'|'mfa'|'error'|'unverified', 'message' => ...)
     */
    public static function attempt($email, $password)
    {
        $email = strtolower(trim($email));
        if (self::isThrottled($email)) {
            return array('status' => 'error', 'message' => 'Too many failed sign-in attempts. Wait ' . self::WINDOW_MIN . ' minutes and try again.');
        }
        $u = DB::row('SELECT * FROM users WHERE email = ?', array($email));
        if (!$u || !password_verify($password, $u['password_hash'])) {
            self::recordAttempt($email, false);
            if ($u) {
                DB::q('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', array($u['id']));
                Audit::log('auth.failed', 'user', $u['id'], 'Failed sign-in for ' . $email, null, null, null, null, $u['id']);
            }
            return array('status' => 'error', 'message' => 'The email or password is incorrect.');
        }
        if ($u['status'] !== 'active') {
            return array('status' => 'error', 'message' => 'This account is disabled. Contact your administrator.');
        }
        if (!$u['email_verified_at']) {
            return array('status' => 'unverified', 'message' => 'Verify your email address before signing in.', 'user_id' => $u['id']);
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', array('password_hash' => self::hash($password)), 'id = ?', array($u['id']));
        }
        self::recordAttempt($email, true);
        if ((int)$u['mfa_enabled'] === 1) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending_uid'] = (int)$u['id'];
            $_SESSION['mfa_pending_at'] = time();
            return array('status' => 'mfa');
        }
        self::completeLogin($u);
        return array('status' => 'ok');
    }

    public static function completeLogin($u)
    {
        session_regenerate_id(true);
        unset($_SESSION['mfa_pending_uid'], $_SESSION['mfa_pending_at']);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['last_activity'] = time();
        DB::update('users', array('last_login_at' => now(), 'failed_logins' => 0), 'id = ?', array($u['id']));
        self::$user = false;
        Audit::log('auth.login', 'user', $u['id'], 'Signed in', null, null, null, null, $u['id']);
    }

    public static function verifyMfa($code)
    {
        $uid = isset($_SESSION['mfa_pending_uid']) ? (int)$_SESSION['mfa_pending_uid'] : 0;
        if (!$uid || time() - (int)$_SESSION['mfa_pending_at'] > 600) {
            unset($_SESSION['mfa_pending_uid']);
            return array('ok' => false, 'message' => 'The sign-in step expired. Sign in again.');
        }
        $u = DB::row('SELECT * FROM users WHERE id = ?', array($uid));
        $email = $u ? $u['email'] : 'unknown';
        if (self::isThrottled($email)) {
            return array('ok' => false, 'message' => 'Too many attempts. Wait ' . self::WINDOW_MIN . ' minutes.');
        }
        $secret = $u && $u['mfa_secret'] ? Crypto::decrypt($u['mfa_secret']) : null;
        if (!$secret || !Totp::verify($secret, $code)) {
            self::recordAttempt($email, false);
            return array('ok' => false, 'message' => 'That code is not valid. Enter the current 6-digit code from your authenticator app.');
        }
        self::completeLogin($u);
        return array('ok' => true);
    }

    public static function logout()
    {
        if (!empty($_SESSION['uid'])) {
            Audit::log('auth.logout', 'user', $_SESSION['uid'], 'Signed out', null, null, null, null, $_SESSION['uid']);
        }
        $_SESSION = array();
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        self::$user = null;
    }

    /** Idle timeout check. Returns false when the session expired. */
    public static function touch()
    {
        $idle = max(5, (int)sys_config('session_idle_minutes', 30)) * 60;
        if (!empty($_SESSION['uid'])) {
            if (!empty($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $idle) {
                self::logout();
                return false;
            }
            $_SESSION['last_activity'] = time();
        }
        return true;
    }

    // ------------------------------------------------------------ tokens
    public static function issueToken($userId, $purpose, $ttlMinutes)
    {
        $plain = random_token(24);
        DB::q('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', array(now(), $userId, $purpose));
        DB::insert('user_tokens', array('user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $plain),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlMinutes * 60), 'created_at' => now()));
        return $plain;
    }

    public static function consumeToken($plain, $purpose)
    {
        if (!is_string($plain) || !preg_match('/^[a-f0-9]{48}$/', $plain)) {
            return null;
        }
        $t = DB::row('SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            array(hash('sha256', $plain), $purpose, now()));
        if (!$t) {
            return null;
        }
        DB::update('user_tokens', array('used_at' => now()), 'id = ?', array($t['id']));
        return (int)$t['user_id'];
    }

    public static function sendVerification($userId)
    {
        $u = DB::row('SELECT * FROM users WHERE id = ?', array($userId));
        if (!$u) {
            return;
        }
        $tok = self::issueToken($userId, 'verify', 60 * 48);
        $link = absolute_url('verify', array('token' => $tok));
        Mailer::send($u['email'], 'Verify your email for ' . config('app_name'),
            "Hello " . $u['full_name'] . ",\n\nConfirm your email address to activate your account:\n" . $link . "\n\nThis link expires in 48 hours.\nIf you did not create this account, ignore this message.");
    }

    public static function sendReset($email)
    {
        $u = DB::row('SELECT * FROM users WHERE email = ? AND status = ?', array(strtolower(trim($email)), 'active'));
        if (!$u) {
            return;
        }
        $tok = self::issueToken($u['id'], 'reset', 60);
        $link = absolute_url('reset', array('token' => $tok));
        Mailer::send($u['email'], 'Reset your password for ' . config('app_name'),
            "Hello " . $u['full_name'] . ",\n\nUse this link to choose a new password:\n" . $link . "\n\nThe link expires in 60 minutes. If you did not ask for this, ignore this message.");
        Audit::log('auth.reset_requested', 'user', $u['id'], 'Password reset requested', null, null, null, null, $u['id']);
    }

    public static function isPrivileged($user = null)
    {
        $user = $user ?: self::user();
        if (!$user) {
            return false;
        }
        if ((int)$user['is_superadmin'] === 1) {
            return true;
        }
        return (int)DB::val('SELECT COUNT(*) FROM memberships m JOIN roles r ON r.code = m.role_code WHERE m.user_id = ? AND m.status = ? AND r.is_privileged = 1',
                array($user['id'], 'active')) > 0;
    }
}

function absolute_url($page, $params = array())
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || config('secure_cookies');
    $cfg = config('base_url', '');
    if ($cfg !== '' && preg_match('#^https?://#', $cfg)) {
        return rtrim($cfg, '/') . '/index.php?' . http_build_query(array_merge(array('p' => $page), $params));
    }
    return ($https ? 'https://' : 'http://') . $host . url($page, $params);
}
