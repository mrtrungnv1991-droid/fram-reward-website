<?php
/**
 * api_v4_accounts.php - API quan ly TAI KHOAN cho game4win.net (PROMPT UI v4, muc 2 + muc 7).
 *
 * PHP 7.4 compatible. PDO SQLite qua db.php (rw_db). Tat ca ham rieng dung prefix v4a_.
 * - Dang nhap: session (rw_is_logged_in) -> 401 neu chua dang nhap.
 * - CSRF: bat buoc voi moi POST (rw_csrf_check) -> 403 neu sai/thieu.
 * - Quyen: admin toan quyen; manager KHONG duoc dat/doi mat khau, KHONG duoc xoa vinh vien -> 403.
 * - Bi mat: KHONG bao gio tra mat khau (write-only); email mask ab***@domain, phone +84***1234,
 *   proxy user:***@host:port. Khong co truong password trong bat ky JSON/CSV nao tra ve.
 * - Loi chuan: {ok:false, error:{code, message}} voi 400/401/403/404/405/409/422.
 *
 * Endpoint (?action=...): list | get | add | bulk_preview | bulk_add | update | set_password |
 *                         disable | delete | restore | trash | export_csv
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/* ==================================================================== */
/* 1. TIEN ICH CHUNG (prefix v4a_)                                       */
/* ==================================================================== */

function v4a_now() { return date('Y-m-d H:i:s'); }

function v4a_fail($http, $code, $message, $extra = array()) {
    $err = array('code' => $code, 'message' => $message);
    if (is_array($extra) && count($extra)) { $err = array_merge($err, $extra); }
    rw_json(array('ok' => false, 'error' => $err), $http);
}

function v4a_str($s) {
    return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
}
function v4a_cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n, 'UTF-8') : substr((string)$s, 0, $n);
}
function v4a_text($v, $max) {
    $v = trim((string)$v);
    $v = preg_replace('/[\r\n\t]+/', ' ', $v);
    return v4a_cut($v, $max);
}

/* ---- Che bi mat ---- */
function v4a_mask_email($email) {
    $email = (string)$email;
    $p = explode('@', $email);
    if (count($p) !== 2 || $p[0] === '') { return '***'; }
    return substr($p[0], 0, 2) . '***@' . $p[1];
}
function v4a_mask_phone($num) {
    $num = (string)$num;
    if ($num === '') { return ''; }
    if (strlen($num) <= 7) { return substr($num, 0, 1) . '***'; }
    return substr($num, 0, 3) . '***' . substr($num, -4);
}
function v4a_mask_proxy($scheme, $host, $port, $user) {
    $user = (string)$user;
    return ($user !== '' ? $user . ':***@' : '') . $host . ':' . $port;
}

/* ---- Doc tham so (POST form hoac JSON body), tuong thich api_crud.php ---- */
function v4a_input() {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    $out = is_array($j) ? $j : array();
    foreach ($_POST as $k => $v) {
        if ($k === 'csrf') { continue; }
        $out[$k] = $v;
    }
    return $out;
}
function v4a_param($p, $key, $default = '') {
    return isset($p[$key]) ? $p[$key] : $default;
}

/* ---- Vai tro ---- */
function v4a_role() { return isset($_SESSION['rw_role']) ? (string)$_SESSION['rw_role'] : ''; }
function v4a_user() { return isset($_SESSION['rw_user']) ? (string)$_SESSION['rw_user'] : '-'; }

function v4a_require_post() {
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($m !== 'POST') {
        v4a_fail(405, 'method_not_allowed', 'Thao tác này bắt buộc dùng phương thức POST.');
    }
}
function v4a_require_admin($what) {
    if (!rw_is_admin()) {
        v4a_fail(403, 'admin_only', 'Chỉ admin mới được ' . $what . '. Tài khoản manager không có quyền này.');
    }
}
function v4a_require_confirm($p) {
    $c = isset($p['confirm']) ? $p['confirm'] : null;
    $ok = ($c === true || $c === 1 || $c === '1' || $c === 'true' || $c === 'yes');
    if (!$ok) {
        v4a_fail(400, 'confirm_required', 'Thiếu tham số xác nhận (confirm=true). Hành động xóa phải được giao diện xác nhận trước để tránh gọi nhầm.');
    }
}

/* ---- Bang ho tro (audit_ui / lich su) ---- */
function v4a_ensure_tables() {
    $db = rw_db();
    $db->exec('CREATE TABLE IF NOT EXISTS audit_ui (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime(\'now\')), ip TEXT, user TEXT, action TEXT, entity TEXT, entity_id TEXT, detail TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS proxy_history (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, proxy_id INTEGER, action TEXT, actor TEXT, note TEXT, ts TEXT DEFAULT (datetime(\'now\')))');
    $db->exec('CREATE TABLE IF NOT EXISTS phone_history (id INTEGER PRIMARY KEY AUTOINCREMENT, phone_id INTEGER, account_id INTEGER, action TEXT, actor TEXT, note TEXT, ts TEXT DEFAULT (datetime(\'now\')))');
}

function v4a_audit($action, $entity, $entity_id, $detail = array()) {
    $clean = array();
    foreach ((array)$detail as $k => $v) {
        if (preg_match('/pass|secret|token|hash|key|seal/i', (string)$k)) { $v = '***'; }
        $clean[$k] = $v;
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'cli';
    $st = rw_db()->prepare('INSERT INTO audit_ui(ts, ip, user, action, entity, entity_id, detail) VALUES(?,?,?,?,?,?,?)');
    $st->execute(array(v4a_now(), $ip, v4a_user(), (string)$action, (string)$entity, (string)$entity_id,
        json_encode($clean, JSON_UNESCAPED_UNICODE)));
}

function v4a_history($kind, $account_id, $ref_id, $action, $note) {
    if ($kind === 'proxy') {
        $st = rw_db()->prepare('INSERT INTO proxy_history(account_id, proxy_id, action, actor, note) VALUES(?,?,?,?,?)');
        $st->execute(array((int)$account_id, (int)$ref_id, (string)$action, v4a_user(), v4a_text($note, 200)));
    } else {
        $st = rw_db()->prepare('INSERT INTO phone_history(phone_id, account_id, action, actor, note) VALUES(?,?,?,?,?)');
        $st->execute(array((int)$ref_id, (int)$account_id, (string)$action, v4a_user(), v4a_text($note, 200)));
    }
}

/* ---- Mat khau: WRITE-ONLY, khong luu tho ---- */
function v4a_seal_password($plain, $device_id) {
    $plain = (string)$plain;
    if ($plain === '') { return ''; }
    if (function_exists('sodium_crypto_box_seal') && defined('SODIUM_CRYPTO_BOX_PUBLICKEYBYTES')) {
        $pk_b64 = '';
        if ($device_id) {
            $st = rw_db()->prepare('SELECT public_key FROM devices WHERE id = ?');
            $st->execute(array((int)$device_id));
            $r = $st->fetch();
            if ($r) { $pk_b64 = (string)$r['public_key']; }
        }
        $pk = base64_decode($pk_b64, true);
        if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return 'nacl:' . base64_encode(sodium_crypto_box_seal($plain, $pk));
        }
    }
    // Fallback: khong luu thô (giong number_enc cua phones) - worker se seal lai khi co khoa thiet bi.
    return 'b64:' . base64_encode($plain);
}

