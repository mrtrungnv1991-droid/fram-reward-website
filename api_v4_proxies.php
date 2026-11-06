<?php
/**
 * api_v4_proxies.php — API cho tab «PROXY» (prompt_website_ui_v4.md mục 3 + 7).
 * PHP 7.4 + PDO SQLite. Gọi từ trình duyệt đã đăng nhập:
 *   GET  api_v4_proxies.php?action=list&page=1&per_page=50
 *   POST api_v4_proxies.php?action=add   (JSON body + header X-CSRF-Token)
 *
 * Quy ước SAO CHÉP từ api_crud.php:
 *   - require security.php → bắt buộc đăng nhập (401) + CSRF cho mọi POST (403)
 *   - phản hồi JSON qua rw_json(); lỗi dạng {"error": "..."} kèm HTTP code
 *   - thao tác ghi/xóa phá hủy cần quyền admin (403 admin_only)
 *   - mọi thay đổi ghi audit_ui + audit_log + proxy_history
 *   - mật khẩu proxy KHÔNG BAO GIỜ xuất hiện trong phản hồi (chỉ che)
 *
 * Action (mục 7):
 *   list | add | bulk_preview | bulk_add | range_expand | test | remove | assign
 *   | auto_assign_preview | auto_assign_apply | release | group_info
 *   | replace_dead | export_csv
 */
require_once __DIR__ . '/security.php';
rw_send_security_headers();
rw_start_session_secure();
rw_require_api_login();
rw_require_csrf();
require_once __DIR__ . '/db.php';
/* Polyfill mb_substr an toàn khi PHP thiếu extension mbstring */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null) {
        $s = (string)$s;
        return ($length === null) ? substr($s, $start) : substr($s, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) { return strlen((string)$s); }
}


define('RWVP_MAX_EXPAND', 5000);   // giới hạn bung dải mỗi lần gọi
define('RWVP_MAX_LOCAL_TEST', 50); // giới hạn test TCP từ hosting mỗi lần gọi

/* ===================== TỰ ĐẢM BẢO SCHEMA V4 =====================
 * Nếu DB chưa chạy migrate v4, tự thêm bảng/cột cần thiết (an toàn, idempotent).
 * proxies: label/group_key/color_idx/exit_ip/checked_note
 * assignments: since/sticky/updated_at
 * bảng: proxy_history, phone_history, proxy_groups, audit_ui
 */
function rwvp_ensure_schema() {
    $db = rw_db();
    $add_cols = array(
        'proxies' => array(
            'label' => "TEXT",
            'group_key' => "TEXT",
            'color_idx' => "INTEGER",
            'exit_ip' => "TEXT",
            'checked_note' => "TEXT"),
        'assignments' => array(
            'since' => "TEXT",
            'sticky' => "INTEGER DEFAULT 1",
            'updated_at' => "TEXT"),
        'accounts' => array(
            'deleted_at' => "TEXT",
            'deleted_by' => "TEXT"));
    foreach ($add_cols as $table => $cols) {
        $have = array();
        foreach ($db->query("PRAGMA table_info(" . $table . ")")->fetchAll() as $c) {
            $have[] = (string)$c['name'];
        }
        foreach ($cols as $name => $type) {
            if (!in_array($name, $have, true)) {
                $db->exec("ALTER TABLE " . $table . " ADD COLUMN " . $name . " " . $type);
            }
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS proxy_history( id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, proxy_id INTEGER, action TEXT, actor TEXT, note TEXT, ts TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS phone_history( id INTEGER PRIMARY KEY AUTOINCREMENT, phone_id INTEGER, account_id INTEGER, action TEXT, actor TEXT, note TEXT, ts TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS proxy_groups( id INTEGER PRIMARY KEY AUTOINCREMENT, proxy_id INTEGER UNIQUE, color_idx INTEGER, label TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS audit_ui( id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime('now')), ip TEXT, user TEXT, action TEXT, entity TEXT, entity_id TEXT, detail TEXT)");
}
rwvp_ensure_schema();

/* ===================== QUY ƯỚC CHUNG (sao chép api_crud.php) ===================== */
function rwvp_input() {
    $raw = file_get_contents('php://input');
    $p = json_decode($raw, true);
    if (!is_array($p)) { $p = array(); }
    if (isset($_POST) && is_array($_POST) && count($_POST)) {
        $p = array_merge($_POST, $p); // JSON body thắng, $_POST làm fallback
    }
    return $p;
}
function rwvp_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
}
function rwvp_admin_only() {
    if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
}
function rwvp_actor() {
    return isset($_SESSION['rw_user']) ? (string)$_SESSION['rw_user'] : '-';
}
function rwvp_now() { return rw_db_now(); }
function rwvp_generated() { return date('c'); }
function rwvp_action_name($p) {
    $a = isset($_GET['action']) ? (string)$_GET['action'] : (isset($p['action']) ? (string)$p['action'] : '');
    $a = strtolower(preg_replace('/[^a-z_]/', '', $a));
    foreach (array('proxies_', 'proxy_') as $pre) {
        if (strpos($a, $pre) === 0) { return substr($a, strlen($pre)); }
    }
    return $a;
}
function rwvp_setting($key, $default = '') { return (string)rw_db_setting($key, $default); }
function rwvp_int_setting($key, $default) {
    $v = (int)rw_db_setting($key, (string)$default);
    return $v > 0 ? $v : (int)$default;
}

/* ===================== AUDIT + LỊCH SỬ ===================== */
function rwvp_audit($action, $entity, $entity_id, $detail = array()) {
    $db = rw_db();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
    $st = $db->prepare("INSERT INTO audit_ui(ts, ip, user, action, entity, entity_id, detail) VALUES(?,?,?,?,?,?,?)");
    $st->execute(array(rwvp_now(), $ip, rwvp_actor(), $action, $entity, (string)$entity_id,
        json_encode($detail, JSON_UNESCAPED_UNICODE)));
    rw_db_audit($action, array_merge(array('entity' => $entity, 'entity_id' => $entity_id), $detail));
}
function rwvp_history($account_id, $proxy_id, $action, $note = '') {
    $st = rw_db()->prepare("INSERT INTO proxy_history(account_id, proxy_id, action, actor, note, ts) VALUES(?,?,?,?,?,?)");
    $st->execute(array(
        $account_id !== null ? (int)$account_id : null,
        $proxy_id !== null ? (int)$proxy_id : null,
        $action, rwvp_actor(), (string)$note, rwvp_now()));
}

/* ===================== NHẬN DẠNG / CHE ===================== */
function rwvp_build_key($scheme, $host, $port, $user) {
    return $scheme . '://' . $host . ':' . $port . ($user !== '' ? ' (' . $user . ')' : '');
}
function rwvp_mask_key($p) {
    $s = isset($p['scheme']) ? $p['scheme'] : 'http';
    $h = isset($p['host']) ? $p['host'] : '';
    $po = isset($p['port']) ? $p['port'] : 0;
    $u = isset($p['username']) ? $p['username'] : '';
    return $s . '://' . $h . ':' . $po . ($u !== '' ? ':' . $u . ':****' : '');
}
/* ===================== CHE THÔNG TIN NHẠY CẢM (mục 3.2: che pass) ===================== */
function rwvp_mask_line($line) {
    $line = trim((string)$line);
    $scheme = '';
    $rest = $line;
    if (preg_match('#^((?:https?|socks[45])://)(.+)$#i', $line, $m)) {
        $scheme = $m[1];
        $rest = $m[2];
    }
    if (strpos($rest, '@') !== false) {
        $parts = explode('@', $rest, 2);
        $cp = explode(':', $parts[0], 2);
        return $scheme . $cp[0] . ':****@' . $parts[1];
    }
    $hp = explode(':', $rest);
    if (count($hp) >= 4) {
        return $scheme . $hp[0] . ':' . $hp[1] . ':' . $hp[2] . ':****';
    }
    return $line;
}
function rwvp_mask_rows($rows) {
    foreach ($rows as $k => $r) {
        if (isset($r['line'])) { $rows[$k]['line'] = rwvp_mask_line($r['line']); }
    }
    return $rows;
}
function rwvp_mask_errors($errs) {
    foreach ($errs as $k => $e) {
        if (isset($e['line'])) { $errs[$k]['line'] = rwvp_mask_line($e['line']); }
    }
    return $errs;
}

function rwvp_subnet($host) {
    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', (string)$host)) {
        $x = explode('.', (string)$host);
        return $x[0] . '.' . $x[1] . '.' . $x[2] . '.0/24';
    }
    return '';
}

/* ===================== PHÂN TÍCH 1 DÒNG PROXY =====================
 * Chấp nhận: host:port | host:port:user:pass | user:pass@host:port
 *            scheme://host:port | scheme://user:pass@host:port
 */
function rwvp_parse_line($line) {
    $line = trim((string)$line);
    if ($line === '') { return array('ok' => false, 'error' => 'empty', 'raw' => $line); }
    $scheme = 'http'; $rest = $line;
    if (preg_match('/^(https?|socks[45]):\/\/(.+)$/i', $line, $m)) {
        $scheme = strtolower($m[1]); $rest = trim($m[2]);
    }
    $user = ''; $pass = '';
    if (strpos($rest, '@') !== false) {
        $parts = explode('@', $rest, 2);
        $cred = $parts[0]; $rest = trim($parts[1]);
        $cp = explode(':', $cred, 2);
        $user = $cp[0]; $pass = isset($cp[1]) ? $cp[1] : '';
    }
    $hp = explode(':', $rest);
    $host = trim($hp[0]);
    if (count($hp) >= 4 && $user === '') {
        $port = (int)$hp[1]; $user = $hp[2]; $pass = $hp[3];
    } elseif (count($hp) >= 3 && $user === '') {
        $port = (int)$hp[1]; $user = $hp[2];
    } elseif (count($hp) >= 2) {
        $port = (int)$hp[1];
    } else {
        return array('ok' => false, 'error' => 'invalid_host_port', 'raw' => $line);
    }
    if ($host === '' || $port <= 0 || $port > 65535) {
        return array('ok' => false, 'error' => 'invalid_host_port', 'raw' => $line);
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
        return array('ok' => false, 'error' => 'invalid_host', 'raw' => $line);
    }
    return array('ok' => true, 'error' => '', 'raw' => $line,
        'scheme' => $scheme, 'host' => $host, 'port' => $port,
        'username' => $user, 'password' => $pass,
        'proxy_key' => rwvp_build_key($scheme, $host, $port, $user));
}

/* ===================== BUNG DẢI (mục 3.2) =====================
 * host:20000-20199 | host:20000-20199:user:pass | scheme://host:a-b:user:pass
 * 1.2.3.10-1.2.3.60:port | 1.2.3.10-1.2.3.60:port:user:pass
 */
function rwvp_expand_line($line) {
    $raw = trim((string)$line);
    if ($raw === '') { return array('lines' => array(), 'error' => 'empty', 'is_range' => false); }
    $scheme = 'http'; $rest = $raw;
    if (preg_match('/^(https?|socks[45]):\/\/(.+)$/i', $raw, $m)) {
        $scheme = strtolower($m[1]); $rest = trim($m[2]);
    }
    $user = ''; $pass = '';
    if (strpos($rest, '@') !== false) {
        $parts = explode('@', $rest, 2);
        $cred = $parts[0]; $rest = trim($parts[1]);
        $cp = explode(':', $cred, 2);
        $user = $cp[0]; $pass = isset($cp[1]) ? $cp[1] : '';
    }
    $hp = explode(':', $rest);
    $host = trim($hp[0]);
    $port_token = isset($hp[1]) ? trim($hp[1]) : '';
    if ($user === '' && count($hp) >= 4) { $user = $hp[2]; $pass = $hp[3]; }
    elseif ($user === '' && count($hp) >= 3) { $user = $hp[2]; }
    $prefix = ($scheme !== 'http') ? ($scheme . '://') : '';
    $suffix = ($user !== '') ? (':' . $user . ':' . $pass) : '';
    $out = array();
    if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $port_token, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2];
        if ($a < 1 || $b > 65535 || $b < $a) {
            return array('lines' => array(), 'error' => 'invalid_port_range', 'is_range' => true);
        }
        for ($p = $a; $p <= $b; $p++) { $out[] = $prefix . $host . ':' . $p . $suffix; }
        return array('lines' => $out, 'error' => '', 'is_range' => true);
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3})$/', $host, $m)
        && $port_token !== '' && ctype_digit($port_token)) {
        $from = ip2long($m[1]); $to = ip2long($m[2]);
        if ($from === false || $to === false || $to < $from) {
            return array('lines' => array(), 'error' => 'invalid_ip_range', 'is_range' => true);
        }
        for ($ip = $from; $ip <= $to; $ip++) {
            $out[] = $prefix . long2ip($ip) . ':' . $port_token . $suffix;
        }
        return array('lines' => $out, 'error' => '', 'is_range' => true);
    }
    return array('lines' => array($raw), 'error' => '', 'is_range' => false);
}

