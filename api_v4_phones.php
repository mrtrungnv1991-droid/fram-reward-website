<?php
/**
 * api_v4_phones.php — API cho tab «PHONE» (prompt_website_ui_v4.md mục 4 + 7).
 * PHP 7.4 + PDO SQLite. Gọi từ trình duyệt đã đăng nhập:
 *   GET  api_v4_phones.php?action=list&page=1&per_page=50
 *   POST api_v4_phones.php?action=assign  (JSON body + header X-CSRF-Token)
 *
 * Quy ước SAO CHÉP từ api_crud.php:
 *   - require security.php → bắt buộc đăng nhập (401) + CSRF cho mọi POST (403)
 *   - phản hồi JSON qua rw_json(); lỗi dạng {"error": "..."} kèm HTTP code
 *   - thao tác ghi/xóa cần quyền admin với các thao tác phá hủy (remove)
 *   - mọi thay đổi ghi audit_ui + audit_log + phone_history
 *   - số phone KHÔNG BAO GIỜ trả nguyên bản, chỉ number_mask (mục 4.2)
 *
 * Action (mục 7 + mở rộng khớp tab Phone):
 *   list | add | import_preview | import | assign | unassign | set_status | history
 *   | update | remove | summary | export_csv
 *
 * Trạng thái phone (mục 4.1):
 *   free · attached · pending_verify · verified · burned · needs_check
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


define('RWVH_MAX_IMPORT', 5000); // giới hạn số dòng import mỗi lần gọi

/* ===================== TỰ ĐẢM BẢO SCHEMA V4 =====================
 * phones: max_accounts/country_code/created_by/deleted_at
 * assignments: since/sticky/updated_at
 * bảng: phone_history, audit_ui
 */
function rwvh_ensure_schema() {
    $db = rw_db();
    $add_cols = array(
        'phones' => array(
            'max_accounts' => "INTEGER DEFAULT 1",
            'country_code' => "TEXT",
            'created_by' => "TEXT",
            'deleted_at' => "TEXT"),
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
    $db->exec("CREATE TABLE IF NOT EXISTS phone_history( id INTEGER PRIMARY KEY AUTOINCREMENT, phone_id INTEGER, account_id INTEGER, action TEXT, actor TEXT, note TEXT, ts TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS audit_ui( id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime('now')), ip TEXT, user TEXT, action TEXT, entity TEXT, entity_id TEXT, detail TEXT)");
}
rwvh_ensure_schema();

/* ===================== QUY ƯỚC CHUNG (sao chép api_crud.php) ===================== */
function rwvh_input() {
    $raw = file_get_contents('php://input');
    $p = json_decode($raw, true);
    if (!is_array($p)) { $p = array(); }
    if (isset($_POST) && is_array($_POST) && count($_POST)) {
        $p = array_merge($_POST, $p); // JSON body thắng, $_POST làm fallback
    }
    return $p;
}
function rwvh_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
}
function rwvh_admin_only() {
    if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
}
function rwvh_actor() {
    return isset($_SESSION['rw_user']) ? (string)$_SESSION['rw_user'] : '-';
}
function rwvh_now() { return rw_db_now(); }
function rwvh_generated() { return date('c'); }
function rwvh_action_name($p) {
    $a = isset($_GET['action']) ? (string)$_GET['action'] : (isset($p['action']) ? (string)$p['action'] : '');
    $a = strtolower(preg_replace('/[^a-z_]/', '', $a));
    foreach (array('phones_', 'phone_') as $pre) {
        if (strpos($a, $pre) === 0) { return substr($a, strlen($pre)); }
    }
    return $a;
}
function rwvh_setting($key, $default = '') { return (string)rw_db_setting($key, $default); }
function rwvh_int_setting($key, $default) {
    $v = (int)rw_db_setting($key, (string)$default);
    return $v > 0 ? $v : (int)$default;
}

/* ===================== AUDIT + LỊCH SỬ ===================== */
function rwvh_audit($action, $entity, $entity_id, $detail = array()) {
    $db = rw_db();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
    $st = $db->prepare("INSERT INTO audit_ui(ts, ip, user, action, entity, entity_id, detail) VALUES(?,?,?,?,?,?,?)");
    $st->execute(array(rwvh_now(), $ip, rwvh_actor(), $action, $entity, (string)$entity_id,
        json_encode($detail, JSON_UNESCAPED_UNICODE)));
    rw_db_audit($action, array_merge(array('entity' => $entity, 'entity_id' => $entity_id), $detail));
}
function rwvh_history($phone_id, $account_id, $action, $note = '') {
    $st = rw_db()->prepare("INSERT INTO phone_history(phone_id, account_id, action, actor, note, ts) VALUES(?,?,?,?,?,?)");
    $st->execute(array(
        $phone_id !== null ? (int)$phone_id : null,
        $account_id !== null ? (int)$account_id : null,
        $action, rwvh_actor(), (string)$note, rwvh_now()));
}