/* ---- Validate ---- */
function v4a_valid_email($e) {
    $e = (string)$e;
    return $e !== '' && v4a_str($e) <= 254 && (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}
function v4a_valid_phone($s) {
    return (bool)preg_match('/^\+?[0-9]{8,15}$/', (string)$s);
}
function v4a_status_list() {
    // Bao gom ca status ke thua tu DB v4 (ok, needs_recovery) de loc/sua duoc moi dong dang co.
    return array('new', 'provisioning', 'ready', 'running', 'done_today', 'cooling', 'partial',
                 'error', 'needs_human', 'needs_recovery', 'ok', 'locked', 'disabled', 'unknown');
}
function v4a_parse_proxy($str) {
    $str = trim((string)$str);
    if ($str === '') { return null; }
    if (preg_match('/^(?:(https?|socks4|socks5):\/\/)?(?:([^:@\/\s]+):([^:@\/\s]*)@)?([A-Za-z0-9.\-_]+):(\d{1,5})$/i', $str, $m)) {
        $port = (int)$m[5];
        if ($port < 1 || $port > 65535) { return false; }
        return array('scheme' => strtolower(isset($m[1]) && $m[1] !== '' ? $m[1] : 'http'),
                     'user' => isset($m[2]) ? $m[2] : '',
                     'pass' => isset($m[3]) ? $m[3] : '',
                     'host' => $m[4], 'port' => $port);
    }
    return false;
}

function v4a_ids($p) {
    $ids = array();
    if (isset($p['ids'])) {
        $v = $p['ids'];
        if (is_array($v)) {
            foreach ($v as $x) { if (ctype_digit((string)$x)) { $ids[] = (int)$x; } }
        } else {
            foreach (explode(',', (string)$v) as $x) {
                $x = trim($x);
                if ($x !== '' && ctype_digit($x)) { $ids[] = (int)$x; }
            }
        }
    } elseif (isset($p['id'])) {
        $v = $p['id'];
        if (is_array($v)) {
            foreach ($v as $x) { if (ctype_digit((string)$x)) { $ids[] = (int)$x; } }
        } elseif (ctype_digit((string)$v)) {
            $ids[] = (int)$v;
        }
    }
    $ids = array_values(array_unique(array_filter($ids, function ($x) { return $x > 0; })));
    if (!count($ids)) {
        v4a_fail(400, 'id_required', 'Thiếu tham số id/ids hợp lệ (số nguyên dương).');
    }
    if (count($ids) > 1000) {
        v4a_fail(400, 'too_many_ids', 'Mỗi thao tác tối đa 1000 tài khoản.');
    }
    return $ids;
}

/* ==================================================================== */
/* 2. TRUY VAN + ANH XA BAN GHI                                         */
/* ==================================================================== */

function v4a_fputcsv($stream, $fields) {
    // Gan ro tham so escape: tuong thich PHP 7.4 va khong gay deprecation o PHP 8.4.
    return fputcsv($stream, $fields, ',', '"', '\\');
}

function v4a_base_sql() {
    return 'SELECT a.*, px.scheme AS px_scheme, px.host AS px_host, px.port AS px_port, px.username AS px_user,'
         . ' px.status AS px_status, px.ip AS px_ip, px.geo AS px_geo, px.max_profiles AS px_max_profiles,'
         . ' ph.number_mask AS ph_mask, ph.status AS ph_status,'
         . ' (SELECT COUNT(*) FROM jobs j WHERE j.account_id = a.id AND j.status IN (\'pending\', \'running\')) AS jobs_running'
         . ' FROM accounts a'
         . ' LEFT JOIN proxies px ON px.id = a.proxy_id'
         . ' LEFT JOIN phones ph ON ph.id = a.phone_id';
}

function v4a_map_row($r) {
    $proxy = null;
    if (isset($r['proxy_id']) && $r['proxy_id'] !== null) {
        $proxy = array(
            'id' => (int)$r['proxy_id'],
            'label' => v4a_mask_proxy($r['px_scheme'], $r['px_host'], (int)$r['px_port'], $r['px_user']),
            'status' => (string)$r['px_status'],
            'ip' => (string)$r['px_ip'],
            'geo' => (string)$r['px_geo'],
            'max_profiles' => (int)$r['px_max_profiles']
        );
    }
    $phone = null;
    if (isset($r['phone_id']) && $r['phone_id'] !== null) {
        $phone = array('id' => (int)$r['phone_id'], 'mask' => (string)$r['ph_mask'], 'status' => (string)$r['ph_status']);
    }
    return array(
        'id' => (int)$r['id'],
        'email' => v4a_mask_email($r['email']),
        'status' => (string)$r['status'],
        'device_id' => ($r['device_id'] !== null && $r['device_id'] !== '') ? (int)$r['device_id'] : null,
        'proxy' => $proxy,
        'phone' => $phone,
        'points_today' => ($r['points_today'] !== null) ? (int)$r['points_today'] : 0,
        'points_start' => ($r['points_start'] !== null) ? (int)$r['points_start'] : null,
        'points_end' => ($r['points_end'] !== null) ? (int)$r['points_end'] : null,
        'delta' => ($r['delta'] !== null) ? (int)$r['delta'] : null,
        'tasks_ok' => (int)$r['tasks_ok'],
        'tasks_error' => (int)$r['tasks_error'],
        'next_run_at' => $r['next_run_at'],
        'last_run_at' => $r['last_run_at'],
        'last_success_at' => $r['last_success_at'],
        'last_error' => $r['last_error'],
        'error_class' => $r['error_class'],
        'notes' => (string)$r['notes'],
        'tags' => (string)$r['tags'],
        'group_label' => (string)$r['group_label'],
        'flags' => (string)$r['flags'],
        'has_password' => ((string)$r['password_sealed'] !== ''),
        'jobs_running' => (int)$r['jobs_running'],
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
        'deleted_at' => $r['deleted_at'],
        'deleted_by' => isset($r['deleted_by']) ? $r['deleted_by'] : null
    );
}

function v4a_build_filters($p) {
    $where = array('1=1');
    $args = array();
    $filters = array();

    $deleted = trim((string)v4a_param($p, 'deleted', '0'));
    if ($deleted === 'trash' || $deleted === '1') {
        $where[] = 'a.deleted_at IS NOT NULL';
        $filters['deleted'] = 'trash';
    } elseif ($deleted === 'all') {
        $filters['deleted'] = 'all';
    } else {
        $where[] = 'a.deleted_at IS NULL';
        $filters['deleted'] = 'active';
    }

    $q = v4a_text(v4a_param($p, 'q', ''), 100);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(a.email LIKE ? OR a.notes LIKE ? OR a.tags LIKE ? OR a.group_label LIKE ?)';
        array_push($args, $like, $like, $like, $like);
        $filters['q'] = $q;
    }

    $status = preg_replace('/[^a-z_]/', '', (string)v4a_param($p, 'status', ''));
    if ($status !== '') {
        if (!in_array($status, v4a_status_list(), true)) {
            v4a_fail(422, 'invalid_filter_status', 'Bộ lọc trạng thái không hợp lệ.');
        }
        $where[] = 'a.status = ?';
        $args[] = $status;
        $filters['status'] = $status;
    }

    $tag = v4a_text(v4a_param($p, 'tag', ''), 64);
    if ($tag !== '') {
        $where[] = 'a.tags LIKE ?';
        $args[] = '%' . $tag . '%';
        $filters['tag'] = $tag;
    }

    $group = v4a_text(v4a_param($p, 'group_label', ''), 64);
    if ($group !== '') {
        $where[] = 'a.group_label = ?';
        $args[] = $group;
        $filters['group_label'] = $group;
    }

    $proxy = trim((string)v4a_param($p, 'proxy', ''));
    if ($proxy === 'none') { $where[] = 'a.proxy_id IS NULL'; $filters['proxy'] = 'none'; }
    elseif ($proxy === 'has') { $where[] = 'a.proxy_id IS NOT NULL'; $filters['proxy'] = 'has'; }
    elseif ($proxy !== '' && ctype_digit($proxy)) { $where[] = 'a.proxy_id = ?'; $args[] = (int)$proxy; $filters['proxy'] = (int)$proxy; }
    elseif ($proxy !== '') { v4a_fail(422, 'invalid_filter_proxy', 'Bộ lọc proxy không hợp lệ (chỉ nhận: none, has hoặc id số).'); }

    $phone = trim((string)v4a_param($p, 'phone', ''));
    if ($phone === 'none') { $where[] = 'a.phone_id IS NULL'; $filters['phone'] = 'none'; }
    elseif ($phone === 'has') { $where[] = 'a.phone_id IS NOT NULL'; $filters['phone'] = 'has'; }
    elseif ($phone !== '' && ctype_digit($phone)) { $where[] = 'a.phone_id = ?'; $args[] = (int)$phone; $filters['phone'] = (int)$phone; }
    elseif ($phone !== '') { v4a_fail(422, 'invalid_filter_phone', 'Bộ lọc phone không hợp lệ (chỉ nhận: none, has hoặc id số).'); }

    $device = trim((string)v4a_param($p, 'device', ''));
    if ($device === 'none') { $where[] = 'a.device_id IS NULL'; $filters['device'] = 'none'; }
    elseif ($device !== '' && ctype_digit($device)) { $where[] = 'a.device_id = ?'; $args[] = (int)$device; $filters['device'] = (int)$device; }
    elseif ($device !== '') { v4a_fail(422, 'invalid_filter_device', 'Bộ lọc thiết bị không hợp lệ (chỉ nhận: none hoặc id số).'); }

    $chip = preg_replace('/[^a-z_]/', '', (string)v4a_param($p, 'chip', ''));
    switch ($chip) {
        case '':
            break;
        case 'no_proxy':
            $where[] = 'a.proxy_id IS NULL';
            break;
        case 'no_phone':
            $where[] = 'a.phone_id IS NULL';
            break;
        case 'needs_human':
            $where[] = 'a.status = ?';
            $args[] = 'needs_human';
            break;
        case 'error':
            $where[] = 'a.status = ?';
            $args[] = 'error';
            break;
        case 'cooling':
            $where[] = 'a.status = ?';
            $args[] = 'cooling';
            break;
        case 'disabled':
            $where[] = 'a.status = ?';
            $args[] = 'disabled';
            break;
        case 'not_farmed_today':
            $where[] = '(a.points_today IS NULL OR a.points_today = 0) AND (a.last_success_at IS NULL OR substr(a.last_success_at,1,10) < date(\'now\',\'localtime\'))';
            break;
        default:
            v4a_fail(422, 'invalid_filter_chip', 'Chip lọc không hợp lệ.');
    }
    if ($chip !== '') { $filters['chip'] = $chip; }

    return array($where, $args, $filters);
}