/* Gom danh sách dòng từ body: lines (chuỗi) | text | items[] — hỗ trợ dải */
function rwvp_collect_lines($p) {
    $text = '';
    if (isset($p['lines'])) { $text = (string)$p['lines']; }
    elseif (isset($p['text'])) { $text = (string)$p['text']; }
    elseif (isset($p['items']) && is_array($p['items'])) {
        foreach ($p['items'] as $it) {
            if (is_array($it)) {
                $text .= (isset($it['line']) ? (string)$it['line'] : (isset($it['proxy']) ? (string)$it['proxy'] : '')) . "\n";
            } else {
                $text .= (string)$it . "\n";
            }
        }
    }
    $raw_lines = preg_split('/\r\n|\r|\n/', $text);
    $out = array(); $errors = array(); $expanded = 0; $range_lines = 0;
    foreach ($raw_lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || substr($ln, 0, 1) === '#') { continue; }
        $ex = rwvp_expand_line($ln);
        if ($ex['error'] !== '') { $errors[] = array('line' => $ln, 'error' => $ex['error']); continue; }
        if ($ex['is_range']) {
            $expanded++;
            $range_lines += count($ex['lines']);
            if (count($out) + count($ex['lines']) > RWVP_MAX_EXPAND) {
                $errors[] = array('line' => $ln, 'error' => 'range_too_large', 'limit' => RWVP_MAX_EXPAND);
                continue;
            }
        }
        foreach ($ex['lines'] as $x) { $out[] = $x; }
    }
    return array('lines' => $out, 'errors' => $errors, 'ranges' => $expanded, 'range_lines' => $range_lines);
}

/* Phân loại: ok | dup (trùng trong lô / đã có trong DB) | error — KHÔNG trả mật khẩu */
function rwvp_classify($lines) {
    $db = rw_db();
    $st = $db->prepare("SELECT id FROM proxies WHERE proxy_key = ?");
    $seen = array(); $rows = array();
    $counts = array('ok' => 0, 'dup' => 0, 'error' => 0);
    foreach ($lines as $line) {
        $p = rwvp_parse_line($line);
        if (!$p['ok']) {
            $counts['error']++;
            $rows[] = array('line' => $line, 'status' => 'error', 'error' => $p['error']);
            continue;
        }
        $masked = rwvp_mask_key($p);
        if (isset($seen[$p['proxy_key']])) {
            $counts['dup']++;
            $rows[] = array('line' => $line, 'status' => 'dup', 'error' => 'duplicate_in_batch', 'proxy' => $masked);
            continue;
        }
        $st->execute(array($p['proxy_key']));
        if ($st->fetch()) {
            $counts['dup']++;
            $rows[] = array('line' => $line, 'status' => 'dup', 'error' => 'already_exists', 'proxy' => $masked);
            continue;
        }
        $seen[$p['proxy_key']] = true;
        $counts['ok']++;
        $rows[] = array('line' => $line, 'status' => 'ok', 'error' => '', 'proxy' => $masked,
            'scheme' => $p['scheme'], 'host' => $p['host'], 'port' => $p['port'], 'username' => $p['username']);
    }
    return array('rows' => $rows, 'counts' => $counts);
}