/* ===================== CHUẨN HÓA SỐ PHONE (mục 4.3: E.164) =====================
 * Trả về mảng {ok, e164, country_code, error}
 * - bỏ mọi ký tự không phải số/+ ở đầu
 * - nếu bắt đầu bằng 00 → thay bằng +
 * - nếu không có + và độ dài 9-10 → coi như số VN nội địa, thêm +84 (mặc định)
 *   (đổi được bằng default_country)
 */
function rwvh_normalize($raw, $default_country = 'VN') {
    $s = trim((string)$raw);
    $s = preg_replace('/[\s\-\.\(\)]/', '', $s);
    if (strpos($s, '00') === 0) { $s = '+' . substr($s, 2); }
    if (!preg_match('/^\+?[0-9]{6,15}$/', $s)) {
        return array('ok' => false, 'e164' => '', 'country_code' => '', 'error' => 'invalid_number');
    }
    if (strpos($s, '+') !== 0) {
        // số nội địa: tiền tố theo quốc gia mặc định
        $prefixes = array('VN' => '84', 'US' => '1', 'GB' => '44', 'IN' => '91', 'ID' => '62',
            'PH' => '63', 'TH' => '66', 'MY' => '60', 'SG' => '65', 'CN' => '86', 'JP' => '81',
            'KR' => '82', 'DE' => '49', 'FR' => '33', 'BR' => '55', 'RU' => '7');
        $d = strtoupper((string)$default_country);
        $prefix = isset($prefixes[$d]) ? $prefixes[$d] : '84';
        $digits = ltrim($s, '0');
        $s = '+' . $prefix . $digits;
    }
    $digits = substr($s, 1);
    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return array('ok' => false, 'e164' => '', 'country_code' => '', 'error' => 'invalid_length');
    }
    // nhận diện quốc gia theo đầu số
    $cc = '';
    $map = array('84' => 'VN', '1' => 'US', '44' => 'GB', '91' => 'IN', '62' => 'ID',
        '63' => 'PH', '66' => 'TH', '60' => 'MY', '65' => 'SG', '86' => 'CN', '81' => 'JP',
        '82' => 'KR', '49' => 'DE', '33' => 'FR', '55' => 'BR', '7' => 'RU');
    foreach (array('84', '91', '62', '63', '66', '60', '65', '86', '81', '82', '49', '44', '33', '55', '1', '7') as $p) {
        if (strpos($digits, $p) === 0) { $cc = $map[$p]; break; }
    }
    return array('ok' => true, 'e164' => '+' . $digits, 'country_code' => $cc, 'error' => '');
}
function rwvh_mask($e164) {
    $d = preg_replace('/[^0-9]/', '', (string)$e164);
    if (strlen($d) < 6) { return '****'; }
    $first = substr($d, 0, 1);
    $cc = ($first === '1' || $first === '7') ? $first : substr($d, 0, 2);
    return '+' . $cc . '***' . substr($d, -4); // dạng +84***5678 (mục 4.2)
}
/* Che số phone trên 1 dòng nhập trước khi trả JSON (mục 4.2: số luôn che) */
function rwvh_mask_line($line) {
    $parts = explode('|', (string)$line);
    $n = rwvh_normalize($parts[0], 'VN');
    $parts[0] = $n['ok'] ? rwvh_mask($n['e164']) : '****';
    return implode('|', $parts);
}
function rwvh_mask_rows($rows) {
    foreach ($rows as $k => $r) {
        if (isset($r['line'])) { $rows[$k]['line'] = rwvh_mask_line($r['line']); }
    }
    return $rows;
}
function rwvh_mask_errors($errs) {
    foreach ($errs as $k => $e) {
        if (isset($e['line'])) { $errs[$k]['line'] = rwvh_mask_line($e['line']); }
    }
    return $errs;
}
function rwvh_enc($e164) {
    return base64_encode((string)$e164); // nhất quán với api_crud.php (number_enc)
}
function rwvh_dec($enc) {
    $x = base64_decode((string)$enc, true);
    return $x === false ? '' : $x;
}

/* Phân tích 1 dòng nhập phone: hỗ trợ số | country | carrier | notes | max_accounts */
function rwvh_parse_line($line, $default_country = 'VN') {
    $line = trim((string)$line);
    if ($line === '') { return array('ok' => false, 'error' => 'empty'); }
    $parts = array_map('trim', explode('|', $line));
    $n = rwvh_normalize($parts[0], $default_country);
    if (!$n['ok']) { return array('ok' => false, 'error' => $n['error'], 'raw' => $line); }
    $cc = isset($parts[1]) && $parts[1] !== '' ? strtoupper(preg_replace('/[^A-Za-z]/', '', $parts[1])) : $n['country_code'];
    $carrier = isset($parts[2]) ? preg_replace('/[^A-Za-z0-9 .\-]/u', '', $parts[2]) : '';
    $notes = isset($parts[3]) ? mb_substr($parts[3], 0, 250) : '';
    $max_acc = isset($parts[4]) ? (int)$parts[4] : 0;
    return array('ok' => true, 'error' => '', 'raw' => $line,
        'e164' => $n['e164'], 'country_code' => $cc, 'carrier' => $carrier,
        'notes' => $notes, 'max_accounts' => $max_acc,
        'mask' => rwvh_mask($n['e164']), 'enc' => rwvh_enc($n['e164']));
}

