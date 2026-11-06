<?php
/**
 * api_v4_stats.php — API cho tab «TIẾN ĐỘ & THỐNG KÊ» (prompt_website_ui_v4.md mục 5 + 7 + 8).
 * PHP 7.4 + PDO SQLite. Gọi từ trình duyệt đã đăng nhập:
 *   GET  api_v4_stats.php?action=summary&period=day&date=2026-11-04
 *   GET  api_v4_stats.php?action=timeseries&period=week&date=2026-11-04&bucket=day
 *   GET  api_v4_stats.php?action=export_csv&what=by_account&period=month&date=2026-11-01
 *
 * Quy ước SAO CHÉP từ api_v4_accounts.php / api_v4_phones.php:
 *   - require security.php → bắt buộc đăng nhập (401) + CSRF cho POST (403)
 *   - phản hồi JSON qua rw_json(); lỗi {"ok":false,"error":{"code","message"}} + HTTP code
 *   - email/proxy/phone LUÔN che: Se***@outlook.com, +84***1234, user:***@host:port
 *   - phản hồi danh sách luôn có total, page, per_page, pages, filters_applied, generated_at (mục 7)
 *
 * Action (mục 7):
 *   summary | timeseries | by_task | by_error | by_account | by_proxy | calendar | pending_tasks
 *   | export_csv   (mục 5.3 có nút «Xuất CSV» — bổ sung ngoài danh sách tối thiểu)
 *
 * NGUYÊN TẮC READ-ONLY (yêu cầu: KHÔNG sửa DB):
 *   - file này KHÔNG có INSERT/UPDATE/DELETE/CREATE/ALTER nào
 *   - mở SQLite riêng ở chế độ chỉ-đọc (mode=ro, fallback immutable=1), KHÔNG gọi rw_db()
 *     → không kích hoạt auto-create schema của db.php, không tạo/sửa file DB
 *   - bảng/cột tùy chọn theo mục 8 (goals, task_events.skill_id|skill_version|difficulty)
 *     được phát hiện lúc chạy; thiếu thì trả null + ghi vào data_confidence.storage_warnings
 *
 * ĐỊNH NGHĨA SỐ LIỆU (mục 5.2 — tooltip công thức nằm ở trường `formulas` của response):
 *   - Điểm thăng = Σ (points_after - points_before) của run có đủ điểm trước và sau.
 *     Run thiếu điểm KHÔNG tính vào tổng, KHÔNG coi là 0 → đếm ở `points_unknown_runs`.
 *   - Không dùng cột runs.delta vì dữ liệu migrate từng ghi sai (xem header báo cáo bugs).
 *   - Tỉ lệ/ngũ phân vị null khi mẫu = 0 (không bịa số 0).
 *
 * GIẢ ĐỊNH:
 *   - múi giờ hiển thị theo settings.timezone (mặc định Asia/Bangkok), mốc reset theo settings.reset_time;
 *     ranh giới ngày do worker ghi vào runs.date / task_events.date nên API nhóm theo 2 cột này.
 *   - account đã xóa mềm (deleted_at khác NULL) bị loại khỏi mọi số liệu; account đủ điều kiện
 *     = chưa xóa mềm, trừ status disabled/locked (mục 5.2).
 *   - run thành công = status IN (ok, done, success, completed); run lỗi = status IN (error, failed, fail)
 *     hoặc có text lỗi khác rỗng.
 *   - nhiệm vụ khả dụng = task_events trừ state thuộc nhóm unavailable; done = state IN (done, ok,
 *     completed, success, solved).
 */
require_once __DIR__ . '/security.php';
/* Auth (session + CSRF) được gọi BÊN TRONG rws_main() theo đúng quy ước api_v4_accounts.php,
   để khi require ở chế độ test (RW_V4S_TEST_MODE) không tự chạy auth/exit. */
require_once __DIR__ . '/db.php'; // chỉ dùng hằng RW_DB_PATH; KHÔNG gọi rw_db()

/* Hook test: cho phép trỏ API sang DB sandbox mà không sửa DB gốc. Không dùng trên production. */
if (!defined('RW_V4S_DB_PATH')) { define('RW_V4S_DB_PATH', RW_DB_PATH); }

/* Polyfill mb_* an toàn khi PHP thiếu extension mbstring (giống api_v4_phones.php) */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null) {
        $s = (string)$s;
        return ($length === null) ? substr($s, $start) : substr($s, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) { return strlen((string)$s); }
}

define('RWS_MAX_PER_PAGE', 500);
define('RWS_MAX_DAYS', 400);
define('RWS_MAX_ERROR_ROWS', 5000);

/* ====================================================================
 * 1. LỚP TRUY VẤN CHỈ-ĐỌC
 * ==================================================================== */
function rws_fail($http, $code, $message, $extra = array()) {
    $err = array('code' => $code, 'message' => $message);
    if (is_array($extra) && count($extra)) { $err = array_merge($err, $extra); }
    rw_json(array('ok' => false, 'error' => $err), $http);
}

function rws_ro() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $path = RW_V4S_DB_PATH;
    if (!is_file($path)) {
        rws_fail(503, 'db_not_found', 'Không tìm thấy cơ sở dữ liệu (' . basename($path) . '). API thống kê chỉ đọc nên không tự tạo DB mới.');
    }
    $opts = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
    /* DSN đúng phải có prefix 'sqlite:'; URI 'file:...?mode=ro' chỉ hoạt động khi PDO_SQLITE
       được biên dịch với URI support. Chuỗi mở dần từ nghiêm ngặt → rộng, luôn bật
       PRAGMA query_only=1 để SQLite chặn mọi câu ghi ngay cả khi mở đường dẫn thường. */
    $attempts = array(
        'sqlite:file:' . $path . '?mode=ro',   // read-only qua URI (tốt nhất)
        'sqlite:file:' . $path . '?immutable=1', // không đọc journal, không ghi được
        'sqlite:' . $path,                     // mở thường + query_only chặn ghi
    );
    $pdo = null; $last = '';
    foreach ($attempts as $dsn) {
        try {
            $pdo = new PDO($dsn, null, null, $opts);
            break;
        } catch (Exception $e) {
            $last = $e->getMessage();
        }
    }
    if ($pdo === null) {
        rws_fail(503, 'db_readonly_failed', 'Không mở được cơ sở dữ liệu ở chế độ chỉ-đọc: ' . $last);
    }
    try { $pdo->exec('PRAGMA query_only = 1'); } catch (Exception $e) { /* càng tốt càng an toàn */ }
    return $pdo;
}

function rws_has_table($table) {
    static $cache = array();
    if (isset($cache[$table])) { return $cache[$table]; }
    $st = rws_ro()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute(array($table));
    $ok = (bool)$st->fetch();
    $cache[$table] = $ok;
    return $ok;
}

function rws_cols($table) {
    static $cache = array();
    if (isset($cache[$table])) { return $cache[$table]; }
    $cols = array();
    if (rws_has_table($table)) {
        $st = rws_ro()->query('PRAGMA table_info(' . $table . ')');
        foreach ($st->fetchAll() as $c) { $cols[] = (string)$c['name']; }
    }
    $cache[$table] = $cols;
    return $cols;
}

function rws_has_col($table, $col) { return in_array($col, rws_cols($table), true); }

function rws_setting($key, $default = '') {
    if (!rws_has_table('settings')) { return $default; }
    $st = rws_ro()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute(array($key));
    $r = $st->fetch();
    return $r ? (string)$r['value'] : $default;
}

/* ====================================================================
 * 2. TIỆN ÍCH CHUNG: input, mask, tỉ lệ
 * ==================================================================== */
function rws_input() {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    $out = is_array($j) ? $j : array();
    foreach ((array)$_POST as $k => $v) {
        if ($k === 'csrf') { continue; }
        $out[$k] = $v;
    }
    return $out;
}

function rws_arg($p, $key, $default = '') {
    if (isset($_GET[$key])) { return $_GET[$key]; }
    if (isset($p[$key])) { return $p[$key]; }
    return $default;
}

function rws_action_name($p) {
    $a = isset($_GET['action']) ? (string)$_GET['action'] : (isset($p['action']) ? (string)$p['action'] : '');
    $a = strtolower(preg_replace('/[^a-z_]/', '', $a));
    if (strpos($a, 'stats_') === 0) { return substr($a, 6); }
    return $a;
}

/* Che bí mật — cùng định dạng api_v4_accounts.php (mục 0 spec) */
function rws_mask_email($email) {
    $email = (string)$email;
    $parts = explode('@', $email);
    if (count($parts) !== 2 || $parts[0] === '') { return '***'; }
    return substr($parts[0], 0, 2) . '***@' . $parts[1];
}
function rws_mask_phone($num) {
    $num = (string)$num;
    if ($num === '') { return ''; }
    if (strlen($num) <= 7) { return substr($num, 0, 1) . '***'; }
    return substr($num, 0, 3) . '***' . substr($num, -4);
}
function rws_mask_proxy($scheme, $host, $port, $user) {
    $user = (string)$user;
    return ($user !== '' ? $user . ':***@' : '') . $host . ':' . $port;
}
function rws_scrub($msg) {
    $m = (string)$msg;
    $m = preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', function ($mm) {
        return rws_mask_email($mm[0]);
    }, $m);
    $m = preg_replace('/([A-Za-z0-9_.-]+):([^\s@]{1,})@/', '$1:***@', $m); // user:pass@host
    $m = preg_replace('/\s+/', ' ', $m);
    $m = trim($m);
    return v4s_cut($m, 160);
}
function v4s_cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n, 'UTF-8') : substr((string)$s, 0, $n);
}

function rws_rate($num, $den) {
    if ($den === null || (float)$den == 0.0) { return null; }
    return round(((float)$num) / ((float)$den), 4);
}
function rws_div($num, $den, $digits = 2) {
    if ($den === null || (float)$den == 0.0) { return null; }
    return round(((float)$num) / ((float)$den), $digits);
}
function rws_delta_pct($cur, $prev) {
    if ($cur === null || $prev === null) { return array('delta_pct' => null, 'direction' => null); }
    if ((float)$prev == 0.0) {
        if ((float)$cur == 0.0) { return array('delta_pct' => 0.0, 'direction' => 'flat'); }
        return array('delta_pct' => null, 'direction' => ((float)$cur > 0 ? 'up' : 'down'));
    }
    $pct = round((((float)$cur - (float)$prev) / abs((float)$prev)) * 100, 1);
    $dir = ($pct > 0) ? 'up' : (($pct < 0) ? 'down' : 'flat');
    return array('delta_pct' => $pct, 'direction' => $dir);
}

/* ====================================================================
 * 3. KỲ (mục 5.1: Ngày · Tuần · Tháng · Tùy chọn) + so sánh kỳ trước
 * ==================================================================== */
function rws_tz() {
    $name = rws_setting('timezone', 'Asia/Bangkok');
    try { return new DateTimeZone($name); } catch (Exception $e) { return new DateTimeZone('Asia/Bangkok'); }
}