/* ===================== NHÓM MÀU (mục 3.4) ===================== */
function rwvp_color_for($proxy_id) {
    return (int)(sprintf('%u', crc32('proxy:' . (int)$proxy_id)) % 12);
}
function rwvp_label_for($proxy_id) {
    return 'P' . str_pad((string)(int)$proxy_id, 2, '0', STR_PAD_LEFT);
}
function rwvp_ensure_group($proxy_id) {
    $db = rw_db();
    $proxy_id = (int)$proxy_id;
    $st = $db->prepare("SELECT color_idx, label FROM proxy_groups WHERE proxy_id = ?");
    $st->execute(array($proxy_id));
    $r = $st->fetch();
    if ($r) {
        return array('color_idx' => (int)$r['color_idx'], 'label' => (string)$r['label']);
    }
    $color = rwvp_color_for($proxy_id);
    $label = rwvp_label_for($proxy_id);
    $st = $db->prepare("INSERT INTO proxy_groups(proxy_id, color_idx, label, created_at) VALUES(?,?,?,?) ON CONFLICT(proxy_id) DO NOTHING");
    $st->execute(array($proxy_id, $color, $label, rwvp_now()));
    return array('color_idx' => $color, 'label' => $label);
}

/* ===================== SỨC CHỨA / SUBNET ===================== */
function rwvp_max_profiles($proxy_row) {
    $setting = rwvp_int_setting('max_profiles_per_proxy', 1);
    $row = isset($proxy_row['max_profiles']) ? (int)$proxy_row['max_profiles'] : 0;
    return $row > 0 ? $row : $setting;
}
function rwvp_used_count($proxy_id, $exclude_account_id = 0) {
    $st = rw_db()->prepare("SELECT COUNT(*) c FROM accounts WHERE proxy_id = ? AND deleted_at IS NULL AND id != ?");
    $st->execute(array((int)$proxy_id, (int)$exclude_account_id));
    return (int)$st->fetch()['c'];
}

/* Ghi 1 proxy vào DB, trả {id, created} */
function rwvp_insert($p, $extra = array()) {
    $db = rw_db();
    $st = $db->prepare("SELECT id FROM proxies WHERE proxy_key = ?");
    $st->execute(array($p['proxy_key']));
    $row = $st->fetch();
    if ($row) { return array('id' => (int)$row['id'], 'created' => false); }
    $maxp = isset($extra['max_profiles']) ? (int)$extra['max_profiles'] : rwvp_int_setting('max_profiles_per_proxy', 1);
    if ($maxp <= 0) { $maxp = 1; }
    $label = isset($extra['label']) ? (string)$extra['label'] : '';
    $group_key = isset($extra['group_key']) ? (string)$extra['group_key'] : '';
    $country = isset($extra['country']) ? (string)$extra['country'] : '';
    $geo = isset($extra['geo']) ? (string)$extra['geo'] : '';
    $st = $db->prepare("INSERT INTO proxies(proxy_key, scheme, host, port, username, password_sealed, status, max_profiles, label, group_key, country, geo, created_at) VALUES(?,?,?,?,?,?,'unchecked',?,?,?,?,?,?)");
    $st->execute(array($p['proxy_key'], $p['scheme'], $p['host'], $p['port'], $p['username'],
        $p['password'], $maxp, $label, $group_key, $country, $geo, rwvp_now()));
    $id = (int)$db->lastInsertId();
    $g = rwvp_ensure_group($id);
    return array('id' => $id, 'created' => true, 'group' => $g);
}

/* Hàng proxy đã che + thông tin ghép nối cho bảng pool */
function rwvp_row_out($r, $used, $first_email, $maxp) {
    $over = $used > $maxp;
    return array(
        'id' => (int)$r['id'],
        'proxy' => rwvp_mask_key(array('scheme' => $r['scheme'], 'host' => $r['host'], 'port' => $r['port'], 'username' => $r['username'])),
        'scheme' => $r['scheme'], 'host' => $r['host'], 'port' => (int)$r['port'], 'username' => $r['username'],
        'label' => $r['label'], 'group_key' => $r['group_key'],
        'color_idx' => $r['color_idx'] !== null ? (int)$r['color_idx'] : rwvp_color_for($r['id']),
        'group_label' => $r['group_label'] !== null && $r['group_label'] !== '' ? $r['group_label'] : rwvp_label_for($r['id']),
        'status' => $r['status'], 'ip' => $r['ip'], 'exit_ip' => $r['exit_ip'],
        'geo' => $r['geo'], 'country' => $r['country'],
        'latency_ms' => $r['latency_ms'] !== null ? (int)$r['latency_ms'] : null,
        'fail_count' => (int)$r['fail_count'],
        'max_profiles' => $maxp, 'assigned_count' => (int)$used,
        'first_account_email' => $first_email !== null ? rw_mask_email($first_email) : null,
        'over_capacity' => $over, 'subnet' => rwvp_subnet($r['host']),
        'checked_note' => $r['checked_note'], 'last_checked' => $r['last_checked'], 'created_at' => $r['created_at']);
}

/* ===================== XỬ LÝ ACTION ===================== */
$p_in = rwvp_input();
$action = rwvp_action_name($p_in);
$db = rw_db();