/* Gom danh sách dòng từ body: lines | text | items[] */
function rwvh_collect_lines($p) {
    $text = '';
    if (isset($p['lines'])) { $text = (string)$p['lines']; }
    elseif (isset($p['text'])) { $text = (string)$p['text']; }
    elseif (isset($p['items']) && is_array($p['items'])) {
        foreach ($p['items'] as $it) {
            if (is_array($it)) {
                $text .= (isset($it['line']) ? (string)$it['line'] : (isset($it['number']) ? (string)$it['number'] : '')) . "\n";
            } else {
                $text .= (string)$it . "\n";
            }
        }
    }
    $raw_lines = preg_split('/\r\n|\r|\n/', $text);
    $out = array(); $errors = array();
    foreach ($raw_lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || substr($ln, 0, 1) === '#') { continue; }
        $out[] = $ln;
    }
    if (count($out) > RWVH_MAX_IMPORT) {
        $errors[] = array('error' => 'too_many_lines', 'limit' => RWVH_MAX_IMPORT, 'got' => count($out));
        $out = array_slice($out, 0, RWVH_MAX_IMPORT);
    }
    return array('lines' => $out, 'errors' => $errors);
}

/* Phân loại dòng: ok | dup (trùng lô / đã có DB) | error — chỉ trả mask */
function rwvh_classify($lines, $default_country = 'VN') {
    $db = rw_db();
    $st = $db->prepare("SELECT id, deleted_at FROM phones WHERE number_enc = ?");
    $seen = array(); $rows = array();
    $counts = array('ok' => 0, 'dup' => 0, 'error' => 0);
    foreach ($lines as $line) {
        $p = rwvh_parse_line($line, $default_country);
        if (!$p['ok']) {
            $counts['error']++;
            $rows[] = array('line' => $line, 'status' => 'error', 'error' => $p['error']);
            continue;
        }
        if (isset($seen[$p['enc']])) {
            $counts['dup']++;
            $rows[] = array('line' => $line, 'status' => 'dup', 'error' => 'duplicate_in_batch', 'number_mask' => $p['mask']);
            continue;
        }
        $st->execute(array($p['enc']));
        $ex = $st->fetch();
        if ($ex && $ex['deleted_at'] === null) {
            $counts['dup']++;
            $rows[] = array('line' => $line, 'status' => 'dup', 'error' => 'already_exists',
                'number_mask' => $p['mask'], 'phone_id' => (int)$ex['id']);
            continue;
        }
        $seen[$p['enc']] = true;
        $counts['ok']++;
        $rows[] = array('line' => $line, 'status' => 'ok', 'error' => '',
            'number_mask' => $p['mask'], 'country_code' => $p['country_code'],
            'carrier' => $p['carrier'], 'max_accounts' => $p['max_accounts'] > 0 ? $p['max_accounts'] : null);
    }
    return array('rows' => $rows, 'counts' => $counts);
}

/* Sức chứa phone (mục 4.3: max_accounts_per_phone, mặc định 1) */
function rwvh_max_accounts($phone_row) {
    $setting = rwvh_int_setting('max_accounts_per_phone', 1);
    $row = isset($phone_row['max_accounts']) ? (int)$phone_row['max_accounts'] : 0;
    return $row > 0 ? $row : $setting;
}
function rwvh_used_count($phone_id, $exclude_account_id = 0) {
    $st = rw_db()->prepare("SELECT COUNT(*) c FROM accounts WHERE phone_id = ? AND deleted_at IS NULL AND id != ?");
    $st->execute(array((int)$phone_id, (int)$exclude_account_id));
    return (int)$st->fetch()['c'];
}

