<?php
/**
 * REST API (token-scoped). See docs/API.md.
 *   GET  api.php?r=files[&client_id=]          file status list
 *   GET  api.php?r=file&id=N[&client_id=]      download a DAT that has accepted validator evidence
 *   GET  api.php?r=directory[&client_id=]      customers, suppliers, payees
 *   POST api.php?r=withholding|sales|purchases JSON {"client_id":1,"rows":[...]}
 */
define('GSB_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function api_fail($code, $msg)
{
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $msg));
    exit;
}

$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
if (!$auth && function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    $auth = isset($h['Authorization']) ? $h['Authorization'] : '';
}
if (!preg_match('/^Bearer\s+(gsb_[a-f0-9]{48})$/', trim($auth), $m)) {
    api_fail(401, 'Send Authorization: Bearer <token>.');
}
$tok = DB::row('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)', array(hash('sha256', $m[1]), now()));
if (!$tok) {
    api_fail(401, 'The token is invalid, revoked or expired.');
}
$user = DB::row('SELECT * FROM users WHERE id = ? AND status = ?', array($tok['user_id'], 'active'));
if (!$user) {
    api_fail(401, 'The token owner is disabled.');
}
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['org_id'] = (int)$tok['organization_id'];
Auth::setUserForCli($user);
Ctx::reset();
if (!Ctx::membership() && !Ctx::isSuper()) {
    api_fail(403, 'The token owner is no longer a member of this organization.');
}
DB::update('api_tokens', array('last_used_at' => now()), 'id = ?', array($tok['id']));
$scopes = explode(',', $tok['scopes']);
$need = function ($s) use ($scopes) {
    if (!in_array($s, $scopes, true)) {
        api_fail(403, 'This token lacks the ' . $s . ' scope.');
    }
};
$perm = function ($p) {
    if (!Ctx::can($p)) {
        api_fail(403, 'The token owner\'s role does not allow ' . $p . ' for this client.');
    }
};
$body = json_decode((string)file_get_contents('php://input'), true) ?: array();
$cid = (int)($tok['client_id'] ?: (isset($_GET['client_id']) ? $_GET['client_id'] : (isset($body['client_id']) ? $body['client_id'] : 0)));
if ($tok['client_id'] && $cid !== (int)$tok['client_id']) {
    api_fail(403, 'This token is limited to client ' . (int)$tok['client_id'] . '.');
}
if (!Ctx::switchClient($cid)) {
    api_fail(404, 'Client not found in the token owner\'s scope. Pass client_id.');
}
$client = Ctx::client();
$r = isset($_GET['r']) ? $_GET['r'] : '';
$method = $_SERVER['REQUEST_METHOD'];