switch ($action) {
    /* ---------------- 3.1 POOL PROXY ---------------- */
    case 'list': {
        $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
        $per_page = (int)(isset($_GET['per_page']) ? $_GET['per_page'] : (isset($_GET['limit']) ? $_GET['limit'] : 50));
        $per_page = max(1, min(500, $per_page > 0 ? $per_page : 50));
        $filters = array();
        $where = array('1=1'); $args = array();
        $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
        if ($status !== '') { $where[] = "p.status = ?"; $args[] = $status; $filters['status'] = $status; }
        $label = isset($_GET['label']) ? trim((string)$_GET['label']) : '';
        if ($label !== '') { $where[] = "p.label = ?"; $args[] = $label; $filters['label'] = $label; }
        $group_key = isset($_GET['group_key']) ? trim((string)$_GET['group_key']) : '';
        if ($group_key !== '') { $where[] = "p.group_key = ?"; $args[] = $group_key; $filters['group_key'] = $group_key; }
        $country = isset($_GET['country']) ? trim((string)$_GET['country']) : '';
        if ($country !== '') { $where[] = "p.country = ?"; $args[] = $country; $filters['country'] = $country; }
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q !== '') {
            $where[] = "(p.host LIKE ? OR p.ip LIKE ? OR p.exit_ip LIKE ? OR p.label LIKE ? OR p.proxy_key LIKE ? OR p.group_key LIKE ?)";
            for ($i = 0; $i < 6; $i++) { $args[] = '%' . $q . '%'; }
            $filters['q'] = $q;
        }
        $assigned = isset($_GET['assigned']) ? strtolower(trim((string)$_GET['assigned'])) : '';
        if ($assigned === 'busy') {
            $where[] = "EXISTS(SELECT 1 FROM accounts a WHERE a.proxy_id = p.id AND a.deleted_at IS NULL)";
            $filters['assigned'] = 'busy';
        } elseif ($assigned === 'free') {
            $where[] = "NOT EXISTS(SELECT 1 FROM accounts a WHERE a.proxy_id = p.id AND a.deleted_at IS NULL)";
            $filters['assigned'] = 'free';
        }
        $where_sql = implode(' AND ', $where);
        $st = $db->prepare("SELECT COUNT(*) c FROM proxies p WHERE $where_sql");
        $st->execute($args);
        $total = (int)$st->fetch()['c'];
        $st = $db->prepare(
            "SELECT p.*, g.color_idx AS g_color, g.label AS g_label,
                    (SELECT COUNT(*) FROM accounts a WHERE a.proxy_id = p.id AND a.deleted_at IS NULL) AS used_count,
                    (SELECT a.email FROM accounts a WHERE a.proxy_id = p.id AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1) AS first_email
             FROM proxies p LEFT JOIN proxy_groups g ON g.proxy_id = p.id
             WHERE $where_sql ORDER BY p.id LIMIT ? OFFSET ?");
        $args2 = $args; $args2[] = $per_page; $args2[] = ($page - 1) * $per_page;
        $st->execute($args2);
        $rows = array();
        foreach ($st->fetchAll() as $r) {
            $r['color_idx'] = $r['g_color'];
            $r['group_label'] = $r['g_label'];
            $maxp = rwvp_max_profiles($r);
            $rows[] = rwvp_row_out($r, (int)$r['used_count'], $r['first_email'], $maxp);
        }
        rw_json(array(
            'total' => $total, 'page' => $page, 'per_page' => $per_page,
            'filters_applied' => $filters, 'generated_at' => rwvp_generated(),
            'settings' => array(
                'max_profiles_per_proxy' => rwvp_int_setting('max_profiles_per_proxy', 1),
                'require_proxy' => rwvp_setting('require_proxy', '1'),
                'block_same_subnet' => rwvp_setting('block_same_subnet', 'warn')),
            'proxies' => $rows));
        break;
    }

    /* ---------------- 3.2 THÊM 1 PROXY ---------------- */
    case 'add': {
        rwvp_post_only();
        $p = $p_in;
        $line = isset($p['line']) ? trim((string)$p['line']) : '';
        if ($line === '') {
            $scheme = isset($p['scheme']) ? strtolower(preg_replace('/[^a-z]/', '', (string)$p['scheme'])) : 'http';
            if (!in_array($scheme, array('http', 'https', 'socks4', 'socks5'), true)) {
                rw_json(array('error' => 'invalid_scheme', 'allowed' => array('http', 'https', 'socks4', 'socks5')), 422);
            }
            $host = isset($p['host']) ? trim((string)$p['host']) : '';
            $port = isset($p['port']) ? (int)$p['port'] : 0;
            $user = isset($p['username']) ? (string)$p['username'] : '';
            $pass = isset($p['password_sealed']) ? (string)$p['password_sealed'] : (isset($p['password']) ? (string)$p['password'] : '');
            $line = $scheme . '://' . ($user !== '' ? $user . ':' . $pass . '@' : '') . $host . ':' . $port;
        }
        $parsed = rwvp_parse_line($line);
        if (!$parsed['ok']) { rw_json(array('error' => $parsed['error'], 'line' => $line), 422); }
        $extra = array(
            'label' => isset($p['label']) ? mb_substr(trim((string)$p['label']), 0, 60) : '',
            'group_key' => isset($p['group_key']) ? mb_substr(trim((string)$p['group_key']), 0, 60) : '',
            'max_profiles' => isset($p['max_profiles']) ? (int)$p['max_profiles'] : rwvp_int_setting('max_profiles_per_proxy', 1),
            'country' => isset($p['country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p['country'])) : '',
            'geo' => isset($p['geo']) ? mb_substr(trim((string)$p['geo']), 0, 40) : '');
        $res = rwvp_insert($parsed, $extra);
        rwvp_audit('proxy_add', 'proxy', $res['id'], array('proxy' => rwvp_mask_key($parsed), 'label' => $extra['label']));
        rw_json(array('ok' => true, 'proxy_id' => $res['id'], 'created' => $res['created'],
            'proxy' => rwvp_mask_key($parsed), 'group' => rwvp_ensure_group($res['id']),
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.2 XEM TRƯỚC NHẬP HÀNG LOẠT ---------------- */
    case 'bulk_preview': {
        rwvp_post_only();
        $col = rwvp_collect_lines($p_in);
        $cl = rwvp_classify($col['lines']);
        rw_json(array(
            'ok' => true,
            'total' => count($col['lines']),
            'counts' => $cl['counts'],
            'preview' => rwvp_mask_rows($cl['rows']),
            'range_errors' => rwvp_mask_errors($col['errors']),
            'ranges_expanded' => $col['ranges'],
            'filters_applied' => array('ranges' => $col['ranges']),
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.2 NHẬP HÀNG LOẠT (kể cả dải) ---------------- */
    case 'bulk_add': {
        rwvp_post_only();
        $col = rwvp_collect_lines($p_in);
        $cl = rwvp_classify($col['lines']);
        $label = isset($p_in['label']) ? mb_substr(trim((string)$p_in['label']), 0, 60) : '';
        $group_key = isset($p_in['group_key']) ? mb_substr(trim((string)$p_in['group_key']), 0, 60) : '';
        $maxp = isset($p_in['max_profiles']) ? (int)$p_in['max_profiles'] : rwvp_int_setting('max_profiles_per_proxy', 1);
        $added = 0; $skipped = 0; $errors = $col['errors']; $ids = array();
        $db->beginTransaction();
        try {
            foreach ($cl['rows'] as $row) {
                if ($row['status'] === 'dup') { $skipped++; continue; }
                if ($row['status'] === 'error') { $skipped++; $errors[] = array('line' => $row['line'], 'error' => $row['error']); continue; }
                $parsed = rwvp_parse_line($row['line']);
                if (!$parsed['ok']) { $skipped++; $errors[] = array('line' => $row['line'], 'error' => $parsed['error']); continue; }
                $res = rwvp_insert($parsed, array('label' => $label, 'group_key' => $group_key, 'max_profiles' => $maxp));
                if ($res['created']) { $added++; $ids[] = $res['id']; } else { $skipped++; }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'bulk_add_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvp_audit('proxy_bulk_add', 'proxies', implode(',', array_slice($ids, 0, 20)),
            array('added' => $added, 'skipped' => $skipped, 'label' => $label, 'ranges' => $col['ranges']));
        rw_json(array('ok' => true, 'added' => $added, 'skipped' => $skipped,
            'errors' => rwvp_mask_errors(array_slice($errors, 0, 100)), 'proxy_ids' => array_slice($ids, 0, 200),
            'ranges_expanded' => $col['ranges'], 'total' => count($col['lines']),
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.2 BUNG DẢI (xem trước số lượng) ---------------- */
    case 'range_expand': {
        rwvp_post_only();
        $col = rwvp_collect_lines($p_in);
        $cl = rwvp_classify($col['lines']);
        rw_json(array(
            'ok' => true,
            'count' => count($col['lines']),
            'total' => count($col['lines']),
            'items' => rwvp_mask_rows($cl['rows']),
            'counts' => $cl['counts'],
            'ranges_expanded' => $col['ranges'],
            'range_errors' => rwvp_mask_errors($col['errors']),
            'truncated' => count($col['lines']) >= RWVP_MAX_EXPAND,
            'limit' => RWVP_MAX_EXPAND,
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.1 TEST PROXY ----------------
     * mode=worker (mặc định, đúng mục 3.1): xếp job cho worker test thật
     * mode=local: test TCP nhanh từ hosting (chẩn đoán), giới hạn RWVP_MAX_LOCAL_TEST
     * results[]: worker báo kết quả {proxy_id,status,latency_ms,exit_ip,error}
     */
    case 'test': {
        rwvp_post_only();
        $p = $p_in;
        if (isset($p['results']) && is_array($p['results'])) {
            $updated = 0;
            foreach ($p['results'] as $r) {
                $pid = isset($r['proxy_id']) ? (int)$r['proxy_id'] : 0;
                if ($pid <= 0) { continue; }
                $status = isset($r['status']) ? strtolower(preg_replace('/[^a-z_]/', '', (string)$r['status'])) : '';
                if (!in_array($status, array('alive', 'slow', 'dead', 'unchecked'), true)) { continue; }
                $lat = isset($r['latency_ms']) ? (int)$r['latency_ms'] : null;
                $exit_ip = isset($r['exit_ip']) ? trim((string)$r['exit_ip']) : '';
                $err = isset($r['error']) ? mb_substr((string)$r['error'], 0, 200) : '';
                $st = $db->prepare("UPDATE proxies SET status = ?, latency_ms = ?, exit_ip = CASE WHEN ? != '' THEN ? ELSE exit_ip END, ip = CASE WHEN ? != '' THEN ? ELSE ip END, last_checked = ?, checked_note = ?, fail_count = CASE WHEN ? = 'dead' THEN fail_count + 1 ELSE 0 END WHERE id = ?");
                $st->execute(array($status, $lat, $exit_ip, $exit_ip, $exit_ip, $exit_ip, rwvp_now(), $err, $status, $pid));
                rwvp_history(null, $pid, 'test_result', $status . ' ' . $lat . 'ms ' . $exit_ip . ' ' . $err);
                $updated++;
            }
            rwvp_audit('proxy_test_result', 'proxies', '', array('updated' => $updated));
            rw_json(array('ok' => true, 'updated' => $updated, 'generated_at' => rwvp_generated()));
        }
        $ids = array();
        if (isset($p['proxy_id'])) { $ids[] = (int)$p['proxy_id']; }
        if (isset($p['proxy_ids']) && is_array($p['proxy_ids'])) {
            foreach ($p['proxy_ids'] as $x) { $ids[] = (int)$x; }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $all = isset($p['all']) && ($p['all'] === true || $p['all'] === 1 || $p['all'] === '1');
        if (!count($ids) && !$all) { rw_json(array('error' => 'proxy_id_required'), 422); }
        if ($all) {
            $ids = array();
            foreach ($db->query("SELECT id FROM proxies ORDER BY id")->fetchAll() as $r) { $ids[] = (int)$r['id']; }
        }
        $mode = isset($p['mode']) ? strtolower((string)$p['mode']) : 'worker';
        if ($mode === 'local') {
            if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
            $ids = array_slice($ids, 0, RWVP_MAX_LOCAL_TEST);
            $timeout = isset($p['timeout']) ? max(1, min(15, (int)$p['timeout'])) : 5;
            $slow_ms = isset($p['slow_ms']) ? (int)$p['slow_ms'] : 2000;
            $out = array();
            foreach ($ids as $pid) {
                $st = $db->prepare("SELECT * FROM proxies WHERE id = ?");
                $st->execute(array($pid));
                $pr = $st->fetch();
                if (!$pr) { $out[] = array('proxy_id' => $pid, 'error' => 'proxy_not_found'); continue; }
                $t0 = microtime(true);
                $errno = 0; $errstr = '';
                $fp = @fsockopen($pr['host'], (int)$pr['port'], $errno, $errstr, $timeout);
                $lat = $fp ? (int)round((microtime(true) - $t0) * 1000) : 0;
                $status = $fp ? ($lat > $slow_ms ? 'slow' : 'alive') : 'dead';
                if ($fp) { fclose($fp); }
                $st = $db->prepare("UPDATE proxies SET status = ?, latency_ms = ?, last_checked = ?, checked_note = ?, fail_count = CASE WHEN ? = 'dead' THEN fail_count + 1 ELSE 0 END WHERE id = ?");
                $st->execute(array($status, $lat, rwvp_now(), $errstr !== '' ? mb_substr($errstr, 0, 200) : 'local tcp check', $status, $pid));
                rwvp_history(null, $pid, 'test_local', $status . ' ' . $lat . 'ms');
                $out[] = array('proxy_id' => $pid, 'status' => $status, 'latency_ms' => $lat, 'error' => $errstr !== '' ? $errstr : null);
            }
            rwvp_audit('proxy_test_local', 'proxies', '', array('count' => count($out)));
            rw_json(array('ok' => true, 'mode' => 'local', 'results' => $out, 'generated_at' => rwvp_generated()));
        }
        // mode=worker: xếp job, worker sẽ test thật rồi báo qua action=test&results[]
        $jobs = array();
        foreach ($ids as $pid) {
            $job_key = 'proxytest-' . $pid . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $st = $db->prepare("INSERT INTO jobs(job_key, device_id, account_id, action, args_json, status, priority, timeout_s, created_at) VALUES(?,NULL,NULL,'proxy_test',?,'pending',5,120,?) ON CONFLICT(job_key) DO NOTHING");
            $st->execute(array($job_key, json_encode(array('proxy_id' => $pid), JSON_UNESCAPED_UNICODE), rwvp_now()));
            $st = $db->prepare("UPDATE proxies SET checked_note = ? WHERE id = ?");
            $st->execute(array('queued worker test ' . rwvp_now(), $pid));
            rwvp_history(null, $pid, 'test_queued', $job_key);
            $jobs[] = array('proxy_id' => $pid, 'job_key' => $job_key);
        }
        rwvp_audit('proxy_test', 'proxies', '', array('mode' => 'worker', 'count' => count($jobs)));
        rw_json(array('ok' => true, 'mode' => 'worker', 'queued' => count($jobs), 'jobs' => $jobs,
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.1 GỠ KHỎI POOL ---------------- */
    case 'remove':
    case 'delete': {
        rwvp_post_only();
        rwvp_admin_only();
        $p = $p_in;
        $ids = array();
        if (isset($p['proxy_id'])) { $ids[] = (int)$p['proxy_id']; }
        if (isset($p['proxy_ids']) && is_array($p['proxy_ids'])) {
            foreach ($p['proxy_ids'] as $x) { $ids[] = (int)$x; }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!count($ids)) { rw_json(array('error' => 'proxy_id_required'), 422); }
        $removed = 0; $freed = 0;
        foreach ($ids as $pid) {
            $st = $db->prepare("SELECT COUNT(*) c FROM accounts WHERE proxy_id = ? AND deleted_at IS NULL");
            $st->execute(array($pid));
            $n = (int)$st->fetch()['c'];
            $st = $db->prepare("UPDATE accounts SET proxy_id = NULL, updated_at = ? WHERE proxy_id = ?");
            $st->execute(array(rwvp_now(), $pid));
            $st = $db->prepare("UPDATE assignments SET proxy_id = NULL, updated_at = ? WHERE proxy_id = ?");
            $st->execute(array(rwvp_now(), $pid));
            rwvp_history(null, $pid, 'proxy_removed', 'freed=' . $n);
            $st = $db->prepare("DELETE FROM proxy_groups WHERE proxy_id = ?");
            $st->execute(array($pid));
            $st = $db->prepare("DELETE FROM proxies WHERE id = ?");
            $st->execute(array($pid));
            $removed += $st->rowCount();
            $freed += $n;
        }
        rwvp_audit('proxy_remove', 'proxies', implode(',', $ids), array('removed' => $removed, 'freed_accounts' => $freed));
        rw_json(array('ok' => true, 'removed' => $removed, 'freed_accounts' => $freed,
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.3 GÁN PROXY → ACCOUNT ---------------- */
    case 'assign': {
        rwvp_post_only();
        $p = $p_in;
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        $proxy_id = isset($p['proxy_id']) ? (int)$p['proxy_id'] : 0;
        if ($acc_id <= 0 || $proxy_id <= 0) {
            rw_json(array('error' => 'account_id_and_proxy_id_required'), 422);
        }
        $override = isset($p['override']) && ($p['override'] === true || $p['override'] === 1 || $p['override'] === '1');
        $sticky = !isset($p['sticky']) || $p['sticky'] === true || $p['sticky'] === 1 || $p['sticky'] === '1';
        $st = $db->prepare("SELECT id, email, device_id, proxy_id, status FROM accounts WHERE id = ? AND deleted_at IS NULL");
        $st->execute(array($acc_id));
        $acc = $st->fetch();
        if (!$acc) { rw_json(array('error' => 'account_not_found'), 404); }
        $st = $db->prepare("SELECT * FROM proxies WHERE id = ?");
        $st->execute(array($proxy_id));
        $pr = $st->fetch();
        if (!$pr) { rw_json(array('error' => 'proxy_not_found'), 404); }
        $maxp = rwvp_max_profiles($pr);
        $used = rwvp_used_count($proxy_id, $acc_id);
        $warnings = array();
        if ($used >= $maxp) {
            if (!$override) {
                rw_json(array('error' => 'proxy_capacity_full', 'max_profiles' => $maxp,
                    'used' => $used, 'hint' => 'admin có thể gán đè với override=true (ghi audit)'), 409);
            }
            if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
            $warnings[] = array('code' => 'override_capacity', 'max_profiles' => $maxp, 'used' => $used);
        }
        $block_subnet = rwvp_setting('block_same_subnet', 'warn');
        if ($block_subnet !== '0' && $block_subnet !== '' && $block_subnet !== 'off') {
            $sn = rwvp_subnet($pr['host']);
            if ($sn !== '') {
                $st = $db->prepare("SELECT a.id, a.email, p.host FROM accounts a JOIN proxies p ON p.id = a.proxy_id WHERE a.deleted_at IS NULL AND a.id != ? AND a.device_id IS ? LIMIT 5");
                $st->execute(array($acc_id, $acc['device_id']));
                foreach ($st->fetchAll() as $other) {
                    if (rwvp_subnet($other['host']) === $sn) {
                        if ($block_subnet === 'block') {
                            rw_json(array('error' => 'same_subnet_blocked', 'subnet' => $sn,
                                'conflict_account_id' => (int)$other['id'], 'conflict_email' => rw_mask_email($other['email'])), 409);
                        }
                        $warnings[] = array('code' => 'same_subnet', 'subnet' => $sn,
                            'conflict_account_id' => (int)$other['id']);
                    }
                }
            }
        }
        $db->beginTransaction();
        try {
            $st = $db->prepare("UPDATE accounts SET proxy_id = ?, updated_at = ? WHERE id = ?");
            $st->execute(array($proxy_id, rwvp_now(), $acc_id));
            $st = $db->prepare("SELECT id FROM assignments WHERE account_id = ?");
            $st->execute(array($acc_id));
            $a = $st->fetch();
            if ($a) {
                $st = $db->prepare("UPDATE assignments SET proxy_id = ?, device_id = ?, sticky = ?, updated_at = ? WHERE account_id = ?");
                $st->execute(array($proxy_id, $acc['device_id'], $sticky ? 1 : 0, rwvp_now(), $acc_id));
            } else {
                $st = $db->prepare("INSERT INTO assignments(account_id, device_id, proxy_id, phone_id, created_at, since, sticky, updated_at) VALUES(?,?,?,NULL,?,?,?,?)");
                $st->execute(array($acc_id, $acc['device_id'], $proxy_id, rwvp_now(), rwvp_now(), $sticky ? 1 : 0, rwvp_now()));
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'assign_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvp_history($acc_id, $proxy_id, 'assign', 'sticky=' . ($sticky ? 1 : 0));
        rwvp_audit('proxy_assign', 'account', $acc_id, array('proxy_id' => $proxy_id, 'override' => $override, 'warnings' => $warnings));
        rw_json(array('ok' => true, 'account_id' => $acc_id, 'proxy_id' => $proxy_id,
            'sticky' => $sticky, 'warnings' => $warnings, 'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.3 TỰ ĐỘNG GÁN (xem trước / áp dụng) ---------------- */
    case 'auto_assign_preview':
    case 'auto_assign_apply': {
        $apply = ($action === 'auto_assign_apply');
        if ($apply) { rwvp_post_only(); }
        $p = $p_in;
        $mode = isset($p['mode']) ? strtolower(preg_replace('/[^a-z_]/', '', (string)$p['mode'])) : 'sequential';
        if (!in_array($mode, array('sequential', 'random', 'geo', 'avoid_subnet'), true)) {
            rw_json(array('error' => 'invalid_mode', 'allowed' => array('sequential', 'random', 'geo', 'avoid_subnet')), 422);
        }
        $include_dead = isset($p['include_dead']) && ($p['include_dead'] === true || $p['include_dead'] === 1 || $p['include_dead'] === '1');
        // danh sách account cần proxy
        $acc_args = array(); $acc_sql = "SELECT id, email, device_id, proxy_id, status, notes, tags, group_label FROM accounts WHERE deleted_at IS NULL AND (proxy_id IS NULL OR proxy_id = 0)";
        if (isset($p['account_ids']) && is_array($p['account_ids']) && count($p['account_ids'])) {
            $ph = array();
            foreach ($p['account_ids'] as $x) { $ph[] = '?'; $acc_args[] = (int)$x; }
            $acc_sql = "SELECT id, email, device_id, proxy_id, status, notes, tags, group_label FROM accounts WHERE deleted_at IS NULL AND id IN (" . implode(',', $ph) . ")";
        }
        $acc_sql .= " ORDER BY id";
        $st = $db->prepare($acc_sql);
        $st->execute($acc_args);
        $accounts = $st->fetchAll();
        // pool proxy rảnh
        $proxy_sql = "SELECT * FROM proxies " . ($include_dead ? "" : "WHERE status != 'dead' ") . "ORDER BY id";
        $proxies = array();
        foreach ($db->query($proxy_sql)->fetchAll() as $pr) {
            $maxp = rwvp_max_profiles($pr);
            if (rwvp_used_count($pr['id']) >= $maxp) { continue; }
            $proxies[] = $pr;
        }
        if ($mode === 'random') {
            $seed = isset($p['seed']) ? (string)$p['seed'] : date('Ymd');
            mt_srand(crc32($seed));
            $shuffled = array();
            $idx = array_keys($proxies);
            while (count($idx)) {
                $k = mt_rand(0, count($idx) - 1);
                $shuffled[] = $proxies[$idx[$k]];
                array_splice($idx, $k, 1);
            }
            $proxies = $shuffled;
        }
        $pairs = array(); $skipped = array();
        $used_subnets = array(); // device_id => subnet => true (avoid_subnet)
        $pi = 0;
        foreach ($accounts as $acc) {
            $picked = null; $reason = $mode;
            if ($mode === 'geo') {
                $want = '';
                $blob = strtoupper((string)$acc['tags'] . ' ' . (string)$acc['group_label'] . ' ' . (string)$acc['notes']);
                foreach (array('VN', 'US', 'GB', 'IN', 'ID', 'PH', 'TH', 'MY', 'SG', 'CN', 'JP', 'KR', 'DE', 'FR', 'BR', 'RU') as $cc) {
                    if (preg_match('/\b' . $cc . '\b/', $blob)) { $want = $cc; break; }
                }
                foreach ($proxies as $k => $pr) {
                    if ($want !== '' && strtoupper((string)$pr['country']) === $want) { $picked = $k; break; }
                }
                if ($picked === null) {
                    $picked = $pi < count($proxies) ? $pi : null;
                    $reason = $want !== '' ? 'geo_fallback' : 'no_geo_match';
                }
            } elseif ($mode === 'avoid_subnet') {
                foreach ($proxies as $k => $pr) {
                    $sn = rwvp_subnet($pr['host']);
                    $dev = $acc['device_id'] === null ? 'none' : (string)$acc['device_id'];
                    if ($sn !== '' && isset($used_subnets[$dev][$sn])) { continue; }
                    $picked = $k;
                    if ($sn !== '') { $used_subnets[$dev][$sn] = true; }
                    break;
                }
                if ($picked === null) {
                    $picked = $pi < count($proxies) ? $pi : null;
                    $reason = 'subnet_exhausted';
                }
            } else {
                $picked = $pi < count($proxies) ? $pi : null;
            }
            if ($picked === null) {
                $skipped[] = array('account_id' => (int)$acc['id'], 'email' => rw_mask_email($acc['email']), 'reason' => 'no_free_proxy');
                continue;
            }
            $pr = $proxies[$picked];
            $pairs[] = array(
                'account_id' => (int)$acc['id'], 'email' => rw_mask_email($acc['email']),
                'proxy_id' => (int)$pr['id'], 'proxy' => rwvp_mask_key($pr),
                'device_id' => $acc['device_id'] !== null ? (int)$acc['device_id'] : null,
                'rule' => $reason);
            $maxp = rwvp_max_profiles($pr);
            if (rwvp_used_count($pr['id']) + 1 >= $maxp) { array_splice($proxies, $picked, 1); $pi = 0; }
            else { $pi = $picked + 1; }
        }
        if (!$apply) {
            rw_json(array('ok' => true, 'mode' => $mode, 'preview' => true,
                'total' => count($pairs), 'pairs' => $pairs, 'skipped' => $skipped,
                'counts' => array('assign' => count($pairs), 'skip' => count($skipped)),
                'filters_applied' => array('mode' => $mode), 'generated_at' => rwvp_generated()));
        }
        $done = 0;
        $db->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $acc_id = $pair['account_id']; $proxy_id = $pair['proxy_id'];
                $st = $db->prepare("UPDATE accounts SET proxy_id = ?, updated_at = ? WHERE id = ?");
                $st->execute(array($proxy_id, rwvp_now(), $acc_id));
                $st = $db->prepare("SELECT id FROM assignments WHERE account_id = ?");
                $st->execute(array($acc_id));
                if ($st->fetch()) {
                    $st = $db->prepare("UPDATE assignments SET proxy_id = ?, device_id = ?, sticky = 1, updated_at = ? WHERE account_id = ?");
                    $st->execute(array($proxy_id, $pair['device_id'], rwvp_now(), $acc_id));
                } else {
                    $st = $db->prepare("INSERT INTO assignments(account_id, device_id, proxy_id, phone_id, created_at, since, sticky, updated_at) VALUES(?,?,?,NULL,?,?,1,?)");
                    $st->execute(array($acc_id, $pair['device_id'], $proxy_id, rwvp_now(), rwvp_now(), rwvp_now()));
                }
                rwvp_history($acc_id, $proxy_id, 'auto_assign', 'mode=' . $mode . ' rule=' . $pair['rule']);
                $done++;
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'auto_assign_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvp_audit('proxy_auto_assign', 'proxies', '', array('mode' => $mode, 'assigned' => $done, 'skipped' => count($skipped)));
        rw_json(array('ok' => true, 'mode' => $mode, 'assigned' => $done, 'skipped' => $skipped,
            'pairs' => $pairs, 'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.1/3.3 GIẢI PHÓNG (release) ---------------- */
    case 'release': {
        rwvp_post_only();
        $p = $p_in;
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        $proxy_id = isset($p['proxy_id']) ? (int)$p['proxy_id'] : 0;
        if ($acc_id <= 0 && $proxy_id <= 0) {
            rw_json(array('error' => 'account_id_or_proxy_id_required'), 422);
        }
        $freed = 0;
        if ($acc_id > 0) {
            $st = $db->prepare("UPDATE accounts SET proxy_id = NULL, updated_at = ? WHERE id = ? AND proxy_id IS NOT NULL");
            $st->execute(array(rwvp_now(), $acc_id));
            $freed = $st->rowCount();
            $st = $db->prepare("UPDATE assignments SET proxy_id = NULL, updated_at = ? WHERE account_id = ?");
            $st->execute(array(rwvp_now(), $acc_id));
            rwvp_history($acc_id, $proxy_id > 0 ? $proxy_id : null, 'release', '');
        }
        if ($proxy_id > 0) {
            $st = $db->prepare("UPDATE accounts SET proxy_id = NULL, updated_at = ? WHERE proxy_id = ?");
            $st->execute(array(rwvp_now(), $proxy_id));
            $freed += $st->rowCount();
            $st = $db->prepare("UPDATE assignments SET proxy_id = NULL, updated_at = ? WHERE proxy_id = ?");
            $st->execute(array(rwvp_now(), $proxy_id));
            rwvp_history($acc_id > 0 ? $acc_id : null, $proxy_id, 'release', 'freed=' . $freed);
        }
        rwvp_audit('proxy_release', 'proxies', implode(',', array_filter(array($acc_id, $proxy_id))), array('freed' => $freed));
        rw_json(array('ok' => true, 'freed_accounts' => $freed, 'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- 3.4 THÔNG TIN NHÓM / MÀU ---------------- */
    case 'group_info': {
        $st = $db->prepare("SELECT id, email, device_id, status, proxy_id FROM accounts WHERE deleted_at IS NULL");
        $st->execute();
        $accs = $st->fetchAll();
        $proxies = array();
        foreach ($db->query("SELECT * FROM proxies ORDER BY id")->fetchAll() as $pr) {
            $g = rwvp_ensure_group($pr['id']);
            $pr['color_idx'] = $g['color_idx'];
            $pr['group_label'] = $g['label'];
            $pr['_max'] = rwvp_max_profiles($pr);
            $pr['_used'] = 0;
            $pr['_accounts'] = array();
            $proxies[(int)$pr['id']] = $pr;
        }
        $by_proxy = array();
        $unassigned = 0;
        foreach ($accs as $a) {
            if ($a['proxy_id'] === null || (int)$a['proxy_id'] === 0) { $unassigned++; continue; }
            $pid = (int)$a['proxy_id'];
            if (!isset($proxies[$pid])) { $unassigned++; continue; }
            $proxies[$pid]['_used']++;
            $proxies[$pid]['_accounts'][] = array('id' => (int)$a['id'], 'email' => rw_mask_email($a['email']),
                'device_id' => $a['device_id'] !== null ? (int)$a['device_id'] : null, 'status' => $a['status']);
            $by_proxy[$pid][] = (int)$a['id'];
        }
        $groups = array();
        $used_colors = array();
        // sắp theo kích thước giảm dần để nhóm nhỏ phải đổi màu khi trùng (mục 3.4)
        $order = array();
        foreach ($by_proxy as $pid => $list) {
            if (count($list) < 2) { continue; }
            $order[] = $pid;
        }
        usort($order, function ($x, $y) use ($by_proxy) {
            $d = count($by_proxy[$y]) - count($by_proxy[$x]);
            return $d !== 0 ? $d : ($x - $y);
        });
        foreach ($order as $pid) {
            $pr = $proxies[$pid];
            $size = count($by_proxy[$pid]);
            $color = (int)$pr['color_idx'];
            if (isset($used_colors[$color])) {
                for ($c = 0; $c < 12; $c++) {
                    if (!isset($used_colors[$c])) { $color = $c; break; }
                }
            }
            $used_colors[$color] = true;
            $maxp = $pr['_max'];
            $groups[] = array(
                'type' => 'proxy', 'proxy_id' => $pid,
                'label' => $pr['group_label'], 'color_idx' => $color,
                'size' => $size,
                'proxy' => rwvp_mask_key($pr),
                'status' => $pr['status'], 'latency_ms' => $pr['latency_ms'] !== null ? (int)$pr['latency_ms'] : null,
                'exit_ip' => $pr['exit_ip'], 'subnet' => rwvp_subnet($pr['host']),
                'dead' => $pr['status'] === 'dead',
                'blocked_accounts' => $pr['status'] === 'dead' ? $size : 0,
                'capacity' => array('used' => $pr['_used'], 'max' => $maxp, 'over' => $pr['_used'] > $maxp),
                'accounts' => $pr['_accounts']);
        }
        // trùng IP thoát thực tế (mục 3.4 mức 2)
        $by_ip = array();
        foreach ($proxies as $pr) {
            $ip = trim((string)$pr['exit_ip']);
            if ($ip === '') { continue; }
            $by_ip[$ip][] = (int)$pr['id'];
        }
        $exit_groups = array();
        foreach ($by_ip as $ip => $pids) {
            if (count($pids) < 2) { continue; }
            $acc_ids = array();
            foreach ($pids as $pid) {
                if (isset($by_proxy[$pid])) { $acc_ids = array_merge($acc_ids, $by_proxy[$pid]); }
            }
            $exit_groups[] = array('type' => 'exit_ip', 'exit_ip' => $ip, 'proxy_ids' => $pids,
                'size' => count($acc_ids), 'account_ids' => $acc_ids, 'label' => 'cùng IP');
        }
        // cảnh báo mềm cùng /24 (mục 3.4 mức 3)
        $by_sn = array();
        foreach ($proxies as $pr) {
            $sn = rwvp_subnet($pr['host']);
            if ($sn === '') { continue; }
            $by_sn[$sn][] = (int)$pr['id'];
        }
        $subnet_warnings = array();
        foreach ($by_sn as $sn => $pids) {
            if (count($pids) < 2) { continue; }
            $acc_ids = array();
            foreach ($pids as $pid) {
                if (isset($by_proxy[$pid])) { $acc_ids = array_merge($acc_ids, $by_proxy[$pid]); }
            }
            $subnet_warnings[] = array('subnet' => $sn, 'proxy_ids' => $pids,
                'proxy_count' => count($pids), 'account_count' => count($acc_ids));
        }
        $shared_accounts = 0; $dead_proxies = 0; $dead_blocked = 0;
        foreach ($groups as $g) {
            $shared_accounts += $g['size'];
            if ($g['dead']) { $dead_proxies++; $dead_blocked += $g['size']; }
        }
        foreach ($proxies as $pr) {
            if ($pr['status'] === 'dead' && count($pr['_accounts']) && count($pr['_accounts']) < 2) {
                $dead_proxies++; $dead_blocked += count($pr['_accounts']);
            }
        }
        $legend = array(
            'total_accounts' => count($accs),
            'distinct_proxies' => count($by_proxy),
            'shared_groups' => count($groups),
            'shared_accounts' => $shared_accounts,
            'unassigned_accounts' => $unassigned,
            'dead_proxies' => $dead_proxies,
            'dead_blocked_accounts' => $dead_blocked);
        rw_json(array(
            'ok' => true, 'total' => count($groups), 'page' => 1,
            'filters_applied' => array(), 'generated_at' => rwvp_generated(),
            'groups' => $groups, 'exit_ip_groups' => $exit_groups, 'subnet_warnings' => $subnet_warnings,
            'legend' => $legend, 'unassigned_accounts' => $unassigned,
            'settings' => array(
                'max_profiles_per_proxy' => rwvp_int_setting('max_profiles_per_proxy', 1),
                'require_proxy' => rwvp_setting('require_proxy', '1'),
                'block_same_subnet' => rwvp_setting('block_same_subnet', 'warn'))));
        break;
    }

    /* ---------------- 3.3 THAY PROXY CHẾT ---------------- */
    case 'replace_dead': {
        $apply = isset($p_in['apply']) && ($p_in['apply'] === true || $p_in['apply'] === 1 || $p_in['apply'] === '1');
        if ($apply) { rwvp_post_only(); }
        $dead = array();
        foreach ($db->query("SELECT * FROM proxies WHERE status = 'dead' ORDER BY id")->fetchAll() as $pr) {
            $dead[(int)$pr['id']] = $pr;
        }
        $st = $db->prepare("SELECT id, email, device_id, proxy_id FROM accounts WHERE deleted_at IS NULL AND proxy_id IS NOT NULL ORDER BY id");
        $st->execute();
        $accs = $st->fetchAll();
        // pool proxy sống còn chỗ
        $free = array();
        foreach ($db->query("SELECT * FROM proxies WHERE status != 'dead' ORDER BY id")->fetchAll() as $pr) {
            $maxp = rwvp_max_profiles($pr);
            if (rwvp_used_count($pr['id']) >= $maxp) { continue; }
            $free[] = $pr;
        }
        $pairs = array(); $skipped = array(); $fi = 0;
        foreach ($accs as $acc) {
            $pid = (int)$acc['proxy_id'];
            if (!isset($dead[$pid])) { continue; }
            if ($fi >= count($free)) {
                $skipped[] = array('account_id' => (int)$acc['id'], 'email' => rw_mask_email($acc['email']),
                    'reason' => 'no_free_proxy', 'dead_proxy_id' => $pid);
                continue;
            }
            $np = $free[$fi];
            $pairs[] = array('account_id' => (int)$acc['id'], 'email' => rw_mask_email($acc['email']),
                'from_proxy_id' => $pid, 'from_proxy' => rwvp_mask_key($dead[$pid]),
                'proxy_id' => (int)$np['id'], 'proxy' => rwvp_mask_key($np), 'rule' => 'replace_dead');
            $maxp = rwvp_max_profiles($np);
            if (rwvp_used_count($np['id']) + 1 >= $maxp) { $fi++; }
        }
        if (!$apply) {
            rw_json(array('ok' => true, 'preview' => true, 'total' => count($pairs), 'pairs' => $pairs,
                'skipped' => $skipped, 'dead_proxies' => count($dead),
                'counts' => array('replace' => count($pairs), 'skip' => count($skipped)),
                'generated_at' => rwvp_generated()));
        }
        $done = 0;
        $db->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $acc_id = $pair['account_id']; $np = $pair['proxy_id'];
                $st = $db->prepare("UPDATE accounts SET proxy_id = ?, updated_at = ? WHERE id = ?");
                $st->execute(array($np, rwvp_now(), $acc_id));
                $st = $db->prepare("SELECT id FROM assignments WHERE account_id = ?");
                $st->execute(array($acc_id));
                if ($st->fetch()) {
                    $st = $db->prepare("UPDATE assignments SET proxy_id = ?, sticky = 1, updated_at = ? WHERE account_id = ?");
                    $st->execute(array($np, rwvp_now(), $acc_id));
                } else {
                    $st = $db->prepare("INSERT INTO assignments(account_id, device_id, proxy_id, phone_id, created_at, since, sticky, updated_at) VALUES(?,?,?,NULL,?,?,1,?)");
                    $st->execute(array($acc_id, null, $np, rwvp_now(), rwvp_now(), rwvp_now()));
                }
                rwvp_history($acc_id, $np, 'replace_dead', 'from=' . $pair['from_proxy_id']);
                $done++;
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'replace_dead_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvp_audit('proxy_replace_dead', 'proxies', '', array('replaced' => $done, 'skipped' => count($skipped)));
        rw_json(array('ok' => true, 'replaced' => $done, 'skipped' => $skipped, 'pairs' => $pairs,
            'generated_at' => rwvp_generated()));
        break;
    }

    /* ---------------- EXPORT CSV ---------------- */
    case 'export_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="proxies_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('id', 'proxy', 'scheme', 'host', 'port', 'username', 'label', 'group_key',
            'color_idx', 'status', 'exit_ip', 'geo', 'country', 'latency_ms', 'fail_count',
            'assigned_count', 'max_profiles', 'subnet', 'last_checked', 'created_at'));
        $st = $db->prepare(
            "SELECT p.*, g.color_idx AS g_color, g.label AS g_label,
                    (SELECT COUNT(*) FROM accounts a WHERE a.proxy_id = p.id AND a.deleted_at IS NULL) AS used_count
             FROM proxies p LEFT JOIN proxy_groups g ON g.proxy_id = p.id ORDER BY p.id");
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $maxp = rwvp_max_profiles($r);
            fputcsv($out, array(
                $r['id'],
                rwvp_mask_key(array('scheme' => $r['scheme'], 'host' => $r['host'], 'port' => $r['port'], 'username' => $r['username'])),
                $r['scheme'], $r['host'], $r['port'], $r['username'], $r['label'], $r['group_key'],
                $r['g_color'] !== null ? $r['g_color'] : rwvp_color_for($r['id']),
                $r['status'], $r['exit_ip'], $r['geo'], $r['country'], $r['latency_ms'], $r['fail_count'],
                $r['used_count'], $maxp, rwvp_subnet($r['host']), $r['last_checked'], $r['created_at']));
        }
        fclose($out);
        rw_audit('proxy_export_csv', array('rows' => 'all'));
        exit;
    }

    default:
        rw_json(array('error' => 'unknown_action', 'action' => $action,
            'available' => array('list', 'add', 'bulk_preview', 'bulk_add', 'range_expand', 'test',
                'remove', 'assign', 'auto_assign_preview', 'auto_assign_apply', 'release',
                'group_info', 'replace_dead', 'export_csv')), 400);
}
?>