/* ==================================================================== */
/* 3. GAN PROXY / PHONE (cap nhat ca assignments + lich su)              */
/* ==================================================================== */

function v4a_proxy_capacity_state($db, $proxy_id, $exclude_id) {
    $st = $db->prepare('SELECT max_profiles FROM proxies WHERE id = ?');
    $st->execute(array((int)$proxy_id));
    $pr = $st->fetch();
    if (!$pr) { return 'not_found'; }
    $st = $db->prepare('SELECT COUNT(*) AS c FROM accounts WHERE proxy_id = ? AND id != ? AND deleted_at IS NULL');
    $st->execute(array((int)$proxy_id, (int)$exclude_id));
    return ((int)$st->fetch()['c'] < (int)$pr['max_profiles']) ? 'ok' : 'full';
}

function v4a_phone_capacity_state($db, $phone_id, $exclude_id) {
    $st = $db->prepare('SELECT id FROM phones WHERE id = ?');
    $st->execute(array((int)$phone_id));
    if (!$st->fetch()) { return 'not_found'; }
    $max_per_phone = (int)rw_db_setting('max_accounts_per_phone', '1');
    $st = $db->prepare('SELECT COUNT(*) AS c FROM accounts WHERE phone_id = ? AND id != ? AND deleted_at IS NULL');
    $st->execute(array((int)$phone_id, (int)$exclude_id));
    return ((int)$st->fetch()['c'] < $max_per_phone) ? 'ok' : 'full';
}

/* Ban khong loi (tra ve array) de dung duoc trong bulk import (khong exit giua giao dich). */
function v4a_try_change_proxy($db, $account_id, $new_proxy_id, $note) {
    $st = $db->prepare('SELECT proxy_id FROM accounts WHERE id = ?');
    $st->execute(array($account_id));
    $cur = $st->fetch();
    $old = ($cur && $cur['proxy_id'] !== null) ? (int)$cur['proxy_id'] : null;

    if ($new_proxy_id === null) {
        if ($old !== null) {
            $db->prepare('UPDATE accounts SET proxy_id = NULL, updated_at = ? WHERE id = ?')->execute(array(rw_db_now(), $account_id));
            $db->prepare('UPDATE assignments SET proxy_id = NULL, updated_at = ? WHERE account_id = ?')->execute(array(rw_db_now(), $account_id));
            v4a_history('proxy', $account_id, $old, 'release', $note);
        }
        return array('ok' => true);
    }
    $new_proxy_id = (int)$new_proxy_id;
    $state = v4a_proxy_capacity_state($db, $new_proxy_id, $account_id);
    if ($state === 'not_found') {
        return array('ok' => false, 'http' => 404, 'error' => 'proxy_not_found',
                     'message' => 'Không tìm thấy proxy id=' . $new_proxy_id . '.');
    }
    if ($state === 'full') {
        return array('ok' => false, 'http' => 409, 'error' => 'proxy_capacity_full',
                     'message' => 'Proxy id=' . $new_proxy_id . ' đã gán đủ số tài khoản cho phép (max_profiles). Hãy chọn proxy khác.');
    }
    $db->prepare('UPDATE accounts SET proxy_id = ?, updated_at = ? WHERE id = ?')->execute(array($new_proxy_id, rw_db_now(), $account_id));
    $db->prepare('UPDATE assignments SET proxy_id = ?, updated_at = ? WHERE account_id = ?')->execute(array($new_proxy_id, rw_db_now(), $account_id));
    if ($old !== null && $old !== $new_proxy_id) {
        v4a_history('proxy', $account_id, $old, 'release', $note);
    }
    v4a_history('proxy', $account_id, $new_proxy_id, 'assign', $note);
    return array('ok' => true);
}

function v4a_change_proxy($db, $account_id, $new_proxy_id, $note) {
    $r = v4a_try_change_proxy($db, $account_id, $new_proxy_id, $note);
    if (!$r['ok']) { v4a_fail((int)$r['http'], $r['error'], $r['message']); }
}

function v4a_try_change_phone($db, $account_id, $new_phone_id, $note) {
    $st = $db->prepare('SELECT phone_id FROM accounts WHERE id = ?');
    $st->execute(array($account_id));
    $cur = $st->fetch();
    $old = ($cur && $cur['phone_id'] !== null) ? (int)$cur['phone_id'] : null;

    if ($new_phone_id === null) {
        if ($old !== null) {
            $db->prepare('UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE id = ?')->execute(array(rw_db_now(), $account_id));
            $db->prepare('UPDATE assignments SET phone_id = NULL, updated_at = ? WHERE account_id = ?')->execute(array(rw_db_now(), $account_id));
            $db->prepare('UPDATE phones SET status = ?, attached_at = NULL WHERE id = ?')->execute(array('free', $old));
            v4a_history('phone', $account_id, $old, 'release', $note);
        }
        return array('ok' => true);
    }
    $new_phone_id = (int)$new_phone_id;
    $state = v4a_phone_capacity_state($db, $new_phone_id, $account_id);
    if ($state === 'not_found') {
        return array('ok' => false, 'http' => 404, 'error' => 'phone_not_found',
                     'message' => 'Không tìm thấy phone id=' . $new_phone_id . '.');
    }
    if ($state === 'full') {
        return array('ok' => false, 'http' => 409, 'error' => 'phone_capacity_full',
                     'message' => 'Phone id=' . $new_phone_id . ' đã gán đủ số tài khoản cho phép (max_accounts_per_phone). Hãy dùng phone khác.');
    }
    $db->prepare('UPDATE accounts SET phone_id = ?, updated_at = ? WHERE id = ?')->execute(array($new_phone_id, rw_db_now(), $account_id));
    $db->prepare('UPDATE assignments SET phone_id = ?, updated_at = ? WHERE account_id = ?')->execute(array($new_phone_id, rw_db_now(), $account_id));
    $db->prepare('UPDATE phones SET status = ?, attached_at = ? WHERE id = ?')->execute(array('pending_verify', rw_db_now(), $new_phone_id));
    if ($old !== null && $old !== $new_phone_id) {
        $db->prepare('UPDATE phones SET status = ?, attached_at = NULL WHERE id = ?')->execute(array('free', $old));
        v4a_history('phone', $account_id, $old, 'release', $note);
    }
    v4a_history('phone', $account_id, $new_phone_id, 'assign', $note);
    return array('ok' => true);
}

