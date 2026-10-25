<?php
/**
 * Tenancy and permission context: organization, membership, client and branch scope.
 * Every data query in the app filters by Ctx::orgId() and Ctx::clientId().
 */
class Ctx
{
    private static $org = false;
    private static $membership = false;
    private static $perms = null;
    private static $clients = null;
    private static $client = false;

    public static function reset()
    {
        self::$org = false;
        self::$membership = false;
        self::$perms = null;
        self::$clients = null;
        self::$client = false;
    }

    public static function memberships()
    {
        $uid = Auth::id();
        if (!$uid) {
            return array();
        }
        return DB::all('SELECT m.*, o.name AS org_name, o.org_type, r.name AS role_name FROM memberships m
            JOIN organizations o ON o.id = m.organization_id JOIN roles r ON r.code = m.role_code
            WHERE m.user_id = ? AND m.status = ? AND o.status = ? ORDER BY o.name', array($uid, 'active', 'active'));
    }

    public static function org()
    {
        if (self::$org === false) {
            self::$org = null;
            $u = Auth::user();
            if (!$u) {
                return null;
            }
            $orgId = isset($_SESSION['org_id']) ? (int)$_SESSION['org_id'] : 0;
            if ($orgId) {
                if ((int)$u['is_superadmin'] === 1) {
                    self::$org = DB::row('SELECT * FROM organizations WHERE id = ?', array($orgId));
                } else {
                    self::$org = DB::row('SELECT o.* FROM organizations o JOIN memberships m ON m.organization_id = o.id
                        WHERE o.id = ? AND m.user_id = ? AND m.status = ? AND o.status = ?', array($orgId, $u['id'], 'active', 'active'));
                }
            }
            if (!self::$org) {
                $ms = self::memberships();
                if ($ms) {
                    self::$org = DB::row('SELECT * FROM organizations WHERE id = ?', array($ms[0]['organization_id']));
                    $_SESSION['org_id'] = (int)$ms[0]['organization_id'];
                }
            }
        }
        return self::$org;
    }

    public static function orgId()
    {
        $o = self::org();
        return $o ? (int)$o['id'] : 0;
    }

    public static function switchOrg($orgId)
    {
        $_SESSION['org_id'] = (int)$orgId;
        unset($_SESSION['client_id']);
        self::reset();
        return self::orgId() === (int)$orgId;
    }

    public static function membership()
    {
        if (self::$membership === false) {
            self::$membership = null;
            if (self::orgId() && Auth::id()) {
                self::$membership = DB::row('SELECT m.*, r.name AS role_name, r.is_privileged FROM memberships m JOIN roles r ON r.code = m.role_code
                    WHERE m.user_id = ? AND m.organization_id = ? AND m.status = ?', array(Auth::id(), self::orgId(), 'active'));
            }
        }
        return self::$membership;
    }

    public static function isSuper()
    {
        $u = Auth::user();
        return $u && (int)$u['is_superadmin'] === 1;
    }

    public static function roleCode()
    {
        $m = self::membership();
        if ($m) {
            return $m['role_code'];
        }
        return self::isSuper() ? 'superadmin' : null;
    }

    public static function roleName()
    {
        $m = self::membership();
        if ($m) {
            return $m['role_name'];
        }
        return self::isSuper() ? 'Platform superadmin' : 'No role';
    }

    public static function perms()
    {
        if (self::$perms === null) {
            self::$perms = array();
            if (self::isSuper()) {
                foreach (DB::col('SELECT code FROM permissions') as $p) {
                    self::$perms[$p] = true;
                }
                return self::$perms;
            }
            $m = self::membership();
            if (!$m) {
                return self::$perms;
            }
            if ($m['role_code'] === 'support') {
                $grant = DB::val('SELECT id FROM support_grants WHERE user_id = ? AND organization_id = ? AND revoked_at IS NULL AND expires_at > ?',
                    array(Auth::id(), self::orgId(), now()));
                if (!$grant) {
                    return self::$perms;
                }
            }
            foreach (DB::col('SELECT permission_code FROM role_permissions WHERE role_code = ?', array($m['role_code'])) as $p) {
                self::$perms[$p] = true;
            }
        }
        return self::$perms;
    }

    public static function can($perm)
    {
        $p = self::perms();
        return isset($p[$perm]);
    }

    public static function requirePerm($perm)
    {
        if (!self::can($perm)) {
            deny('Your role does not allow this action (' . $perm . ').');
        }
    }

    /** Clients the current member may access. */
    public static function clients()
    {
        if (self::$clients === null) {
            self::$clients = array();
            $orgId = self::orgId();
            if (!$orgId) {
                return self::$clients;
            }
            $m = self::membership();
            if (self::isSuper() || ($m && (int)$m['all_clients'] === 1)) {
                self::$clients = DB::all('SELECT * FROM client_entities WHERE organization_id = ? AND deleted_at IS NULL ORDER BY COALESCE(registered_name, last_name)', array($orgId));
            } elseif ($m) {
                self::$clients = DB::all('SELECT DISTINCT c.* FROM client_entities c JOIN membership_scopes s ON s.client_id = c.id
                    WHERE s.membership_id = ? AND c.organization_id = ? AND c.deleted_at IS NULL ORDER BY COALESCE(c.registered_name, c.last_name)', array($m['id'], $orgId));
            }
        }
        return self::$clients;
    }

    public static function clientAllowed($clientId)
    {
        foreach (self::clients() as $c) {
            if ((int)$c['id'] === (int)$clientId) {
                return $c;
            }
        }
        return null;
    }

    /** Refuse changes to an archived client. */
    public static function requireActive()
    {
        $c = self::client();
        if ($c && $c['status'] === 'archived') {
            throw new RuntimeException(self::clientName($c) . ' is archived. Reactivate the client to make changes.');
        }
    }

    public static function client()
    {
        if (self::$client === false) {
            self::$client = null;
            $id = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : 0;
            if ($id) {
                self::$client = self::clientAllowed($id);
            }
            if (!self::$client) {
                $list = self::clients();
                if (count($list) >= 1) {
                    self::$client = $list[0];
                    $_SESSION['client_id'] = (int)$list[0]['id'];
                }
            }
        }
        return self::$client;
    }

    public static function clientId()
    {
        $c = self::client();
        return $c ? (int)$c['id'] : 0;
    }

    public static function switchClient($id)
    {
        if (!self::clientAllowed($id)) {
            return false;
        }
        $_SESSION['client_id'] = (int)$id;
        self::$client = false;
        return true;
    }

    public static function requireClient()
    {
        if (!self::client()) {
            flash('info', 'Add or select a client first.');
            redirect(url('clients'));
        }
        return self::client();
    }

    /** Branch IDs this member may touch for the current client, or null for all. */
    public static function branchScope()
    {
        if (self::isSuper()) {
            return null;
        }
        $m = self::membership();
        if (!$m || (int)$m['all_clients'] === 1) {
            return null;
        }
        $rows = DB::all('SELECT branch_id FROM membership_scopes WHERE membership_id = ? AND client_id = ?', array($m['id'], self::clientId()));
        $ids = array();
        foreach ($rows as $r) {
            if ($r['branch_id'] === null) {
                return null;
            }
            $ids[] = (int)$r['branch_id'];
        }
        return $ids;
    }

    public static function branches($clientId = null)
    {
        $clientId = $clientId ?: self::clientId();
        $rows = DB::all('SELECT * FROM branches WHERE client_id = ? AND organization_id = ? ORDER BY branch_code', array($clientId, self::orgId()));
        $scope = self::branchScope();
        if ($scope === null) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($b) use ($scope) {
            return in_array((int)$b['id'], $scope, true);
        }));
    }

    public static function branchAllowed($branchId)
    {
        foreach (self::branches() as $b) {
            if ((int)$b['id'] === (int)$branchId) {
                return $b;
            }
        }
        return null;
    }

    public static function headOffice($clientId = null)
    {
        $clientId = $clientId ?: self::clientId();
        $b = DB::row('SELECT * FROM branches WHERE client_id = ? AND is_head_office = 1 ORDER BY id LIMIT 1', array($clientId));
        return $b ?: DB::row('SELECT * FROM branches WHERE client_id = ? ORDER BY branch_code LIMIT 1', array($clientId));
    }

    public static function clientName($c)
    {
        if (!$c) {
            return '';
        }
        if ($c['taxpayer_type'] === 'individual' || !$c['registered_name']) {
            return trim($c['last_name'] . ', ' . $c['first_name'] . ' ' . $c['middle_name'], ', ');
        }
        return $c['registered_name'];
    }
}

function deny($message = 'You do not have access to this page.')
{
    if (is_ajax()) {
        json_out(array('ok' => false, 'error' => $message), 403);
    }
    http_response_code(403);
    View::render('errors/denied', array('message' => $message), 'Access denied');
    exit;
}

function party_name($row)
{
    if (!$row) {
        return '';
    }
    if (!empty($row['registered_name'])) {
        return $row['registered_name'];
    }
    $n = trim((isset($row['last_name']) ? $row['last_name'] : '') . ', ' . (isset($row['first_name']) ? $row['first_name'] : '') . ' ' . (isset($row['middle_name']) ? $row['middle_name'] : ''));
    return trim($n, ', ');
}
