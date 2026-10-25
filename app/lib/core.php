<?php
/**
 * Small helpers shared by every page.
 */

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function storage_path($rel = '')
{
    $base = rtrim(str_replace('\\', '/', config('storage_path', BASE_PATH . '/storage')), '/');
    return $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
}

function base_url()
{
    $cfg = config('base_url', '');
    if ($cfg !== '') {
        return rtrim($cfg, '/');
    }
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $dir;
}

function url($page = 'dashboard', $params = array())
{
    $q = array_merge(array('p' => $page), $params);
    return base_url() . '/index.php?' . http_build_query($q);
}

function asset($path)
{
    $file = BASE_PATH . '/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : GSB_VERSION;
    return base_url() . '/assets/' . $path . '?v=' . $v;
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

function now()
{
    return date('Y-m-d H:i:s');
}

function today()
{
    return date('Y-m-d');
}

function is_post()
{
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax()
{
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false && !isset($_SERVER['HTTP_UPGRADE_INSECURE_REQUESTS']));
}

function input($key, $default = null)
{
    if (isset($_POST[$key])) {
        return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    }
    return $default;
}

function input_int($key, $default = 0)
{
    $v = input($key, null);
    return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int)$v;
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function str_starts($haystack, $needle)
{
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}

function str_has($haystack, $needle)
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function array_get($arr, $key, $default = null)
{
    return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'cli';
}

function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

// ---------------------------------------------------------------- flash messages
function flash($type, $msg)
{
    $_SESSION['_flash'][] = array('type' => $type, 'msg' => $msg);
}

function flashes()
{
    $f = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : array();
    unset($_SESSION['_flash']);
    return $f;
}

function remember_old($data)
{
    $_SESSION['_old'] = $data;
}

function old($key, $default = '')
{
    if (isset($_SESSION['_old'][$key])) {
        return $_SESSION['_old'][$key];
    }
    return $default;
}

function clear_old()
{
    unset($_SESSION['_old']);
}

// ---------------------------------------------------------------- CSRF
function csrf_token()
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_token(16);
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $sent = isset($_POST['_csrf']) ? $_POST['_csrf'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (is_ajax()) {
            json_out(array('ok' => false, 'error' => 'Your session expired. Reload the page and try again.'), 419);
        }
        http_response_code(419);
        flash('error', 'Your session expired. Please try again.');
        $back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : url('dashboard');
        redirect($back);
    }
}

// ---------------------------------------------------------------- formatting
function fmt_date($d, $fmt = 'M j, Y')
{
    if (!$d || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date($fmt, $t) : '';
}

function fmt_dt($d)
{
    return fmt_date($d, 'M j, Y g:i A');
}

function month_label($ym)
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$ym, $m)) {
        return (string)$ym;
    }
    return date('M Y', mktime(0, 0, 0, (int)$m[2], 1, (int)$m[1]));
}

function last_day($ym)
{
    list($y, $m) = explode('-', $ym);
    return date('Y-m-t', mktime(0, 0, 0, (int)$m, 1, (int)$y));
}

function quarter_months($year, $q)
{
    $start = ($q - 1) * 3 + 1;
    return array(sprintf('%04d-%02d', $year, $start), sprintf('%04d-%02d', $year, $start + 1), sprintf('%04d-%02d', $year, $start + 2));
}

function human_bytes($b)
{
    $u = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($b >= 1024 && $i < 3) {
        $b /= 1024;
        $i++;
    }
    return ($i ? number_format($b, 1) : $b) . ' ' . $u[$i];
}

function plural($n, $one, $many = null)
{
    return number_format($n) . ' ' . ($n == 1 ? $one : ($many ?: $one . 's'));
}

function sys_config($key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            foreach (DB::all('SELECT k, v FROM system_config') as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Exception $e) {
            $cache = array();
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_sys_config($key, $value)
{
    DB::q('INSERT INTO system_config (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', array($key, $value));
}

/** Store an uploaded file under storage/<dir> with a random name. Returns array or throws. */
function store_upload($file, $dir, $allowedExt, $maxBytes = null)
{
    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $code = is_array($file) && isset($file['error']) ? $file['error'] : -1;
        $msgs = array(UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.', UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.', UPLOAD_ERR_NO_FILE => 'Choose a file to upload.');
        throw new RuntimeException(isset($msgs[$code]) ? $msgs[$code] : 'The upload failed.');
    }
    $max = $maxBytes ?: ((int)sys_config('max_upload_mb', 20) * 1024 * 1024);
    if ($file['size'] > $max) {
        throw new RuntimeException('The file is larger than ' . human_bytes($max) . '.');
    }
    $name = basename($file['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Only these file types are accepted: ' . implode(', ', $allowedExt) . '.');
    }
    $sig = file_get_contents($file['tmp_name'], false, null, 0, 8);
    if (in_array($ext, array('xlsx', 'zip'), true) && substr($sig, 0, 2) !== 'PK') {
        throw new RuntimeException('The file content is not a valid ' . strtoupper($ext) . ' package.');
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Legacy .xls workbooks are not read. Open the file in Excel and save it as .xlsx, then upload again.');
    }
    if ($ext === 'pdf' && substr($sig, 0, 4) !== '%PDF') {
        throw new RuntimeException('The file content is not a PDF.');
    }
    if (in_array($ext, array('png', 'jpg', 'jpeg'), true) && @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('The file content is not a valid image.');
    }
    if (in_array($ext, array('dat', 'txt', 'csv'), true) && strpos(file_get_contents($file['tmp_name'], false, null, 0, 65536), "\0") !== false) {
        throw new RuntimeException('The file contains binary data and is not a text file.');
    }
    $rel = trim($dir, '/') . '/' . date('Y/m') . '/' . random_token(12) . '.' . $ext;
    $abs = storage_path($rel);
    if (!is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0775, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $abs) && !rename($file['tmp_name'], $abs)) {
        throw new RuntimeException('The file could not be saved.');
    }
    return array('path' => $rel, 'name' => $name, 'size' => filesize($abs), 'sha256' => hash_file('sha256', $abs), 'ext' => $ext);
}

function send_download($absPath, $downloadName, $mime = 'application/octet-stream')
{
    if (!is_file($absPath)) {
        http_response_code(404);
        exit('File not found.');
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absPath));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Cache-Control: private, no-store');
    readfile($absPath);
    exit;
}

function send_bytes($bytes, $downloadName, $mime = 'application/octet-stream', $inline = false)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

/**
 * Normalise an RDO code. BIR codes are three digits (081) or digits with a letter (17A, 21B, 093A).
 * Returns '' for blank input, the normalised code, or null when the format is wrong.
 */
function rdo_normalize($raw)
{
    $v = strtoupper(preg_replace('/\s+/', '', (string)$raw));
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{1,3}$/', $v)) {
        return str_pad($v, 3, '0', STR_PAD_LEFT);
    }
    return preg_match('/^\d{2,3}[A-Z]$/', $v) ? $v : null;
}