function v4a_change_phone($db, $account_id, $new_phone_id, $note) {
    $r = v4a_try_change_phone($db, $account_id, $new_phone_id, $note);
    if (!$r['ok']) { v4a_fail((int)$r['http'], $r['error'], $r['message']); }
}

/* ==================================================================== */
/* 4. XOA: tra proxy/phone ve pool + huy job dang chay                   */
/* ==================================================================== */

function v4a_cancel_jobs($db, $ids) {
    if (!count($ids)) { return 0; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare('UPDATE jobs SET status = \'cancelled\', finished_at = ?, error = ? WHERE account_id IN (' . $ph . ') AND status IN (\'pending\', \'running\')');
    $vals = array(rw_db_now(), 'Hủy do thao tác xóa tài khoản (api_v4_accounts)');
    $vals = array_merge($vals, $ids);
    $st->execute($vals);
    return $st->rowCount();
}

/* ==================================================================== */
/* 5. PARSE DONG DAN HANG LOAT (email|pass|proxy|phone)                  */
/* ==================================================================== */

function v4a_split_colon($line) {
    $pos1 = strpos($line, ':');
    if ($pos1 === false) { return array($line); }
    $pos2 = strpos($line, ':', $pos1 + 1);
    $email = substr($line, 0, $pos1);
    if ($pos2 === false) { return array($email, substr($line, $pos1 + 1)); }
    $password = substr($line, $pos1 + 1, $pos2 - $pos1 - 1);
    $rest = substr($line, $pos2 + 1);
    // rest = proxy[:phone]; proxy dang [scheme://][user:pass@]host:port
    if (preg_match('/^((?:[^:@\/\s]+:[^:@\/\s]+@)?[^:@\/\s]+:\d{1,5})(?:[:\t](.*))?$/', $rest, $m)) {
        $out = array($email, $password, $m[1]);
        if (isset($m[2]) && $m[2] !== '') { $out[] = $m[2]; }
        return $out;
    }
    if (preg_match('/^\+?\d{8,15}$/', $rest)) { return array($email, $password, '', $rest); }
    return array($email, $password, $rest);
}

function v4a_parse_bulk_line($line) {
    if (strpos($line, "\t") !== false) { $parts = explode("\t", $line); }
    elseif (strpos($line, '|') !== false) { $parts = explode('|', $line); }
    else { $parts = v4a_split_colon($line); }
    $parts = array_map('trim', $parts);
    if (count($parts) > 4) {
        return array('error' => 'Dòng có quá nhiều cột (tối đa 4: email|pass|proxy|phone).');
    }
    while (count($parts) < 4) { $parts[] = ''; }
    return array('fields' => array($parts[0], $parts[1], $parts[2], $parts[3]));
}

function v4a_mask_bulk_line($line) {
    if (strpos($line, "\t") !== false) { $delim = "\t"; }
    elseif (strpos($line, '|') !== false) { $delim = '|'; }
    else { $delim = ':'; }
    $parts = ($delim === ':') ? v4a_split_colon($line) : explode($delim, $line);
    // Mask email (cot 0) va mat khau (cot 1) de khong lo ro.
    if (count($parts) >= 1 && trim($parts[0]) !== '') { $parts[0] = v4a_mask_email(trim($parts[0])); }
    if (count($parts) >= 2 && trim($parts[1]) !== '') { $parts[1] = '***'; }
    return implode($delim, $parts);
}
function v4a_mask_input_val($s) {
    $s = (string)$s;
    if ($s === '') { return '(trống)'; }
    return v4a_cut($s, 2) . '***';
}

function v4a_bulk_analyze($text) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    if (count($lines) > 5000) {
        v4a_fail(400, 'too_many_lines', 'Vượt quá 5000 dòng cho mỗi lần dán (hiện có ' . count($lines) . ' dòng).');
    }
    $db = rw_db();
    $public = array();
    $valid = array();
    $seen = array();
    $no = 0;
    foreach ($lines as $raw) {
        $no++;
        $line = trim($raw);
        if ($line === '' || substr($line, 0, 1) === '#') { continue; }
        $item = array('no' => $no, 'line' => v4a_mask_bulk_line($line), 'status' => 'ok', 'reason' => 'Hop le');
        if (v4a_str($line) > 1024) {
            $item['status'] = 'error';
            $item['reason'] = 'Dòng vượt quá 1024 ký tự.';
            $public[] = $item;
            continue;
        }
        $pr = v4a_parse_bulk_line($line);
        if (isset($pr['error'])) {
            $item['status'] = 'error';
            $item['reason'] = $pr['error'];
            $public[] = $item;
            continue;
        }
        $f = $pr['fields'];
        $email = $f[0]; $pass = $f[1]; $proxy = $f[2]; $phone = $f[3];
        if (!v4a_valid_email($email)) {
            $item['status'] = 'error';
            $item['reason'] = 'Email không hợp lệ: "' . v4a_mask_input_val($email) . '".';
            $public[] = $item;
            continue;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            $item['status'] = 'duplicate';
            $item['reason'] = 'Email lặp lại trong danh sách dán (dòng ' . $seen[$key] . ').';
            $public[] = $item;
            continue;
        }
        $seen[$key] = $no;
        if ($pass === '') {
            $item['status'] = 'error';
            $item['reason'] = 'Thiếu mật khẩu (cột 2).';
            $public[] = $item;
            continue;
        }
        if (v4a_str($pass) > 2048) {
            $item['status'] = 'error';
            $item['reason'] = 'Mật khẩu vượt quá 2048 ký tự.';
            $public[] = $item;
            continue;
        }
        $st = $db->prepare('SELECT id, deleted_at FROM accounts WHERE email = ?');
        $st->execute(array($email));
        $row = $st->fetch();
        if ($row && $row['deleted_at'] === null) {
            $item['status'] = 'duplicate';
            $item['reason'] = 'Email đã tồn tại trong hệ thống.';
            $public[] = $item;
            continue;
        }
        if ($row) {
            $item['status'] = 'duplicate';
            $item['reason'] = 'Email đã có trong Thùng rác (đã xóa mềm) - hãy khôi phục để dùng lại.';
            $public[] = $item;
            continue;
        }
        $proxy_arr = null;
        if ($proxy !== '') {
            $proxy_arr = v4a_parse_proxy($proxy);
            if ($proxy_arr === false) {
                $item['status'] = 'error';
                $item['reason'] = 'Proxy không hợp lệ (cần dạng host:port hoặc scheme://user:pass@host:port).';
                $public[] = $item;
                continue;
            }
        }
        if ($phone !== '' && !v4a_valid_phone($phone)) {
            $item['status'] = 'error';
            $item['reason'] = 'Số điện thoại không hợp lệ (cần 8-15 chữ số, có thể bắt đầu bằng +).';
            $public[] = $item;
            continue;
        }
        $public[] = $item;
        $valid[] = array('no' => $no, 'email' => $email, 'pass' => $pass,
                         'proxy_raw' => $proxy, 'proxy' => $proxy_arr, 'phone' => $phone);
    }
    return array('items' => $public, 'valid' => $valid);
}

function v4a_upsert_proxy($db, $pr) {
    $key = $pr['scheme'] . '://' . $pr['host'] . ':' . $pr['port'] . ($pr['user'] !== '' ? ' (' . $pr['user'] . ')' : '');
    $st = $db->prepare('SELECT id FROM proxies WHERE proxy_key = ?');
    $st->execute(array($key));
    $r = $st->fetch();
    if ($r) { return (int)$r['id']; }
    $st = $db->prepare('INSERT INTO proxies(proxy_key, scheme, host, port, username, password_sealed, status, max_profiles, created_at) VALUES(?,?,?,?,?,?,?,1,?)');
    $st->execute(array($key, $pr['scheme'], $pr['host'], $pr['port'], $pr['user'], $pr['pass'], 'unchecked', rw_db_now()));
    return (int)$db->lastInsertId();
}

