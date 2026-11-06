<?php
// Lop bao mat tap trung (Muc 17) - PHP 7.4 compatible.
require_once __DIR__ . '/config.php';

define('RW_AUDIT_FILE', RW_SECURE_DIR . '/audit.log');
define('RW_RL_FILE', RW_SECURE_DIR . '/ratelimit.json');
define('RW_RL_MAX_FAIL', 5);
define('RW_RL_LOCK', 900);
define('RW_LOGIN_ERR', 'Thong tin dang nhap khong dung.');

function rw_send_security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    $csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function rw_start_session_secure() {
    if (session_status() === PHP_SESSION_NONE) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params(array(
                'lifetime' => RW_SESSION_IDLE, 'path' => '/', 'domain' => '',
                'secure' => $https, 'httponly' => true, 'samesite' => 'Strict'
            ));
        }
        session_start();
    }
}

function rw_rl_load() {
    $f = RW_RL_FILE;
    if (!is_file($f)) { return array(); }
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : array();
}
function rw_rl_save($d) {
    if (!is_dir(RW_SECURE_DIR)) { @mkdir(RW_SECURE_DIR, 0700, true); }
    file_put_contents(RW_RL_FILE, json_encode($d), LOCK_EX);
}
function rw_rl_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
    return md5($ip);
}
function rw_rl_status() {
    $d = rw_rl_load(); $k = rw_rl_key();
    if (!isset($d[$k])) { return array('locked'=>false,'fails'=>0,'until'=>0); }
    $e = $d[$k];
    if (!empty($e['locked_until']) && time() < $e['locked_until']) {
        return array('locked'=>true,'fails'=>(int)$e['fails'],'until'=>(int)$e['locked_until']);
    }
    return array('locked'=>false,'fails'=>(int)(isset($e['fails'])?$e['fails']:0),'until'=>0);
}
function rw_rl_fail() {
    $d = rw_rl_load(); $k = rw_rl_key();
    $fails = isset($d[$k]['fails']) ? (int)$d[$k]['fails'] + 1 : 1;
    $d[$k] = array('fails'=>$fails, 'last'=>time(), 'locked_until'=>0);
    if ($fails >= RW_RL_MAX_FAIL) { $d[$k]['locked_until'] = time() + RW_RL_LOCK; }
    rw_rl_save($d);
    return $fails;
}
function rw_rl_clear() {
    $d = rw_rl_load(); unset($d[rw_rl_key()]); rw_rl_save($d);
}

function rw_require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !rw_csrf_check()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error'=>'csrf_invalid'));
        exit;
    }
}

function rw_mask($s) {
    $s = (string)$s;
    if ($s === '') { return ''; }
    $len = strlen($s);
    if ($len <= 4) { return str_repeat('*', $len); }
    return substr($s,0,2) . str_repeat('*', max(1,$len-4)) . substr($s,-2);
}
function rw_mask_email($e) {
    $e = (string)$e;
    $p = explode('@', $e);
    if (count($p) !== 2) { return rw_mask($e); }
    return rw_mask($p[0]) . '@' . $p[1];
}
function rw_audit($action, $detail = array()) {
    if (!is_dir(RW_SECURE_DIR)) { @mkdir(RW_SECURE_DIR, 0700, true); }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
    $user = isset($_SESSION['rw_user']) ? $_SESSION['rw_user'] : '-';
    $clean = array();
    foreach ($detail as $k => $v) {
        if (preg_match('/pass|secret|token|hash|key/i', (string)$k)) { $v = rw_mask((string)$v); }
        $clean[$k] = $v;
    }
    $line = date('c') . ' | ' . $ip . ' | ' . $user . ' | ' . $action . ' | ' . json_encode($clean, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(RW_AUDIT_FILE, $line, FILE_APPEND | LOCK_EX);
    @chmod(RW_AUDIT_FILE, 0600);
}

function rw_need_password_change() {
    if (empty($_SESSION['rw_user'])) { return false; }
    $f = RW_SECURE_DIR . '/users.php';
    if (!is_file($f)) { return false; }
    $RW_USERS = null; include $f;
    $u = $_SESSION['rw_user'];
    return isset($RW_USERS[$u]) && isset($RW_USERS[$u]['must_change']) && (int)$RW_USERS[$u]['must_change'] === 1;
}

function rw_write_users_file($users) {
    $f = RW_SECURE_DIR . '/users.php';
    if (!is_dir(RW_SECURE_DIR)) { @mkdir(RW_SECURE_DIR, 0700, true); }
    $o = "<?php\n// Danh sach tai khoan dashboard. Mat khau luu bang bcrypt, khong luu ro.\n\$RW_USERS = array(\n";
    foreach ($users as $u => $d) {
        $o .= "    '" . addslashes($u) . "' => array(\n";
        $o .= "        'hash' => '" . addslashes(isset($d['hash'])?$d['hash']:'') . "',\n";
        $o .= "        'role' => '" . addslashes(isset($d['role'])?$d['role']:'') . "',\n";
        $o .= "        'full' => '" . addslashes(isset($d['full'])?$d['full']:'') . "',\n";
        $o .= "        'must_change' => " . (isset($d['must_change'])?(int)$d['must_change']:0) . ",\n";
        $o .= "    ),\n";
    }
    $o .= ");\n";
    if (file_put_contents($f, $o, LOCK_EX) === false) { return false; }
    @chmod($f, 0600);
    return true;
}

function rw_set_password($username, $newpass) {
    $f = RW_SECURE_DIR . '/users.php';
    if (!is_file($f)) { return false; }
    $RW_USERS = null; include $f;
    if (!isset($RW_USERS[$username])) { return false; }
    $RW_USERS[$username]['hash'] = password_hash($newpass, PASSWORD_BCRYPT);
    $RW_USERS[$username]['must_change'] = 0;
    return rw_write_users_file($RW_USERS);
}
?>