/* Ghi 1 phone vào DB, trả {id, created} */
function rwvh_insert($p, $extra = array()) {
    $db = rw_db();
    $st = $db->prepare("SELECT id, deleted_at FROM phones WHERE number_enc = ?");
    $st->execute(array($p['enc']));
    $row = $st->fetch();
    if ($row && $row['deleted_at'] === null) {
        return array('id' => (int)$row['id'], 'created' => false);
    }
    $maxa = isset($extra['max_accounts']) ? (int)$extra['max_accounts'] : $p['max_accounts'];
    if ($maxa <= 0) { $maxa = rwvh_int_setting('max_accounts_per_phone', 1); }
    $carrier = isset($extra['carrier']) ? (string)$extra['carrier'] : $p['carrier'];
    $notes = isset($extra['notes']) ? (string)$extra['notes'] : $p['notes'];
    $cc = isset($extra['country_code']) ? (string)$extra['country_code'] : $p['country_code'];
    $status = isset($extra['status']) ? (string)$extra['status'] : 'free';
    if ($row) {
        // khôi phục bản ghi đã xóa mềm
        $st = $db->prepare("UPDATE phones SET deleted_at = NULL, country_code = ?, carrier = ?, notes = ?, max_accounts = ?, status = ?, created_by = ? WHERE id = ?");
        $st->execute(array($cc, $carrier, $notes, $maxa, $status, rwvh_actor(), $row['id']));
        return array('id' => (int)$row['id'], 'created' => true, 'restored' => true);
    }
    $st = $db->prepare("INSERT INTO phones(number_enc, number_mask, country, country_code, carrier, status, notes, max_accounts, created_by, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $st->execute(array($p['enc'], $p['mask'], $cc, $cc, $carrier, $status, $notes, $maxa, rwvh_actor(), rwvh_now()));
    $id = (int)$db->lastInsertId();
    rwvh_history($id, null, 'phone_added', $p['mask']);
    return array('id' => $id, 'created' => true);
}

/* Trạng thái hợp lệ (mục 4.1) */
function rwvh_valid_status($s) {
    return in_array($s, array('free', 'attached', 'pending_verify', 'verified', 'burned', 'needs_check'), true);
}

/* Hàng phone đã che + thông tin ghép nối cho bảng */
function rwvh_row_out($r, $used, $acc_email, $acc_id) {
    $maxa = rwvh_max_accounts($r);
    return array(
        'id' => (int)$r['id'],
        'number_mask' => $r['number_mask'],
        'country' => $r['country'], 'country_code' => $r['country_code'],
        'carrier' => $r['carrier'], 'status' => $r['status'],
        'attached_account_id' => $acc_id !== null ? (int)$acc_id : null,
        'attached_email' => $acc_email !== null ? rw_mask_email($acc_email) : null,
        'attached_at' => $r['attached_at'], 'verified_at' => $r['verified_at'],
        'max_accounts' => $maxa, 'assigned_count' => (int)$used,
        'over_capacity' => $used > $maxa,
        'notes' => $r['notes'], 'created_by' => $r['created_by'],
        'created_at' => $r['created_at'], 'deleted_at' => $r['deleted_at']);
}

/* ===================== XỬ LÝ ACTION ===================== */
$p_in = rwvh_input();
$action = rwvh_action_name($p_in);
$db = rw_db();

switch ($action) {
    /* ---------------- 4.2 DANH SÁCH PHONE ---------------- */
    case 'list': {
        $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
        $per_page = (int)(isset($_GET['per_page']) ? $_GET['per_page'] : (isset($_GET['limit']) ? $_GET['limit'] : 50));
        $per_page = max(1, min(500, $per_page > 0 ? $per_page : 50));
        $filters = array();
        $where = array('p.deleted_at IS NULL'); $args = array();
        $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
        if ($status !== '') {
            if ($status === 'free' || $status === 'attached' || $status === 'pending_verify'
                || $status === 'verified' || $status === 'burned' || $status === 'needs_check') {
                $where[] = "p.status = ?"; $args[] = $status; $filters['status'] = $status;
            }
        }
        $country = isset($_GET['country']) ? strtoupper(trim((string)$_GET['country'])) : '';
        if ($country !== '') { $where[] = "(p.country = ? OR p.country_code = ?)"; $args[] = $country; $args[] = $country; $filters['country'] = $country; }
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q !== '') {
            $where[] = "(p.number_mask LIKE ? OR p.carrier LIKE ? OR p.notes LIKE ? OR p.country LIKE ?)";
            for ($i = 0; $i < 4; $i++) { $args[] = '%' . $q . '%'; }
            $filters['q'] = $q;
        }
        $assigned = isset($_GET['assigned']) ? strtolower(trim((string)$_GET['assigned'])) : '';
        if ($assigned === 'busy') {
            $where[] = "EXISTS(SELECT 1 FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL)";
            $filters['assigned'] = 'busy';
        } elseif ($assigned === 'free') {
            $where[] = "NOT EXISTS(SELECT 1 FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL)";
            $filters['assigned'] = 'free';
        }
        $where_sql = implode(' AND ', $where);
        $st = $db->prepare("SELECT COUNT(*) c FROM phones p WHERE $where_sql");
        $st->execute($args);
        $total = (int)$st->fetch()['c'];
        $st = $db->prepare(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL) AS used_count,
                    (SELECT a.id FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1) AS acc_id,
                    (SELECT a.email FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1) AS acc_email
             FROM phones p WHERE $where_sql ORDER BY p.id LIMIT ? OFFSET ?");
        $args2 = $args; $args2[] = $per_page; $args2[] = ($page - 1) * $per_page;
        $st->execute($args2);
        $rows = array();
        foreach ($st->fetchAll() as $r) {
            $rows[] = rwvh_row_out($r, (int)$r['used_count'], $r['acc_email'], $r['acc_id']);
        }
        rw_json(array(
            'total' => $total, 'page' => $page, 'per_page' => $per_page,
            'filters_applied' => $filters, 'generated_at' => rwvh_generated(),
            'settings' => array('max_accounts_per_phone' => rwvh_int_setting('max_accounts_per_phone', 1)),
            'phones' => $rows));
        break;
    }

    /* ---------------- 4.3 THÊM 1 PHONE ---------------- */
    case 'add': {
        rwvh_post_only();
        $p = $p_in;
        $raw = isset($p['number']) ? (string)$p['number'] : (isset($p['line']) ? (string)$p['line'] : '');
        if ($raw === '') { rw_json(array('error' => 'number_required'), 422); }
        $default_country = isset($p['default_country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p['default_country'])) : 'VN';
        $parsed = rwvh_parse_line($raw, $default_country);
        if (!$parsed['ok']) { rw_json(array('error' => $parsed['error'], 'number' => rwvh_mask($raw)), 422); }
        $extra = array(
            'carrier' => isset($p['carrier']) ? preg_replace('/[^A-Za-z0-9 .\-]/u', '', (string)$p['carrier']) : '',
            'notes' => isset($p['notes']) ? mb_substr((string)$p['notes'], 0, 250) : '',
            'country_code' => isset($p['country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p['country'])) : '',
            'max_accounts' => isset($p['max_accounts']) ? (int)$p['max_accounts'] : 0);
        $res = rwvh_insert($parsed, $extra);
        rwvh_audit('phone_add', 'phone', $res['id'], array('number_mask' => $parsed['mask']));
        rw_json(array('ok' => true, 'phone_id' => $res['id'], 'created' => $res['created'],
            'number_mask' => $parsed['mask'], 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 XEM TRƯỚC IMPORT (mục 7: import_preview) ---------------- */
    case 'import_preview': {
        rwvh_post_only();
        $default_country = isset($p_in['default_country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p_in['default_country'])) : 'VN';
        $col = rwvh_collect_lines($p_in);
        $cl = rwvh_classify($col['lines'], $default_country);
        rw_json(array(
            'ok' => true,
            'total' => count($col['lines']),
            'counts' => $cl['counts'],
            'preview' => rwvh_mask_rows($cl['rows']),
            'range_errors' => rwvh_mask_errors($col['errors']),
            'filters_applied' => array('default_country' => $default_country),
            'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 IMPORT HÀNG LOẠT (mục 7: import) ---------------- */
    case 'import':
    case 'bulk_add': {
        rwvh_post_only();
        $default_country = isset($p_in['default_country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p_in['default_country'])) : 'VN';
        $col = rwvh_collect_lines($p_in);
        $cl = rwvh_classify($col['lines'], $default_country);
        $added = 0; $skipped = 0; $errors = $col['errors']; $ids = array();
        $db->beginTransaction();
        try {
            foreach ($cl['rows'] as $row) {
                if ($row['status'] === 'dup') { $skipped++; continue; }
                if ($row['status'] === 'error') { $skipped++; $errors[] = array('line' => $row['line'], 'error' => $row['error']); continue; }
                $parsed = rwvh_parse_line($row['line'], $default_country);
                if (!$parsed['ok']) { $skipped++; $errors[] = array('line' => $row['line'], 'error' => $parsed['error']); continue; }
                $res = rwvh_insert($parsed);
                if ($res['created']) { $added++; $ids[] = $res['id']; } else { $skipped++; }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'import_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvh_audit('phone_import', 'phones', implode(',', array_slice($ids, 0, 20)),
            array('added' => $added, 'skipped' => $skipped));
        rw_json(array('ok' => true, 'added' => $added, 'skipped' => $skipped,
            'errors' => rwvh_mask_errors(array_slice($errors, 0, 100)), 'phone_ids' => array_slice($ids, 0, 200),
            'total' => count($col['lines']), 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 GẮN PHONE VÀO ACCOUNT (mục 7: assign) ----------------
     * Chặn khi vượt max_accounts_per_phone (mục 4.3) trừ khi admin override=true
     */
    case 'assign': {
        rwvh_post_only();
        $p = $p_in;
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($acc_id <= 0 || $phone_id <= 0) {
            rw_json(array('error' => 'account_id_and_phone_id_required'), 422);
        }
        $override = isset($p['override']) && ($p['override'] === true || $p['override'] === 1 || $p['override'] === '1');
        $st = $db->prepare("SELECT id, email, device_id, phone_id, status FROM accounts WHERE id = ? AND deleted_at IS NULL");
        $st->execute(array($acc_id));
        $acc = $st->fetch();
        if (!$acc) { rw_json(array('error' => 'account_not_found'), 404); }
        $st = $db->prepare("SELECT * FROM phones WHERE id = ? AND deleted_at IS NULL");
        $st->execute(array($phone_id));
        $ph = $st->fetch();
        if (!$ph) { rw_json(array('error' => 'phone_not_found'), 404); }
        if ($ph['status'] === 'burned') {
            rw_json(array('error' => 'phone_burned', 'hint' => 'đánh dấu lại trạng thái trước khi gán'), 409);
        }
        $maxa = rwvh_max_accounts($ph);
        $used = rwvh_used_count($phone_id, $acc_id);
        $warnings = array();
        if ($used >= $maxa) {
            if (!$override) {
                rw_json(array('error' => 'phone_capacity_full', 'max_accounts' => $maxa,
                    'used' => $used, 'hint' => 'admin có thể gán đè với override=true (ghi audit)'), 409);
            }
            if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
            $warnings[] = array('code' => 'override_capacity', 'max_accounts' => $maxa, 'used' => $used);
        }
        $db->beginTransaction();
        try {
            // gắn account → phone
            $st = $db->prepare("UPDATE accounts SET phone_id = ?, updated_at = ? WHERE id = ?");
            $st->execute(array($phone_id, rwvh_now(), $acc_id));
            // cập nhật trạng thái phone (mục 4.1)
            $new_status = $ph['status'] === 'verified' ? 'verified' : 'attached';
            if ($ph['attached_profile'] === null || $ph['attached_profile'] === '') {
                $st = $db->prepare("UPDATE phones SET status = ?, attached_profile = ?, attached_at = ? WHERE id = ?");
                $st->execute(array($new_status, (string)$acc_id, rwvh_now(), $phone_id));
            }
            // assignments (mục 8)
            $st = $db->prepare("SELECT id FROM assignments WHERE account_id = ?");
            $st->execute(array($acc_id));
            if ($st->fetch()) {
                $st = $db->prepare("UPDATE assignments SET phone_id = ?, device_id = ?, updated_at = ? WHERE account_id = ?");
                $st->execute(array($phone_id, $acc['device_id'], rwvh_now(), $acc_id));
            } else {
                $st = $db->prepare("INSERT INTO assignments(account_id, device_id, proxy_id, phone_id, created_at, since, sticky, updated_at) VALUES(?,?,NULL,?,?,?,1,?)");
                $st->execute(array($acc_id, $acc['device_id'], $phone_id, rwvh_now(), rwvh_now(), rwvh_now()));
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            rw_json(array('error' => 'assign_failed', 'detail' => $e->getMessage()), 500);
        }
        rwvh_history($phone_id, $acc_id, 'assign', 'override=' . ($override ? 1 : 0));
        rwvh_audit('phone_assign', 'phone', $phone_id, array('account_id' => $acc_id, 'override' => $override, 'warnings' => $warnings));
        rw_json(array('ok' => true, 'account_id' => $acc_id, 'phone_id' => $phone_id,
            'status' => $new_status, 'warnings' => $warnings, 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 GỠ PHONE KHỎI ACCOUNT (mục 7: unassign) ---------------- */
    case 'unassign': {
        rwvh_post_only();
        $p = $p_in;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        if ($phone_id <= 0 && $acc_id <= 0) {
            rw_json(array('error' => 'phone_id_or_account_id_required'), 422);
        }
        $freed = 0;
        if ($phone_id > 0) {
            $st = $db->prepare("UPDATE phones SET status = 'free', attached_profile = NULL, attached_at = NULL WHERE id = ? AND deleted_at IS NULL");
            $st->execute(array($phone_id));
            $st = $db->prepare("UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
            $st->execute(array(rwvh_now(), $phone_id));
            $freed = $st->rowCount();
            $st = $db->prepare("UPDATE assignments SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
            $st->execute(array(rwvh_now(), $phone_id));
            rwvh_history($phone_id, $acc_id > 0 ? $acc_id : null, 'unassign', 'freed=' . $freed);
        }
        if ($acc_id > 0 && $phone_id <= 0) {
            $st = $db->prepare("UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE id = ? AND phone_id IS NOT NULL");
            $st->execute(array(rwvh_now(), $acc_id));
            $freed = $st->rowCount();
            $st = $db->prepare("UPDATE assignments SET phone_id = NULL, updated_at = ? WHERE account_id = ?");
            $st->execute(array(rwvh_now(), $acc_id));
            rwvh_history(null, $acc_id, 'unassign', 'freed=' . $freed);
        }
        rwvh_audit('phone_unassign', 'phone', $phone_id > 0 ? $phone_id : $acc_id, array('freed' => $freed));
        rw_json(array('ok' => true, 'freed' => $freed, 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 ĐỔI TRẠNG THÁI (mục 7: set_status) ----------------
     * Trạng thái: free|attached|pending_verify|verified|burned|needs_check
     * Khi verified → ghi verified_at + actor (mục 4.3)
     */
    case 'set_status': {
        rwvh_post_only();
        $p = $p_in;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($phone_id <= 0) { rw_json(array('error' => 'phone_id_required'), 422); }
        $status = isset($p['status']) ? strtolower(preg_replace('/[^a-z_]/', '', (string)$p['status'])) : '';
        if (!rwvh_valid_status($status)) {
            rw_json(array('error' => 'invalid_status', 'allowed' => array('free', 'attached', 'pending_verify', 'verified', 'burned', 'needs_check')), 422);
        }
        $note = isset($p['note']) ? mb_substr((string)$p['note'], 0, 250) : '';
        $st = $db->prepare("SELECT * FROM phones WHERE id = ? AND deleted_at IS NULL");
        $st->execute(array($phone_id));
        $ph = $st->fetch();
        if (!$ph) { rw_json(array('error' => 'phone_not_found'), 404); }
        $verified_at = ($status === 'verified') ? rwvh_now() : $ph['verified_at'];
        $attached_profile = $ph['attached_profile'];
        $attached_at = $ph['attached_at'];
        if ($status === 'free') {
            $attached_profile = null; $attached_at = null;
            $st = $db->prepare("UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
            $st->execute(array(rwvh_now(), $phone_id));
        }
        $st = $db->prepare("UPDATE phones SET status = ?, verified_at = ?, attached_profile = ?, attached_at = ?, notes = CASE WHEN ? != '' THEN ? ELSE notes END WHERE id = ?");
        $st->execute(array($status, $verified_at, $attached_profile, $attached_at, $note, $note, $phone_id));
        rwvh_history($phone_id, $attached_profile !== null ? (int)$attached_profile : null, 'set_status', $status . ' ' . $note);
        rwvh_audit('phone_set_status', 'phone', $phone_id, array('status' => $status, 'note' => $note));
        rw_json(array('ok' => true, 'phone_id' => $phone_id, 'status' => $status,
            'verified_at' => $verified_at, 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.3 LỊCH SỬ (mục 7: history) ---------------- */
    case 'history': {
        $phone_id = isset($_GET['phone_id']) ? (int)$_GET['phone_id'] : (isset($p_in['phone_id']) ? (int)$p_in['phone_id'] : 0);
        $acc_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : (isset($p_in['account_id']) ? (int)$p_in['account_id'] : 0);
        $limit = (int)(isset($_GET['limit']) ? $_GET['limit'] : 100);
        $limit = max(1, min(500, $limit > 0 ? $limit : 100));
        $where = array('1=1'); $args = array();
        if ($phone_id > 0) { $where[] = "h.phone_id = ?"; $args[] = $phone_id; }
        if ($acc_id > 0) { $where[] = "h.account_id = ?"; $args[] = $acc_id; }
        $where_sql = implode(' AND ', $where);
        $st = $db->prepare("SELECT COUNT(*) c FROM phone_history h WHERE $where_sql");
        $st->execute($args);
        $total = (int)$st->fetch()['c'];
        $st = $db->prepare(
            "SELECT h.*, p.number_mask, a.email AS account_email
             FROM phone_history h
             LEFT JOIN phones p ON p.id = h.phone_id
             LEFT JOIN accounts a ON a.id = h.account_id
             WHERE $where_sql ORDER BY h.id DESC LIMIT ?");
        $args2 = $args; $args2[] = $limit;
        $st->execute($args2);
        $rows = array();
        foreach ($st->fetchAll() as $r) {
            $rows[] = array(
                'id' => (int)$r['id'], 'phone_id' => $r['phone_id'] !== null ? (int)$r['phone_id'] : null,
                'number_mask' => $r['number_mask'],
                'account_id' => $r['account_id'] !== null ? (int)$r['account_id'] : null,
                'account_email' => $r['account_email'] !== null ? rw_mask_email($r['account_email']) : null,
                'action' => $r['action'], 'actor' => $r['actor'], 'note' => $r['note'], 'ts' => $r['ts']);
        }
        rw_json(array(
            'total' => $total, 'page' => 1, 'per_page' => $limit,
            'filters_applied' => array('phone_id' => $phone_id, 'account_id' => $acc_id),
            'generated_at' => rwvh_generated(), 'history' => $rows));
        break;
    }

    /* ---------------- 4.3 SỬA PHONE (mở rộng tab Phone) ---------------- */
    case 'update': {
        rwvh_post_only();
        $p = $p_in;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($phone_id <= 0) { rw_json(array('error' => 'phone_id_required'), 422); }
        $st = $db->prepare("SELECT * FROM phones WHERE id = ? AND deleted_at IS NULL");
        $st->execute(array($phone_id));
        $ph = $st->fetch();
        if (!$ph) { rw_json(array('error' => 'phone_not_found'), 404); }
        $sets = array(); $vals = array();
        if (isset($p['carrier'])) {
            $sets[] = "carrier = ?"; $vals[] = preg_replace('/[^A-Za-z0-9 .\-]/u', '', (string)$p['carrier']);
        }
        if (isset($p['notes'])) {
            $sets[] = "notes = ?"; $vals[] = mb_substr((string)$p['notes'], 0, 250);
        }
        if (isset($p['country'])) {
            $cc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$p['country']));
            $sets[] = "country = ?"; $vals[] = $cc;
            $sets[] = "country_code = ?"; $vals[] = $cc;
        }
        if (isset($p['max_accounts'])) {
            $sets[] = "max_accounts = ?"; $vals[] = (int)$p['max_accounts'];
        }
        if (!count($sets)) { rw_json(array('error' => 'nothing_to_update'), 422); }
        $vals[] = $phone_id;
        $st = $db->prepare("UPDATE phones SET " . implode(', ', $sets) . " WHERE id = ?");
        $st->execute($vals);
        rwvh_history($phone_id, null, 'update', implode(',', array_keys(array_diff_key($p, array('phone_id' => 1, 'action' => 1)))));
        rwvh_audit('phone_update', 'phone', $phone_id, array('fields' => array_keys($p)));
        rw_json(array('ok' => true, 'phone_id' => $phone_id, 'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- XÓA PHONE (mềm / cứng) ---------------- */
    case 'remove':
    case 'delete': {
        rwvh_post_only();
        rwvh_admin_only();
        $p = $p_in;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($phone_id <= 0) { rw_json(array('error' => 'phone_id_required'), 422); }
        $hard = isset($p['hard']) && ($p['hard'] === true || $p['hard'] === 1 || $p['hard'] === '1');
        $st = $db->prepare("SELECT * FROM phones WHERE id = ?");
        $st->execute(array($phone_id));
        $ph = $st->fetch();
        if (!$ph) { rw_json(array('error' => 'phone_not_found'), 404); }
        // tháo phone khỏi account trước khi xóa
        $st = $db->prepare("UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
        $st->execute(array(rwvh_now(), $phone_id));
        $freed = $st->rowCount();
        $st = $db->prepare("UPDATE assignments SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
        $st->execute(array(rwvh_now(), $phone_id));
        if ($hard) {
            $st = $db->prepare("DELETE FROM phones WHERE id = ?");
            $st->execute(array($phone_id));
            rwvh_history($phone_id, null, 'phone_hard_delete', 'freed=' . $freed);
        } else {
            $st = $db->prepare("UPDATE phones SET deleted_at = ?, status = 'free', attached_profile = NULL, attached_at = NULL WHERE id = ?");
            $st->execute(array(rwvh_now(), $phone_id));
            rwvh_history($phone_id, null, 'phone_soft_delete', 'freed=' . $freed);
        }
        rwvh_audit('phone_remove', 'phone', $phone_id, array('hard' => $hard, 'freed_accounts' => $freed));
        rw_json(array('ok' => true, 'phone_id' => $phone_id, 'hard' => $hard, 'freed_accounts' => $freed,
            'generated_at' => rwvh_generated()));
        break;
    }

    /* ---------------- 4.4 TỔNG HỢP (summary) ---------------- */
    case 'summary': {
        $st = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL) AS total,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'free') AS free,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'attached') AS attached,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'pending_verify') AS pending_verify,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'verified') AS verified,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'burned') AS burned,
                (SELECT COUNT(*) FROM phones WHERE deleted_at IS NULL AND status = 'needs_check') AS needs_check,
                (SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND phone_id IS NOT NULL) AS accounts_with_phone,
                (SELECT COUNT(*) FROM accounts WHERE deleted_at IS NULL AND (phone_id IS NULL OR phone_id = 0)) AS accounts_without_phone");
        $s = $st->fetch();
        // cảnh báo mục 4.4: account cần phone nhưng thiếu phone rảnh
        $need = (int)$s['accounts_without_phone'];
        $free = (int)$s['free'];
        $warning = '';
        if ($need > $free) {
            $warning = 'Còn ' . $need . ' account cần phone nhưng chỉ còn ' . $free . ' phone rảnh';
        }
        rw_json(array(
            'ok' => true, 'generated_at' => rwvh_generated(),
            'total' => (int)$s['total'],
            'summary' => array(
                'accounts_with_phone' => (int)$s['accounts_with_phone'],
                'accounts_without_phone' => (int)$s['accounts_without_phone'],
                'phones_free' => (int)$s['free'],
                'phones_attached' => (int)$s['attached'],
                'phones_pending_verify' => (int)$s['pending_verify'],
                'phones_verified' => (int)$s['verified'],
                'phones_burned' => (int)$s['burned'],
                'phones_needs_check' => (int)$s['needs_check']),
            'warning' => $warning));
        break;
    }

    /* ---------------- EXPORT CSV ---------------- */
    case 'export_csv': {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="phones_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('id', 'number_mask', 'country', 'country_code', 'carrier', 'status',
            'attached_account_id', 'attached_email', 'attached_at', 'verified_at',
            'assigned_count', 'max_accounts', 'notes', 'created_by', 'created_at'));
        $st = $db->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL) AS used_count,
                    (SELECT a.id FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1) AS acc_id,
                    (SELECT a.email FROM accounts a WHERE a.phone_id = p.id AND a.deleted_at IS NULL ORDER BY a.id LIMIT 1) AS acc_email
             FROM phones p WHERE p.deleted_at IS NULL ORDER BY p.id");
        foreach ($st->fetchAll() as $r) {
            $maxa = rwvh_max_accounts($r);
            fputcsv($out, array(
                $r['id'], $r['number_mask'], $r['country'], $r['country_code'], $r['carrier'], $r['status'],
                $r['acc_id'], $r['acc_email'] !== null ? rw_mask_email($r['acc_email']) : '',
                $r['attached_at'], $r['verified_at'], $r['used_count'], $maxa,
                $r['notes'], $r['created_by'], $r['created_at']));
        }
        fclose($out);
        rw_audit('phone_export_csv', array('rows' => 'all'));
        exit;
    }

    default:
        rw_json(array('error' => 'unknown_action', 'action' => $action,
            'available' => array('list', 'add', 'import_preview', 'import', 'assign', 'unassign',
                'set_status', 'history', 'update', 'remove', 'summary', 'export_csv')), 400);
}
?>