function v4a_upsert_phone($db, $number) {
    $enc = base64_encode((string)$number);
    $st = $db->prepare('SELECT id FROM phones WHERE number_enc = ?');
    $st->execute(array($enc));
    $r = $st->fetch();
    if ($r) { return (int)$r['id']; }
    $st = $db->prepare('INSERT INTO phones(number_enc, number_mask, status, created_at) VALUES(?,?,?,?)');
    $st->execute(array($enc, v4a_mask_phone($number), 'free', rw_db_now()));
    return (int)$db->lastInsertId();
}

function v4a_bulk_import($valid) {
    $db = rw_db();
    $added = 0;
    $ids = array();
    $errors = array();
    $db->beginTransaction();
    try {
        foreach ($valid as $f) {
            $sealed = v4a_seal_password($f['pass'], null);
            $st = $db->prepare('INSERT INTO accounts(email, password_sealed, status, points_today, created_at, updated_at) VALUES(?,?,?,?,?,?)');
            $st->execute(array($f['email'], $sealed, 'new', 0, rw_db_now(), rw_db_now()));
            $aid = (int)$db->lastInsertId();
            $ids[] = $aid;
            if (is_array($f['proxy'])) {
                $pid = v4a_upsert_proxy($db, $f['proxy']);
                $r = v4a_try_change_proxy($db, $aid, $pid, 'Import hàng loạt');
                if (!$r['ok']) {
                    $errors[] = array('no' => $f['no'], 'line' => 'dòng ' . $f['no'], 'status' => 'error',
                                      'reason' => 'Tài khoản đã thêm nhưng không gán được proxy: ' . $r['message']);
                }
            }
            if ($f['phone'] !== '') {
                $phid = v4a_upsert_phone($db, $f['phone']);
                $r = v4a_try_change_phone($db, $aid, $phid, 'Import hàng loạt');
                if (!$r['ok']) {
                    $errors[] = array('no' => $f['no'], 'line' => 'dòng ' . $f['no'], 'status' => 'error',
                                      'reason' => 'Tài khoản đã thêm nhưng không gán được phone: ' . $r['message']);
                }
            }
            $added++;
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        v4a_fail(500, 'import_failed', 'Import thất bại, đã hủy giao dịch: ' . $e->getMessage());
    }
    return array('added' => $added, 'ids' => $ids, 'errors' => $errors);
}

/* ==================================================================== */
/* 6. BOOTSTRAP + DISPATCH                                               */
/* ==================================================================== */

function v4a_bootstrap() {
    rw_send_security_headers();
    rw_start_session_secure();
    if (!rw_is_logged_in()) {
        v4a_fail(401, 'unauthorized', 'Chưa đăng nhập hoặc phiên đã hết hạn. Vui lòng đăng nhập lại.');
    }
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($m === 'POST' && !rw_csrf_check()) {
        v4a_fail(403, 'csrf_invalid', 'Token CSRF không hợp lệ hoặc thiếu. Hãy tải lại trang rồi thử lại.');
    }
}

function v4a_main() {
    v4a_bootstrap();
    v4a_ensure_tables();

    $action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['action']) : '';
    if ($action === '' && isset($_POST['action'])) {
        $action = preg_replace('/[^a-z_]/', '', (string)$_POST['action']);
    }

    switch ($action) {

        /* ==================== LIST / TRASH ==================== */
        case 'list':
        case 'trash':
            $p = array_merge($_GET, v4a_input());
            if ($action === 'trash') { $p['deleted'] = 'trash'; }
            list($where, $wargs, $filters) = v4a_build_filters($p);
            $sort_map = array(
                'id' => 'a.id', 'email' => 'a.email', 'status' => 'a.status',
                'device_id' => 'a.device_id', 'proxy_id' => 'a.proxy_id', 'phone_id' => 'a.phone_id',
                'points_today' => 'a.points_today', 'last_success_at' => 'a.last_success_at',
                'last_run_at' => 'a.last_run_at', 'next_run_at' => 'a.next_run_at',
                'created_at' => 'a.created_at', 'updated_at' => 'a.updated_at',
                'group_label' => 'a.group_label', 'tags' => 'a.tags'
            );
            $sort_by = (string)v4a_param($p, 'sort', 'id');
            if (!isset($sort_map[$sort_by])) { $sort_by = 'id'; }
            $sort_dir = (strtolower((string)v4a_param($p, 'dir', 'desc')) === 'asc') ? 'ASC' : 'DESC';
            $page = max(1, (int)v4a_param($p, 'page', 1));
            $per_page = (int)v4a_param($p, 'per_page', 50);
            if ($per_page < 1) { $per_page = 50; }
            if ($per_page > 500) { $per_page = 500; }
            $db = rw_db();
            $sqlw = ' WHERE ' . implode(' AND ', $where);
            $st = $db->prepare('SELECT COUNT(*) AS c FROM accounts a' . $sqlw);
            $st->execute($wargs);
            $total = (int)$st->fetch()['c'];
            $sql = v4a_base_sql() . $sqlw
                 . ' ORDER BY ' . $sort_map[$sort_by] . ' ' . $sort_dir . ', a.id ' . $sort_dir
                 . ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)(($page - 1) * $per_page);
            $st = $db->prepare($sql);
            $st->execute($wargs);
            $rows = array();
            foreach ($st->fetchAll() as $r) { $rows[] = v4a_map_row($r); }
            rw_json(array(
                'ok' => true,
                'data' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => (int)ceil($total / $per_page),
                'filters_applied' => $filters,
                'sort' => array('by' => $sort_by, 'dir' => $sort_dir),
                'generated_at' => rw_db_now()
            ));
            break;

        /* ==================== GET ==================== */
        case 'get':
            $p = array_merge($_GET, v4a_input());
            $id = (int)v4a_param($p, 'id', 0);
            if ($id <= 0) { v4a_fail(400, 'id_required', 'Thiếu tham số id.'); }
            $db = rw_db();
            $st = $db->prepare(v4a_base_sql() . ' WHERE a.id = ?');
            $st->execute(array($id));
            $r = $st->fetch();
            if (!$r) { v4a_fail(404, 'account_not_found', 'Không tìm thấy tài khoản id=' . $id . '.'); }
            $data = v4a_map_row($r);
            $st = $db->prepare('SELECT proxy_id, action, actor, note, ts FROM proxy_history WHERE account_id = ? ORDER BY id DESC LIMIT 20');
            $st->execute(array($id));
            $data['proxy_history'] = $st->fetchAll();
            $st = $db->prepare('SELECT phone_id, action, actor, note, ts FROM phone_history WHERE account_id = ? ORDER BY id DESC LIMIT 20');
            $st->execute(array($id));
            $data['phone_history'] = $st->fetchAll();
            $st = $db->prepare('SELECT id, job_key, action, status, priority, created_at, started_at FROM jobs WHERE account_id = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 20');
            $st->execute(array($id, 'pending', 'running'));
            $data['jobs'] = $st->fetchAll();
            rw_json(array('ok' => true, 'data' => $data, 'generated_at' => rw_db_now()));
            break;

        /* ==================== ADD (mot tai khoan) ==================== */
        case 'add':
            v4a_require_post();
            $p = v4a_input();
            $email = trim((string)v4a_param($p, 'email', ''));
            if ($email === '') { v4a_fail(400, 'email_required', 'Thiếu trường email.'); }
            if (!v4a_valid_email($email)) {
                v4a_fail(422, 'invalid_email', 'Email không hợp lệ: "' . v4a_mask_input_val($email) . '".');
            }
            $plain = (string)v4a_param($p, 'password', '');
            $sealed_in = (string)v4a_param($p, 'password_sealed', '');
            if ($plain === '' && $sealed_in === '') {
                v4a_fail(422, 'password_required', 'Thiếu mật khẩu. Gửi trường password (write-only) hoặc password_sealed (đã seal bằng khóa thiết bị).');
            }
            if ($plain !== '' && v4a_str($plain) > 2048) {
                v4a_fail(422, 'password_too_long', 'Mật khẩu vượt quá 2048 ký tự.');
            }
            $status = preg_replace('/[^a-z_]/', '', (string)v4a_param($p, 'status', 'new'));
            if ($status === '') { $status = 'new'; }
            if (!in_array($status, v4a_status_list(), true)) {
                v4a_fail(422, 'invalid_status', 'Trạng thái không hợp lệ.');
            }
            $device_id = null;
            $dv = v4a_param($p, 'device_id', null);
            if ($dv !== null && $dv !== '') {
                if (!ctype_digit((string)$dv)) { v4a_fail(422, 'invalid_device_id', 'device_id phải là số.'); }
                $st = rw_db()->prepare('SELECT id FROM devices WHERE id = ?');
                $st->execute(array((int)$dv));
                if (!$st->fetch()) { v4a_fail(404, 'device_not_found', 'Không tìm thấy thiết bị id=' . (int)$dv . '.'); }
                $device_id = (int)$dv;
            }
            $db = rw_db();
            $st = $db->prepare('SELECT id, deleted_at FROM accounts WHERE email = ?');
            $st->execute(array($email));
            $ex = $st->fetch();
            if ($ex && $ex['deleted_at'] === null) {
                v4a_fail(409, 'email_exists', 'Email đã tồn tại trong hệ thống.');
            }
            if ($ex) {
                v4a_fail(409, 'email_in_trash', 'Email đã có trong Thùng rác (đã xóa mềm) - hãy khôi phục thay vì tạo mới.');
            }
            // Xac thuc + resolve proxy/phone + kiem suc chua TRUOC khi ghi DB.
            $proxy_txt = trim((string)v4a_param($p, 'proxy', ''));
            $proxy_id_in = v4a_param($p, 'proxy_id', null);
            $phone_txt = trim((string)v4a_param($p, 'phone', ''));
            $phone_id_in = v4a_param($p, 'phone_id', null);
            if ($proxy_id_in !== null && $proxy_id_in !== '' && !ctype_digit((string)$proxy_id_in)) {
                v4a_fail(422, 'invalid_proxy_id', 'proxy_id phải là số.');
            }
            if ($phone_id_in !== null && $phone_id_in !== '' && !ctype_digit((string)$phone_id_in)) {
                v4a_fail(422, 'invalid_phone_id', 'phone_id phải là số.');
            }
            $proxy_pr = null;
            if ($proxy_txt !== '') {
                $proxy_pr = v4a_parse_proxy($proxy_txt);
                if ($proxy_pr === false) {
                    v4a_fail(422, 'invalid_proxy', 'Proxy không hợp lệ (cần dạng host:port hoặc scheme://user:pass@host:port).');
                }
            }
            if ($phone_txt !== '' && !v4a_valid_phone($phone_txt)) {
                v4a_fail(422, 'invalid_phone', 'Số điện thoại không hợp lệ.');
            }
            $nra_add = v4a_text(v4a_param($p, 'next_run_at', ''), 19);
            if ($nra_add !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $nra_add)) {
                v4a_fail(422, 'invalid_next_run_at', 'Thời điểm chạy kế tiếp không hợp lệ (cần dạng YYYY-MM-DD HH:MM).');
            }
            // Resolve proxy/phone id (upsert neu can) + kiem suc chua TRUOC khi INSERT account.
            $resolved_proxy_id = null;
            if ($proxy_id_in !== null && $proxy_id_in !== '') {
                $resolved_proxy_id = (int)$proxy_id_in;
            } elseif ($proxy_pr !== null) {
                $resolved_proxy_id = v4a_upsert_proxy($db, $proxy_pr);
            }
            if ($resolved_proxy_id !== null) {
                $cap = v4a_proxy_capacity_state($db, $resolved_proxy_id, 0);
                if ($cap === 'not_found') { v4a_fail(404, 'proxy_not_found', 'Không tìm thấy proxy id=' . $resolved_proxy_id . '.'); }
                if ($cap === 'full') { v4a_fail(409, 'proxy_capacity_full', 'Proxy id=' . $resolved_proxy_id . ' đã gán đủ số tài khoản cho phép (max_profiles). Hãy chọn proxy khác.'); }
            }
            $resolved_phone_id = null;
            if ($phone_id_in !== null && $phone_id_in !== '') {
                $resolved_phone_id = (int)$phone_id_in;
            } elseif ($phone_txt !== '') {
                $resolved_phone_id = v4a_upsert_phone($db, $phone_txt);
            }
            if ($resolved_phone_id !== null) {
                $cap = v4a_phone_capacity_state($db, $resolved_phone_id, 0);
                if ($cap === 'not_found') { v4a_fail(404, 'phone_not_found', 'Không tìm thấy phone id=' . $resolved_phone_id . '.'); }
                if ($cap === 'full') { v4a_fail(409, 'phone_capacity_full', 'Phone id=' . $resolved_phone_id . ' đã gán đủ số tài khoản cho phép (max_accounts_per_phone). Hãy dùng phone khác.'); }
            }
            $sealed = ($sealed_in !== '') ? v4a_cut($sealed_in, 2048) : v4a_seal_password($plain, $device_id);
            $st = $db->prepare('INSERT INTO accounts(email, password_sealed, device_id, status, next_run_at, notes, tags, group_label, flags, points_today, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array(
                $email, $sealed, $device_id, $status,
                $nra_add,
                v4a_text(v4a_param($p, 'notes', ''), 2000),
                v4a_text(v4a_param($p, 'tags', ''), 255),
                v4a_text(v4a_param($p, 'group_label', ''), 64),
                v4a_text(v4a_param($p, 'flags', ''), 255),
                0, rw_db_now(), rw_db_now()
            ));
            $aid = (int)$db->lastInsertId();
            if ($resolved_proxy_id !== null) {
                v4a_change_proxy($db, $aid, $resolved_proxy_id, 'Thêm tài khoản');
            }
            if ($resolved_phone_id !== null) {
                v4a_change_phone($db, $aid, $resolved_phone_id, 'Thêm tài khoản');
            }
            v4a_audit('account_add', 'account', $aid, array('email' => v4a_mask_email($email), 'status' => $status));
            $st = $db->prepare(v4a_base_sql() . ' WHERE a.id = ?');
            $st->execute(array($aid));
            rw_json(array('ok' => true, 'id' => $aid, 'account' => v4a_map_row($st->fetch()),
                          'message' => 'Đã thêm tài khoản.', 'generated_at' => rw_db_now()));
            break;

        /* ==================== BULK PREVIEW ==================== */
        case 'bulk_preview':
            v4a_require_post();
            $p = v4a_input();
            $text = (string)v4a_param($p, 'lines', '');
            if (trim($text) === '') { $text = (string)v4a_param($p, 'text', ''); }
            if (trim($text) === '') {
                v4a_fail(400, 'lines_required', 'Thiếu nội dung dán (trường lines). Hãy dán danh sách email|pass|proxy|phone.');
            }
            if (strlen($text) > 2 * 1024 * 1024) {
                v4a_fail(400, 'lines_too_large', 'Nội dung dán vượt quá 2MB.');
            }
            $an = v4a_bulk_analyze($text);
            $valid = 0; $dup = 0; $err = 0;
            foreach ($an['items'] as $it) {
                if ($it['status'] === 'ok') { $valid++; }
                elseif ($it['status'] === 'duplicate') { $dup++; }
                else { $err++; }
            }
            rw_json(array(
                'ok' => true,
                'total' => count($an['items']),
                'valid' => $valid,
                'duplicate' => $dup,
                'error' => $err,
                'items' => $an['items'],
                'message' => 'Xem trước: Hợp lệ ' . $valid . ' · Trùng ' . $dup . ' · Lỗi ' . $err,
                'generated_at' => rw_db_now()
            ));
            break;

        /* ==================== BULK ADD ==================== */
        case 'bulk_add':
            v4a_require_post();
            $p = v4a_input();
            $text = (string)v4a_param($p, 'lines', '');
            if (trim($text) === '') { $text = (string)v4a_param($p, 'text', ''); }
            if (trim($text) === '') {
                v4a_fail(400, 'lines_required', 'Thiếu nội dung dán (trường lines).');
            }
            if (strlen($text) > 2 * 1024 * 1024) {
                v4a_fail(400, 'lines_too_large', 'Nội dung dán vượt quá 2MB.');
            }
            $an = v4a_bulk_analyze($text);
            $dup = 0; $err = 0; $errors = array();
            foreach ($an['items'] as $it) {
                if ($it['status'] === 'duplicate') { $dup++; $errors[] = $it; }
                elseif ($it['status'] === 'error') { $err++; $errors[] = $it; }
            }
            $res = v4a_bulk_import($an['valid']);
            $added = $res['added'];
            if (count($res['errors'])) {
                $errors = array_merge($errors, $res['errors']);
                $err += count($res['errors']);
            }
            v4a_audit('account_bulk_add', 'account', 'bulk', array(
                'added' => $added, 'duplicate' => $dup, 'error' => $err,
                'ids' => $res['ids']
            ));
            rw_json(array(
                'ok' => true,
                'message' => 'Thêm ' . $added . ' · Trùng ' . $dup . ' · Lỗi ' . $err,
                'added' => $added,
                'duplicate' => $dup,
                'error' => $err,
                'added_ids' => $res['ids'],
                'errors' => $errors,
                'generated_at' => rw_db_now()
            ));
            break;

        /* ==================== UPDATE ==================== */
        case 'update':
            v4a_require_post();
            $p = v4a_input();
            $id = (int)v4a_param($p, 'id', 0);
            if ($id <= 0) { v4a_fail(400, 'id_required', 'Thiếu tham số id.'); }
            $db = rw_db();
            $st = $db->prepare('SELECT * FROM accounts WHERE id = ?');
            $st->execute(array($id));
            if (!$st->fetch()) { v4a_fail(404, 'account_not_found', 'Không tìm thấy tài khoản id=' . $id . '.'); }
            $sets = array(); $vals = array(); $changed = array();
            if (array_key_exists('status', $p)) {
                $s = preg_replace('/[^a-z_]/', '', (string)$p['status']);
                if (!in_array($s, v4a_status_list(), true)) {
                    v4a_fail(422, 'invalid_status', 'Trạng thái không hợp lệ. Cho phép: ' . implode(', ', v4a_status_list()) . '.');
                }
                $sets[] = 'status = ?'; $vals[] = $s; $changed[] = 'status';
            }
            if (array_key_exists('notes', $p)) {
                if (v4a_str((string)$p['notes']) > 2000) { v4a_fail(422, 'notes_too_long', 'Ghi chú vượt quá 2000 ký tự.'); }
                $sets[] = 'notes = ?'; $vals[] = v4a_text($p['notes'], 2000); $changed[] = 'notes';
            }
            if (array_key_exists('tags', $p)) {
                if (v4a_str((string)$p['tags']) > 255) { v4a_fail(422, 'tags_too_long', 'Tags vượt quá 255 ký tự.'); }
                $sets[] = 'tags = ?'; $vals[] = v4a_text($p['tags'], 255); $changed[] = 'tags';
            }
            if (array_key_exists('group_label', $p)) {
                $sets[] = 'group_label = ?'; $vals[] = v4a_text($p['group_label'], 64); $changed[] = 'group_label';
            }
            if (array_key_exists('flags', $p)) {
                $sets[] = 'flags = ?'; $vals[] = v4a_text($p['flags'], 255); $changed[] = 'flags';
            }
            if (array_key_exists('next_run_at', $p)) {
                $nra = trim((string)$p['next_run_at']);
                if ($nra !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $nra)) {
                    v4a_fail(422, 'invalid_next_run_at', 'Thời điểm chạy kế tiếp không hợp lệ (cần dạng YYYY-MM-DD HH:MM).');
                }
                $sets[] = 'next_run_at = ?'; $vals[] = ($nra === '' ? null : $nra); $changed[] = 'next_run_at';
            }
            if (array_key_exists('device_id', $p)) {
                $dv = $p['device_id'];
                if ($dv === null || $dv === '') {
                    $sets[] = 'device_id = NULL'; $changed[] = 'device_id';
                } else {
                    if (!ctype_digit((string)$dv)) { v4a_fail(422, 'invalid_device_id', 'device_id phải là số.'); }
                    $st2 = $db->prepare('SELECT id FROM devices WHERE id = ?');
                    $st2->execute(array((int)$dv));
                    if (!$st2->fetch()) { v4a_fail(404, 'device_not_found', 'Không tìm thấy thiết bị id=' . (int)$dv . '.'); }
                    $sets[] = 'device_id = ?'; $vals[] = (int)$dv; $changed[] = 'device_id';
                }
            }
            if (array_key_exists('proxy_id', $p)) {
                $pv = $p['proxy_id'];
                if ($pv === null || $pv === '') {
                    v4a_change_proxy($db, $id, null, 'Cập nhật tài khoản');
                    $changed[] = 'proxy_id';
                } else {
                    if (!ctype_digit((string)$pv)) { v4a_fail(422, 'invalid_proxy_id', 'proxy_id phải là số.'); }
                    v4a_change_proxy($db, $id, (int)$pv, 'Cập nhật tài khoản');
                    $changed[] = 'proxy_id';
                }
            }
            if (array_key_exists('phone_id', $p)) {
                $qv = $p['phone_id'];
                if ($qv === null || $qv === '') {
                    v4a_change_phone($db, $id, null, 'Cập nhật tài khoản');
                    $changed[] = 'phone_id';
                } else {
                    if (!ctype_digit((string)$qv)) { v4a_fail(422, 'invalid_phone_id', 'phone_id phải là số.'); }
                    v4a_change_phone($db, $id, (int)$qv, 'Cập nhật tài khoản');
                    $changed[] = 'phone_id';
                }
            }
            if (!count($sets) && !count($changed)) {
                v4a_fail(400, 'nothing_to_update', 'Không có trường nào để cập nhật. Gửi ít nhất một trường hợp lệ.');
            }
            if (count($sets)) {
                $sets[] = 'updated_at = ?';
                $vals[] = rw_db_now();
                $vals[] = $id;
                $st = $db->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?');
                $st->execute($vals);
            }
            v4a_audit('account_update', 'account', $id, array('fields' => $changed));
            $st = $db->prepare(v4a_base_sql() . ' WHERE a.id = ?');
            $st->execute(array($id));
            rw_json(array('ok' => true, 'id' => $id, 'updated_fields' => $changed,
                          'account' => v4a_map_row($st->fetch()),
                          'message' => 'Đã cập nhật tài khoản id=' . $id . '.', 'generated_at' => rw_db_now()));
            break;

        /* ==================== SET PASSWORD (write-only) ==================== */
        case 'set_password':
            v4a_require_post();
            v4a_require_admin('đặt/đổi mật khẩu tài khoản');
            $p = v4a_input();
            $id = (int)v4a_param($p, 'id', 0);
            if ($id <= 0) { v4a_fail(400, 'id_required', 'Thiếu tham số id.'); }
            $db = rw_db();
            $st = $db->prepare('SELECT id, email, device_id FROM accounts WHERE id = ?');
            $st->execute(array($id));
            $row = $st->fetch();
            if (!$row) { v4a_fail(404, 'account_not_found', 'Không tìm thấy tài khoản id=' . $id . '.'); }
            $plain = (string)v4a_param($p, 'password', '');
            $sealed_in = (string)v4a_param($p, 'password_sealed', '');
            if ($plain === '' && $sealed_in === '') {
                v4a_fail(422, 'password_required', 'Thiếu mật khẩu mới (trường password hoặc password_sealed).');
            }
            if ($plain !== '' && v4a_str($plain) > 2048) {
                v4a_fail(422, 'password_too_long', 'Mật khẩu vượt quá 2048 ký tự.');
            }
            $sealed = ($sealed_in !== '') ? v4a_cut($sealed_in, 2048) : v4a_seal_password($plain, $row['device_id']);
            $st = $db->prepare('UPDATE accounts SET password_sealed = ?, updated_at = ? WHERE id = ?');
            $st->execute(array($sealed, rw_db_now(), $id));
            v4a_audit('account_set_password', 'account', $id, array('email' => v4a_mask_email($row['email'])));
            rw_json(array('ok' => true, 'id' => $id, 'password_saved' => true,
                          'message' => 'Đã lưu mật khẩu (write-only, không hiển thị lại).', 'generated_at' => rw_db_now()));
            break;

        /* ==================== DISABLE ==================== */
        case 'disable':
            v4a_require_post();
            $p = v4a_input();
            $ids = v4a_ids($p);
            $db = rw_db();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare('UPDATE accounts SET status = ?, updated_at = ? WHERE id IN (' . $ph . ')');
            $vals = array_merge(array('disabled', rw_db_now()), $ids);
            $st->execute($vals);
            $n = $st->rowCount();
            v4a_audit('account_disable', 'account', implode(',', $ids), array('count' => $n));
            rw_json(array('ok' => true, 'count' => $n, 'ids' => $ids,
                          'message' => 'Đã disable ' . $n . ' tài khoản.', 'generated_at' => rw_db_now()));
            break;

        /* ==================== DELETE (disable | soft | hard) ==================== */
        case 'delete':
            v4a_require_post();
            $p = v4a_input();
            $ids = v4a_ids($p);
            $mode = preg_replace('/[^a-z]/', '', strtolower((string)v4a_param($p, 'mode', '')));
            if (!in_array($mode, array('disable', 'soft', 'hard'), true)) {
                v4a_fail(400, 'invalid_mode', 'Tham số mode không hợp lệ. Chỉ nhận: disable, soft, hard.');
            }
            v4a_require_confirm($p);
            if ($mode === 'hard') {
                v4a_require_admin('xóa vĩnh viễn tài khoản');
            }
            $db = rw_db();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare('SELECT * FROM accounts WHERE id IN (' . $ph . ')');
            $st->execute($ids);
            $rows = $st->fetchAll();
            if (!count($rows)) {
                v4a_fail(404, 'account_not_found', 'Không tìm thấy tài khoản nào trong danh sách id.');
            }
            $found = array(); $done = array();
            $released = 0; $freed_phone = 0; $cancelled = 0;
            foreach ($rows as $r) {
                $found[] = (int)$r['id'];
                if ($mode === 'disable') {
                    $db->prepare('UPDATE accounts SET status = ?, updated_at = ? WHERE id = ?')->execute(array('disabled', rw_db_now(), $r['id']));
                    $done[] = (int)$r['id'];
                    continue;
                }
                if ($mode === 'soft') {
                    if ($r['deleted_at'] !== null) { continue; }
                    $db->prepare('UPDATE accounts SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ?')
                        ->execute(array(rw_db_now(), v4a_user(), rw_db_now(), $r['id']));
                }
                // soft + hard: tra proxy/phone ve pool (ghi lich su) + huy job
                if ($r['proxy_id'] !== null) {
                    v4a_history('proxy', $r['id'], (int)$r['proxy_id'], 'release', 'Xóa ' . $mode);
                    $released++;
                }
                if ($r['phone_id'] !== null) {
                    v4a_history('phone', $r['id'], (int)$r['phone_id'], 'release', 'Xóa ' . $mode);
                    $freed_phone++;
                    $db->prepare('UPDATE phones SET status = ?, attached_at = NULL WHERE id = ?')->execute(array('free', (int)$r['phone_id']));
                }
                if ($r['proxy_id'] !== null || $r['phone_id'] !== null) {
                    $db->prepare('UPDATE accounts SET proxy_id = NULL, phone_id = NULL, updated_at = ? WHERE id = ?')
                        ->execute(array(rw_db_now(), $r['id']));
                    $db->prepare('UPDATE assignments SET proxy_id = NULL, phone_id = NULL, updated_at = ? WHERE account_id = ?')
                        ->execute(array(rw_db_now(), $r['id']));
                }
                $cancelled += v4a_cancel_jobs($db, array((int)$r['id']));
                if ($mode === 'hard') {
                    $db->prepare('DELETE FROM assignments WHERE account_id = ?')->execute(array($r['id']));
                    $db->prepare('DELETE FROM accounts WHERE id = ?')->execute(array($r['id']));
                }
                $done[] = (int)$r['id'];
            }
            v4a_audit('account_delete_' . $mode, 'account', implode(',', $done), array(
                'mode' => $mode, 'ids' => $done,
                'released_proxy' => $released, 'released_phone' => $freed_phone,
                'jobs_cancelled' => $cancelled
            ));
            if ($mode === 'hard') {
                $msg = 'Đã xóa vĩnh viễn ' . count($done) . ' tài khoản. Proxy/phone đã trả về pool, job đang chạy đã bị hủy.';
            } elseif ($mode === 'soft') {
                $msg = 'Đã chuyển ' . count($done) . ' tài khoản vào Thùng rác (hoàn tác được trong 30 ngày). Proxy/phone đã trả về pool.';
            } else {
                $msg = 'Đã disable ' . count($done) . ' tài khoản.';
            }
            rw_json(array(
                'ok' => true,
                'mode' => $mode,
                'ids' => $done,
                'not_found' => array_values(array_diff($ids, $found)),
                'released_proxy' => $released,
                'released_phone' => $freed_phone,
                'jobs_cancelled' => $cancelled,
                'message' => $msg,
                'generated_at' => rw_db_now()
            ));
            break;

        /* ==================== RESTORE ==================== */
        case 'restore':
            v4a_require_post();
            $p = v4a_input();
            $ids = v4a_ids($p);
            $db = rw_db();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare('SELECT id, deleted_at FROM accounts WHERE id IN (' . $ph . ')');
            $st->execute($ids);
            $rows = $st->fetchAll();
            if (!count($rows)) {
                v4a_fail(404, 'account_not_found', 'Không tìm thấy tài khoản nào trong danh sách id.');
            }
            $restored = array(); $skipped = array();
            foreach ($rows as $r) {
                if ($r['deleted_at'] === null) { $skipped[] = (int)$r['id']; continue; }
                $db->prepare('UPDATE accounts SET deleted_at = NULL, deleted_by = NULL, updated_at = ? WHERE id = ?')
                    ->execute(array(rw_db_now(), $r['id']));
                $restored[] = (int)$r['id'];
            }
            v4a_audit('account_restore', 'account', implode(',', $restored), array('restored' => $restored, 'skipped' => $skipped));
            rw_json(array('ok' => true, 'restored' => $restored, 'skipped' => $skipped,
                          'message' => 'Đã khôi phục ' . count($restored) . ' tài khoản từ Thùng rác.',
                          'generated_at' => rw_db_now()));
            break;

        /* ==================== EXPORT CSV (che bi mat) ==================== */
        case 'export_csv':
            $p = array_merge($_GET, v4a_input());
            $p['deleted'] = isset($p['deleted']) ? $p['deleted'] : 'all';
            list($where, $wargs, $filters) = v4a_build_filters($p);
            $st = rw_db()->prepare(v4a_base_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id ASC LIMIT 5000');
            $st->execute($wargs);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="accounts_export_' . date('Ymd_His') . '.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            v4a_fputcsv($out, array('id', 'email', 'status', 'device_id', 'proxy', 'phone', 'points_today',
                                'tasks_ok', 'tasks_error', 'next_run_at', 'last_success_at', 'notes', 'tags',
                                'group_label', 'created_at', 'updated_at', 'deleted_at'));
            foreach ($st->fetchAll() as $r) {
                $m = v4a_map_row($r);
                v4a_fputcsv($out, array(
                    $m['id'], $m['email'], $m['status'], $m['device_id'],
                    $m['proxy'] ? $m['proxy']['label'] : '',
                    $m['phone'] ? $m['phone']['mask'] : '',
                    $m['points_today'], $m['tasks_ok'], $m['tasks_error'],
                    $m['next_run_at'], $m['last_success_at'], $m['notes'], $m['tags'],
                    $m['group_label'], $m['created_at'], $m['updated_at'], $m['deleted_at']
                ));
            }
            fclose($out);
            exit;

        default:
            v4a_fail(400, 'unknown_action', 'Action không hợp lệ. Cho phép: list, get, add, bulk_preview, bulk_add, update, set_password, disable, delete, restore, trash, export_csv.');
    }
}

// Khi test CLI co the define RW_V4A_TEST_MODE truoc khi include, sau do goi v4a_main() chu dong.
if (!defined('RW_V4A_TEST_MODE')) {
    v4a_main();
}