function rws_period($p) {
    $kind = strtolower(trim((string)rws_arg($p, 'period', 'day')));
    if (!in_array($kind, array('day', 'week', 'month', 'custom'), true)) {
        rws_fail(422, 'invalid_period', 'Tham số period không hợp lệ. Chỉ nhận: day, week, month, custom.');
    }
    $tz = rws_tz();
    $now = new DateTime('now', $tz);
    $date_str = (string)rws_arg($p, 'date', $now->format('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
        rws_fail(422, 'invalid_date', 'Tham số date phải có dạng YYYY-MM-DD.');
    }
    list($y, $m, $d) = explode('-', $date_str);
    if (!checkdate((int)$m, (int)$d, (int)$y)) {
        rws_fail(422, 'invalid_date', 'Ngày không tồn tại: ' . $date_str . '.');
    }
    $from = new DateTime($date_str, $tz);
    $to = clone $from;
    if ($kind === 'week') {
        $dow = (int)$from->format('N'); // 1 = Monday
        $from->modify('-' . ($dow - 1) . ' days');
        $to = clone $from;
        $to->modify('+6 days');
    } elseif ($kind === 'month') {
        $from->modify('first day of this month');
        $to = clone $from;
        $to->modify('last day of this month');
    } elseif ($kind === 'custom') {
        $fs = (string)rws_arg($p, 'from', '');
        $ts = (string)rws_arg($p, 'to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fs) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts)) {
            rws_fail(422, 'invalid_range', 'Kỳ tùy chọn cần tham số from và to dạng YYYY-MM-DD.');
        }
        $from = new DateTime($fs, $tz);
        $to = new DateTime($ts, $tz);
        if ($from > $to) {
            rws_fail(422, 'invalid_range', 'Tham số from phải trước hoặc bằng to.');
        }
        $days = (int)$from->diff($to)->days + 1;
        if ($days > RWS_MAX_DAYS) {
            rws_fail(422, 'range_too_long', 'Kỳ tùy chọn tối đa ' . RWS_MAX_DAYS . ' ngày.');
        }
    }
    $from_s = $from->format('Y-m-d');
    $to_s = $to->format('Y-m-d');
    $days = (int)$from->diff($to)->days + 1;
    $prev_to = clone $from;
    $prev_to->modify('-1 day');
    $prev_from = clone $prev_to;
    $prev_from->modify('-' . ($days - 1) . ' days');
    $today = $now->format('Y-m-d');
    return array(
        'kind' => $kind,
        'date' => $date_str,
        'from' => $from_s,
        'to' => $to_s,
        'days' => $days,
        'prev_from' => $prev_from->format('Y-m-d'),
        'prev_to' => $prev_to->format('Y-m-d'),
        'timezone' => $tz->getName(),
        'reset_time' => rws_setting('reset_time', '00:00'),
        'now' => $now->format('Y-m-d H:i:s'),
        'today' => $today,
        'contains_today' => ($today >= $from_s && $today <= $to_s),
    );
}

function rws_period_month_of($period) {
    if ($period['kind'] === 'month') { return $period; }
    $tz = rws_tz();
    $from = new DateTime($period['from'], $tz);
    $from->modify('first day of this month');
    $to = clone $from;
    $to->modify('last day of this month');
    $p = $period;
    $p['kind'] = 'month';
    $p['date'] = $period['from'];
    $p['from'] = $from->format('Y-m-d');
    $p['to'] = $to->format('Y-m-d');
    $p['days'] = (int)$from->diff($to)->days + 1;
    $p['contains_today'] = ($p['today'] >= $p['from'] && $p['today'] <= $p['to']);
    return $p;
}

function rws_prev_period($period) {
    $p = $period;
    $p['from'] = $period['prev_from'];
    $p['to'] = $period['prev_to'];
    $p['date'] = $period['prev_to'];
    $p['contains_today'] = false;
    return $p;
}

function rws_days_list($period) {
    $tz = rws_tz();
    $cur = new DateTime($period['from'], $tz);
    $end = new DateTime($period['to'], $tz);
    $out = array();
    while ($cur <= $end) {
        $out[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $out;
}

/* ====================================================================
 * 4. BỘ LỌC (mục 5.1: thiết bị, nhóm, proxy, nhà cung cấp, tag)
 * ==================================================================== */
function rws_filters($p) {
    $acc_sql = '';
    $acc_args = array();
    $run_sql = '';
    $run_args = array();
    $dev_sql = '';
    $dev_args = array();
    $applied = array();
    $warnings = array();

    if (rws_has_col('accounts', 'deleted_at')) {
        $acc_sql .= ' AND a.deleted_at IS NULL';
        $applied['deleted'] = 'active';
    }

    $v = rws_arg($p, 'account_id', '');
    if ($v !== '' && $v !== null) {
        $id = (int)$v;
        if ($id <= 0) { rws_fail(422, 'invalid_filter', 'Bộ lọc account_id phải là số nguyên dương.'); }
        $acc_sql .= ' AND a.id = ?';
        $acc_args[] = $id;
        $applied['account_id'] = $id;
    }

    $v = rws_arg($p, 'device_id', '');
    if ($v !== '' && $v !== null) {
        $id = (int)$v;
        if ($id <= 0) { rws_fail(422, 'invalid_filter', 'Bộ lọc device_id phải là số nguyên dương.'); }
        if (!rws_has_col('accounts', 'device_id')) {
            rws_fail(422, 'filter_unavailable', 'Bộ lọc device_id chưa dùng được (thiếu cột accounts.device_id).');
        }
        $run_sql .= ' AND (r.device_id = ? OR (r.device_id IS NULL AND a.device_id = ?))';
        $run_args[] = $id;
        $run_args[] = $id;
        $dev_sql .= ' AND a.device_id = ?';
        $dev_args[] = $id;
        $applied['device_id'] = $id;
    }

    $v = trim((string)rws_arg($p, 'group_label', ''));
    if ($v !== '') {
        if (!rws_has_col('accounts', 'group_label')) {
            rws_fail(422, 'filter_unavailable', 'Bộ lọc nhóm (group_label) chưa dùng được (thiếu cột accounts.group_label).');
        }
        $acc_sql .= ' AND a.group_label = ?';
        $acc_args[] = v4s_cut($v, 64);
        $applied['group_label'] = v4s_cut($v, 64);
    }

    $v = trim((string)rws_arg($p, 'tag', ''));
    if ($v !== '') {
        if (!rws_has_col('accounts', 'tags')) {
            rws_fail(422, 'filter_unavailable', 'Bộ lọc tag chưa dùng được (thiếu cột accounts.tags).');
        }
        $acc_sql .= ' AND a.tags LIKE ?';
        $acc_args[] = '%' . v4s_cut($v, 64) . '%';
        $applied['tag'] = v4s_cut($v, 64);
    }

    $v = rws_arg($p, 'proxy_id', '');
    if ($v !== '' && $v !== null) {
        $id = (int)$v;
        if ($id < 0) { rws_fail(422, 'invalid_filter', 'Bộ lọc proxy_id phải là số nguyên không âm (0 = không proxy).'); }
        if (!rws_has_col('accounts', 'proxy_id')) {
            rws_fail(422, 'filter_unavailable', 'Bộ lọc proxy_id chưa dùng được (thiếu cột accounts.proxy_id).');
        }
        if ($id === 0) {
            $acc_sql .= ' AND (a.proxy_id IS NULL OR a.proxy_id = 0)';
        } else {
            $acc_sql .= ' AND a.proxy_id = ?';
            $acc_args[] = $id;
        }
        $applied['proxy_id'] = $id;
    }

    $v = trim((string)rws_arg($p, 'provider', ''));
    if ($v !== '') {
        if (!rws_has_table('proxies')) {
            rws_fail(422, 'filter_unavailable', 'Bộ lọc nhà cung cấp chưa dùng được (thiếu bảng proxies).');
        }
        $acc_sql .= ' AND a.proxy_id IN (SELECT id FROM proxies WHERE group_key = ? OR label = ? OR host = ?)';
        $acc_args[] = v4s_cut($v, 64);
        $acc_args[] = v4s_cut($v, 64);
        $acc_args[] = v4s_cut($v, 64);
        $applied['provider'] = v4s_cut($v, 64);
    }

    return array(
        'acc_sql' => $acc_sql,
        'acc_args' => $acc_args,
        'run_sql' => $run_sql,
        'run_args' => $run_args,
        'dev_sql' => $dev_sql,
        'dev_args' => $dev_args,
        'applied' => $applied,
        'warnings' => $warnings,
    );
}

/* ====================================================================
 * 5. BIỂU THỨC SQL DÙNG CHUNG
 * ==================================================================== */
function rws_dexpr($alias) {
    return "COALESCE(NULLIF(" . $alias . ".date,''), substr(" . $alias . ".started_at,1,10))";
}
function rws_okexpr($alias) {
    return $alias . ".status IN ('ok','done','success','completed')";
}
function rws_errexpr($alias) {
    return "(" . $alias . ".status IN ('error','failed','fail') OR (" . $alias . ".error IS NOT NULL AND TRIM(" . $alias . ".error) <> ''))";
}
function rws_knownexpr($alias) {
    return "(" . $alias . ".points_before IS NOT NULL AND " . $alias . ".points_after IS NOT NULL)";
}
function rws_pointsexpr($alias) {
    return "CASE WHEN " . rws_knownexpr($alias) . " THEN (" . $alias . ".points_after - " . $alias . ".points_before) ELSE 0 END";
}

/* ====================================================================
 * 6. TỔNG HỢP DỮ LIỆU (một nguồn dùng chung cho mọi action → số liệu nhất quán)
 * ==================================================================== */
function rws_agg($period, $F) {
    $db = rws_ro();
    $d = rws_dexpr('r');
    $where = ' WHERE ' . $d . ' BETWEEN ? AND ?' . $F['acc_sql'] . $F['run_sql'];
    $args = array_merge(array($period['from'], $period['to']), $F['acc_args'], $F['run_args']);

    /* Q1: theo ngày */
    $sql = 'SELECT ' . $d . ' AS d,' 
        . ' COUNT(*) AS runs,'
        . ' SUM(CASE WHEN ' . rws_okexpr('r') . ' THEN 1 ELSE 0 END) AS runs_ok,'
        . ' SUM(CASE WHEN ' . rws_errexpr('r') . ' THEN 1 ELSE 0 END) AS runs_err,'
        . ' SUM(CASE WHEN ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_known,'
        . ' SUM(CASE WHEN NOT ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_unknown,'
        . ' SUM(' . rws_pointsexpr('r') . ') AS points,'
        . ' COUNT(DISTINCT r.account_id) AS accounts_ran,'
        . ' COUNT(DISTINCT CASE WHEN ' . rws_okexpr('r') . ' THEN r.account_id END) AS active_accounts,'
        . ' SUM(CASE WHEN length(r.started_at) >= 16 THEN 1 ELSE 0 END) AS runs_with_time,'
        . ' SUM(CASE WHEN length(r.started_at) >= 19 AND length(r.finished_at) >= 19 THEN 1 ELSE 0 END) AS timed_runs,'
        . ' SUM(CASE WHEN length(r.started_at) >= 19 AND length(r.finished_at) >= 19 THEN (julianday(r.finished_at) - julianday(r.started_at)) * 86400.0 ELSE 0 END) AS duration_s,'
        . ' MIN(CASE WHEN length(r.started_at) >= 19 THEN r.started_at END) AS first_ts,'
        . ' MAX(CASE WHEN length(r.started_at) >= 19 THEN r.started_at END) AS last_ts'
        . ' FROM runs r LEFT JOIN accounts a ON a.id = r.account_id' . $where . ' GROUP BY d ORDER BY d';
    $st = $db->prepare($sql);
    $st->execute($args);
    $days = array();
    $totals = array('runs' => 0, 'runs_ok' => 0, 'runs_err' => 0, 'runs_known' => 0, 'runs_unknown' => 0,
        'points' => 0, 'accounts_ran' => 0, 'active_accounts' => 0, 'runs_with_time' => 0,
        'timed_runs' => 0, 'duration_s' => 0.0, 'first_ts' => null, 'last_ts' => null,
        'orphan_runs' => 0);
    foreach ($st->fetchAll() as $r) {
        $row = array(
            'runs' => (int)$r['runs'],
            'runs_ok' => (int)$r['runs_ok'],
            'runs_err' => (int)$r['runs_err'],
            'runs_known' => (int)$r['runs_known'],
            'runs_unknown' => (int)$r['runs_unknown'],
            'points' => (int)$r['points'],
            'accounts_ran' => (int)$r['accounts_ran'],
            'active_accounts' => (int)$r['active_accounts'],
            'runs_with_time' => (int)$r['runs_with_time'],
            'timed_runs' => (int)$r['timed_runs'],
            'duration_s' => (float)$r['duration_s'],
            'first_ts' => $r['first_ts'],
            'last_ts' => $r['last_ts'],
        );
        $days[(string)$r['d']] = $row;
        foreach (array('runs', 'runs_ok', 'runs_err', 'runs_known', 'runs_unknown', 'points', 'runs_with_time', 'timed_runs') as $k) {
            $totals[$k] += $row[$k];
        }
        $totals['duration_s'] += $row['duration_s'];
        if ($row['first_ts'] !== null && ($totals['first_ts'] === null || $row['first_ts'] < $totals['first_ts'])) { $totals['first_ts'] = $row['first_ts']; }
        if ($row['last_ts'] !== null && ($totals['last_ts'] === null || $row['last_ts'] > $totals['last_ts'])) { $totals['last_ts'] = $row['last_ts']; }
    }
    $totals['accounts_ran'] = 0;   // tính lại theo distinct toàn kỳ
    $totals['active_accounts'] = 0;

    /* Q2: theo account × ngày */
    $acc_cols = '';
    if (rws_has_col('accounts', 'device_id')) { $acc_cols .= ', MAX(a.device_id) AS device_id'; }
    if (rws_has_col('accounts', 'proxy_id')) { $acc_cols .= ', MAX(a.proxy_id) AS proxy_id'; }
    if (rws_has_col('accounts', 'group_label')) { $acc_cols .= ', MAX(a.group_label) AS group_label'; }
    if (rws_has_col('accounts', 'tags')) { $acc_cols .= ', MAX(a.tags) AS tags'; }
    $sql2 = 'SELECT r.account_id AS account_id, ' . $d . ' AS d,'
        . ' MAX(a.email) AS email, MAX(a.status) AS status' . $acc_cols . ','
        . ' COUNT(*) AS runs,'
        . ' SUM(CASE WHEN ' . rws_okexpr('r') . ' THEN 1 ELSE 0 END) AS runs_ok,'
        . ' SUM(CASE WHEN ' . rws_errexpr('r') . ' THEN 1 ELSE 0 END) AS runs_err,'
        . ' SUM(CASE WHEN ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_known,'
        . ' SUM(CASE WHEN NOT ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_unknown,'
        . ' SUM(' . rws_pointsexpr('r') . ') AS points,'
        . ' MIN(r.started_at) AS first_start, MAX(r.started_at) AS last_start'
        . ' FROM runs r LEFT JOIN accounts a ON a.id = r.account_id' . $where . ' GROUP BY r.account_id, d ORDER BY r.account_id, d';
    $st2 = $db->prepare($sql2);
    $st2->execute($args);
    $accounts = array();
    $active_set = array();
    $ran_set = array();
    foreach ($st2->fetchAll() as $r) {
        /* Run mồ côi (account_id NULL hoặc không khớp accounts) KHÔNG được lặng lẽ bỏ:
           gom vào nhóm id=0, cộng vào totals.orphan_runs để bộc lộ trong data_confidence,
           nhưng không đếm là account (không vào accounts_ran/active_accounts). */
        $is_orphan = ($r['account_id'] === null || $r['email'] === null);
        $aid = $is_orphan ? 0 : (int)$r['account_id'];
        if ($is_orphan) { $totals['orphan_runs'] += (int)$r['runs']; }
        if (!isset($accounts[$aid])) {
            $accounts[$aid] = array(
                'id' => $is_orphan ? null : $aid,
                'orphan' => $is_orphan,
                'email' => $is_orphan ? '' : (string)$r['email'],
                'status' => $is_orphan ? 'orphan' : (string)$r['status'],
                'device_id' => isset($r['device_id']) ? (int)$r['device_id'] : null,
                'proxy_id' => isset($r['proxy_id']) ? $r['proxy_id'] : null,
                'group_label' => isset($r['group_label']) ? (string)$r['group_label'] : '',
                'tags' => isset($r['tags']) ? (string)$r['tags'] : '',
                'days' => array(),
                'totals' => array('runs' => 0, 'runs_ok' => 0, 'runs_err' => 0, 'runs_known' => 0, 'runs_unknown' => 0, 'points' => 0),
                'last_start' => null,
            );
        }
        $row = array(
            'runs' => (int)$r['runs'],
            'runs_ok' => (int)$r['runs_ok'],
            'runs_err' => (int)$r['runs_err'],
            'runs_known' => (int)$r['runs_known'],
            'runs_unknown' => (int)$r['runs_unknown'],
            'points' => (int)$r['points'],
        );
        $accounts[$aid]['days'][(string)$r['d']] = $row;
        foreach (array('runs', 'runs_ok', 'runs_err', 'runs_known', 'runs_unknown', 'points') as $k) {
            $accounts[$aid]['totals'][$k] += $row[$k];
        }
        if ($r['last_start'] !== null && ($accounts[$aid]['last_start'] === null || $r['last_start'] > $accounts[$aid]['last_start'])) {
            $accounts[$aid]['last_start'] = (string)$r['last_start'];
        }
        if (!$is_orphan) {
            $ran_set[$aid] = true;
            if ($row['runs_ok'] > 0) { $active_set[$aid] = true; }
        }
    }
    $totals['accounts_ran'] = count($ran_set);
    $totals['active_accounts'] = count($active_set);

    /* Q3: task_events theo ngày/nhóm/trạng thái/solved_by */
    $tasks = array('groups' => array(), 'totals' => rws_empty_task_stats(), 'rows' => array(), 'available' => false);
    if (rws_has_table('task_events')) {
        $tasks['available'] = true;
        $sql3 = "SELECT NULLIF(te.date,'') AS d, te.task_type AS task_type, te.task_key AS task_key,"
            . " te.state AS state, COALESCE(NULLIF(te.solved_by,''),'') AS solved_by, COUNT(*) AS n,"
            . " SUM(CASE WHEN te.points_earned IS NOT NULL THEN te.points_earned ELSE 0 END) AS points_earned"
            . " FROM task_events te LEFT JOIN accounts a ON a.id = te.account_id"
            . " WHERE NULLIF(te.date,'') BETWEEN ? AND ?" . $F['acc_sql']
            . ' GROUP BY d, te.task_type, te.task_key, te.state, solved_by';
        $st3 = $db->prepare($sql3);
        $st3->execute(array_merge(array($period['from'], $period['to']), $F['acc_args']));
        foreach ($st3->fetchAll() as $r) {
            $group = rws_task_group($r['task_type'], $r['task_key']);
            $cls = rws_task_state_class($r['state']);
            $sb = rws_solved_by_class($r['solved_by']);
            $n = (int)$r['n'];
            if (!isset($tasks['groups'][$group])) { $tasks['groups'][$group] = rws_empty_task_stats(); }
            rws_task_add($tasks['groups'][$group], $cls, $sb, $n, (int)$r['points_earned']);
            rws_task_add($tasks['totals'], $cls, $sb, $n, (int)$r['points_earned']);
            $tasks['rows'][] = array('date' => $r['d'], 'group' => $group, 'task_key' => $r['task_key'],
                'task_type' => $r['task_type'], 'state' => $r['state'], 'solved_by' => $r['solved_by'], 'n' => $n);
        }
    }

    /* Q4: lỗi theo nguyên nhân */
    $errors = array();
    $error_rows_truncated = false;
    $err_sel = "SELECT r.status AS status, COALESCE(NULLIF(r.error,''), NULLIF(a.last_error,'')) AS err_text,"
        . (rws_has_col('accounts', 'error_class') ? " a.error_class AS error_class," : " '' AS error_class,")
        . " COUNT(*) AS n"
        . " FROM runs r LEFT JOIN accounts a ON a.id = r.account_id" . $where
        . ' AND ' . rws_errexpr('r')
        . " GROUP BY r.status, err_text, error_class LIMIT " . RWS_MAX_ERROR_ROWS;
    $st4 = $db->prepare($err_sel);
    $st4->execute($args);
    foreach ($st4->fetchAll() as $r) {
        $cls = rws_error_class($r['err_text'], $r['error_class']);
        if (!isset($errors[$cls])) { $errors[$cls] = array('count' => 0, 'samples' => array()); }
        $n = (int)$r['n'];
        $errors[$cls]['count'] += $n;
        $txt = rws_scrub((string)$r['err_text']);
        if ($txt !== '' && count($errors[$cls]['samples']) < 3) { $errors[$cls]['samples'][] = $txt; }
        if (count($errors) + $n > RWS_MAX_ERROR_ROWS) { $error_rows_truncated = true; }
    }

    /* Q5: theo proxy */
    $proxies = array();
    $sql5 = 'SELECT a.proxy_id AS proxy_id, COUNT(*) AS runs,'
        . ' SUM(CASE WHEN ' . rws_okexpr('r') . ' THEN 1 ELSE 0 END) AS runs_ok,'
        . ' SUM(CASE WHEN ' . rws_errexpr('r') . ' THEN 1 ELSE 0 END) AS runs_err,'
        . ' SUM(CASE WHEN ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_known,'
        . ' SUM(' . rws_pointsexpr('r') . ') AS points,'
        . ' COUNT(DISTINCT r.account_id) AS accounts'
        . ' FROM runs r LEFT JOIN accounts a ON a.id = r.account_id' . $where . ' GROUP BY a.proxy_id';
    $st5 = $db->prepare($sql5);
    $st5->execute($args);
    foreach ($st5->fetchAll() as $r) {
        $key = ($r['proxy_id'] === null || $r['proxy_id'] === '') ? 'none' : (string)(int)$r['proxy_id'];
        $proxies[$key] = array(
            'proxy_id' => $key === 'none' ? null : (int)$r['proxy_id'],
            'runs' => (int)$r['runs'],
            'runs_ok' => (int)$r['runs_ok'],
            'runs_err' => (int)$r['runs_err'],
            'runs_known' => (int)$r['runs_known'],
            'points' => (int)$r['points'],
            'accounts' => (int)$r['accounts'],
        );
    }

    /* Q6: theo giờ (chỉ khi started_at có giờ) */
    $hourly = array();
    $sql6 = "SELECT substr(r.started_at,12,2) AS h, COUNT(*) AS runs,"
        . ' SUM(CASE WHEN ' . rws_okexpr('r') . ' THEN 1 ELSE 0 END) AS runs_ok,'
        . ' SUM(CASE WHEN ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_known,'
        . ' SUM(CASE WHEN NOT ' . rws_knownexpr('r') . ' THEN 1 ELSE 0 END) AS runs_unknown,'
        . ' SUM(' . rws_pointsexpr('r') . ') AS points,'
        . " SUM(CASE WHEN lower(COALESCE(r.error,'')) LIKE '%human%' OR lower(COALESCE(r.error,'')) LIKE '%verify%'"
        . " OR lower(COALESCE(r.error,'')) LIKE '%2fa%' OR lower(COALESCE(r.error,'')) LIKE '%otp%'"
        . " OR lower(COALESCE(r.error,'')) LIKE '%xác minh%' THEN 1 ELSE 0 END) AS verify_n"
        . " FROM runs r LEFT JOIN accounts a ON a.id = r.account_id" . $where
        . ' AND length(r.started_at) >= 16 GROUP BY h ORDER BY h';
    $st6 = $db->prepare($sql6);
    $st6->execute($args);
    foreach ($st6->fetchAll() as $r) {
        $hourly[(string)$r['h']] = array(
            'runs' => (int)$r['runs'],
            'runs_ok' => (int)$r['runs_ok'],
            'runs_known' => (int)$r['runs_known'],
            'runs_unknown' => (int)$r['runs_unknown'],
            'points' => (int)$r['points'],
            'verify_n' => (int)$r['verify_n'],
        );
    }

    /* Số account tổng/đủ điều kiện */
    $counts = array('total_accounts' => 0, 'eligible' => 0, 'disabled_or_locked' => 0);
    $sqlc = "SELECT COUNT(*) AS total,"
        . " SUM(CASE WHEN a.status IN ('disabled','locked') THEN 1 ELSE 0 END) AS dl,"
        . " SUM(CASE WHEN a.status NOT IN ('disabled','locked') THEN 1 ELSE 0 END) AS elig"
        . ' FROM accounts a WHERE 1=1' . $F['acc_sql'] . $F['dev_sql'];
    $stc = $db->prepare($sqlc);
    $stc->execute(array_merge($F['acc_args'], $F['dev_args']));
    $rc = $stc->fetch();
    if ($rc) {
        $counts['total_accounts'] = (int)$rc['total'];
        $counts['disabled_or_locked'] = (int)$rc['dl'];
        $counts['eligible'] = (int)$rc['elig'];
    }

    return array(
        'totals' => $totals,
        'days' => $days,
        'accounts' => $accounts,
        'tasks' => $tasks,
        'errors' => $errors,
        'error_rows_truncated' => $error_rows_truncated,
        'proxies' => $proxies,
        'hourly' => $hourly,
        'counts' => $counts,
    );
}

/* ====================================================================
 * 7. PHÂN LOẠI NHIỆM VỤ / LỖI (mục 5.2)
 * ==================================================================== */
function rws_task_group($task_type, $task_key) {
    $s = strtolower(trim((string)$task_type . ' ' . (string)$task_key));
    if ($s === '') { return 'Khác'; }
    if (preg_match('/daily|check_?in|keep_?earning|diem_?danh/', $s)) { return 'Daily set'; }
    if (preg_match('/search|tim_?kiem/', $s)) { return 'Search'; }
    if (preg_match('/quiz|poll|survey/', $s)) { return 'Quiz/Poll'; }
    if (preg_match('/game|play_?game/', $s)) { return 'Game'; }
    if (preg_match('/news|article|doc_?bao|bao|read/', $s)) { return 'Đọc báo'; }
    if (preg_match('/click|offer|promo|ads|browse/', $s)) { return 'Click/Offer'; }
    return 'Khác';
}

function rws_task_group_rules() {
    return array(
        'Daily set' => 'task_type|task_key khớp daily|check_in|keep_earning|điểm danh',
        'Search' => 'khớp search|tìm kiếm',
        'Quiz/Poll' => 'khớp quiz|poll|survey',
        'Game' => 'khớp game|play_game',
        'Đọc báo' => 'khớp news|article|đọc báo|read',
        'Click/Offer' => 'khớp click|offer|promo|ads|browse',
        'Khác' => 'không khớp nhóm nào',
    );
}

function rws_task_state_class($state) {
    $s = strtolower(trim((string)$state));
    if (in_array($s, array('done', 'ok', 'completed', 'success', 'solved'), true)) { return 'done'; }
    if (in_array($s, array('needs_human', 'manual', 'human_required'), true)) { return 'needs_human'; }
    if (in_array($s, array('unavailable', 'not_available', 'skipped'), true)) { return 'unavailable'; }
    if (in_array($s, array('error', 'failed', 'fail', 'timeout'), true)) { return 'failed'; }
    return 'pending';
}

function rws_solved_by_class($solved_by) {
    $s = strtolower(trim((string)$solved_by));
    if ($s === 'skill') { return 'skill'; }
    if ($s === 'ai') { return 'ai'; }
    if ($s === 'manual' || $s === 'human') { return 'manual'; }
    return 'other';
}

function rws_empty_task_stats() {
    return array(
        'total' => 0, 'available' => 0, 'done' => 0, 'failed' => 0, 'needs_human' => 0,
        'pending' => 0, 'unavailable' => 0, 'points_earned' => 0,
        'solved_by' => array('skill' => 0, 'ai' => 0, 'manual' => 0, 'other' => 0),
    );
}

function rws_task_add(&$bucket, $cls, $sb, $n, $points) {
    $bucket['total'] += $n;
    $bucket['points_earned'] += $points;
    if (!isset($bucket[$cls])) { $bucket[$cls] = 0; }
    $bucket[$cls] += $n;
    if ($cls !== 'unavailable') { $bucket['available'] += $n; }
    if (!isset($bucket['solved_by'][$sb])) { $bucket['solved_by'][$sb] = 0; }
    $bucket['solved_by'][$sb] += $n;
}

function rws_error_class($text, $fallback = '') {
    $known = array('proxy', 'login', 'verify', 'captcha', 'timeout', 'browser', 'other');
    $fb = strtolower(trim((string)$fallback));
    $t = (string)$text;
    if (trim($t) === '' && in_array($fb, $known, true)) { return $fb; }
    $s = strtolower($t . ' ' . $fb);
    if (preg_match('/captcha|recaptcha|hcaptcha|turnstile|cloudflare/', $s)) { return 'captcha'; }
    if (preg_match('/needs_?human|human|verify|verification|2fa|\botp\b|sms|xac_?minh|xác minh/', $s)) { return 'verify'; }
    if (preg_match('/login|sign_?in|password|credential|auth|session|cookie|dang_?nhap|đăng nhập/', $s)) { return 'login'; }
    if (preg_match('/proxy|socks|tunnel|407|econn|gateway|exit_?ip|ket_?noi|kết nối/', $s)) { return 'proxy'; }
    if (preg_match('/timeout|timed_?out|deadline|qua_?han|quá hạn/', $s)) { return 'timeout'; }
    if (preg_match('/browser|chrome|chromium|playwright|selenium|renderer|navigate|webgl|context|crash/', $s)) { return 'browser'; }
    if (in_array($fb, $known, true)) { return $fb; }
    return 'other';
}

function rws_error_label($class) {
    $map = array(
        'proxy' => 'Proxy',
        'login' => 'Đăng nhập',
        'verify' => 'Xác minh / needs_human',
        'captcha' => 'Captcha',
        'timeout' => 'Timeout',
        'browser' => 'Trình duyệt',
        'other' => 'Khác',
    );
    return isset($map[$class]) ? $map[$class] : $class;
}

function rws_error_class_list() {
    return array('proxy', 'login', 'verify', 'captcha', 'timeout', 'browser', 'other');
}

/* ====================================================================
 * 8. KPI (mục 5.2) + so sánh kỳ trước (mục 5.1)
 * ==================================================================== */
function rws_kpi($agg, $period) {
    $t = $agg['totals'];
    $c = $agg['counts'];
    $days_with_runs = 0;
    foreach ($agg['days'] as $row) { if ($row['runs'] > 0) { $days_with_runs++; } }

    // Điểm thăng: run thiếu điểm KHÔNG coi là 0 (mục 5.2)
    if ($t['runs_known'] > 0) {
        $points = $t['points'];
    } elseif ($t['runs'] > 0) {
        $points = null; // toàn bộ run chưa xác định điểm → không hiển thị 0
    } else {
        $points = 0;
    }

    $task_avail = $agg['tasks']['available'] ? $agg['tasks']['totals']['available'] : null;
    $task_done = $agg['tasks']['available'] ? $agg['tasks']['totals']['done'] : null;
    $task_total = $agg['tasks']['available'] ? $agg['tasks']['totals']['total'] : null;
    $skill_n = $agg['tasks']['available'] ? $agg['tasks']['totals']['solved_by']['skill'] : null;
    $ai_n = $agg['tasks']['available'] ? $agg['tasks']['totals']['solved_by']['ai'] : null;

    $avg_duration = ($t['timed_runs'] > 0) ? round($t['duration_s'] / $t['timed_runs'], 1) : null;
    $throughput = null;
    if ($t['active_accounts'] > 0 && $t['first_ts'] !== null && $t['last_ts'] !== null) {
        $a = new DateTime($t['first_ts']);
        $b = new DateTime($t['last_ts']);
        $span_h = ((float)$b->format('U') - (float)$a->format('U')) / 3600.0;
        if ($span_h > 0) { $throughput = round($t['active_accounts'] / $span_h, 2); }
    }

    // Tỉ lệ hoàn tất ngày: (account, ngày) hoàn tất ÷ (account đủ điều kiện × số ngày có chạy)
    $done_pairs = 0;
    foreach ($agg['accounts'] as $acc) {
        if (rws_is_blocked_status($acc['status'])) { continue; }
        foreach ($acc['days'] as $row) {
            if ($row['runs_ok'] > 0 && $row['runs_err'] == 0) { $done_pairs++; }
        }
    }
    $day_den = $c['eligible'] * $days_with_runs;

    $eta = null;
    if ($period['contains_today'] && $throughput !== null && $c['eligible'] > 0) {
        $done_today = 0;
        foreach ($agg['accounts'] as $acc) {
            if (rws_is_blocked_status($acc['status'])) { continue; }
            $row = isset($acc['days'][$period['today']]) ? $acc['days'][$period['today']] : null;
            if ($row !== null && $row['runs_ok'] > 0 && $row['runs_err'] == 0) { $done_today++; }
        }
        $remaining = $c['eligible'] - $done_today;
        if ($remaining > 0 && $throughput > 0) {
            $eta_hours = round($remaining / $throughput, 2);
            $eta_dt = new DateTime($period['now']);
            $eta_dt->modify('+' . (int)ceil($eta_hours * 60) . ' minutes');
            $eta = array('eta_hours' => $eta_hours, 'eta_at' => $eta_dt->format('Y-m-d H:i:s'),
                'remaining_accounts' => $remaining, 'done_today' => $done_today);
        } else {
            $eta = array('eta_hours' => 0.0, 'eta_at' => $period['now'], 'remaining_accounts' => 0, 'done_today' => $done_today);
        }
    }

    return array(
        'points_gained' => $points,
        'points_unknown_runs' => (int)$t['runs_unknown'],
        'points_incomplete' => ($t['runs_unknown'] > 0),
        'active_accounts' => (int)$t['active_accounts'],
        'total_accounts' => (int)$c['total_accounts'],
        'eligible_accounts' => (int)$c['eligible'],
        'disabled_or_locked_accounts' => (int)$c['disabled_or_locked'],
        'avg_points_per_active' => ($points === null) ? null : rws_div($points, $t['active_accounts']),
        'avg_points_per_total' => ($points === null) ? null : rws_div($points, $c['total_accounts']),
        'avg_points_per_active_per_day' => ($points === null) ? null : rws_div($points, $t['active_accounts'] * max(1, $days_with_runs)),
        'days_with_runs' => $days_with_runs,
        'task_completion_rate' => ($task_avail === null || $task_avail === 0) ? null : rws_rate($task_done, $task_avail),
        'tasks_available' => $task_avail,
        'tasks_done' => $task_done,
        'tasks_total' => $task_total,
        'run_success_rate' => rws_rate($t['runs_ok'], $t['runs']),
        'run_error_rate' => rws_rate($t['runs_err'], $t['runs']),
        'runs_total' => (int)$t['runs'],
        'runs_ok' => (int)$t['runs_ok'],
        'runs_err' => (int)$t['runs_err'],
        'day_completion_rate' => ($day_den > 0) ? rws_rate($done_pairs, $day_den) : null,
        'avg_duration_s' => $avg_duration,
        'throughput_per_hour' => $throughput,
        'eta_today' => $eta,
        'skill_coverage_rate' => ($task_total === null || $task_total === 0) ? null : rws_rate($skill_n, $task_total),
        'ai_calls' => $ai_n,
    );
}

function rws_is_blocked_status($status) {
    $s = strtolower(trim((string)$status));
    return ($s === 'disabled' || $s === 'locked');
}

function rws_compare($cur, $prev) {
    $out = array();
    foreach ($cur as $k => $v) {
        if (is_array($v)) { continue; } // bỏ qua object như eta_today
        $pv = array_key_exists($k, $prev) ? $prev[$k] : null;
        $d = rws_delta_pct($v, $pv);
        $out[$k] = array('prev' => $pv, 'delta_pct' => $d['delta_pct'], 'direction' => $d['direction']);
    }
    return $out;
}

function rws_formulas() {
    return array(
        'points_gained' => 'Điểm thăng = Σ (points_after − points_before) của các run có đủ điểm trước và điểm sau. Run thiếu điểm không tính vào tổng (xem points_unknown_runs), không coi là 0.',
        'points_unknown_runs' => 'Số run KHÔNG đọc được điểm (thiếu points_before hoặc points_after).',
        'active_accounts' => 'Account hoạt động = số account có ≥ 1 run thành công trong kỳ.',
        'avg_points_per_active' => 'Điểm TB / account hoạt động = điểm thăng ÷ account hoạt động.',
        'avg_points_per_total' => 'Điểm TB / tổng account = điểm thăng ÷ tổng account (chưa xóa mềm). Hiển thị cạnh avg_points_per_active để không gây hiểu lầm khi nhiều account bị chặn.',
        'avg_points_per_active_per_day' => 'Điểm TB / account / ngày = điểm thăng ÷ (account hoạt động × số ngày có chạy).',
        'task_completion_rate' => 'Tỉ lệ hoàn thành nhiệm vụ = nhiệm vụ done ÷ nhiệm vụ khả dụng (task_events; nhóm state=unavailable không tính vào mẫu).',
        'run_success_rate' => 'Tỉ lệ run thành công = run ok ÷ tổng run.',
        'day_completion_rate' => 'Tỉ lệ hoàn tất ngày = số (account, ngày) hoàn tất ÷ (account đủ điều kiện × số ngày có chạy); hoàn tất = có run thành công và không run lỗi trong ngày. Account đủ điều kiện = chưa xóa mềm, trừ disabled/locked.',
        'run_error_rate' => 'Tỉ lệ lỗi = run lỗi ÷ tổng run.',
        'avg_duration_s' => 'Thời gian TB / account = trung bình (finished_at − started_at) của run đủ 2 mốc thời gian đầy đủ (giờ:phút:giây).',
        'throughput_per_hour' => 'Throughput = account hoạt động ÷ số giờ giữa run đầu tiên và run cuối cùng trong kỳ.',
        'eta_today' => 'ETA hôm nay = (account đủ điều kiện − account hoàn tất hôm nay) ÷ throughput, tính từ thời điểm hiện tại.',
        'skill_coverage_rate' => 'Tỉ lệ phủ skill = nhiệm vụ solved_by=skill ÷ tổng nhiệm vụ.',
        'ai_calls' => 'Lượt AI tiêu thụ = số nhiệm vụ solved_by=ai.',
        'streak_days' => 'Chuỗi ngày = số ngày liên tiếp tính từ cuối kỳ ngược về có điểm thăng > 0.',
        'weak_label' => 'Nhãn «yếu»: 10% account có điểm kỳ thấp nhất (khi bảng có ≥ 5 account).',
        'broken_streak_label' => 'Nhãn «đứt chuỗi»: ≥ 3 ngày liên tiếp không có điểm.',
        'spark' => 'Mảng điểm theo từng ngày trong kỳ: số = điểm thăng ngày đó; null = ngày có run nhưng chưa xác định được điểm; 0 = ngày không có run.',
    );
}

/* ====================================================================
 * 9. MỤC TIÊU + DỰ BÁO + ĐỘ TIN CẬY + CẢNH BÁO
 * ==================================================================== */
function rws_goals($period, $kpi, $group_label) {
    $target = null;
    $source = 'none';
    $group_targets = array();
    if (rws_has_table('goals') && rws_has_col('goals', 'target_points_per_day')) {
        $st = rws_ro()->query('SELECT scope, target_points_per_day FROM goals');
        foreach ($st->fetchAll() as $r) {
            $scope = trim((string)$r['scope']);
            $val = ($r['target_points_per_day'] === null) ? null : (float)$r['target_points_per_day'];
            if ($scope === '' || $scope === 'global' || $scope === 'all') {
                $target = $val;
                $source = 'goals';
            } else {
                $group_targets[$scope] = $val;
            }
        }
    }
    if ($target === null) {
        $v = rws_setting('goal_points_per_day', '');
        if ($v !== '' && is_numeric($v)) {
            $target = (float)$v;
            $source = 'settings';
        }
    }
    if ($group_label !== '' ) {
        $v = rws_setting('goal_points_per_day:' . $group_label, '');
        if ($v !== '' && is_numeric($v)) { $group_targets[$group_label] = (float)$v; }
    }
    $target_period = ($target === null) ? null : round($target * $period['days'], 2);
    $progress = null;
    if ($target_period !== null && $target_period > 0 && $kpi['points_gained'] !== null) {
        $progress = round($kpi['points_gained'] / $target_period * 100, 1);
    }
    return array(
        'target_points_per_day' => $target,
        'target_period_points' => $target_period,
        'progress_pct' => $progress,
        'below_70_warning' => ($progress !== null && $progress < 70),
        'source' => $source,
        'group_targets' => $group_targets,
    );
}

function rws_forecast($period, $kpi) {
    $today = $period['today'];
    $days_elapsed = 0;
    if ($today >= $period['from']) {
        $end = ($today < $period['to']) ? $today : $period['to'];
        $a = new DateTime($period['from']);
        $b = new DateTime($end);
        $days_elapsed = (int)$a->diff($b)->days + 1;
    }
    $points = $kpi['points_gained'];
    $avg = ($points === null || $days_elapsed <= 0) ? null : round($points / $days_elapsed, 2);
    $projected = ($avg === null) ? null : round($avg * $period['days'], 2);
    return array(
        'method' => 'trung bình điểm/ngày × số ngày của kỳ',
        'days_elapsed' => $days_elapsed,
        'days_total' => $period['days'],
        'points_so_far' => $points,
        'avg_per_day' => $avg,
        'projected_period_points' => $projected,
    );
}

function rws_confidence($agg) {
    $t = $agg['totals'];
    $warnings = array();
    if (!rws_has_table('task_events')) {
        $warnings[] = 'Thiếu bảng task_events (mục 8) → KPI nhiệm vụ/phủ skill/lượt AI = null.';
    } elseif ($t['runs'] >= 0 && $agg['tasks']['totals']['total'] == 0) {
        $warnings[] = 'Bảng task_events rỗng trong kỳ → tỉ lệ hoàn thành nhiệm vụ và phủ skill = null (không phải 0%).';
    }
    if (!rws_has_table('goals') && rws_setting('goal_points_per_day', '') === '') {
        $warnings[] = 'Chưa có bảng goals hay setting goal_points_per_day (mục 8) → mục tiêu/dự báo theo mục tiêu = null.';
    }
    if (rws_has_table('task_events')) {
        foreach (array('skill_id', 'skill_version', 'difficulty') as $col) {
            if (!rws_has_col('task_events', $col)) {
                $warnings[] = 'Thiếu cột task_events.' . $col . ' (mục 8) → không truy vết được skill/độ khó.';
            }
        }
    }
    $low_days = array();
    foreach ($agg['days'] as $day => $row) {
        if ($row['runs'] > 0 && $row['runs_known'] == 0) {
            $low_days[] = $day;
        } elseif ($row['runs'] > 0 && ($row['runs_unknown'] / $row['runs']) > 0.5) {
            $low_days[] = $day;
        }
    }
    $timing = ($t['timed_runs'] > 0);
    if (!$timing) {
        $warnings[] = 'started_at/finished_at chỉ có ngày (mất giờ) → thời gian TB, throughput, ETA, phân bố 24 giờ không tính được (trả null).';
    }
    $orphan = isset($t['orphan_runs']) ? (int)$t['orphan_runs'] : 0;
    if ($orphan > 0) {
        $warnings[] = $orphan . ' run không gắn với tài khoản nào (account_id rỗng hoặc mồ côi) — số liệu nhóm theo tài khoản/proxy bị thiếu nhóm này; nên kiểm lại nguồn dữ liệu worker.';
    }
    return array(
        'runs_total' => (int)$t['runs'],
        'runs_with_points' => (int)$t['runs_known'],
        'runs_points_unknown' => (int)$t['runs_unknown'],
        'orphan_runs' => $orphan,
        'points_unknown_ratio' => rws_rate($t['runs_unknown'], $t['runs']),
        'low_confidence_days' => $low_days,
        'low_confidence_rule' => 'ngày có > 50% run chưa xác định điểm',
        'timing_available' => $timing,
        'hourly_available' => ($t['runs_with_time'] > 0),
        'storage_warnings' => $warnings,
    );
}

function rws_notices($kpi, $conf) {
    $n = array();
    if ($kpi['points_unknown_runs'] > 0) {
        $n[] = $kpi['points_unknown_runs'] . ' run chưa xác định điểm';
    }
    if (count($conf['low_confidence_days']) > 0) {
        $n[] = 'Ngày thiếu độ tin cậy dữ liệu: ' . implode(', ', $conf['low_confidence_days']);
    }
    return $n;
}

function rws_anomalies($period, $F, $agg) {
    $out = array();
    $db = rws_ro();

    /* 1. needs_human > 20% trong 1 giờ */
    if (!empty($agg['hourly'])) {
        foreach ($agg['hourly'] as $h => $row) {
            if ($row['runs'] >= 5) {
                $ratio = $row['verify_n'] / $row['runs'];
                if ($ratio > 0.20) {
                    $out[] = array(
                        'code' => 'needs_human_hot_hour',
                        'severity' => 'warning',
                        'message' => 'Tỉ lệ needs_human ' . round($ratio * 100, 1) . '% trong giờ ' . $h . ' (' . $row['verify_n'] . '/' . $row['runs'] . ' run) — đề nghị giảm song song hoặc kiểm tra proxy.',
                        'data' => array('hour' => $h, 'ratio' => round($ratio, 4), 'verify_n' => $row['verify_n'], 'runs' => $row['runs']),
                    );
                }
            }
        }
    }

    /* 2. điểm giảm > 30% so với TB 7 ngày */
    $days_with_points = array();
    foreach ($agg['days'] as $day => $row) {
        if ($row['runs_known'] > 0) { $days_with_points[$day] = $row['points']; }
    }
    if (count($days_with_points) >= 2) {
        ksort($days_with_points);
        $keys = array_keys($days_with_points);
        $last_day = $keys[count($keys) - 1];
        $last_val = $days_with_points[$last_day];
        $prev_vals = array_slice($days_with_points, max(0, count($keys) - 8), 7, true);
        unset($prev_vals[$last_day]);
        $prev_vals = array_slice($prev_vals, -7);
        if (count($prev_vals) > 0) {
            $avg = array_sum($prev_vals) / count($prev_vals);
            if ($avg > 0 && $last_val < $avg * 0.7) {
                $out[] = array(
                    'code' => 'points_drop',
                    'severity' => 'warning',
                    'message' => 'Điểm ngày ' . $last_day . ' (' . $last_val . ') giảm hơn 30% so với TB 7 ngày (' . round($avg, 1) . ').',
                    'data' => array('date' => $last_day, 'points' => $last_val, 'avg_7d' => round($avg, 2)),
                );
            }
        }
    }

    /* 3. ledger_mismatch + dữ liệu migrate lệch */
    $where = ' WHERE ' . rws_dexpr('r') . ' BETWEEN ? AND ?' . $F['acc_sql'] . $F['run_sql'];
    $args = array_merge(array($period['from'], $period['to']), $F['acc_args'], $F['run_args']);
    $sql = 'SELECT COUNT(*) AS n FROM runs r LEFT JOIN accounts a ON a.id = r.account_id' . $where
        . ' AND r.points_before IS NOT NULL AND r.points_after IS NOT NULL'
        . ' AND r.delta IS NOT NULL AND r.delta <> (r.points_after - r.points_before)';
    $st = $db->prepare($sql);
    $st->execute($args);
    $mismatch_run = (int)$st->fetch()['n'];
    $mismatch_ledger = 0;
    $ledger_unlinked = 0;
    if (rws_has_table('points_ledger')) {
        $st = $db->prepare('SELECT COUNT(*) AS n FROM points_ledger l LEFT JOIN accounts a ON a.id = l.account_id'
            . ' WHERE l.points_before IS NOT NULL AND l.points_after IS NOT NULL'
            . ' AND l.delta IS NOT NULL AND l.delta <> (l.points_after - l.points_before)'
            . " AND COALESCE(NULLIF(l.ts,''), '') <> ''" 
            . ' AND substr(l.ts,1,10) BETWEEN ? AND ?' . $F['acc_sql']);
        $st->execute(array_merge(array($period['from'], $period['to']), $F['acc_args']));
        $mismatch_ledger = (int)$st->fetch()['n'];
        $st = $db->prepare('SELECT COUNT(*) AS n FROM points_ledger WHERE run_id IS NULL');
        $st->execute();
        $ledger_unlinked = (int)$st->fetch()['n'];
    }
    if ($mismatch_run > 0 || $mismatch_ledger > 0) {
        $out[] = array(
            'code' => 'ledger_mismatch',
            'severity' => 'warning',
            'message' => 'Sai lệch giữa runs.delta/points_ledger.delta và hiệu points_after − points_before: '
                . $mismatch_run . ' run, ' . $mismatch_ledger . ' bản ghi ledger. API dùng hiệu points_after − points_before.',
            'data' => array('runs_mismatch' => $mismatch_run, 'ledger_mismatch' => $mismatch_ledger, 'ledger_unlinked' => $ledger_unlinked),
        );
    }

    /* 4. thiết bị offline > 15 phút */
    if (rws_has_table('devices')) {
        $st = $db->query("SELECT device_key, name, status, last_seen FROM devices WHERE revoked = 0 ORDER BY last_seen");
        $now_ts = strtotime($period['now']);
        $stale = array();
        foreach ($st->fetchAll() as $r) {
            $seen = $r['last_seen'] ? strtotime($r['last_seen']) : 0;
            $offline = (strtolower((string)$r['status']) === 'offline');
            if (($offline || ($seen > 0 && ($now_ts - $seen) > 900)) && count($stale) < 5) {
                $stale[] = array('device_key' => (string)$r['device_key'], 'name' => (string)$r['name'],
                    'status' => (string)$r['status'], 'last_seen' => $r['last_seen'],
                    'minutes_stale' => ($seen > 0 ? (int)floor(($now_ts - $seen) / 60) : null));
            }
        }
        if (count($stale) > 0) {
            $out[] = array(
                'code' => 'device_offline',
                'severity' => 'warning',
                'message' => count($stale) . ' thiết bị offline hoặc không liên lạc > 15 phút.',
                'data' => array('devices' => $stale),
            );
        }
    }

    return $out;
}

/* ====================================================================
 * 10. BUILDER TỪNG ACTION
 * ==================================================================== */
function rws_envelope($action, $period, $F, $extra = array()) {
    $env = array(
        'ok' => true,
        'action' => $action,
        'period' => array(
            'kind' => $period['kind'],
            'date' => $period['date'],
            'from' => $period['from'],
            'to' => $period['to'],
            'days' => $period['days'],
            'prev' => array('from' => $period['prev_from'], 'to' => $period['prev_to']),
            'timezone' => $period['timezone'],
            'reset_time' => $period['reset_time'],
        ),
        'filters_applied' => $F['applied'],
        'generated_at' => date('c'),
    );
    return array_merge($env, $extra);
}

function rws_build_summary($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $prev = rws_agg(rws_prev_period($period), $F);
    $kpi = rws_kpi($cur, $period);
    $kpi_prev = rws_kpi($prev, rws_prev_period($period));
    $conf = rws_confidence($cur);
    $goals = rws_goals($period, $kpi, trim((string)rws_arg($p, 'group_label', '')));
    $forecast = rws_forecast($period, $kpi);
    $forecast['target_period_points'] = $goals['target_period_points'];
    $forecast['progress_pct'] = $goals['progress_pct'];

    /* Phễu: Tổng → Đủ ĐK → Đã chạy → Hoàn tất → Có điểm (mục 5.3) */
    $ran = 0;
    $done = 0;
    $with_points = 0;
    foreach ($cur['accounts'] as $acc) {
        if (rws_is_blocked_status($acc['status'])) { continue; }
        $ran++;
        $ok_any = ($acc['totals']['runs_ok'] > 0);
        $done_ok = false;
        foreach ($acc['days'] as $row) {
            if ($row['runs_ok'] > 0 && $row['runs_err'] == 0) { $done_ok = true; }
        }
        if ($ok_any && $done_ok) { $done++; }
        if ($acc['totals']['runs_known'] > 0) { $with_points++; }
    }
    $funnel = array(
        'labels' => array('Tổng', 'Đủ điều kiện', 'Đã chạy', 'Hoàn tất', 'Có điểm'),
        'total' => (int)$cur['counts']['total_accounts'],
        'eligible' => (int)$cur['counts']['eligible'],
        'ran' => $ran,
        'done' => $done,
        'with_points' => $with_points,
        'excluded_disabled_locked' => (int)$cur['counts']['disabled_or_locked'],
    );

    $notices = rws_notices($kpi, $conf);
    if ($goals['below_70_warning']) {
        $notices[] = 'Tiến độ mục tiêu ' . $goals['progress_pct'] . '% — dưới ngưỡng 70%.';
    }

    return rws_envelope('summary', $period, $F, array(
        'kpi' => $kpi,
        'compare' => rws_compare($kpi, $kpi_prev),
        'funnel' => $funnel,
        'goals' => $goals,
        'forecast' => $forecast,
        'anomalies' => rws_anomalies($period, $F, $cur),
        'data_confidence' => $conf,
        'notices' => $notices,
        'formulas' => rws_formulas(),
    ));
}

function rws_build_timeseries($period, $F, $p) {
    $bucket = strtolower((string)rws_arg($p, 'bucket', 'auto'));
    if (!in_array($bucket, array('auto', 'hour', 'day'), true)) {
        rws_fail(422, 'invalid_bucket', 'Tham số bucket chỉ nhận: auto, hour, day.');
    }
    $cur = rws_agg($period, $F);
    $prev = rws_agg(rws_prev_period($period), $F);
    $kpi = rws_kpi($cur, $period);
    $goals = rws_goals($period, $kpi, trim((string)rws_arg($p, 'group_label', '')));
    $conf = rws_confidence($cur);
    $warnings = array();

    if ($bucket === 'auto') {
        $bucket = ($period['kind'] === 'day' && $conf['hourly_available']) ? 'hour' : 'day';
    }

    $series = array();
    $prev_series = array();
    $target = null;

    if ($bucket === 'hour') {
        if (!$conf['hourly_available']) {
            $warnings[] = 'started_at không có phần giờ → không vẽ được phân bố 24 giờ; các ô trả null.';
        }
        for ($h = 0; $h < 24; $h++) {
            $key = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $row = isset($cur['hourly'][$key]) ? $cur['hourly'][$key] : null;
            $series[] = array(
                'bucket' => $key,
                'runs' => $row ? $row['runs'] : 0,
                'runs_ok' => $row ? $row['runs_ok'] : 0,
                'points' => ($row === null) ? 0 : (($row['runs_known'] > 0) ? $row['points'] : (($row['runs'] > 0) ? null : 0)),
                'runs_known' => $row ? $row['runs_known'] : 0,
                'runs_unknown' => $row ? $row['runs_unknown'] : 0,
                'target' => null,
            );
        }
    } else {
        $target = $goals['target_points_per_day'];
        $days = rws_days_list($period);
        $prev_days = rws_days_list(rws_prev_period($period));
        foreach ($days as $day) {
            $series[] = rws_day_point($day, $cur, $target, $period);
        }
        foreach ($prev_days as $day) {
            $prev_series[] = rws_day_point($day, $prev, null, rws_prev_period($period));
        }
    }

    return rws_envelope('timeseries', $period, $F, array(
        'bucket' => $bucket,
        'series' => $series,
        'prev_series' => $prev_series,
        'target_points_per_day' => $target,
        'forecast' => rws_forecast($period, $kpi),
        'data_confidence' => $conf,
        'warnings' => $warnings,
        'formulas' => array(
            'points' => 'Σ (points_after − points_before) của run đọc được điểm trong ngày; null = có run nhưng chưa xác định được điểm.',
            'target' => 'Mục tiêu điểm/ngày từ bảng goals hoặc setting goal_points_per_day.',
        ),
    ));
}

function rws_day_point($day, $agg, $target, $period) {
    $row = isset($agg['days'][$day]) ? $agg['days'][$day] : null;
    $is_future = ($day > $period['today']);
    if ($is_future) {
        return array('date' => $day, 'points' => null, 'runs' => 0, 'runs_ok' => 0, 'runs_known' => 0,
            'runs_unknown' => 0, 'active_accounts' => 0, 'completion_rate' => null, 'target' => $target,
            'confidence' => 'future');
    }
    if ($row === null) {
        return array('date' => $day, 'points' => 0, 'runs' => 0, 'runs_ok' => 0, 'runs_known' => 0,
            'runs_unknown' => 0, 'active_accounts' => 0, 'completion_rate' => null, 'target' => $target,
            'confidence' => 'no_runs');
    }
    $points = ($row['runs_known'] > 0) ? $row['points'] : (($row['runs'] > 0) ? null : 0);
    $conf = ($row['runs'] > 0 && $row['runs_known'] == 0) ? 'unknown_points'
        : ((($row['runs_unknown'] > 0 && $row['runs'] > 0 && ($row['runs_unknown'] / $row['runs']) > 0.5) ? 'low' : 'ok'));
    return array(
        'date' => $day,
        'points' => $points,
        'runs' => $row['runs'],
        'runs_ok' => $row['runs_ok'],
        'runs_known' => $row['runs_known'],
        'runs_unknown' => $row['runs_unknown'],
        'active_accounts' => $row['active_accounts'],
        'completion_rate' => null,
        'target' => $target,
        'confidence' => $conf,
    );
}

function rws_build_by_task($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $prev = rws_agg(rws_prev_period($period), $F);
    $groups = array('Daily set', 'Search', 'Click/Offer', 'Đọc báo', 'Quiz/Poll', 'Game', 'Khác');
    $rows = array();
    foreach ($groups as $g) {
        $c = isset($cur['tasks']['groups'][$g]) ? $cur['tasks']['groups'][$g] : rws_empty_task_stats();
        $pv = isset($prev['tasks']['groups'][$g]) ? $prev['tasks']['groups'][$g] : rws_empty_task_stats();
        $rate = ($c['available'] > 0) ? rws_rate($c['done'], $c['available']) : null;
        $prev_rate = ($pv['available'] > 0) ? rws_rate($pv['done'], $pv['available']) : null;
        $d = rws_delta_pct($rate, $prev_rate);
        $rows[] = array(
            'group' => $g,
            'available' => $c['available'],
            'done' => $c['done'],
            'failed' => $c['failed'],
            'needs_human' => $c['needs_human'],
            'pending' => $c['pending'],
            'unavailable' => $c['unavailable'],
            'completion_rate' => $rate,
            'points_earned' => $c['points_earned'],
            'solved_by' => $c['solved_by'],
            'skill_coverage_rate' => ($c['total'] > 0) ? rws_rate($c['solved_by']['skill'], $c['total']) : null,
            'ai_calls' => $c['solved_by']['ai'],
            'prev' => array('available' => $pv['available'], 'done' => $pv['done'], 'completion_rate' => $prev_rate),
            'delta_pct' => $d['delta_pct'],
            'direction' => $d['direction'],
        );
    }
    $t = $cur['tasks']['totals'];
    $totals_rate = ($t['available'] > 0) ? rws_rate($t['done'], $t['available']) : null;
    return rws_envelope('by_task', $period, $F, array(
        'rows' => $rows,
        'totals' => array(
            'total' => $t['total'], 'available' => $t['available'], 'done' => $t['done'],
            'failed' => $t['failed'], 'needs_human' => $t['needs_human'], 'pending' => $t['pending'],
            'unavailable' => $t['unavailable'], 'completion_rate' => $totals_rate,
            'points_earned' => $t['points_earned'], 'solved_by' => $t['solved_by'],
            'skill_coverage_rate' => ($t['total'] > 0) ? rws_rate($t['solved_by']['skill'], $t['total']) : null,
            'ai_calls' => $t['solved_by']['ai'],
        ),
        'total' => count($rows),
        'page' => 1,
        'per_page' => count($rows),
        'pages' => 1,
        'task_group_rules' => rws_task_group_rules(),
        'state_map' => array(
            'done' => 'done|ok|completed|success|solved',
            'failed' => 'error|failed|fail|timeout',
            'needs_human' => 'needs_human|manual|human_required',
            'unavailable' => 'unavailable|not_available|skipped (không tính vào mẫu)',
            'pending' => 'các trạng thái còn lại (pending, running, cooldown...)',
        ),
        'data_available' => $cur['tasks']['available'],
        'formulas' => array(
            'completion_rate' => 'nhiệm vụ done ÷ nhiệm vụ khả dụng của nhóm (mục 5.2).',
            'skill_coverage_rate' => 'nhiệm vụ solved_by=skill ÷ tổng nhiệm vụ của nhóm.',
        ),
    ));
}

function rws_build_by_error($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $prev = rws_agg(rws_prev_period($period), $F);
    $total_cur = 0;
    $total_prev = 0;
    foreach ($cur['errors'] as $e) { $total_cur += $e['count']; }
    foreach ($prev['errors'] as $e) { $total_prev += $e['count']; }
    $rows = array();
    foreach (rws_error_class_list() as $cls) {
        $n = isset($cur['errors'][$cls]) ? $cur['errors'][$cls]['count'] : 0;
        $samples = isset($cur['errors'][$cls]) ? $cur['errors'][$cls]['samples'] : array();
        $pn = isset($prev['errors'][$cls]) ? $prev['errors'][$cls]['count'] : 0;
        $d = rws_delta_pct($n, $pn);
        $rows[] = array(
            'class' => $cls,
            'label' => rws_error_label($cls),
            'count' => $n,
            'share_pct' => ($total_cur > 0) ? round($n / $total_cur * 100, 1) : null,
            'samples' => $samples,
            'prev_count' => $pn,
            'delta_pct' => $d['delta_pct'],
            'direction' => $d['direction'],
        );
    }
    return rws_envelope('by_error', $period, $F, array(
        'rows' => $rows,
        'total' => count($rows),
        'page' => 1,
        'per_page' => count($rows),
        'pages' => 1,
        'error_total' => $total_cur,
        'error_total_prev' => $total_prev,
        'truncated' => $cur['error_rows_truncated'],
        'class_rules' => array(
            'captcha' => 'captcha|recaptcha|hcaptcha|turnstile|cloudflare',
            'verify' => 'needs_human|human|verify|2fa|otp|sms|xác minh',
            'login' => 'login|sign_in|password|credential|auth|session|cookie|đăng nhập',
            'proxy' => 'proxy|socks|tunnel|407|econn|gateway|exit_ip|kết nối',
            'timeout' => 'timeout|timed_out|deadline|quá hạn',
            'browser' => 'browser|chrome|playwright|selenium|renderer|navigate|crash',
            'other' => 'không khớp nhóm nào (hoặc dùng accounts.error_class khi không có text lỗi)',
        ),
        'formulas' => array('share_pct' => 'số lỗi của nguyên nhân ÷ tổng lỗi trong kỳ.'),
    ));
}

function rws_build_by_account($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $days = rws_days_list($period);
    $rows = array();
    foreach ($cur['accounts'] as $aid => $acc) {
        if (rws_is_blocked_status($acc['status'])) { /* vẫn hiển thị nhưng đánh dấu */ }
        $spark = array();
        $streak = 0;
        $last_points_day = null;
        foreach ($days as $day) {
            $row = isset($acc['days'][$day]) ? $acc['days'][$day] : null;
            if ($day > $period['today']) {
                $spark[] = null;
                continue;
            }
            if ($row === null) {
                $spark[] = 0;
            } elseif ($row['runs_known'] > 0) {
                $spark[] = $row['points'];
                if ($row['points'] > 0) { $last_points_day = $day; }
            } else {
                $spark[] = null;
            }
        }
        // chuỗi ngày: từ cuối kỳ ngược về
        $rev = array_reverse($days, true);
        $counting = true;
        foreach ($rev as $day) {
            if ($day > $period['today']) { continue; }
            $row = isset($acc['days'][$day]) ? $acc['days'][$day] : null;
            $has = ($row !== null && $row['runs_known'] > 0 && $row['points'] > 0);
            if ($counting && $has) { $streak++; }
            else { $counting = false; }
        }
        $gap_days = 0;
        if ($last_points_day !== null) {
            $a = new DateTime($last_points_day);
            $b = new DateTime(($period['today'] < $period['to']) ? $period['today'] : $period['to']);
            $gap_days = (int)$a->diff($b)->days;
        } else {
            $gap_days = null;
        }
        $labels = array();
        if ($gap_days !== null && $gap_days >= 3) { $labels[] = 'đứt chuỗi'; }
        $rows[] = array(
            'id' => $aid,
            'email' => rws_mask_email($acc['email']),
            'status' => $acc['status'],
            'blocked' => rws_is_blocked_status($acc['status']),
            'group_label' => $acc['group_label'],
            'tags' => $acc['tags'],
            'points' => ($acc['totals']['runs_known'] > 0) ? $acc['totals']['points'] : (($acc['totals']['runs'] > 0) ? null : 0),
            'avg_per_day' => ($acc['totals']['runs_known'] > 0)
                ? rws_div($acc['totals']['points'], max(1, $cur['totals']['runs'] > 0 ? count(array_filter($acc['days'], function ($x) { return $x['runs'] > 0; })) : 1))
                : null,
            'spark' => $spark,
            'runs' => $acc['totals']['runs'],
            'runs_ok' => $acc['totals']['runs_ok'],
            'runs_err' => $acc['totals']['runs_err'],
            'runs_unknown' => $acc['totals']['runs_unknown'],
            'ok_rate' => rws_rate($acc['totals']['runs_ok'], $acc['totals']['runs']),
            'error_rate' => rws_rate($acc['totals']['runs_err'], $acc['totals']['runs']),
            'streak_days' => $streak,
            'gap_days' => $gap_days,
            'last_run_at' => $acc['last_start'],
            'labels' => $labels,
        );
    }
    // nhãn «yếu»: 10% điểm thấp nhất
    if (count($rows) >= 5) {
        $sorted = $rows;
        usort($sorted, function ($x, $y) {
            $vx = ($x['points'] === null) ? -PHP_INT_MAX : $x['points'];
            $vy = ($y['points'] === null) ? -PHP_INT_MAX : $y['points'];
            if ($vx == $vy) { return $x['id'] - $y['id']; }
            return ($vx < $vy) ? -1 : 1;
        });
        $n_weak = (int)ceil(count($sorted) * 0.10);
        $weak_ids = array();
        for ($i = 0; $i < $n_weak; $i++) { $weak_ids[$sorted[$i]['id']] = true; }
        foreach ($rows as &$row) {
            if (isset($weak_ids[$row['id']])) { $row['labels'][] = 'yếu'; }
        }
        unset($row);
    }

    /* sắp xếp + phân trang */
    $sort = strtolower((string)rws_arg($p, 'sort', 'points'));
    $dir = strtolower((string)rws_arg($p, 'dir', 'desc'));
    $allowed = array('points', 'avg_per_day', 'ok_rate', 'error_rate', 'streak_days', 'last_run_at', 'email', 'status', 'runs');
    if (!in_array($sort, $allowed, true)) {
        rws_fail(422, 'invalid_sort', 'Tham số sort không hợp lệ. Chỉ nhận: ' . implode(', ', $allowed) . '.');
    }
    if (!in_array($dir, array('asc', 'desc'), true)) {
        rws_fail(422, 'invalid_dir', 'Tham số dir chỉ nhận: asc, desc.');
    }
    $sign = ($dir === 'asc') ? 1 : -1;
    usort($rows, function ($x, $y) use ($sort, $sign) {
        $vx = $x[$sort];
        $vy = $y[$sort];
        if ($vx === $vy) { return $x['id'] - $y['id']; }
        if ($vx === null) { return 1; }
        if ($vy === null) { return -1; }
        if ($vx == $vy) { return $x['id'] - $y['id']; }
        return (($vx < $vy) ? -1 : 1) * $sign;
    });

    $total = count($rows);
    $per_page = (int)rws_arg($p, 'per_page', 50);
    $per_page = max(1, min(RWS_MAX_PER_PAGE, $per_page > 0 ? $per_page : 50));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($pages, (int)rws_arg($p, 'page', 1)));
    $slice = array_slice($rows, ($page - 1) * $per_page, $per_page);

    return rws_envelope('by_account', $period, $F, array(
        'rows' => $slice,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'pages' => $pages,
        'sort' => $sort,
        'dir' => $dir,
        'days' => $days,
        'formulas' => array(
            'points' => 'Σ điểm thăng trong kỳ của account (run thiếu điểm không tính).',
            'streak_days' => rws_formulas()['streak_days'],
            'labels' => rws_formulas()['weak_label'] . ' ' . rws_formulas()['broken_streak_label'],
            'spark' => rws_formulas()['spark'],
        ),
    ));
}

function rws_build_by_proxy($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $db = rws_ro();
    $details = array();
    if (rws_has_table('proxies')) {
        $st = $db->query('SELECT id, scheme, host, port, username, status, latency_ms, label, group_key FROM proxies');
        foreach ($st->fetchAll() as $r) {
            $details[(string)(int)$r['id']] = $r;
        }
    }
    $rows = array();
    foreach ($cur['proxies'] as $key => $agg) {
        $det = ($key !== 'none' && isset($details[$key])) ? $details[$key] : null;
        $mask = ($det !== null)
            ? rws_mask_proxy($det['scheme'], $det['host'], $det['port'], $det['username'])
            : 'không gán proxy';
        $rows[] = array(
            'proxy_id' => $agg['proxy_id'],
            'proxy_mask' => $mask,
            'label' => ($det !== null) ? (string)$det['label'] : '',
            'group_key' => ($det !== null) ? (string)$det['group_key'] : '',
            'proxy_status' => ($det !== null) ? (string)$det['status'] : '',
            'latency_ms' => ($det !== null && $det['latency_ms'] !== null) ? (int)$det['latency_ms'] : null,
            'accounts' => $agg['accounts'],
            'runs' => $agg['runs'],
            'runs_ok' => $agg['runs_ok'],
            'runs_err' => $agg['runs_err'],
            'ok_rate' => rws_rate($agg['runs_ok'], $agg['runs']),
            'error_rate' => rws_rate($agg['runs_err'], $agg['runs']),
            'points' => ($agg['runs_known'] > 0) ? $agg['points'] : (($agg['runs'] > 0) ? null : 0),
        );
    }
    usort($rows, function ($x, $y) {
        if ($x['points'] === $y['points']) { return 0; }
        if ($x['points'] === null) { return 1; }
        if ($y['points'] === null) { return -1; }
        return ($x['points'] > $y['points']) ? -1 : 1;
    });
    $total = count($rows);
    $per_page = (int)rws_arg($p, 'per_page', 50);
    $per_page = max(1, min(RWS_MAX_PER_PAGE, $per_page > 0 ? $per_page : 50));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($pages, (int)rws_arg($p, 'page', 1)));
    return rws_envelope('by_proxy', $period, $F, array(
        'rows' => array_slice($rows, ($page - 1) * $per_page, $per_page),
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'pages' => $pages,
        'formulas' => array(
            'ok_rate' => 'run thành công ÷ tổng run của nhóm account dùng proxy.',
            'latency_ms' => 'độ trễ hiện tại của proxy (snapshot bảng proxies), không phải TB trong kỳ.',
        ),
    ));
}

function rws_build_calendar($period0, $F, $p) {
    $period = rws_period_month_of($period0);
    $cur = rws_agg($period, $F);
    $kpi = rws_kpi($cur, $period);
    $goals = rws_goals($period, $kpi, trim((string)rws_arg($p, 'group_label', '')));
    $days = array();
    $tz = rws_tz();
    foreach (rws_days_list($period) as $day) {
        $pt = rws_day_point($day, $cur, $goals['target_points_per_day'], $period);
        $dt = new DateTime($day, $tz);
        $pt['weekday'] = (int)$dt->format('N');
        $pt['is_today'] = ($day === $period['today']);
        $days[] = $pt;
    }
    return rws_envelope('calendar', $period, $F, array(
        'month' => substr($period['from'], 0, 7),
        'days' => $days,
        'target_points_per_day' => $goals['target_points_per_day'],
        'month_total_points' => $kpi['points_gained'],
        'forecast' => rws_forecast($period, $kpi),
        'data_confidence' => rws_confidence($cur),
        'total' => count($days),
        'page' => 1,
        'per_page' => count($days),
        'pages' => 1,
        'formulas' => array(
            'points' => 'điểm thăng của ngày; null = có run nhưng chưa xác định được điểm (tô màu «đứt đoạn», không đọc là 0).',
        ),
    ));
}

function rws_build_pending($period, $F, $p) {
    $cur = rws_agg($period, $F);
    $db = rws_ro();
    $items = array();
    $scope = $period['contains_today'] ? 'today' : 'period';
    $target_day = $period['contains_today'] ? $period['today'] : null;

    /* 1. account chưa hoàn tất */
    $sql = 'SELECT a.id AS id, a.email AS email, a.status AS status, a.next_run_at AS next_run_at,'
        . ' a.last_error AS last_error'
        . (rws_has_col('accounts', 'error_class') ? ', a.error_class AS error_class' : ", '' AS error_class")
        . ' FROM accounts a WHERE 1=1' . $F['acc_sql'] . $F['dev_sql'] . ' ORDER BY a.id';
    $st = $db->prepare($sql);
    $st->execute(array_merge($F['acc_args'], $F['dev_args']));
    foreach ($st->fetchAll() as $r) {
        $aid = (int)$r['id'];
        $acc = isset($cur['accounts'][$aid]) ? $cur['accounts'][$aid] : null;
        $pending_days = array();
        if ($scope === 'today') {
            $row = ($acc !== null && isset($acc['days'][$target_day])) ? $acc['days'][$target_day] : null;
            $done = ($row !== null && $row['runs_ok'] > 0 && $row['runs_err'] == 0);
            if (!$done) { $pending_days[] = $target_day; }
        } else {
            foreach (rws_days_list($period) as $day) {
                if ($day > $period['today']) { continue; }
                $row = ($acc !== null && isset($acc['days'][$day])) ? $acc['days'][$day] : null;
                $done = ($row !== null && $row['runs_ok'] > 0 && $row['runs_err'] == 0);
                if (!$done) { $pending_days[] = $day; }
            }
        }
        foreach ($pending_days as $day) {
            $reason = 'Chưa có run thành công trong ngày.';
            $code = 'not_done';
            $err = rws_scrub((string)$r['last_error']);
            if ($err !== '') {
                $reason = 'Lỗi gần nhất: ' . $err;
                $code = rws_error_class($r['last_error'], $r['error_class']);
            } elseif (strtolower((string)$r['status']) === 'disabled' || strtolower((string)$r['status']) === 'locked') {
                $reason = 'Tài khoản ở trạng thái ' . $r['status'] . '.';
                $code = 'blocked';
            }
            $items[] = array(
                'type' => 'account',
                'account_id' => $aid,
                'email' => rws_mask_email($r['email']),
                'date' => $day,
                'status' => (string)$r['status'],
                'reason' => $reason,
                'reason_code' => $code,
                'retry_at' => $r['next_run_at'],
            );
        }
    }

    /* 2. nhiệm vụ chưa xong (task_events) */
    if (rws_has_table('task_events')) {
        $w = " WHERE NULLIF(te.date,'') BETWEEN ? AND ?"
            . ' AND LOWER(COALESCE(te.state,\'\')) NOT IN (\'done\',\'ok\',\'completed\',\'success\',\'solved\')'
            . $F['acc_sql'];
        $sqlt = 'SELECT te.id AS id, te.account_id AS account_id, te.task_key AS task_key, te.task_type AS task_type,'
            . ' te.state AS state, te.cooldown_until AS cooldown_until, te.error AS error, te.date AS date,'
            . ' MAX(a.email) AS email'
            . ' FROM task_events te LEFT JOIN accounts a ON a.id = te.account_id' . $w
            . ' GROUP BY te.id LIMIT 2000';
        $stt = $db->prepare($sqlt);
        $stt->execute(array_merge(array($period['from'], $period['to']), $F['acc_args']));
        foreach ($stt->fetchAll() as $r) {
            $err = rws_scrub((string)$r['error']);
            $items[] = array(
                'type' => 'task',
                'task_id' => (int)$r['id'],
                'account_id' => ($r['account_id'] === null) ? null : (int)$r['account_id'],
                'email' => ($r['email'] !== null) ? rws_mask_email($r['email']) : null,
                'date' => $r['date'],
                'task_key' => (string)$r['task_key'],
                'task_group' => rws_task_group($r['task_type'], $r['task_key']),
                'status' => (string)$r['state'],
                'reason' => ($err !== '') ? ('Lỗi: ' . $err) : ('Trạng thái ' . (string)$r['state']),
                'reason_code' => ($err !== '') ? rws_error_class($r['error'], '') : rws_task_state_class($r['state']),
                'retry_at' => $r['cooldown_until'],
            );
        }
    }

    foreach ($items as &$it) {
        $it['hours_until'] = null;
        if (!empty($it['retry_at'])) {
            $ts = strtotime($it['retry_at']);
            if ($ts !== false) { $it['hours_until'] = round(($ts - strtotime($period['now'])) / 3600, 2); }
        }
    }
    unset($it);
    usort($items, function ($x, $y) {
        $ax = ($x['retry_at'] === null || $x['retry_at'] === '') ? PHP_INT_MAX : (int)strtotime($x['retry_at']);
        $ay = ($y['retry_at'] === null || $y['retry_at'] === '') ? PHP_INT_MAX : (int)strtotime($y['retry_at']);
        if ($ax === $ay) { return 0; }
        return ($ax < $ay) ? -1 : 1;
    });

    $total = count($items);
    $per_page = (int)rws_arg($p, 'per_page', 50);
    $per_page = max(1, min(RWS_MAX_PER_PAGE, $per_page > 0 ? $per_page : 50));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = max(1, min($pages, (int)rws_arg($p, 'page', 1)));

    return rws_envelope('pending_tasks', $period, $F, array(
        'scope' => $scope,
        'rows' => array_slice($items, ($page - 1) * $per_page, $per_page),
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'pages' => $pages,
        'formulas' => array(
            'scope' => 'today = các account/nhiệm vụ chưa xong hôm nay (khi kỳ chứa hôm nay); period = chưa xong trong từng ngày của kỳ.',
            'retry_at' => 'account: next_run_at; nhiệm vụ: cooldown_until.',
        ),
    ));
}

/* ====================================================================
 * 11. XUẤT CSV (mục 5.3: nút «Xuất CSV» — đúng bộ lọc)
 * ==================================================================== */
function rws_csv_payload($what, $period, $F, $p) {
    $what = strtolower(preg_replace('/[^a-z_]/', '', $what));
    switch ($what) {
        case 'by_account': {
            $d = rws_build_by_account($period, $F, array_merge($p, array('page' => 1, 'per_page' => RWS_MAX_PER_PAGE)));
            $header = array('id', 'email_mask', 'status', 'group_label', 'tags', 'points', 'avg_per_day',
                'runs', 'runs_ok', 'runs_err', 'runs_unknown', 'ok_rate', 'error_rate', 'streak_days', 'gap_days', 'last_run_at', 'labels');
            $rows = array();
            foreach ($d['rows'] as $r) {
                $rows[] = array($r['id'], $r['email'], $r['status'], $r['group_label'], $r['tags'], $r['points'], $r['avg_per_day'],
                    $r['runs'], $r['runs_ok'], $r['runs_err'], $r['runs_unknown'], $r['ok_rate'], $r['error_rate'],
                    $r['streak_days'], $r['gap_days'], $r['last_run_at'], implode('|', $r['labels']));
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'timeseries': {
            $d = rws_build_timeseries($period, $F, $p);
            $header = array('bucket', 'points', 'runs', 'runs_ok', 'runs_known', 'runs_unknown', 'active_accounts', 'target', 'confidence');
            $rows = array();
            foreach ($d['series'] as $r) {
                $rows[] = array($r['bucket'], $r['points'], $r['runs'], $r['runs_ok'], $r['runs_known'], $r['runs_unknown'],
                    isset($r['active_accounts']) ? $r['active_accounts'] : '', $r['target'], $r['confidence']);
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'by_task': {
            $d = rws_build_by_task($period, $F, $p);
            $header = array('group', 'available', 'done', 'failed', 'needs_human', 'pending', 'unavailable',
                'completion_rate', 'points_earned', 'skill', 'ai', 'manual', 'other', 'skill_coverage_rate', 'ai_calls');
            $rows = array();
            foreach ($d['rows'] as $r) {
                $rows[] = array($r['group'], $r['available'], $r['done'], $r['failed'], $r['needs_human'], $r['pending'], $r['unavailable'],
                    $r['completion_rate'], $r['points_earned'], $r['solved_by']['skill'], $r['solved_by']['ai'], $r['solved_by']['manual'],
                    $r['solved_by']['other'], $r['skill_coverage_rate'], $r['ai_calls']);
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'by_error': {
            $d = rws_build_by_error($period, $F, $p);
            $header = array('class', 'label', 'count', 'share_pct', 'prev_count', 'delta_pct', 'samples');
            $rows = array();
            foreach ($d['rows'] as $r) {
                $rows[] = array($r['class'], $r['label'], $r['count'], $r['share_pct'], $r['prev_count'], $r['delta_pct'],
                    implode(' || ', $r['samples']));
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'calendar': {
            $d = rws_build_calendar($period, $F, $p);
            $header = array('date', 'weekday', 'points', 'runs', 'runs_ok', 'runs_known', 'runs_unknown', 'active_accounts', 'target', 'confidence');
            $rows = array();
            foreach ($d['days'] as $r) {
                $rows[] = array($r['date'], $r['weekday'], $r['points'], $r['runs'], $r['runs_ok'], $r['runs_known'], $r['runs_unknown'],
                    $r['active_accounts'], $r['target'], $r['confidence']);
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'pending_tasks':
        case 'pending': {
            $d = rws_build_pending($period, $F, array_merge($p, array('page' => 1, 'per_page' => RWS_MAX_PER_PAGE)));
            $header = array('type', 'account_id', 'email_mask', 'date', 'task_key', 'task_group', 'status', 'reason_code', 'reason', 'retry_at', 'hours_until');
            $rows = array();
            foreach ($d['rows'] as $r) {
                $rows[] = array($r['type'], isset($r['account_id']) ? $r['account_id'] : '', isset($r['email']) ? $r['email'] : '',
                    isset($r['date']) ? $r['date'] : '', isset($r['task_key']) ? $r['task_key'] : '',
                    isset($r['task_group']) ? $r['task_group'] : '', $r['status'], $r['reason_code'], $r['reason'],
                    $r['retry_at'], $r['hours_until']);
            }
            return array('header' => $header, 'rows' => $rows);
        }
        case 'summary':
        default: {
            $d = rws_build_summary($period, $F, $p);
            $header = array('metric', 'value', 'prev', 'delta_pct', 'direction', 'formula');
            $rows = array();
            foreach ($d['kpi'] as $k => $v) {
                if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
                $cmp = isset($d['compare'][$k]) ? $d['compare'][$k] : array('prev' => null, 'delta_pct' => null, 'direction' => null);
                $rows[] = array($k, $v, is_array($cmp['prev']) ? json_encode($cmp['prev'], JSON_UNESCAPED_UNICODE) : $cmp['prev'],
                    $cmp['delta_pct'], $cmp['direction'], isset($d['formulas'][$k]) ? $d['formulas'][$k] : '');
            }
            return array('header' => $header, 'rows' => $rows);
        }
    }
}

function rws_out_csv($period, $F, $p) {
    $what = (string)rws_arg($p, 'what', 'by_account');
    $payload = rws_csv_payload($what, $period, $F, $p);
    $fname = 'stats_' . preg_replace('/[^a-z_]/', '', strtolower($what)) . '_' . $period['from'] . '_' . $period['to'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, $payload['header']);
    foreach ($payload['rows'] as $row) { fputcsv($out, $row); }
    fclose($out);
    exit;
}

/* ====================================================================
 * 12. DISPATCH
 * ==================================================================== */
function rws_main() {
    rw_send_security_headers();
    rw_start_session_secure();
    rw_require_api_login();
    rw_require_csrf();
    $p = rws_input();
    $action = rws_action_name($p);
    if ($action === '') {
        rws_fail(400, 'missing_action', 'Thiếu tham số action. Danh sách: summary, timeseries, by_task, by_error, by_account, by_proxy, calendar, pending_tasks, export_csv.');
    }
    /* Validate action TRƯỚC khi mở DB/parse period để action lạ luôn trả 400 unknown_action,
       không bị lẫn thành 503 db_readonly_failed (mục 7 yêu cầu action sai → 400). */
    $allowed = array('summary', 'timeseries', 'by_task', 'by_error', 'by_account', 'by_proxy',
                     'calendar', 'pending_tasks', 'pending', 'export_csv');
    if (!in_array($action, $allowed, true)) {
        rws_fail(400, 'unknown_action', 'Action không hỗ trợ: ' . $action . '. Danh sách: summary, timeseries, by_task, by_error, by_account, by_proxy, calendar, pending_tasks, export_csv.');
    }
    $period = rws_period($p);
    $F = rws_filters($p);
    switch ($action) {
        case 'summary':
            rw_json(rws_build_summary($period, $F, $p));
            break;
        case 'timeseries':
            rw_json(rws_build_timeseries($period, $F, $p));
            break;
        case 'by_task':
            rw_json(rws_build_by_task($period, $F, $p));
            break;
        case 'by_error':
            rw_json(rws_build_by_error($period, $F, $p));
            break;
        case 'by_account':
            rw_json(rws_build_by_account($period, $F, $p));
            break;
        case 'by_proxy':
            rw_json(rws_build_by_proxy($period, $F, $p));
            break;
        case 'calendar':
            rw_json(rws_build_calendar($period, $F, $p));
            break;
        case 'pending_tasks':
        case 'pending':
            rw_json(rws_build_pending($period, $F, $p));
            break;
        case 'export_csv':
            rws_out_csv($period, $F, $p);
            break;
        default:
            rws_fail(400, 'unknown_action', 'Action không hỗ trợ: ' . $action . '. Danh sách: summary, timeseries, by_task, by_error, by_account, by_proxy, calendar, pending_tasks, export_csv.');
    }
}

if (!defined('RW_V4S_TEST_MODE')) {
    rws_main();
}
?>