if ($r === 'files' && $method === 'GET') {
    $need('files:read');
    $perm('records.view');
    $rows = DB::all('SELECT id, form_code, schedule_code, variant_key, period_label, period_from, period_to, filename, sha256, status, file_label, record_count, total_base, total_tax, origin, created_at
        FROM dat_files WHERE client_id = ? AND status <> ? ORDER BY id DESC LIMIT 500', array($client['id'], 'superseded'));
    echo json_encode(array('ok' => true, 'client' => array('id' => (int)$client['id'], 'tin' => $client['tin']), 'files' => $rows));
    exit;
}
if ($r === 'file' && $method === 'GET') {
    $need('files:read');
    $perm('dat.download');
    $f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array((int)(isset($_GET['id']) ? $_GET['id'] : 0), $client['id']));
    if (!$f) {
        api_fail(404, 'File not found for this client.');
    }
    if (!in_array($f['status'], array('bir_validated', 'approved', 'submitted'), true)) {
        api_fail(409, 'Only files with accepted official validator evidence can be downloaded through the API. This file is ' . $f['status'] . '.');
    }
    $bytes = Reports::storedBytes($f);
    if ($bytes === null || hash('sha256', $bytes) !== $f['sha256']) {
        api_fail(500, 'The stored file does not match its recorded checksum.');
    }
    Audit::log('api.download', 'dat_files', $f['id'], 'API downloaded ' . $f['filename'] . ' via token ' . $tok['name']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f['filename'] . '"');
    header('X-Content-SHA256: ' . $f['sha256']);
    echo $bytes;
    exit;
}
if ($r === 'directory' && $method === 'GET') {
    $need('records:read');
    $perm('records.view');
    $rows = DB::all('SELECT id, tin, branch_code, entity_type, registered_name, last_name, first_name, middle_name, address1, address2, zip_code, is_supplier, is_customer, is_payee, is_payor, default_atc
        FROM counterparties WHERE client_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 5000', array($client['id']));
    echo json_encode(array('ok' => true, 'directory' => $rows));
    exit;
}
if (in_array($r, array('withholding', 'sales', 'purchases'), true) && $method === 'POST') {
    $need('records:write');
    $perm('records.edit');
    $rows = isset($body['rows']) && is_array($body['rows']) ? $body['rows'] : null;
    if (!$rows || count($rows) > 1000) {
        api_fail(422, 'Send between 1 and 1000 rows in "rows".');
    }
    $mod = Records::module($r);
    $role = $r === 'sales' ? 'customer' : ($r === 'purchases' ? 'supplier' : 'payee');
    $ho = Ctx::headOffice($client['id']);
    $out = array();
    foreach ($rows as $i => $row) {
        try {
            $branchId = $ho['id'];
            if (!empty($row['branch_code'])) {
                $b = DB::row('SELECT id FROM branches WHERE client_id = ? AND branch_code = ?', array($client['id'], Tin::branch($row['branch_code'])));
                if (!$b) {
                    throw new RuntimeException('Unknown client branch ' . $row['branch_code']);
                }
                $branchId = (int)$b['id'];
            }
            $party = isset($row['party']) && is_array($row['party']) ? $row['party'] : array();
            list($pd, $pe) = Records::counterpartyValidate($party, $client['id']);
            if (isset($pe['tin']) && $pe['tin'] === Records::DUP_TIN) {
                unset($pe['tin']); // an existing party is matched, not an error
            }
            if ($pe) {
                throw new RuntimeException('Party: ' . implode('; ', $pe));
            }
            $row['branch_id'] = $branchId;
            if ($r === 'withholding') {
                $row['tax_kind'] = isset($row['tax_kind']) ? $row['tax_kind'] : 'EWT';
                $row['schedule_tag'] = isset($row['schedule_tag']) ? $row['schedule_tag'] : 'A';
            }
            list($d, $e) = Records::validate($mod, $row, $client['id'], array('skip_party' => true, 'skip_scope' => true, 'party_entity_type' => $pd['entity_type']));
            if ($e) {
                throw new RuntimeException(implode('; ', $e));
            }
            $id = DB::tx(function () use ($client, $pd, $role, $d, $mod) {
                $d['counterparty_id'] = Records::resolveCounterparty($client['id'], $pd, $role, 'api', null);
                if (DB::val('SELECT id FROM ' . $mod['table'] . ' WHERE client_id = ? AND row_hash = ? AND status = ?', array($client['id'], Records::rowHash($mod, $d), 'posted'))) {
                    throw new RuntimeException('Duplicate of an existing record');
                }
                return Records::save($mod, $d, null, null, 'api');
            });
            $out[] = array('index' => $i, 'ok' => true, 'id' => $id);
        } catch (RuntimeException $ex) {
            $out[] = array('index' => $i, 'ok' => false, 'error' => $ex->getMessage());
        }
    }
    Audit::log('api.records', $mod['table'], null, 'API posted ' . count($rows) . ' ' . $r . ' rows via token ' . $tok['name']);
    echo json_encode(array('ok' => true, 'results' => $out));
    exit;
}
api_fail(404, 'Unknown endpoint. See docs/API.md.');
