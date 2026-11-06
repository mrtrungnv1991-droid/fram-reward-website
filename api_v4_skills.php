<?php
/**
 * api_v4_skills.php - API quan ly SKILL & NHIEM VU (prompt_website_ui_v4.md muc 6, 7, 8).
 *
 * PHP 7.4 compatible. PDO SQLite qua db.php (rw_db). Tat ca ham rieng dung prefix v4k_.
 * - Dang nhap: session (rw_is_logged_in) -> 401 neu chua dang nhap.
 * - CSRF: bat buoc voi moi POST (rw_csrf_check) -> 403 neu sai/thieu.
 * - Quyen: admin bat buoc khi import go khong/chua dung chu ky; cac thao tac con lai user
 *   dang nhap duoc lam (giong quy uoc api_v4_accounts.php).
 * - Moi thao tac sua doi deu co LY DO (reason) va ghi audit_ui (muc 6.6.3).
 * - Bi mat: rules/guide bi chan neu chua mat khau/token/so phone/dap an cung theo ngay
 *   (muc 6.6.2); response khong bao gio tra bi mat, chi mask.
 * - Loi chuan: {ok:false, error:{code, message}} voi 400/401/403/404/405/409/422.
 *
 * Endpoint (?action=...):
 *   GET  : list | get | summary | queue_unmatched | trace_get | export
 *   POST : create | update | compile_guide | test_regression | test_live | verify |
 *          publish | rollback | deprecate | import | clone | merge | fingerprint_add
 *   GET/POST (op=...) : queue_unmatched (op=skip|guide|ai|test), trace_get (op=sample)
 *
 * Phan hoi danh sach luon co total, page, filters_applied, generated_at (muc 7).
 * Vong doi skill (muc 6.3): draft -> verified -> publish(canary -> stable); loi lien tuc
 * >= 3 hoac ty le thanh cong 7 ngay < 60% -> tu ha "can xem lai" (muc 6.6.4).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/* Polyfill mb_* an toan khi PHP thieu extension mbstring */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) { return strlen((string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null) {
        $s = (string)$s;
        return ($length === null) ? substr($s, $start) : substr($s, $start, $length);
    }
}

define('RW_V4K_MAX_STEPS', 100);
define('RW_V4K_MAX_GUIDE', 20000);
define('RW_V4K_MAX_RULES_JSON', 60000);
define('RW_V4K_RISK_LIMIT', 2000);

/* ==================================================================== */
/* 1. TIEN ICH CHUNG (prefix v4k_)                                       */
/* ==================================================================== */

function v4k_now() { return date('Y-m-d H:i:s'); }

function v4k_fail($http, $code, $message, $extra = array()) {
    $err = array('code' => $code, 'message' => $message);
    if (is_array($extra) && count($extra)) { $err = array_merge($err, $extra); }
    rw_json(array('ok' => false, 'error' => $err), $http);
}

function v4k_str($s) {
    return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
}
function v4k_cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n, 'UTF-8') : substr((string)$s, 0, $n);
}
function v4k_text($v, $max) {
    $v = trim((string)$v);
    $v = preg_replace('/[\r\n\t]+/', ' ', $v);
    return v4k_cut($v, $max);
}
function v4k_multiline($v, $max) {
    $v = str_replace("\r\n", "\n", (string)$v);
    $v = trim($v);
    return v4k_cut($v, $max);
}

function v4k_input() {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    $out = is_array($j) ? $j : array();
    foreach ($_POST as $k => $v) {
        if ($k === 'csrf') { continue; }
        $out[$k] = $v;
    }
    return $out;
}
function v4k_param($p, $key, $default = '') {
    return isset($p[$key]) ? $p[$key] : $default;
}
function v4k_bool($v) {
    return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes');
}
function v4k_role() { return isset($_SESSION['rw_role']) ? (string)$_SESSION['rw_role'] : ''; }
function v4k_user() { return isset($_SESSION['rw_user']) ? (string)$_SESSION['rw_user'] : '-'; }

function v4k_require_post() {
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($m !== 'POST') {
        v4k_fail(405, 'method_not_allowed', 'Thao tác này bắt buộc dùng phương thức POST.');
    }
}
function v4k_require_admin($what) {
    if (!rw_is_admin()) {
        v4k_fail(403, 'admin_only', 'Chỉ admin mới được ' . $what . '. Tài khoản manager không có quyền này.');
    }
}
function v4k_require_confirm($p) {
    if (!v4k_bool(v4k_param($p, 'confirm', null))) {
        v4k_fail(400, 'confirm_required', 'Thiếu tham số xác nhận (confirm=true). Hành động nguy hiểm phải được giao diện xác nhận trước để tránh gọi nhầm.');
    }
}
function v4k_require_reason($p, $what, $max = 500) {
    $r = trim((string)v4k_param($p, 'reason', ''));
    if ($r === '') {
        v4k_fail(422, 'reason_required', 'Bắt buộc nhập lý do khi ' . $what . ' (mục 6.6.3).');
    }
    return v4k_text($r, $max);
}

function v4k_ids($p, $key = 'ids') {
    $raw = v4k_param($p, $key, null);
    if ($raw === null || $raw === '') {
        v4k_fail(400, 'invalid_id', 'Thiếu danh sách id (' . $key . ').');
    }
    if (is_array($raw)) { $parts = $raw; } else { $parts = explode(',', (string)$raw); }
    $out = array();
    foreach ($parts as $x) {
        $x = trim((string)$x);
        if ($x === '') { continue; }
        if (!ctype_digit($x) || (int)$x <= 0) {
            v4k_fail(400, 'invalid_id', 'Danh sách id không hợp lệ (chỉ chấp nhận số nguyên dương).');
        }
        $out[(int)$x] = (int)$x;
    }
    if (!count($out)) {
        v4k_fail(400, 'invalid_id', 'Danh sách id rỗng.');
    }
    if (count($out) > 500) {
        v4k_fail(400, 'invalid_id', 'Quá nhiều id trong một lần gọi (tối đa 500).');
    }
    return array_values($out);
}
function v4k_id1($p, $key = 'id') {
    $x = trim((string)v4k_param($p, $key, ''));
    if ($x === '' || !ctype_digit($x) || (int)$x <= 0) {
        v4k_fail(400, 'invalid_id', 'Thiếu hoặc sai tham số ' . $key . ' (cần số nguyên dương).');
    }
    return (int)$x;
}
function v4k_int_opt($p, $key, $default, $min, $max) {
    $v = v4k_param($p, $key, null);
    if ($v === null || $v === '') { return $default; }
    if (!is_numeric($v)) {
        v4k_fail(422, 'invalid_param', 'Tham số ' . $key . ' phải là số.');
    }
    $n = (int)$v;
    if ($n < $min || $n > $max) {
        v4k_fail(422, 'invalid_param', 'Tham số ' . $key . ' phải trong khoảng ' . $min . '–' . $max . '.');
    }
    return $n;
}

/* ==================================================================== */
/* 2. CHE BI MAT & QUET NOI DUNG CAM (muc 6.6.2)                        */
/* ==================================================================== */

function v4k_mask_snippet($s) {
    $s = (string)$s;
    return v4k_cut($s, 12) . '***';
}
function v4k_scan_secrets($text) {
    $text = (string)$text;
    $hits = array();
    $rules = array(
        'password_or_token' => '/(?:password|passwd|pwd|mat[_\s]?khau|secret|token|api[_-]?key|authorization|bearer)\s*[:=]\s*\S+/i',
        'phone_number'      => '/(?:phone|sdt|so\s*dien\s*thoai|so\s*dien\s*thoai)\s*[:=]\s*[+\d][\d\s.\-]{6,}/i',
        'dated_answer'      => '/(?:answer|dap[_\s]?an|correct)\s*[:=].*?(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})/i',
        'long_token'        => '/\b[A-Za-z0-9_\-]{40,}\b/'
    );
    foreach ($rules as $name => $re) {
        if (preg_match_all($re, $text, $m)) {
            foreach (array_slice($m[0], 0, 3) as $one) {
                $hits[] = array('rule' => $name, 'masked' => v4k_mask_snippet($one));
            }
        }
    }
    return $hits;
}
function v4k_assert_clean($fields, $label = 'Nội dung') {
    $hits = array();
    foreach ((array)$fields as $k => $v) {
        if (is_string($v) && $v !== '') {
            foreach (v4k_scan_secrets($v) as $h) {
                $h['field'] = (string)$k;
                $hits[] = $h;
            }
        }
    }
    if (count($hits)) {
        v4k_fail(422, 'skill_contains_secret',
            $label . ' không được chứa mật khẩu, token, số phone hoặc đáp án cứng theo ngày (mục 6.6.2).',
            array('hits' => array_slice($hits, 0, 10)));
    }
}

/* ==================================================================== */
/* 3. MIEU TA MIEU HINH (muc 6.2 / 6.4 / 6.5) + VALIDATOR               */
/* ==================================================================== */

function v4k_categories() {
    return array('search' => 'Search', 'click_offer' => 'Click / Offer', 'article' => 'Đọc báo',
                 'quiz_poll' => 'Quiz / Poll', 'game' => 'Game', 'other' => 'Khác');
}
function v4k_skill_statuses() {
    return array('draft' => 'Nháp', 'verified' => 'Verified', 'needs_review' => 'Cần xem lại', 'deprecated' => 'Ngừng');
}
function v4k_version_statuses() {
    return array('draft' => 'Nháp', 'verified' => 'Verified', 'published' => 'Đã phát hành',
                 'rolled_back' => 'Đã rollback', 'needs_review' => 'Cần xem lại', 'deprecated' => 'Ngừng');
}
function v4k_step_actions() {
    return array('goto', 'click', 'type', 'select', 'wait', 'scroll', 'read', 'answer', 'loop');
}
function v4k_channels() { return array('canary' => 'Canary (1 thiết bị)', 'stable' => 'Stable (tất cả thiết bị)'); }
function v4k_queue_statuses() {
    return array('pending' => 'Chờ xử lý', 'pending_ai' => 'Đang giao AI', 'solved' => 'Đã có skill', 'skipped' => 'Đã bỏ qua');
}
function v4k_difficulty_label($d) {
    $d = (int)$d;
    if ($d <= 30) { return 'Dễ'; }
    if ($d <= 60) { return 'Vừa'; }
    if ($d <= 85) { return 'Khó'; }
    return 'Không tự động được';
}
function v4k_risk_label($risk) {
    $risk = (int)$risk;
    if ($risk < 25) { return 'Ổn định'; }
    if ($risk < 50) { return 'Theo dõi'; }
    if ($risk < 75) { return 'Sắp hỏng'; }
    return 'Cần xem lại ngay';
}

function v4k_semver_ok($s) { return (bool)preg_match('/^\d+\.\d+\.\d+$/', (string)$s); }
function v4k_semver_cmp($a, $b) {
    $pa = array_map('intval', explode('.', (string)$a));
    $pb = array_map('intval', explode('.', (string)$b));
    for ($i = 0; $i < 3; $i++) {
        $x = isset($pa[$i]) ? $pa[$i] : 0;
        $y = isset($pb[$i]) ? $pb[$i] : 0;
        if ($x < $y) { return -1; }
        if ($x > $y) { return 1; }
    }
    return 0;
}
function v4k_semver_bump($s) {
    $p = array_map('intval', explode('.', (string)$s));
    if (count($p) !== 3) { $p = array(0, 1, 0); }
    $p[2] = $p[2] + 1;
    return $p[0] . '.' . $p[1] . '.' . $p[2];
}

/* ---- Kiem tra rules_json (buoc co cau truc, khong phai van ban tu do) ---- */
function v4k_rules_parse($raw, &$errors, &$warnings, $allow_empty = false) {
    $errors = array();
    $warnings = array();
    if (is_string($raw)) {
        $t = trim($raw);
        if ($t === '') {
            if ($allow_empty) { return array('steps' => array()); }
            $errors[] = 'rules_json rỗng.';
            return null;
        }
        if (strlen($t) > RW_V4K_MAX_RULES_JSON) {
            $errors[] = 'rules_json vượt quá ' . RW_V4K_MAX_RULES_JSON . ' byte.';
            return null;
        }
        $j = json_decode($t, true);
        if (!is_array($j)) {
            $errors[] = 'rules_json không phải JSON hợp lệ.';
            return null;
        }
        $raw = $j;
    }
    if (!is_array($raw)) {
        $errors[] = 'rules_json phải là object JSON.';
        return null;
    }
    if (!isset($raw['steps']) || !is_array($raw['steps'])) {
        if ($allow_empty && !count($raw)) { return array('steps' => array()); }
        $errors[] = 'rules_json phải có trường steps (mảng các bước).';
        return null;
    }
    $steps = $raw['steps'];
    if (!count($steps)) {
        if ($allow_empty) {
            $raw['steps'] = array();
            return $raw;
        }
        $errors[] = 'rules_json.steps rỗng — cần ít nhất 1 bước.';
        return null;
    }
    if (count($steps) > RW_V4K_MAX_STEPS) {
        $errors[] = 'Quá nhiều bước (tối đa ' . RW_V4K_MAX_STEPS . ').';
        return null;
    }
    $valid_actions = v4k_step_actions();
    $clean = array();
    foreach (array_values($steps) as $i => $st) {
        $no = $i + 1;
        if (!is_array($st)) {
            $errors[] = 'Bước #' . $no . ' phải là object.';
            continue;
        }
        $act = isset($st['action']) ? strtolower(trim((string)$st['action'])) : '';
        if (!in_array($act, $valid_actions, true)) {
            $errors[] = 'Bước #' . $no . ' có action không hợp lệ "' . v4k_text($act, 20) . '" (chấp nhận: ' . implode(', ', $valid_actions) . ').';
            continue;
        }
        $one = array('action' => $act);
        if (isset($st['note'])) { $one['note'] = v4k_text($st['note'], 300); }
        if (isset($st['url'])) { $one['url'] = v4k_text($st['url'], 2000); }
        if (isset($st['value'])) { $one['value'] = v4k_text($st['value'], 2000); }
        if (isset($st['var'])) { $one['var'] = v4k_text($st['var'], 64); }
        if (isset($st['selectors'])) {
            if (!is_array($st['selectors'])) {
                $errors[] = 'Bước #' . $no . ': selectors phải là mảng chuỗi (selector dự phòng).';
            } else {
                $sel = array();
                foreach (array_slice($st['selectors'], 0, 10) as $s) {
                    $sel[] = v4k_text($s, 300);
                }
                $one['selectors'] = $sel;
            }
        }
        if (isset($st['delay_min']) || isset($st['delay_max'])) {
            $dmin = isset($st['delay_min']) ? (float)$st['delay_min'] : 0.2;
            $dmax = isset($st['delay_max']) ? (float)$st['delay_max'] : $dmin;
            if ($dmin < 0 || $dmax > 120 || $dmin > $dmax) {
                $errors[] = 'Bước #' . $no . ': độ trễ ngẫu nhiên phải nằm trong 0–120 giây và delay_min ≤ delay_max.';
            } else {
                $one['delay_min'] = $dmin;
                $one['delay_max'] = $dmax;
            }
        }
        if (isset($st['condition'])) { $one['condition'] = v4k_text($st['condition'], 300); }
        if ($act === 'loop') {
            $c = isset($st['count']) ? (int)$st['count'] : 0;
            if ($c < 1 || $c > 1000) {
                $errors[] = 'Bước #' . $no . ': loop cần count trong 1–1000 (hoặc đặt biến số câu ở vars).';
            } else {
                $one['count'] = $c;
            }
        }
        if (in_array($act, array('click', 'type', 'select', 'read', 'answer'), true)
            && (!isset($one['selectors']) || !count($one['selectors']))) {
            $warnings[] = 'Bước #' . $no . ' (' . $act . ') chưa có selector dự phòng — nên bổ sung để tăng độ ổn định.';
        }
        $clean[] = $one;
    }
    if (count($errors)) { return null; }
    $raw['steps'] = $clean;
    if (isset($raw['vars']) && is_array($raw['vars'])) {
        $vars = array();
        foreach ($raw['vars'] as $k => $v) {
            $vars[v4k_text($k, 64)] = v4k_text($v, 500);
        }
        $raw['vars'] = $vars;
    } else {
        unset($raw['vars']);
    }
    if (isset($raw['verify']) && is_array($raw['verify'])) {
        $v = array();
        if (isset($raw['verify']['type'])) { $v['type'] = v4k_text($raw['verify']['type'], 40); }
        if (isset($raw['verify']['expect'])) { $v['expect'] = v4k_text($raw['verify']['expect'], 200); }
        $raw['verify'] = $v;
    } else {
        unset($raw['verify']);
    }
    return $raw;
}

/* ---- Khac biet giua 2 bo rules (hien thi cho nguoi duyet) ---- */
function v4k_rules_diff($old, $new) {
    $os = (is_array($old) && isset($old['steps']) && is_array($old['steps'])) ? array_values($old['steps']) : array();
    $ns = (is_array($new) && isset($new['steps']) && is_array($new['steps'])) ? array_values($new['steps']) : array();
    $added = array();
    $removed = array();
    $changed = array();
    $n = max(count($os), count($ns));
    for ($i = 0; $i < $n; $i++) {
        $o = isset($os[$i]) ? $os[$i] : null;
        $x = isset($ns[$i]) ? $ns[$i] : null;
        if ($o === null && $x !== null) {
            $added[] = array('index' => $i + 1, 'step' => $x);
        } elseif ($o !== null && $x === null) {
            $removed[] = array('index' => $i + 1, 'step' => $o);
        } elseif ($o !== null && $x !== null) {
            $jo = json_encode($o, JSON_UNESCAPED_UNICODE);
            $jx = json_encode($x, JSON_UNESCAPED_UNICODE);
            if ($jo !== $jx) {
                $changed[] = array('index' => $i + 1, 'from' => $o, 'to' => $x);
            }
        }
    }
    return array(
        'added' => $added,
        'removed' => $removed,
        'changed' => $changed,
        'summary' => '+' . count($added) . ' −' . count($removed) . ' ~' . count($changed)
    );
}

/* ---- Bien dich huong dan thanh quy tac (muc 6.5 nut "Bien dich huong dan") ---- */
function v4k_compile_guide_text($guide, $ai_rules = null) {
    $warnings = array();
    if ($ai_rules !== null) {
        $errors = array();
        $warn2 = array();
        $parsed = v4k_rules_parse($ai_rules, $errors, $warn2);
        if ($parsed === null) {
            v4k_fail(422, 'rules_invalid', 'rules do AI sinh không hợp lệ.', array('errors' => array_slice($errors, 0, 10)));
        }
        return array('rules' => $parsed, 'warnings' => $warn2, 'source' => 'ai');
    }
    $map = array(
        'goto'   => array('mở', 'truy cập', 'vào url', 'di toi', 'đi tới', 'goto', 'truy cap'),
        'click'  => array('bấm', 'bam', 'click', 'chạm', 'chon the', 'chọn thẻ'),
        'type'   => array('gõ', 'go', 'nhập', 'nhap', 'điền', 'dien', 'type'),
        'select' => array('chọn ', 'chon ', 'select', 'chọn đáp', 'chon dap'),
        'wait'   => array('đợi', 'doi', 'chờ', 'cho ', 'wait'),
        'scroll' => array('cuộn', 'cuon', 'scroll'),
        'read'   => array('đọc', 'doc ', 'read'),
        'answer' => array('trả lời', 'tra loi', 'đáp án', 'dap an', 'answer', 'quiz'),
        'loop'   => array('lặp', 'lap ', 'loop', 'vòng lặp', 'vong lap')
    );
    $steps = array();
    $lines = explode("\n", (string)$guide);
    $idx = 0;
    foreach ($lines as $line) {
        $idx++;
        $t = trim($line);
        if ($t === '') { continue; }
        if (preg_match('/^#{1,6}\s|^\*\*|^[-*]\s*$/i', $t)) { continue; }
        $low = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        $act = '';
        foreach ($map as $a => $keys) {
            foreach ($keys as $k) {
                if (strpos($low, $k) !== false) { $act = $a; break 2; }
            }
        }
        if ($act === '') {
            $warnings[] = 'Dòng ' . $idx . ' không nhận diện được bước — giữ nguyên ở guide.';
            continue;
        }
        $one = array('action' => $act, 'note' => v4k_text($t, 300), 'delay_min' => 0.3, 'delay_max' => 2.5);
        if ($act === 'goto') {
            if (preg_match('#https?://\S+#i', $t, $m)) { $one['url'] = v4k_text($m[0], 2000); }
        }
        $steps[] = $one;
    }
    $warnings[] = 'Biên dịch xác định chỉ là gợi ý — cần người duyệt ở chế độ Biểu mẫu/YAML trước khi chạy thật.';
    return array('rules' => array('steps' => $steps), 'warnings' => $warnings, 'source' => 'deterministic');
}

/* ==================================================================== */
/* 4. DB: bang phu tro, audit, thong ke, dieu kien phat hanh            */
/* ==================================================================== */

function v4k_ensure_tables() {
    $db = rw_db();
    $db->exec("CREATE TABLE IF NOT EXISTS skills (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_key TEXT UNIQUE, name TEXT, category TEXT, fingerprint TEXT, status TEXT DEFAULT 'draft', difficulty INTEGER DEFAULT 0, current_version TEXT DEFAULT '0.1.0', rules_json TEXT, guide_text TEXT, verify_fail INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS skill_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_id INTEGER, semver TEXT, rules_json TEXT, guide_text TEXT, changelog TEXT, reason TEXT, created_by TEXT, status TEXT DEFAULT 'draft', created_at TEXT DEFAULT (datetime('now')), verified_at TEXT, published_at TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS skill_traces (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_id INTEGER, account_id INTEGER, run_id INTEGER, steps_json TEXT, result TEXT, is_sample INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS skill_rollouts (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_id INTEGER, device_id INTEGER, version TEXT, channel TEXT DEFAULT 'stable', status TEXT DEFAULT 'pending', created_at TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE TABLE IF NOT EXISTS skill_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, fingerprint TEXT, title TEXT, category TEXT, sample_url TEXT, screenshot TEXT, difficulty INTEGER DEFAULT 0, occurrences INTEGER DEFAULT 1, status TEXT DEFAULT 'pending', first_seen TEXT DEFAULT (datetime('now')), last_seen TEXT)");
    /* Bang phu tro cua API nay (additive, khong ALTER bang san co) */
    $db->exec("CREATE TABLE IF NOT EXISTS skill_tests (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_id INTEGER, semver TEXT, kind TEXT DEFAULT 'live', account_id INTEGER, device_id INTEGER, job_key TEXT, passed INTEGER, error TEXT, created_by TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_v4k_versions_skill ON skill_versions(skill_id, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_v4k_traces_skill ON skill_traces(skill_id, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_v4k_rollouts_skill ON skill_rollouts(skill_id, device_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_v4k_queue_status ON skill_queue(status, last_seen)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_v4k_tests_skill ON skill_tests(skill_id, kind, id)");
    /* audit_ui (giong api_v4_accounts.php) */
    $db->exec("CREATE TABLE IF NOT EXISTS audit_ui (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime('now')), ip TEXT, user TEXT, action TEXT, entity TEXT, entity_id TEXT, detail TEXT)");
}

function v4k_audit($action, $entity, $entity_id, $detail = array()) {
    $clean = array();
    foreach ((array)$detail as $k => $v) {
        if (preg_match('/pass|secret|token|hash|key|seal|rules|guide/i', (string)$k)) { $v = '***'; }
        $clean[$k] = $v;
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'cli';
    $st = rw_db()->prepare('INSERT INTO audit_ui(ts, ip, user, action, entity, entity_id, detail) VALUES(?,?,?,?,?,?,?)');
    $st->execute(array(v4k_now(), $ip, v4k_user(), (string)$action, (string)$entity, (string)$entity_id,
        json_encode($clean, JSON_UNESCAPED_UNICODE)));
}

/* ---- Thong ke chay that (skill_tests kind=live) ---- */
function v4k_live_stats($db, $skill_id) {
    $out = array('success' => 0, 'fail' => 0, 'pending' => 0, 'rate_7d' => null,
                 'streak_fail' => 0, 'last_live_at' => null, 'account_count' => 0);
    $st = $db->prepare("SELECT COUNT(*) c, SUM(CASE WHEN passed=1 THEN 1 ELSE 0 END) ok,
                SUM(CASE WHEN passed=0 THEN 1 ELSE 0 END) bad,
                SUM(CASE WHEN passed IS NULL THEN 1 ELSE 0 END) pend,
                MAX(created_at) last_at
                FROM skill_tests WHERE skill_id=? AND kind='live'");
    $st->execute(array($skill_id));
    $r = $st->fetch();
    $out['success'] = (int)(isset($r['ok']) ? $r['ok'] : 0);
    $out['fail'] = (int)(isset($r['bad']) ? $r['bad'] : 0);
    $out['pending'] = (int)(isset($r['pend']) ? $r['pend'] : 0);
    $out['last_live_at'] = isset($r['last_at']) ? $r['last_at'] : null;
    $st = $db->prepare("SELECT SUM(CASE WHEN passed=1 THEN 1 ELSE 0 END) ok, SUM(CASE WHEN passed=0 THEN 1 ELSE 0 END) bad
                FROM skill_tests WHERE skill_id=? AND kind='live' AND passed IS NOT NULL
                AND created_at >= datetime('now','-7 days')");
    $st->execute(array($skill_id));
    $r2 = $st->fetch();
    $o = (int)(isset($r2['ok']) ? $r2['ok'] : 0);
    $b = (int)(isset($r2['bad']) ? $r2['bad'] : 0);
    if ($o + $b > 0) { $out['rate_7d'] = round($o / ($o + $b), 4); }
    $st = $db->prepare("SELECT COUNT(DISTINCT account_id) c FROM skill_tests WHERE skill_id=? AND account_id IS NOT NULL");
    $st->execute(array($skill_id));
    $out['account_count'] = (int)$st->fetch()['c'];
    $out['streak_fail'] = v4k_fail_streak($db, $skill_id);
    return $out;
}
function v4k_fail_streak($db, $skill_id) {
    $st = $db->prepare("SELECT passed FROM skill_tests WHERE skill_id=? AND kind='live' AND passed IS NOT NULL ORDER BY id DESC LIMIT 30");
    $st->execute(array($skill_id));
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        if ((int)$r['passed'] === 0) { $n++; } else { break; }
    }
    return $n;
}
function v4k_risk_score($status, $streak_fail, $rate_7d, $last_verified_at, $created_at) {
    $risk = 0;
    $risk += min(40, (int)$streak_fail * 12);
    if ($rate_7d !== null) {
        $risk += (int)round((1 - (float)$rate_7d) * 40);
    } else {
        $risk += 10;
    }
    $ref = $last_verified_at ? $last_verified_at : $created_at;
    $ts = strtotime((string)$ref);
    if ($ts !== false) {
        $days = (time() - $ts) / 86400;
        if ($days > 0) { $risk += min(15, (int)floor($days / 2)); }
    }
    if ($status === 'needs_review') { $risk += 25; }
    if ($status === 'deprecated') { $risk = 0; }
    return max(0, min(100, $risk));
}

/* ---- Dieu kien phat hanh (muc 6.6.1): hoi quy + 2 lan chay that tren 2 account ---- */
function v4k_gates($db, $skill_id, $semver) {
    $st = $db->prepare("SELECT COUNT(*) c FROM skill_tests WHERE skill_id=? AND semver=? AND kind='regression' AND passed=1");
    $st->execute(array($skill_id, $semver));
    $reg = (int)$st->fetch()['c'];
    $st = $db->prepare("SELECT account_id, passed FROM skill_tests WHERE skill_id=? AND semver=? AND kind='live' AND passed=1");
    $st->execute(array($skill_id, $semver));
    $accs = array();
    $live_passes = 0;
    foreach ($st->fetchAll() as $r) {
        $live_passes++;
        if ($r['account_id'] !== null) { $accs[(int)$r['account_id']] = (int)$r['account_id']; }
    }
    $missing = array();
    if ($reg < 1) { $missing[] = 'chưa có kết quả kiểm thử hồi quy PASS cho phiên bản này'; }
    if ($live_passes < 2 || count($accs) < 2) {
        $missing[] = 'cần ít nhất 2 lần chạy thật PASS trên 2 account khác nhau (đang có ' . count($accs) . ' account)';
    }
    return array(
        'semver' => $semver,
        'regression_pass' => $reg,
        'live_passes' => $live_passes,
        'live_accounts' => count($accs),
        'ok' => count($missing) === 0,
        'missing' => $missing
    );
}

/* ---- Tu ha cap khi loi lien tuc (muc 6.6.4) ---- */
function v4k_check_autodowngrade($db, $skill_id) {
    $stats = v4k_live_stats($db, $skill_id);
    $reasons = array();
    if ($stats['streak_fail'] >= 3) {
        $reasons[] = 'fail liên tiếp ' . $stats['streak_fail'] . ' lần (≥ 3)';
    }
    $st = $db->prepare("SELECT COUNT(*) c FROM skill_tests WHERE skill_id=? AND kind='live' AND passed IS NOT NULL AND created_at >= datetime('now','-7 days')");
    $st->execute(array($skill_id));
    $n7 = (int)$st->fetch()['c'];
    if ($n7 >= 5 && $stats['rate_7d'] !== null && (float)$stats['rate_7d'] < 0.6) {
        $reasons[] = 'tỉ lệ thành công 7 ngày ' . round((float)$stats['rate_7d'] * 100) . '% < 60%';
    }
    if (count($reasons)) {
        $st = $db->prepare("UPDATE skills SET status='needs_review', verify_fail=?, updated_at=? WHERE id=? AND status <> 'deprecated'");
        $st->execute(array($stats['streak_fail'], v4k_now(), $skill_id));
        v4k_audit('skill_auto_downgrade', 'skill', $skill_id, array('reasons' => implode('; ', $reasons)));
        return $reasons;
    }
    return array();
}

/* ==================================================================== */
/* 5. KY TEN GOI PHAT HANH + config_pull (muc 6.3)                      */
/* ==================================================================== */

function v4k_sign_key($db) {
    $st = $db->prepare("SELECT value FROM settings WHERE key='v4k_sign_key'");
    $st->execute();
    $r = $st->fetch();
    if ($r && $r['value'] !== '') { return (string)$r['value']; }
    $key = bin2hex(random_bytes(32));
    $st = $db->prepare("INSERT INTO settings(key, value, updated_at) VALUES('v4k_sign_key', ?, ?)");
    $st->execute(array($key, v4k_now()));
    return $key;
}
function v4k_canonical($arr) {
    if (is_array($arr)) {
        ksort($arr);
        foreach ($arr as $k => $v) { $arr[$k] = v4k_canonical($v); }
    }
    return $arr;
}
function v4k_sign($db, $payload) {
    $json = json_encode(v4k_canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash_hmac('sha256', (string)$json, v4k_sign_key($db));
}
function v4k_push_config($db, $device_id, $skill_row, $version_row, $channel) {
    $payload = array(
        'type' => 'skill_package',
        'skill_id' => (int)$skill_row['id'],
        'skill_key' => (string)$skill_row['skill_key'],
        'semver' => (string)$version_row['semver'],
        'rules_json' => (string)$version_row['rules_json'],
        'guide_text' => (string)$version_row['guide_text'],
        'channel' => (string)$channel,
        'issued_at' => v4k_now()
    );
    $payload['signature'] = v4k_sign($db, $payload);
    $st = $db->prepare('SELECT COALESCE(MAX(revision),0) m FROM config_revisions WHERE device_id = ?');
    $st->execute(array($device_id));
    $rev = (int)$st->fetch()['m'] + 1;
    $st = $db->prepare('INSERT INTO config_revisions(device_id, revision, payload_json, created_by, created_at, status) VALUES(?,?,?,?,?,?)');
    $st->execute(array((int)$device_id, $rev, json_encode($payload, JSON_UNESCAPED_UNICODE), v4k_user(), v4k_now(), 'pending'));
    return $rev;
}

/* ==================================================================== */
/* 6. MAPPER DU LIEU                                                    */
/* ==================================================================== */

function v4k_skill_select() {
    return "SELECT s.*,
        COALESCE((SELECT COUNT(*) FROM skill_tests t WHERE t.skill_id=s.id AND t.kind='live' AND t.passed=1),0) AS live_ok,
        COALESCE((SELECT COUNT(*) FROM skill_tests t WHERE t.skill_id=s.id AND t.kind='live' AND t.passed=0),0) AS live_fail,
        COALESCE((SELECT SUM(CASE WHEN t.passed=1 THEN 1 ELSE 0 END) FROM skill_tests t WHERE t.skill_id=s.id AND t.kind='live' AND t.passed IS NOT NULL AND t.created_at>=datetime('now','-7 days')),0) AS live7_ok,
        COALESCE((SELECT SUM(CASE WHEN t.passed=0 THEN 1 ELSE 0 END) FROM skill_tests t WHERE t.skill_id=s.id AND t.kind='live' AND t.passed IS NOT NULL AND t.created_at>=datetime('now','-7 days')),0) AS live7_fail,
        COALESCE((SELECT COUNT(DISTINCT t.account_id) FROM skill_tests t WHERE t.skill_id=s.id AND t.account_id IS NOT NULL),0) AS account_count,
        COALESCE((SELECT COUNT(DISTINCT r.device_id) FROM skill_rollouts r WHERE r.skill_id=s.id),0) AS device_count,
        (SELECT MAX(v.verified_at) FROM skill_versions v WHERE v.skill_id=s.id) AS last_verified_at,
        (SELECT MAX(t.created_at) FROM skill_tests t WHERE t.skill_id=s.id AND t.kind='live') AS last_live_at
        FROM skills s";
}
function v4k_map_skill($r, $streak_fail = null) {
    $l7o = (int)$r['live7_ok'];
    $l7f = (int)$r['live7_fail'];
    $t7 = $l7o + $l7f;
    $rate7 = $t7 > 0 ? round($l7o / $t7, 4) : null;
    $fp = (string)$r['fingerprint'];
    $fps = array();
    foreach (explode(',', $fp) as $x) {
        $x = trim($x);
        if ($x !== '') { $fps[] = v4k_cut($x, 200); }
    }
    if ($streak_fail === null) { $streak_fail = 0; }
    $risk = v4k_risk_score((string)$r['status'], (int)$streak_fail, $rate7, $r['last_verified_at'], $r['created_at']);
    $sts = v4k_skill_statuses();
    return array(
        'id' => (int)$r['id'],
        'skill_key' => (string)$r['skill_key'],
        'name' => (string)$r['name'],
        'category' => (string)$r['category'],
        'category_label' => isset(v4k_categories()[$r['category']]) ? v4k_categories()[$r['category']] : (string)$r['category'],
        'fingerprint' => $fp,
        'fingerprints' => $fps,
        'status' => (string)$r['status'],
        'status_label' => isset($sts[$r['status']]) ? $sts[$r['status']] : (string)$r['status'],
        'difficulty' => (int)$r['difficulty'],
        'difficulty_label' => v4k_difficulty_label((int)$r['difficulty']),
        'current_version' => (string)$r['current_version'],
        'verify_fail' => (int)$r['verify_fail'],
        'success' => (int)$r['live_ok'],
        'fail' => (int)$r['live_fail'],
        'success_rate_7d' => $rate7,
        'fail_streak' => (int)$streak_fail,
        'last_verified_at' => $r['last_verified_at'],
        'last_live_at' => $r['last_live_at'],
        'account_count' => (int)$r['account_count'],
        'device_count' => (int)$r['device_count'],
        'risk_score' => $risk,
        'risk_label' => v4k_risk_label($risk),
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at']
    );
}
function v4k_map_version($r) {
    $sts = v4k_version_statuses();
    return array(
        'id' => (int)$r['id'],
        'skill_id' => (int)$r['skill_id'],
        'semver' => (string)$r['semver'],
        'status' => (string)$r['status'],
        'status_label' => isset($sts[$r['status']]) ? $sts[$r['status']] : (string)$r['status'],
        'changelog' => (string)$r['changelog'],
        'reason' => (string)$r['reason'],
        'created_by' => (string)$r['created_by'],
        'created_at' => $r['created_at'],
        'verified_at' => $r['verified_at'],
        'published_at' => $r['published_at']
    );
}
function v4k_map_trace($r) {
    $steps = json_decode((string)$r['steps_json'], true);
    return array(
        'id' => (int)$r['id'],
        'skill_id' => (int)$r['skill_id'],
        'account_id' => $r['account_id'] !== null ? (int)$r['account_id'] : null,
        'run_id' => $r['run_id'] !== null ? (int)$r['run_id'] : null,
        'result' => (string)$r['result'],
        'is_sample' => (int)$r['is_sample'],
        'steps' => is_array($steps) ? $steps : array(),
        'steps_count' => is_array($steps) ? count($steps) : 0,
        'created_at' => $r['created_at']
    );
}
function v4k_map_queue($r) {
    $sts = v4k_queue_statuses();
    return array(
        'id' => (int)$r['id'],
        'fingerprint' => (string)$r['fingerprint'],
        'title' => (string)$r['title'],
        'category' => (string)$r['category'],
        'sample_url' => (string)$r['sample_url'],
        'screenshot' => (string)$r['screenshot'],
        'difficulty' => (int)$r['difficulty'],
        'difficulty_label' => v4k_difficulty_label((int)$r['difficulty']),
        'occurrences' => (int)$r['occurrences'],
        'status' => (string)$r['status'],
        'status_label' => isset($sts[$r['status']]) ? $sts[$r['status']] : (string)$r['status'],
        'first_seen' => $r['first_seen'],
        'last_seen' => $r['last_seen']
    );
}

function v4k_row_skill($db, $id) {
    $st = $db->prepare(v4k_skill_select() . ' WHERE s.id = ?');
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) {
        v4k_fail(404, 'skill_not_found', 'Không tìm thấy skill id=' . $id . '.');
    }
    return $r;
}
function v4k_streak_map($db, $ids) {
    $out = array();
    if (!count($ids)) { return $out; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT skill_id, passed FROM skill_tests WHERE kind='live' AND passed IS NOT NULL AND skill_id IN ($ph) ORDER BY skill_id ASC, id DESC");
    $st->execute(array_values($ids));
    $buf = array();
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['skill_id'];
        if (!isset($buf[$sid])) {
            $buf[$sid] = array('val' => (int)$r['passed'], 'n' => 1, 'done' => false);
        } elseif (!$buf[$sid]['done'] && (int)$r['passed'] === $buf[$sid]['val']) {
            $buf[$sid]['n']++;
        } else {
            $buf[$sid]['done'] = true;
        }
    }
    foreach ($buf as $sid => $v) {
        $out[$sid] = ($v['val'] === 0 && !$v['done']) ? $v['n'] : (($v['val'] === 0) ? $v['n'] : 0);
    }
    return $out;
}

/* ==================================================================== */
/* 7. BO PHAN XU LY CHINH                                               */
/* ==================================================================== */

function v4k_bootstrap() {
    rw_send_security_headers();
    rw_start_session_secure();
    if (!rw_is_logged_in()) {
        v4k_fail(401, 'unauthorized', 'Chưa đăng nhập hoặc phiên đã hết hạn. Vui lòng đăng nhập lại.');
    }
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($m === 'POST' && !rw_csrf_check()) {
        v4k_fail(403, 'csrf_invalid', 'Token CSRF không hợp lệ hoặc thiếu. Hãy tải lại trang rồi thử lại.');
    }
}

function v4k_main() {
    v4k_bootstrap();
    v4k_ensure_tables();

    $action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['action']) : '';
    if ($action === '' && isset($_POST['action'])) {
        $action = preg_replace('/[^a-z_]/', '', (string)$_POST['action']);
    }
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    $p = ($m === 'GET') ? array_merge($_GET, v4k_input()) : v4k_input();
    $db = rw_db();

    switch ($action) {

        /* ==================== LIST (thu vien skill, muc 6.5) ==================== */
        case 'list': {
            $where = array('1=1');
            $args = array();
            $filters = array();
            $q = trim((string)v4k_param($p, 'q', ''));
            if ($q !== '') {
                $where[] = '(s.name LIKE ? OR s.skill_key LIKE ? OR s.fingerprint LIKE ?)';
                $like = '%' . $q . '%';
                array_push($args, $like, $like, $like);
                $filters['q'] = v4k_cut($q, 60);
            }
            $cat = (string)v4k_param($p, 'category', '');
            if ($cat !== '') {
                if (!isset(v4k_categories()[$cat])) {
                    v4k_fail(422, 'invalid_category', 'Nhóm skill không hợp lệ (chấp nhận: ' . implode(', ', array_keys(v4k_categories())) . ').');
                }
                $where[] = 's.category = ?';
                $args[] = $cat;
                $filters['category'] = $cat;
            }
            $status = (string)v4k_param($p, 'status', '');
            if ($status !== '') {
                if (!isset(v4k_skill_statuses()[$status])) {
                    v4k_fail(422, 'invalid_status', 'Trạng thái skill không hợp lệ.');
                }
                $where[] = 's.status = ?';
                $args[] = $status;
                $filters['status'] = $status;
            }
            $dmin = v4k_int_opt($p, 'difficulty_min', 0, 0, 100);
            $dmax = v4k_int_opt($p, 'difficulty_max', 100, 0, 100);
            if ($dmin > 0) { $where[] = 's.difficulty >= ?'; $args[] = $dmin; $filters['difficulty_min'] = $dmin; }
            if ($dmax < 100) { $where[] = 's.difficulty <= ?'; $args[] = $dmax; $filters['difficulty_max'] = $dmax; }
            $risk_only = v4k_bool(v4k_param($p, 'risk_only', null));
            if ($risk_only) { $filters['risk_only'] = true; }
            $sort_map = array('id' => 's.id', 'name' => 's.name', 'category' => 's.category',
                'status' => 's.status', 'difficulty' => 's.difficulty', 'current_version' => 's.current_version',
                'updated_at' => 's.updated_at', 'created_at' => 's.created_at');
            $sort_by = (string)v4k_param($p, 'sort', 'id');
            $sort_dir = (strtolower((string)v4k_param($p, 'dir', 'desc')) === 'asc') ? 'ASC' : 'DESC';
            $page = max(1, (int)v4k_param($p, 'page', 1));
            $per_page = (int)v4k_param($p, 'per_page', 50);
            if ($per_page < 1) { $per_page = 50; }
            if ($per_page > 200) { $per_page = 200; }
            $sqlw = ' WHERE ' . implode(' AND ', $where);
            $rows = array();
            $total = 0;
            if ($sort_by === 'risk') {
                /* risk tinh theo cong thuc xac dinh -> sap xep trong PHP (gioi han 2000 dong) */
                $st = $db->prepare(v4k_skill_select() . $sqlw . ' LIMIT ' . (int)RW_V4K_RISK_LIMIT);
                $st->execute($args);
                $all = $st->fetchAll();
                $streaks = v4k_streak_map($db, array_map(function ($r) { return (int)$r['id']; }, $all));
                $tmp = array();
                foreach ($all as $r) {
                    $row = v4k_map_skill($r, isset($streaks[(int)$r['id']]) ? $streaks[(int)$r['id']] : 0);
                    if ($risk_only && $row['risk_score'] < 50) { continue; }
                    $tmp[] = $row;
                }
                $total = count($tmp);
                usort($tmp, function ($a, $b) {
                    if ($a['risk_score'] === $b['risk_score']) { return $b['id'] - $a['id']; }
                    return ($a['risk_score'] < $b['risk_score']) ? 1 : -1;
                });
                $rows = array_slice($tmp, ($page - 1) * $per_page, $per_page);
            } else {
                if (!isset($sort_map[$sort_by])) { $sort_by = 'id'; }
                if ($risk_only) {
                    /* loc "sap hong" = risk_score >= 50 -> duyet qua tap hop tai cho */
                    $st = $db->prepare(v4k_skill_select() . $sqlw . ' LIMIT ' . (int)RW_V4K_RISK_LIMIT);
                    $st->execute($args);
                    $all = $st->fetchAll();
                    $streaks = v4k_streak_map($db, array_map(function ($r) { return (int)$r['id']; }, $all));
                    $tmp = array();
                    foreach ($all as $r) {
                        $row = v4k_map_skill($r, isset($streaks[(int)$r['id']]) ? $streaks[(int)$r['id']] : 0);
                        if ($row['risk_score'] < 50) { continue; }
                        $tmp[] = $row;
                    }
                    $total = count($tmp);
                    usort($tmp, function ($a, $b) use ($sort_by, $sort_dir) {
                        $k = $sort_by === 'current_version' ? 'current_version' : $sort_by;
                        $x = isset($a[$k]) ? $a[$k] : 0;
                        $y = isset($b[$k]) ? $b[$k] : 0;
                        if ($x === $y) { return 0; }
                        $cmp = ($x < $y) ? -1 : 1;
                        return ($sort_dir === 'ASC') ? $cmp : -$cmp;
                    });
                    $rows = array_slice($tmp, ($page - 1) * $per_page, $per_page);
                } else {
                    $st = $db->prepare('SELECT COUNT(*) c FROM skills s' . $sqlw);
                    $st->execute($args);
                    $total = (int)$st->fetch()['c'];
                    $st = $db->prepare(v4k_skill_select() . $sqlw
                        . ' ORDER BY ' . $sort_map[$sort_by] . ' ' . $sort_dir . ', s.id ' . $sort_dir
                        . ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)(($page - 1) * $per_page));
                    $st->execute($args);
                    $all = $st->fetchAll();
                    $streaks = v4k_streak_map($db, array_map(function ($r) { return (int)$r['id']; }, $all));
                    foreach ($all as $r) {
                        $rows[] = v4k_map_skill($r, isset($streaks[(int)$r['id']]) ? $streaks[(int)$r['id']] : 0);
                    }
                }
            }
            rw_json(array(
                'ok' => true,
                'data' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => (int)ceil($total / max(1, $per_page)),
                'filters_applied' => $filters,
                'sort' => array('by' => $sort_by, 'dir' => $sort_dir),
                'generated_at' => rw_db_now()
            ));
            break;
        }

        /* ==================== GET (chi tiet skill, 5 tab) ==================== */
        case 'get': {
            $id = v4k_id1($p);
            $r = v4k_row_skill($db, $id);
            $data = v4k_map_skill($r, v4k_fail_streak($db, $id));
            $rules = json_decode((string)$r['rules_json'], true);
            $data['rules'] = is_array($rules) ? $rules : array('steps' => array());
            $data['guide_text'] = (string)$r['guide_text'];
            $st = $db->prepare('SELECT * FROM skill_versions WHERE skill_id = ? ORDER BY id DESC');
            $st->execute(array($id));
            $vers = array();
            foreach ($st->fetchAll() as $v) { $vers[] = v4k_map_version($v); }
            $data['versions'] = $vers;
            $st = $db->prepare('SELECT r.*, d.name AS device_name FROM skill_rollouts r LEFT JOIN devices d ON d.id = r.device_id WHERE r.skill_id = ? ORDER BY r.id DESC');
            $st->execute(array($id));
            $data['rollouts'] = $st->fetchAll();
            $st = $db->prepare('SELECT * FROM skill_traces WHERE skill_id = ? ORDER BY id DESC LIMIT 20');
            $st->execute(array($id));
            $tr = array();
            foreach ($st->fetchAll() as $t) { $tr[] = v4k_map_trace($t); }
            $data['traces'] = $tr;
            $st = $db->prepare("SELECT COUNT(*) c FROM skill_tests WHERE skill_id=? AND kind='regression'");
            $st->execute(array($id));
            $reg_total = (int)$st->fetch()['c'];
            $data['tests'] = array('regression_total' => $reg_total, 'live' => v4k_live_stats($db, $id));
            /* phien ban nhap moi nhat chua phat hanh -> UI biet co "thay doi cho duyet" */
            $data['has_pending_changes'] = false;
            if (count($vers) && (string)$data['current_version'] !== (string)$vers[0]['semver']) {
                $data['has_pending_changes'] = true;
            }
            rw_json(array('ok' => true, 'data' => $data, 'generated_at' => rw_db_now()));
            break;
        }

        /* ==================== SUMMARY (the tong hop, muc 6.5) ==================== */
        case 'summary': {
            $today = date('Y-m-d');
            $cov = array('resolved' => 0, 'by_skill' => 0, 'by_ai' => 0, 'by_human' => 0, 'rate' => null);
            $st = $db->prepare("SELECT COUNT(*) c,
                SUM(CASE WHEN solved_by LIKE 'skill:%' THEN 1 ELSE 0 END) s,
                SUM(CASE WHEN solved_by = 'ai' THEN 1 ELSE 0 END) a,
                SUM(CASE WHEN solved_by IN ('human','manual') THEN 1 ELSE 0 END) h,
                SUM(CASE WHEN solved_by <> '' THEN 1 ELSE 0 END) res
                FROM task_events WHERE date = ?");
            $st->execute(array($today));
            $r = $st->fetch();
            $cov['by_skill'] = (int)(isset($r['s']) ? $r['s'] : 0);
            $cov['by_ai'] = (int)(isset($r['a']) ? $r['a'] : 0);
            $cov['by_human'] = (int)(isset($r['h']) ? $r['h'] : 0);
            $cov['resolved'] = (int)(isset($r['res']) ? $r['res'] : 0);
            if ($cov['resolved'] > 0) { $cov['rate'] = round($cov['by_skill'] / $cov['resolved'], 4); }
            $st = $db->prepare("SELECT COUNT(*) c FROM skill_queue WHERE status='pending'");
            $st->execute();
            $queue_pending = (int)$st->fetch()['c'];
            $st = $db->prepare("SELECT COUNT(*) c FROM skill_queue WHERE status='pending_ai'");
            $st->execute();
            $queue_ai = (int)$st->fetch()['c'];
            /* skill sap hong */
            $st = $db->prepare(v4k_skill_select() . " WHERE s.status <> 'deprecated' LIMIT 200");
            $st->execute();
            $streaks = v4k_streak_map($db, array_map(function ($x) { return (int)$x['id']; }, $st->fetchAll()));
            $st = $db->prepare(v4k_skill_select() . " WHERE s.status <> 'deprecated' LIMIT 200");
            $st->execute();
            $at_risk = array();
            foreach ($st->fetchAll() as $row) {
                $m = v4k_map_skill($row, isset($streaks[(int)$row['id']]) ? $streaks[(int)$row['id']] : 0);
                if ($m['risk_score'] >= 50 && count($at_risk) < 10) {
                    $at_risk[] = array('id' => $m['id'], 'name' => $m['name'], 'risk_score' => $m['risk_score'],
                        'risk_label' => $m['risk_label'], 'fail_streak' => $m['fail_streak'],
                        'success_rate_7d' => $m['success_rate_7d'], 'status' => $m['status']);
                }
            }
            /* nhiem vu khong tu dong duoc */
            $st = $db->prepare("SELECT COUNT(*) c FROM task_events WHERE date = ? AND state = 'needs_human'");
            $st->execute(array($today));
            $nh = (int)$st->fetch()['c'];
            $st = $db->prepare("SELECT task_key, error FROM task_events WHERE date = ? AND state = 'needs_human' ORDER BY id DESC LIMIT 5");
            $st->execute(array($today));
            $reasons = array();
            foreach ($st->fetchAll() as $x) {
                $reasons[] = array('task_key' => (string)$x['task_key'], 'reason' => v4k_text($x['error'], 200));
            }
            $st = $db->prepare("SELECT COUNT(*) c FROM skill_queue WHERE difficulty >= 86");
            $st->execute();
            $nhq = (int)$st->fetch()['c'];
            rw_json(array(
                'ok' => true,
                'data' => array(
                    'date' => $today,
                    'skill_coverage_today' => $cov,
                    'ai_calls_today' => $cov['by_ai'],
                    'queue_pending' => $queue_pending,
                    'queue_pending_ai' => $queue_ai,
                    'skills_at_risk' => $at_risk,
                    'non_automatable' => array('count_today' => $nh, 'count_queue_hard' => $nhq, 'reasons' => $reasons)
                ),
                'generated_at' => rw_db_now()
            ));
            break;
        }

        /* ==================== CREATE ==================== */
        case 'create': {
            v4k_require_post();
            $key = v4k_text(v4k_param($p, 'skill_key', ''), 64);
            if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_\-]{1,63}$/', $key)) {
                v4k_fail(422, 'invalid_skill_key', 'skill_key không hợp lệ (chữ thường, số, _ hoặc -, 2–64 ký tự).');
            }
            $name = v4k_text(v4k_param($p, 'name', ''), 200);
            if ($name === '') {
                v4k_fail(400, 'name_required', 'Thiếu tên skill.');
            }
            $cat = (string)v4k_param($p, 'category', 'other');
            if (!isset(v4k_categories()[$cat])) {
                v4k_fail(422, 'invalid_category', 'Nhóm skill không hợp lệ (chấp nhận: ' . implode(', ', array_keys(v4k_categories())) . ').');
            }
            $diff = v4k_int_opt($p, 'difficulty', 0, 0, 100);
            $fp = v4k_text(v4k_param($p, 'fingerprint', ''), 500);
            $guide = v4k_multiline(v4k_param($p, 'guide_text', ''), RW_V4K_MAX_GUIDE);
            $errors = array();
            $warnings = array();
            $rules = null;
            $rules_in = v4k_param($p, 'rules_json', null);
            if ($rules_in !== null && $rules_in !== '') {
                $rules = v4k_rules_parse($rules_in, $errors, $warnings);
                if ($rules === null) {
                    v4k_fail(422, 'rules_invalid', 'rules_json không hợp lệ.', array('errors' => array_slice($errors, 0, 10)));
                }
            } else {
                $rules = array('steps' => array());
            }
            v4k_assert_clean(array('rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE), 'guide_text' => $guide));
            $reason = v4k_text(v4k_param($p, 'reason', 'Tạo skill mới'), 500);
            $st = $db->prepare('SELECT id FROM skills WHERE skill_key = ?');
            $st->execute(array($key));
            if ($st->fetch()) {
                v4k_fail(409, 'skill_key_exists', 'skill_key "' . $key . '" đã tồn tại trong hệ thống.');
            }
            $semver = v4k_text(v4k_param($p, 'semver', '0.1.0'), 20);
            if (!v4k_semver_ok($semver)) {
                v4k_fail(422, 'invalid_semver', 'semver không hợp lệ (cần dạng x.y.z).');
            }
            $now = v4k_now();
            $db->beginTransaction();
            try {
                $st = $db->prepare('INSERT INTO skills(skill_key, name, category, fingerprint, status, difficulty, current_version, rules_json, guide_text, verify_fail, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute(array($key, $name, $cat, $fp, 'draft', $diff, $semver,
                    json_encode($rules, JSON_UNESCAPED_UNICODE), $guide, 0, $now, $now));
                $sid = (int)$db->lastInsertId();
                $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute(array($sid, $semver, json_encode($rules, JSON_UNESCAPED_UNICODE), $guide,
                    'Tạo skill mới', $reason, v4k_user(), 'draft', $now));
                $vid = (int)$db->lastInsertId();
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                v4k_fail(500, 'create_failed', 'Không tạo được skill: ' . v4k_text($e->getMessage(), 200));
            }
            v4k_audit('skill_create', 'skill', $sid, array('skill_key' => $key, 'reason' => $reason));
            $row = v4k_row_skill($db, $sid);
            rw_json(array(
                'ok' => true,
                'skill' => v4k_map_skill($row, 0),
                'version' => array('id' => $vid, 'semver' => $semver, 'status' => 'draft'),
                'warnings' => $warnings,
                'message' => 'Đã tạo skill "' . $name . '" (phiên bản ' . $semver . ', trạng thái Nháp).'
            ));
            break;
        }

        /* ==================== UPDATE (tao phien ban moi, muc 6.3) ==================== */
        case 'update': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $reason = v4k_require_reason($p, 'sửa quy tắc skill');
            $changelog = v4k_text(v4k_param($p, 'changelog', ''), 500);
            $changed = array();
            $fields = array();
            $name = v4k_param($p, 'name', null);
            if ($name !== null && v4k_text($name, 200) !== (string)$row['name']) {
                $fields['name'] = v4k_text($name, 200);
                $changed[] = 'name';
            }
            $cat = v4k_param($p, 'category', null);
            if ($cat !== null && (string)$cat !== (string)$row['category']) {
                if (!isset(v4k_categories()[$cat])) {
                    v4k_fail(422, 'invalid_category', 'Nhóm skill không hợp lệ.');
                }
                $fields['category'] = (string)$cat;
                $changed[] = 'category';
            }
            $diff = v4k_param($p, 'difficulty', null);
            if ($diff !== null && $diff !== '' && (int)$diff !== (int)$row['difficulty']) {
                $fields['difficulty'] = v4k_int_opt($p, 'difficulty', 0, 0, 100);
                $changed[] = 'difficulty';
            }
            $fp = v4k_param($p, 'fingerprint', null);
            if ($fp !== null && v4k_text($fp, 500) !== (string)$row['fingerprint']) {
                $fields['fingerprint'] = v4k_text($fp, 500);
                $changed[] = 'fingerprint';
            }
            $new_rules = null;
            $rules_in = v4k_param($p, 'rules_json', null);
            $guide_in = v4k_param($p, 'guide_text', null);
            $new_guide = null;
            $errors = array();
            $warnings = array();
            if ($rules_in !== null && $rules_in !== '') {
                $new_rules = v4k_rules_parse($rules_in, $errors, $warnings);
                if ($new_rules === null) {
                    v4k_fail(422, 'rules_invalid', 'rules_json không hợp lệ.', array('errors' => array_slice($errors, 0, 10)));
                }
            }
            if ($guide_in !== null) {
                $new_guide = v4k_multiline($guide_in, RW_V4K_MAX_GUIDE);
            }
            v4k_assert_clean(array(
                'rules_json' => $new_rules !== null ? json_encode($new_rules, JSON_UNESCAPED_UNICODE) : '',
                'guide_text' => $new_guide !== null ? $new_guide : ''));
            $rules_changed = false;
            if ($new_rules !== null) {
                $old_rules = json_decode((string)$row['rules_json'], true);
                if (json_encode($new_rules, JSON_UNESCAPED_UNICODE) !== json_encode($old_rules, JSON_UNESCAPED_UNICODE)) {
                    $rules_changed = true;
                }
            }
            $guide_changed = ($new_guide !== null && $new_guide !== (string)$row['guide_text']);
            if (!$rules_changed && !$guide_changed && !count($changed)) {
                v4k_fail(400, 'nothing_to_update', 'Không có trường nào thay đổi.');
            }
            if (count($changed)) {
                $fields['updated_at'] = v4k_now();
                $set = array();
                $args = array();
                foreach ($fields as $k => $v) { $set[] = $k . ' = ?'; $args[] = $v; }
                $args[] = $id;
                $st = $db->prepare('UPDATE skills SET ' . implode(', ', $set) . ' WHERE id = ?');
                $st->execute($args);
            }
            $created_version = null;
            if ($rules_changed || $guide_changed) {
                $cur = (string)$row['current_version'];
                $given = v4k_text(v4k_param($p, 'semver', ''), 20);
                if ($given !== '') {
                    if (!v4k_semver_ok($given)) {
                        v4k_fail(422, 'invalid_semver', 'semver không hợp lệ (cần dạng x.y.z).');
                    }
                    if (v4k_semver_cmp($given, $cur) <= 0) {
                        v4k_fail(409, 'version_not_greater', 'semver ' . $given . ' phải lớn hơn phiên bản hiện tại ' . $cur . '.');
                    }
                    $next = $given;
                } else {
                    $next = v4k_semver_bump($cur);
                    for ($i = 0; $i < 50; $i++) {
                        $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_id = ? AND semver = ?');
                        $st->execute(array($id, $next));
                        if (!$st->fetch()) { break; }
                        $next = v4k_semver_bump($next);
                    }
                }
                $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_id = ? AND semver = ?');
                $st->execute(array($id, $next));
                if ($st->fetch()) {
                    v4k_fail(409, 'version_exists', 'Phiên bản ' . $next . ' đã tồn tại.');
                }
                $rj = ($new_rules !== null) ? json_encode($new_rules, JSON_UNESCAPED_UNICODE) : (string)$row['rules_json'];
                $gt = ($new_guide !== null) ? $new_guide : (string)$row['guide_text'];
                $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute(array($id, $next, $rj, $gt, $changelog !== '' ? $changelog : 'Sửa quy tắc skill', $reason, v4k_user(), 'draft', v4k_now()));
                $vid = (int)$db->lastInsertId();
                $st = $db->prepare('UPDATE skills SET rules_json = ?, guide_text = ?, updated_at = ? WHERE id = ?');
                $st->execute(array($rj, $gt, v4k_now(), $id));
                $created_version = array('id' => $vid, 'semver' => $next, 'status' => 'draft');
            }
            v4k_audit('skill_update', 'skill', $id, array(
                'changed' => implode(',', array_merge($changed, $rules_changed ? array('rules') : array(), $guide_changed ? array('guide') : array())),
                'semver' => $created_version ? $created_version['semver'] : '',
                'reason' => $reason));
            $row2 = v4k_row_skill($db, $id);
            rw_json(array(
                'ok' => true,
                'skill' => v4k_map_skill($row2, v4k_fail_streak($db, $id)),
                'version' => $created_version,
                'updated_fields' => array_merge($changed, $rules_changed ? array('rules') : array(), $guide_changed ? array('guide') : array()),
                'warnings' => $warnings,
                'message' => $created_version
                    ? 'Đã lưu thay đổi thành phiên bản ' . $created_version['semver'] . ' (Nháp) — cần kiểm thử trước khi phát hành.'
                    : 'Đã cập nhật thông tin skill.'
            ));
            break;
        }

        /* ==================== COMPILE_GUIDE (muc 6.5 tab 2) ==================== */
        case 'compile_guide': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $guide = v4k_param($p, 'guide_text', null);
            $guide = ($guide === null) ? (string)$row['guide_text'] : v4k_multiline($guide, RW_V4K_MAX_GUIDE);
            if (trim($guide) === '') {
                v4k_fail(422, 'guide_empty', 'Chưa có hướng dẫn giải để biên dịch.');
            }
            $ai_rules = v4k_param($p, 'rules_json', null);
            $compiled = v4k_compile_guide_text($guide, ($ai_rules !== null && $ai_rules !== '') ? $ai_rules : null);
            $old_rules = json_decode((string)$row['rules_json'], true);
            $diff = v4k_rules_diff($old_rules, $compiled['rules']);
            $save = v4k_bool(v4k_param($p, 'save', null));
            if (!$save) {
                rw_json(array(
                    'ok' => true,
                    'compiled' => $compiled['rules'],
                    'source' => $compiled['source'],
                    'diff' => $diff,
                    'warnings' => $compiled['warnings'],
                    'saved' => false,
                    'message' => 'Đã biên dịch hướng dẫn thành bước có cấu trúc (chưa lưu) — xem khác biệt để người duyệt.'
                ));
            }
            /* che do luu: tao phien ban moi (can ly do + duyet) */
            $reason = v4k_require_reason($p, 'lưu quy tắc biên dịch từ hướng dẫn');
            v4k_assert_clean(array('rules_json' => json_encode($compiled['rules'], JSON_UNESCAPED_UNICODE)));
            $cur = (string)$row['current_version'];
            $next = v4k_semver_bump($cur);
            for ($i = 0; $i < 50; $i++) {
                $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_id = ? AND semver = ?');
                $st->execute(array($id, $next));
                if (!$st->fetch()) { break; }
                $next = v4k_semver_bump($next);
            }
            $rj = json_encode($compiled['rules'], JSON_UNESCAPED_UNICODE);
            $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute(array($id, $next, $rj, $guide, 'Biên dịch hướng dẫn thành quy tắc', $reason, v4k_user(), 'draft', v4k_now()));
            $vid = (int)$db->lastInsertId();
            $st = $db->prepare('UPDATE skills SET rules_json = ?, updated_at = ? WHERE id = ?');
            $st->execute(array($rj, v4k_now(), $id));
            v4k_audit('skill_compile_guide', 'skill', $id, array('semver' => $next, 'source' => $compiled['source'], 'reason' => $reason));
            rw_json(array(
                'ok' => true,
                'compiled' => $compiled['rules'],
                'source' => $compiled['source'],
                'diff' => $diff,
                'warnings' => $compiled['warnings'],
                'saved' => true,
                'version' => array('id' => $vid, 'semver' => $next, 'status' => 'draft'),
                'message' => 'Đã lưu quy tắc biên dịch thành phiên bản ' . $next . ' (Nháp).'
            ));
            break;
        }

        /* ==================== TEST_REGRESSION (muc 6.5 tab 4) ==================== */
        case 'test_regression': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $semver = v4k_text(v4k_param($p, 'semver', ''), 20);
            if ($semver === '') {
                /* uu tien phien ban nhap moi nhat, nguoc lai phien ban hien tai */
                $st = $db->prepare('SELECT semver FROM skill_versions WHERE skill_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute(array($id));
                $v = $st->fetch();
                $semver = $v ? (string)$v['semver'] : (string)$row['current_version'];
            }
            $st = $db->prepare('SELECT * FROM skill_traces WHERE skill_id = ? AND (is_sample = 1 OR ? = 1) ORDER BY id ASC');
            $use_all = v4k_bool(v4k_param($p, 'use_all_traces', null)) ? 1 : 0;
            $st->execute(array($id, $use_all));
            $traces = $st->fetchAll();
            if (!count($traces)) {
                v4k_fail(409, 'no_regression_data',
                    'Chưa có trace mẫu nào để chạy hồi quy (mục 6.6.1). Hãy ghi trace mẫu (is_sample=1) hoặc gửi trace_ids.');
            }
            $rules = json_decode((string)$row['rules_json'], true);
            $steps = (is_array($rules) && isset($rules['steps']) && is_array($rules['steps'])) ? array_values($rules['steps']) : array();
            if (!count($steps)) {
                v4k_fail(422, 'rules_invalid', 'Skill chưa có bước quy tắc nào để kiểm thử.');
            }
            $details = array();
            $all_pass = true;
            foreach ($traces as $t) {
                $tsteps = json_decode((string)$t['steps_json'], true);
                $tsteps = is_array($tsteps) ? array_values($tsteps) : array();
                $matched = 0;
                $pos = 0;
                foreach ($steps as $s) {
                    for ($j = $pos; $j < count($tsteps); $j++) {
                        $ta = isset($tsteps[$j]['action']) ? strtolower((string)$tsteps[$j]['action']) : '';
                        if ($ta === (string)$s['action']) { $matched++; $pos = $j + 1; break; }
                    }
                }
                $ratio = count($steps) > 0 ? $matched / count($steps) : 0;
                $pass = ($ratio >= 0.8);
                if (!$pass) { $all_pass = false; }
                $details[] = array('trace_id' => (int)$t['id'], 'steps_total' => count($steps),
                    'steps_matched' => $matched, 'match_ratio' => round($ratio, 4), 'pass' => $pass);
            }
            $st = $db->prepare('INSERT INTO skill_tests(skill_id, semver, kind, passed, error, created_by) VALUES(?,?,?,?,?,?)');
            $st->execute(array($id, $semver, 'regression', $all_pass ? 1 : 0,
                $all_pass ? '' : 'Hồi quy lệch cấu trúc bước so với trace mẫu', v4k_user()));
            v4k_audit('skill_test_regression', 'skill', $id, array('semver' => $semver, 'result' => $all_pass ? 'pass' : 'fail',
                'traces' => count($details)));
            rw_json(array(
                'ok' => true,
                'result' => $all_pass ? 'pass' : 'fail',
                'semver' => $semver,
                'traces_tested' => count($details),
                'details' => $details,
                'message' => $all_pass
                    ? 'Hồi quy PASS trên ' . count($details) . ' trace mẫu.'
                    : 'Hồi quy FAIL — cần xem lại quy tắc so với trace mẫu.'
            ));
            break;
        }

        /* ==================== TEST_LIVE (chay that tren account) ==================== */
        case 'test_live': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $semver = v4k_text(v4k_param($p, 'semver', ''), 20);
            if ($semver === '') {
                $st = $db->prepare('SELECT semver FROM skill_versions WHERE skill_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute(array($id));
                $v = $st->fetch();
                $semver = $v ? (string)$v['semver'] : (string)$row['current_version'];
            }
            $mode = (string)v4k_param($p, 'mode', '');
            if ($mode === '') {
                $mode = (v4k_param($p, 'result', null) !== null) ? 'report' : 'enqueue';
            }
            if ($mode === 'report') {
                $res = strtolower(trim((string)v4k_param($p, 'result', '')));
                if ($res !== 'pass' && $res !== 'fail') {
                    v4k_fail(422, 'invalid_result', 'result phải là pass hoặc fail.');
                }
                $acc = v4k_param($p, 'account_id', null);
                $acc = ($acc === null || $acc === '') ? null : v4k_id1($p, 'account_id');
                $job_key = v4k_text(v4k_param($p, 'job_key', ''), 120);
                $dev = v4k_param($p, 'device_id', null);
                $dev = ($dev === null || $dev === '') ? null : v4k_id1($p, 'device_id');
                $err = v4k_text(v4k_param($p, 'error', ''), 300);
                if ($job_key !== '') {
                    $st = $db->prepare("SELECT id FROM skill_tests WHERE job_key = ? AND kind='live'");
                    $st->execute(array($job_key));
                    $t = $st->fetch();
                    if ($t) {
                        $st = $db->prepare('UPDATE skill_tests SET passed = ?, error = ?, device_id = COALESCE(?, device_id) WHERE id = ?');
                        $st->execute(array($res === 'pass' ? 1 : 0, $err, $dev, (int)$t['id']));
                    } else {
                        $st = $db->prepare('INSERT INTO skill_tests(skill_id, semver, kind, account_id, device_id, job_key, passed, error, created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                        $st->execute(array($id, $semver, 'live', $acc, $dev, $job_key, $res === 'pass' ? 1 : 0, $err, v4k_user()));
                    }
                } else {
                    $st = $db->prepare('INSERT INTO skill_tests(skill_id, semver, kind, account_id, device_id, passed, error, created_by) VALUES(?,?,?,?,?,?,?,?)');
                    $st->execute(array($id, $semver, 'live', $acc, $dev, $res === 'pass' ? 1 : 0, $err, v4k_user()));
                }
                /* ghi trace buoc neu co (dung cho hoi quy sau nay) */
                $steps_in = v4k_param($p, 'steps', null);
                if ($steps_in !== null && $steps_in !== '') {
                    $sj = is_array($steps_in) ? json_encode($steps_in, JSON_UNESCAPED_UNICODE) : (string)$steps_in;
                    if (strlen($sj) <= RW_V4K_MAX_RULES_JSON) {
                        $is_sample = v4k_bool(v4k_param($p, 'is_sample', null)) ? 1 : 0;
                        $st = $db->prepare('INSERT INTO skill_traces(skill_id, account_id, steps_json, result, is_sample) VALUES(?,?,?,?,?)');
                        $st->execute(array($id, $acc, $sj, $res === 'pass' ? 'pass' : 'fail', $is_sample));
                    }
                }
                $down = v4k_check_autodowngrade($db, $id);
                $stats = v4k_live_stats($db, $id);
                rw_json(array(
                    'ok' => true,
                    'recorded' => true,
                    'result' => $res,
                    'stats' => $stats,
                    'downgraded' => count($down) > 0,
                    'downgrade_reasons' => $down,
                    'message' => count($down)
                        ? 'Đã ghi kết quả. Skill tự hạ về «Cần xem lại»: ' . implode('; ', $down) . '.'
                        : 'Đã ghi kết quả chạy thật.'
                ));
            }
            /* enqueue: tao job chay thu cho 1..5 account */
            $accs = v4k_ids($p, 'account_ids');
            if (count($accs) > 5) {
                v4k_fail(400, 'invalid_id', 'Chạy thử tối đa 5 account mỗi lần.');
            }
            $dev = v4k_param($p, 'device_id', null);
            if ($dev !== null && $dev !== '') {
                $dev = v4k_id1($p, 'device_id');
                $st = $db->prepare('SELECT id, revoked FROM devices WHERE id = ?');
                $st->execute(array($dev));
                $d = $st->fetch();
                if (!$d) { v4k_fail(404, 'device_not_found', 'Không tìm thấy thiết bị id=' . $dev . '.'); }
                if ((int)$d['revoked'] === 1) { v4k_fail(409, 'device_revoked', 'Thiết bị đã bị thu hồi, không nhận job.'); }
            } else {
                $dev = null;
            }
            $jobs = array();
            foreach ($accs as $acc) {
                $jk = 'v4k-live-' . $id . '-' . $semver . '-' . $acc . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $st = $db->prepare('INSERT INTO jobs(job_key, device_id, account_id, action, args_json, status, priority, timeout_s, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute(array($jk, $dev, $acc, 'skill_test_live',
                    json_encode(array('type' => 'skill_live_test', 'skill_id' => $id, 'semver' => $semver, 'account_id' => $acc), JSON_UNESCAPED_UNICODE),
                    'pending', 3, 900, v4k_now()));
                $st = $db->prepare('INSERT INTO skill_tests(skill_id, semver, kind, account_id, device_id, job_key, created_by) VALUES(?,?,?,?,?,?,?)');
                $st->execute(array($id, $semver, 'live', $acc, $dev, $jk, v4k_user()));
                $jobs[] = array('job_key' => $jk, 'account_id' => $acc, 'device_id' => $dev);
            }
            v4k_audit('skill_test_live_enqueue', 'skill', $id, array('semver' => $semver, 'accounts' => count($accs)));
            rw_json(array(
                'ok' => true,
                'queued' => count($jobs),
                'semver' => $semver,
                'jobs' => $jobs,
                'message' => 'Đã xếp ' . count($jobs) . ' job chạy thử thật (không AI) cho skill.'
            ));
            break;
        }

        /* ==================== VERIFY (muc 6.6.1) ==================== */
        case 'verify': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $semver = v4k_text(v4k_param($p, 'semver', ''), 20);
            if ($semver === '') {
                $st = $db->prepare('SELECT semver FROM skill_versions WHERE skill_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute(array($id));
                $v = $st->fetch();
                $semver = $v ? (string)$v['semver'] : (string)$row['current_version'];
            }
            $st = $db->prepare('SELECT * FROM skill_versions WHERE skill_id = ? AND semver = ?');
            $st->execute(array($id, $semver));
            $ver = $st->fetch();
            if (!$ver) {
                v4k_fail(404, 'version_not_found', 'Không tìm thấy phiên bản ' . $semver . ' của skill.');
            }
            $gates = v4k_gates($db, $id, $semver);
            if (!$gates['ok']) {
                v4k_fail(409, 'gates_not_met',
                    'Chưa đủ điều kiện verify (mục 6.6.1): ' . implode('; ', $gates['missing']) . '.',
                    array('gates' => $gates));
            }
            $st = $db->prepare("UPDATE skill_versions SET status='verified', verified_at=? WHERE id = ?");
            $st->execute(array(v4k_now(), $ver['id']));
            $st = $db->prepare("UPDATE skills SET status='verified', verify_fail=0, updated_at=? WHERE id = ? AND status <> 'deprecated'");
            $st->execute(array(v4k_now(), $id));
            v4k_audit('skill_verify', 'skill', $id, array('semver' => $semver, 'gates' => json_encode($gates)));
            rw_json(array(
                'ok' => true,
                'version' => array('id' => (int)$ver['id'], 'semver' => $semver, 'status' => 'verified'),
                'gates' => $gates,
                'message' => 'Đã verify phiên bản ' . $semver . '. Có thể phát hành (canary trước, stable sau).'
            ));
            break;
        }

        /* ==================== PUBLISH (channel canary|stable) ==================== */
        case 'publish': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $channel = strtolower(trim((string)v4k_param($p, 'channel', 'stable')));
            if (!isset(v4k_channels()[$channel])) {
                v4k_fail(422, 'invalid_channel', 'Kênh phát hành không hợp lệ (chấp nhận: canary, stable).');
            }
            $semver = v4k_text(v4k_param($p, 'semver', ''), 20);
            if ($semver === '') {
                /* mac dinh: phien ban MOI NHAT (giong verify/test_regression/test_live),
                   khong phai ban da phat hanh cu (skills.current_version) */
                $st = $db->prepare('SELECT semver FROM skill_versions WHERE skill_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute(array($id));
                $v0 = $st->fetch();
                $semver = $v0 ? (string)$v0['semver'] : (string)$row['current_version'];
            }
            $st = $db->prepare('SELECT * FROM skill_versions WHERE skill_id = ? AND semver = ?');
            $st->execute(array($id, $semver));
            $ver = $st->fetch();
            if (!$ver) {
                v4k_fail(404, 'version_not_found', 'Không tìm thấy phiên bản ' . $semver . ' của skill.');
            }
            /* muc 6.6.5: canary (1 thiet bi) -> stable (tat ca); phien ban da 'published'
               van duoc phat hanh tiep (len stable); draft/rolled_back/deprecated bi chan */
            $vst = (string)$ver['status'];
            if ($vst !== 'verified' && $vst !== 'published') {
                v4k_fail(409, 'version_not_verified',
                    'Phiên bản ' . $semver . ' chưa được verify (trạng thái hiện tại: ' . $vst . '). Chạy verify trước khi phát hành.');
            }
            /* muc 6.6.1: hoi quy PASS + >= 2 lan chay that PASS tren 2 account khac nhau */
            $gates = v4k_gates($db, $id, $semver);
            if (!$gates['ok']) {
                v4k_fail(409, 'gates_not_met',
                    'Không phát hành được ' . $semver . ' (mục 6.6.1): ' . implode('; ', $gates['missing']) . '.',
                    array('gates' => $gates));
            }
            /* chon thiet bi nhan ban */
            $devices = array();
            if ($channel === 'canary') {
                $dev = v4k_param($p, 'device_id', null);
                if ($dev !== null && $dev !== '') {
                    $dev = v4k_id1($p, 'device_id');
                    $st = $db->prepare('SELECT id FROM devices WHERE id = ? AND revoked = 0');
                    $st->execute(array($dev));
                    $d = $st->fetch();
                    if (!$d) { v4k_fail(404, 'device_not_found', 'Không tìm thấy thiết bị id=' . $dev . ' (hoặc thiết bị đã bị thu hồi).'); }
                    $devices[] = $dev;
                } else {
                    $st = $db->prepare("SELECT id FROM devices WHERE revoked = 0 ORDER BY CASE status WHEN 'online' THEN 0 WHEN 'busy' THEN 1 ELSE 2 END, id ASC LIMIT 1");
                    $st->execute();
                    $d = $st->fetch();
                    if (!$d) {
                        v4k_fail(409, 'no_devices', 'Không có thiết bị nào để phát hành canary. Hãy đăng ký thiết bị trước.');
                    }
                    $devices[] = (int)$d['id'];
                }
            } else {
                $st = $db->prepare('SELECT id FROM devices WHERE revoked = 0 ORDER BY id ASC');
                $st->execute();
                foreach ($st->fetchAll() as $d) { $devices[] = (int)$d['id']; }
                if (!count($devices)) {
                    v4k_fail(409, 'no_devices', 'Không có thiết bị nào để phát hành stable. Hãy đăng ký thiết bị trước.');
                }
            }
            $reason = v4k_text(v4k_param($p, 'reason', ''), 500);
            $now = v4k_now();
            $revisions = array();
            $db->beginTransaction();
            try {
                /* ban cu -> superseded */
                $st = $db->prepare("UPDATE skill_rollouts SET status='superseded' WHERE skill_id = ? AND status IN ('pending','delivered','active')");
                $st->execute(array($id));
                foreach ($devices as $dev) {
                    $st = $db->prepare('INSERT INTO skill_rollouts(skill_id, device_id, version, channel, status, created_at) VALUES(?,?,?,?,?,?)');
                    $st->execute(array($id, $dev, $semver, $channel, 'pending', $now));
                    $revisions[] = array('device_id' => $dev, 'revision' => v4k_push_config($db, $dev, $row, $ver, $channel));
                }
                $st = $db->prepare("UPDATE skill_versions SET status='published', published_at=? WHERE id = ?");
                $st->execute(array($now, $ver['id']));
                $st = $db->prepare('UPDATE skills SET current_version = ?, updated_at = ? WHERE id = ?');
                $st->execute(array($semver, $now, $id));
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                v4k_fail(500, 'publish_failed', 'Không phát hành được: ' . v4k_text($e->getMessage(), 200));
            }
            v4k_audit('skill_publish', 'skill', $id, array('semver' => $semver, 'channel' => $channel,
                'devices' => count($devices), 'reason' => $reason));
            rw_json(array(
                'ok' => true,
                'semver' => $semver,
                'channel' => $channel,
                'devices' => $devices,
                'config_revisions' => $revisions,
                'message' => 'Đã phát hành ' . $semver . ' qua kênh ' . $channel . ' (' . count($devices) . ' thiết bị, gói có chữ ký qua config_pull).'
            ));
            break;
        }

        /* ==================== ROLLBACK ==================== */
        case 'rollback': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $reason = v4k_require_reason($p, 'rollback skill');
            $target = v4k_text(v4k_param($p, 'target_semver', ''), 20);
            if ($target === '') {
                $st = $db->prepare("SELECT semver FROM skill_versions WHERE skill_id = ? AND status = 'published' AND semver <> ? ORDER BY id DESC LIMIT 1");
                $st->execute(array($id, (string)$row['current_version']));
                $v = $st->fetch();
                if (!$v) {
                    v4k_fail(409, 'no_rollback_target', 'Không có phiên bản đã phát hành trước đó để rollback. Hãy chỉ định target_semver.');
                }
                $target = (string)$v['semver'];
            } else {
                if (!v4k_semver_ok($target)) {
                    v4k_fail(422, 'invalid_semver', 'target_semver không hợp lệ (cần dạng x.y.z).');
                }
            }
            $st = $db->prepare('SELECT * FROM skill_versions WHERE skill_id = ? AND semver = ?');
            $st->execute(array($id, $target));
            $ver = $st->fetch();
            if (!$ver) {
                v4k_fail(404, 'version_not_found', 'Không tìm thấy phiên bản mục tiêu ' . $target . '.');
            }
            $now = v4k_now();
            $db->beginTransaction();
            try {
                $st = $db->prepare("UPDATE skill_versions SET status='rolled_back' WHERE skill_id = ? AND semver = ? AND status IN ('published','verified')");
                $st->execute(array($id, (string)$row['current_version']));
                $st = $db->prepare("UPDATE skill_versions SET status='published', published_at=? WHERE id = ?");
                $st->execute(array($now, $ver['id']));
                $st = $db->prepare('UPDATE skill_rollouts SET version = ?, status = ? WHERE skill_id = ?');
                $st->execute(array($target, 'rollback', $id));
                $st = $db->prepare('UPDATE skills SET current_version = ?, updated_at = ? WHERE id = ?');
                $st->execute(array($target, $now, $id));
                $st = $db->prepare('SELECT id FROM devices WHERE revoked = 0');
                $st->execute();
                foreach ($st->fetchAll() as $d) {
                    v4k_push_config($db, (int)$d['id'], $row, $ver, 'rollback');
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                v4k_fail(500, 'rollback_failed', 'Không rollback được: ' . v4k_text($e->getMessage(), 200));
            }
            v4k_audit('skill_rollback', 'skill', $id, array('target_semver' => $target, 'reason' => $reason));
            rw_json(array(
                'ok' => true,
                'rolled_back_to' => array('id' => (int)$ver['id'], 'semver' => $target, 'status' => 'published'),
                'message' => 'Đã rollback về phiên bản ' . $target . ' (mọi thiết bị nhận gói thay thế qua config_pull).'
            ));
            break;
        }

        /* ==================== DEPRECATE ==================== */
        case 'deprecate': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            v4k_require_confirm($p);
            $reason = v4k_require_reason($p, 'ngừng skill');
            $st = $db->prepare("UPDATE skills SET status='deprecated', updated_at=? WHERE id = ?");
            $st->execute(array(v4k_now(), $id));
            $st = $db->prepare("UPDATE skill_rollouts SET status='stopped' WHERE skill_id = ? AND status IN ('pending','delivered','active')");
            $st->execute(array($id));
            v4k_audit('skill_deprecate', 'skill', $id, array('reason' => $reason));
            rw_json(array('ok' => true, 'message' => 'Đã ngừng skill "' . (string)$row['name'] . '" — worker không tự chạy skill này nữa.'));
            break;
        }

        /* ==================== EXPORT (goi co chu ky) ==================== */
        case 'export': {
            $one = v4k_param($p, 'id', null);
            $where = '';
            $args = array();
            if ($one !== null && $one !== '') {
                $where = ' WHERE s.id = ?';
                $args[] = v4k_id1($p);
            }
            $st = $db->prepare('SELECT * FROM skills s' . $where . ' ORDER BY s.id ASC');
            $st->execute($args);
            $skills = array();
            foreach ($st->fetchAll() as $r) {
                $st2 = $db->prepare('SELECT semver, rules_json, guide_text, changelog, reason, status, created_at, verified_at, published_at FROM skill_versions WHERE skill_id = ? ORDER BY id ASC');
                $st2->execute(array($r['id']));
                $vers = array();
                foreach ($st2->fetchAll() as $v) { $vers[] = $v; }
                $skills[] = array(
                    'skill_key' => (string)$r['skill_key'],
                    'name' => (string)$r['name'],
                    'category' => (string)$r['category'],
                    'fingerprint' => (string)$r['fingerprint'],
                    'difficulty' => (int)$r['difficulty'],
                    'status' => (string)$r['status'],
                    'current_version' => (string)$r['current_version'],
                    'rules_json' => (string)$r['rules_json'],
                    'guide_text' => (string)$r['guide_text'],
                    'versions' => $vers
                );
            }
            /* khong xuat bi mat ke ca du lieu cu */
            foreach ($skills as $i => $s) {
                foreach (array('rules_json', 'guide_text') as $f) {
                    $hits = v4k_scan_secrets($s[$f]);
                    foreach ($hits as $h) {
                        $skills[$i][$f] = preg_replace('/\S+/', '***', $s[$f]);
                        break 2;
                    }
                }
            }
            $bundle = array(
                'format' => 'v4k_bundle_v1',
                'exported_at' => v4k_now(),
                'exported_by' => v4k_user(),
                'skills' => $skills
            );
            $bundle['signature'] = v4k_sign($db, $bundle);
            header('Content-Disposition: attachment; filename="skills_bundle_' . date('Ymd_His') . '.json"');
            rw_json(array('ok' => true, 'bundle' => $bundle, 'total' => count($skills), 'generated_at' => rw_db_now()));
            break;
        }

        /* ==================== IMPORT ==================== */
        case 'import': {
            v4k_require_post();
            $raw = v4k_param($p, 'bundle', null);
            if ($raw === null || $raw === '') {
                v4k_fail(400, 'bundle_required', 'Thiếu gói dữ liệu (bundle) để nhập.');
            }
            $bundle = is_array($raw) ? $raw : json_decode((string)$raw, true);
            if (!is_array($bundle) || !isset($bundle['skills']) || !is_array($bundle['skills'])) {
                v4k_fail(400, 'invalid_bundle', 'Gói nhập không hợp lệ (cần format=v4k_bundle_v1 và mảng skills).');
            }
            if (isset($bundle['format']) && (string)$bundle['format'] !== 'v4k_bundle_v1') {
                v4k_fail(400, 'invalid_bundle', 'Phiên bản gói không được hỗ trợ: ' . v4k_text($bundle['format'], 40));
            }
            $signed_ok = false;
            if (isset($bundle['signature']) && (string)$bundle['signature'] !== '') {
                $payload = $bundle;
                unset($payload['signature']);
                $signed_ok = hash_equals((string)v4k_sign($db, $payload), (string)$bundle['signature']);
            }
            if (!$signed_ok) {
                v4k_require_admin('nhập gói skill không có chữ ký hợp lệ');
                if (!v4k_bool(v4k_param($p, 'allow_unsigned', null))) {
                    v4k_fail(409, 'signature_mismatch',
                        'Gói không có chữ ký hợp lệ. Chỉ admin được nhập và phải gửi allow_unsigned=true.');
                }
            }
            $mode = (string)v4k_param($p, 'mode', 'merge');
            if ($mode !== 'merge' && $mode !== 'replace') {
                v4k_fail(400, 'invalid_mode', 'mode phải là merge hoặc replace.');
            }
            if ($mode === 'replace') { v4k_require_confirm($p); }
            $added = array();
            $updated = array();
            $unchanged = array();
            $errors = array();
            foreach ($bundle['skills'] as $s) {
                if (!is_array($s)) { $errors[] = array('skill_key' => '?', 'reason' => 'Mục không phải object.');; continue; }
                $key = isset($s['skill_key']) ? v4k_text($s['skill_key'], 64) : '';
                if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_\-]{1,63}$/', $key)) {
                    $errors[] = array('skill_key' => v4k_text($key, 40), 'reason' => 'skill_key không hợp lệ.');
                    continue;
                }
                $name = isset($s['name']) ? v4k_text($s['name'], 200) : '';
                if ($name === '') { $errors[] = array('skill_key' => $key, 'reason' => 'Thiếu tên skill.');; continue; }
                $cat = isset($s['category']) ? (string)$s['category'] : 'other';
                if (!isset(v4k_categories()[$cat])) { $cat = 'other'; }
                $diff = isset($s['difficulty']) ? max(0, min(100, (int)$s['difficulty'])) : 0;
                $fp = isset($s['fingerprint']) ? v4k_text($s['fingerprint'], 500) : '';
                $guide = isset($s['guide_text']) ? v4k_multiline($s['guide_text'], RW_V4K_MAX_GUIDE) : '';
                $errors2 = array(); $warn2 = array();
                $rules = null;
                if (isset($s['rules_json']) && $s['rules_json'] !== '' && $s['rules_json'] !== null) {
                    $rules = v4k_rules_parse($s['rules_json'], $errors2, $warn2, true);
                    if ($rules === null) {
                        $errors[] = array('skill_key' => $key, 'reason' => 'rules_json không hợp lệ: ' . implode(' ', array_slice($errors2, 0, 3)));
                        continue;
                    }
                } else {
                    $rules = array('steps' => array());
                }
                $hits = array_merge(v4k_scan_secrets(json_encode($rules, JSON_UNESCAPED_UNICODE)), v4k_scan_secrets($guide));
                if (count($hits)) {
                    $errors[] = array('skill_key' => $key, 'reason' => 'Nội dung chứa bí mật bị cấm (mục 6.6.2): ' . $hits[0]['rule'] . ' = ' . $hits[0]['masked']);
                    continue;
                }
                $rj = json_encode($rules, JSON_UNESCAPED_UNICODE);
                $st = $db->prepare('SELECT * FROM skills WHERE skill_key = ?');
                $st->execute(array($key));
                $ex = $st->fetch();
                if (!$ex) {
                    $im_ver = isset($s['current_version']) ? v4k_text($s['current_version'], 20) : '0.1.0';
                    if (!v4k_semver_ok($im_ver)) { $im_ver = '0.1.0'; }
                    $st = $db->prepare('INSERT INTO skills(skill_key, name, category, fingerprint, status, difficulty, current_version, rules_json, guide_text, verify_fail, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                    $st->execute(array($key, $name, $cat, $fp, 'draft', $diff, $im_ver, $rj, $guide, 0, v4k_now(), v4k_now()));
                    $sid = (int)$db->lastInsertId();
                    $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                    $st->execute(array($sid, $im_ver, $rj, $guide, 'Nhập từ gói export', 'import', v4k_user(), 'draft', v4k_now()));
                    $added[] = $key;
                } else {
                    $same_rules = (json_decode((string)$ex['rules_json'], true) == $rules);
                    $same_guide = ((string)$ex['guide_text'] === $guide);
                    if ($same_rules && $same_guide) {
                        $unchanged[] = $key;
                        continue;
                    }
                    $cur = (string)$ex['current_version'];
                    $next = v4k_semver_bump($cur);
                    for ($i = 0; $i < 50; $i++) {
                        $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_id = ? AND semver = ?');
                        $st->execute(array($ex['id'], $next));
                        if (!$st->fetch()) { break; }
                        $next = v4k_semver_bump($next);
                    }
                    $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                    $st->execute(array((int)$ex['id'], $next, $rj, $guide, 'Nhập từ gói export', 'import', v4k_user(), 'draft', v4k_now()));
                    $st = $db->prepare('UPDATE skills SET name=?, category=?, fingerprint=?, difficulty=?, rules_json=?, guide_text=?, updated_at=? WHERE id=?');
                    $st->execute(array($name, $cat, $fp, $diff, $rj, $guide, v4k_now(), (int)$ex['id']));
                    $updated[] = $key;
                }
            }
            if ($mode === 'replace') {
                $keep = array();
                foreach ($bundle['skills'] as $s) {
                    if (is_array($s) && isset($s['skill_key'])) { $keep[v4k_text($s['skill_key'], 64)] = true; }
                }
                $st = $db->prepare("SELECT id, skill_key FROM skills WHERE status <> 'deprecated'");
                $st->execute();
                foreach ($st->fetchAll() as $r) {
                    if (!isset($keep[(string)$r['skill_key']])) {
                        $st2 = $db->prepare("UPDATE skills SET status='deprecated', updated_at=? WHERE id=?");
                        $st2->execute(array(v4k_now(), $r['id']));
                        v4k_audit('skill_deprecate', 'skill', $r['id'], array('reason' => 'import replace: không có trong gói'));
                    }
                }
            }
            v4k_audit('skill_import', 'skill', '-', array('added' => count($added), 'updated' => count($updated),
                'errors' => count($errors), 'signed' => $signed_ok, 'mode' => $mode));
            rw_json(array(
                'ok' => true,
                'signature_valid' => $signed_ok,
                'added' => $added,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'errors' => $errors,
                'message' => 'Thêm ' . count($added) . ' · Cập nhật ' . count($updated) . ' · Không đổi ' . count($unchanged) . ' · Lỗi ' . count($errors),
                'generated_at' => rw_db_now()
            ));
            break;
        }

        /* ==================== QUEUE_UNMATCHED (hang doi nhiem vu moi) ==================== */
        case 'queue_unmatched': {
            $op = strtolower((string)v4k_param($p, 'op', ''));
            if ($op === '' && $m === 'GET') {
                /* danh sach hang doi */
                $where = array('1=1');
                $args = array();
                $filters = array();
                $stq = (string)v4k_param($p, 'status', '');
                if ($stq !== '') {
                    if (!isset(v4k_queue_statuses()[$stq])) {
                        v4k_fail(422, 'invalid_status', 'Trạng thái hàng đợi không hợp lệ.');
                    }
                    $where[] = 'status = ?';
                    $args[] = $stq;
                    $filters['status'] = $stq;
                }
                $cat = (string)v4k_param($p, 'category', '');
                if ($cat !== '') {
                    $where[] = 'category = ?';
                    $args[] = $cat;
                    $filters['category'] = $cat;
                }
                $q = trim((string)v4k_param($p, 'q', ''));
                if ($q !== '') {
                    $where[] = '(title LIKE ? OR fingerprint LIKE ? OR sample_url LIKE ?)';
                    $like = '%' . $q . '%';
                    array_push($args, $like, $like, $like);
                    $filters['q'] = v4k_cut($q, 60);
                }
                $dmin = v4k_int_opt($p, 'difficulty_min', 0, 0, 100);
                $dmax = v4k_int_opt($p, 'difficulty_max', 100, 0, 100);
                if ($dmin > 0) { $where[] = 'difficulty >= ?'; $args[] = $dmin; $filters['difficulty_min'] = $dmin; }
                if ($dmax < 100) { $where[] = 'difficulty <= ?'; $args[] = $dmax; $filters['difficulty_max'] = $dmax; }
                $sort_map = array('id' => 'id', 'last_seen' => 'last_seen', 'first_seen' => 'first_seen',
                    'occurrences' => 'occurrences', 'difficulty' => 'difficulty');
                $sort_by = (string)v4k_param($p, 'sort', 'last_seen');
                if (!isset($sort_map[$sort_by])) { $sort_by = 'last_seen'; }
                $sort_dir = (strtolower((string)v4k_param($p, 'dir', 'desc')) === 'asc') ? 'ASC' : 'DESC';
                $page = max(1, (int)v4k_param($p, 'page', 1));
                $per_page = (int)v4k_param($p, 'per_page', 50);
                if ($per_page < 1) { $per_page = 50; }
                if ($per_page > 200) { $per_page = 200; }
                $sqlw = ' WHERE ' . implode(' AND ', $where);
                $st = $db->prepare('SELECT COUNT(*) c FROM skill_queue' . $sqlw);
                $st->execute($args);
                $total = (int)$st->fetch()['c'];
                $st = $db->prepare('SELECT * FROM skill_queue' . $sqlw
                    . ' ORDER BY ' . $sort_map[$sort_by] . ' ' . $sort_dir . ', id ' . $sort_dir
                    . ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)(($page - 1) * $per_page));
                $st->execute($args);
                $rows = array();
                foreach ($st->fetchAll() as $r) { $rows[] = v4k_map_queue($r); }
                $st = $db->prepare('SELECT status, COUNT(*) c FROM skill_queue GROUP BY status');
                $st->execute();
                $by_status = array();
                foreach ($st->fetchAll() as $r) { $by_status[(string)$r['status']] = (int)$r['c']; }
                rw_json(array(
                    'ok' => true,
                    'data' => $rows,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $per_page,
                    'pages' => (int)ceil($total / max(1, $per_page)),
                    'summary' => array('by_status' => $by_status),
                    'filters_applied' => $filters,
                    'sort' => array('by' => $sort_by, 'dir' => $sort_dir),
                    'generated_at' => rw_db_now()
                ));
                break;
            }
            v4k_require_post();
            if ($op === 'skip') {
                $ids = v4k_ids($p);
                $reason = v4k_text(v4k_param($p, 'reason', 'Bỏ qua theo yêu cầu'), 300);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("UPDATE skill_queue SET status='skipped' WHERE id IN ($ph) AND status IN ('pending','pending_ai')");
                $st->execute($ids);
                $n = $st->rowCount();
                foreach ($ids as $qid) { v4k_audit('skill_queue_skip', 'skill_queue', $qid, array('reason' => $reason)); }
                rw_json(array('ok' => true, 'updated' => $n, 'message' => 'Đã bỏ qua ' . $n . ' nhiệm vụ trong hàng đợi.'));
                break;
            }
            if ($op === 'guide') {
                $qid = v4k_id1($p, 'queue_id');
                $st = $db->prepare('SELECT * FROM skill_queue WHERE id = ?');
                $st->execute(array($qid));
                $item = $st->fetch();
                if (!$item) { v4k_fail(404, 'queue_not_found', 'Không tìm thấy mục hàng đợi id=' . $qid . '.'); }
                $guide = v4k_multiline(v4k_param($p, 'guide_text', ''), RW_V4K_MAX_GUIDE);
                if (trim($guide) === '') {
                    v4k_fail(422, 'guide_empty', 'Thiếu hướng dẫn giải (guide_text).');
                }
                v4k_assert_clean(array('guide_text' => $guide));
                $name = v4k_text(v4k_param($p, 'name', ''), 200);
                if ($name === '') { $name = v4k_text($item['title'], 200); }
                if ($name === '') { $name = 'skill-' . $qid; }
                $cat = (string)v4k_param($p, 'category', (string)$item['category']);
                if (!isset(v4k_categories()[$cat])) { $cat = 'other'; }
                $diff = v4k_int_opt($p, 'difficulty', (int)$item['difficulty'], 0, 100);
                $fp = v4k_text(v4k_param($p, 'fingerprint', (string)$item['fingerprint']), 500);
                $errors = array(); $warnings = array();
                $rules = null;
                $rules_in = v4k_param($p, 'rules_json', null);
                if ($rules_in !== null && $rules_in !== '') {
                    $rules = v4k_rules_parse($rules_in, $errors, $warnings);
                    if ($rules === null) {
                        v4k_fail(422, 'rules_invalid', 'rules_json không hợp lệ.', array('errors' => array_slice($errors, 0, 10)));
                    }
                } else {
                    $rules = array('steps' => array());
                }
                $base = preg_replace('/[^a-z0-9_\-]/', '', strtolower(str_replace(' ', '_', v4k_cut($name, 40))));
                if ($base === '' || strlen($base) < 2) { $base = 'skill-' . $qid; }
                $key = $base;
                $n = 1;
                while (true) {
                    $st = $db->prepare('SELECT id FROM skills WHERE skill_key = ?');
                    $st->execute(array($key));
                    if (!$st->fetch()) { break; }
                    $n++;
                    $key = $base . '-' . $n;
                    if ($n > 50) { break; }
                }
                $now = v4k_now();
                $rj = json_encode($rules, JSON_UNESCAPED_UNICODE);
                $st = $db->prepare('INSERT INTO skills(skill_key, name, category, fingerprint, status, difficulty, current_version, rules_json, guide_text, verify_fail, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute(array($key, $name, $cat, $fp, 'draft', $diff, '0.1.0', $rj, $guide, 0, $now, $now));
                $sid = (int)$db->lastInsertId();
                $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute(array($sid, '0.1.0', $rj, $guide, 'Tạo từ hàng đợi nhiệm vụ #' . $qid, 'viết hướng dẫn từ queue', v4k_user(), 'draft', $now));
                $st = $db->prepare("UPDATE skill_queue SET status='solved' WHERE id = ?");
                $st->execute(array($qid));
                v4k_audit('skill_queue_guide', 'skill_queue', $qid, array('skill_id' => $sid, 'skill_key' => $key));
                rw_json(array(
                    'ok' => true,
                    'skill' => array('id' => $sid, 'skill_key' => $key, 'name' => $name, 'status' => 'draft', 'current_version' => '0.1.0'),
                    'warnings' => $warnings,
                    'message' => 'Đã tạo skill nháp "' . $name . '" từ mục hàng đợi #' . $qid . '.'
                ));
                break;
            }
            if ($op === 'ai') {
                $qid = v4k_id1($p, 'queue_id');
                $st = $db->prepare('SELECT * FROM skill_queue WHERE id = ?');
                $st->execute(array($qid));
                $item = $st->fetch();
                if (!$item) { v4k_fail(404, 'queue_not_found', 'Không tìm thấy mục hàng đợi id=' . $qid . '.'); }
                $budget = v4k_int_opt($p, 'budget_tokens', 4000, 100, 200000);
                $st = $db->prepare("UPDATE skill_queue SET status='pending_ai' WHERE id = ?");
                $st->execute(array($qid));
                $jk = 'v4k-ai-' . $qid . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $st = $db->prepare('INSERT INTO jobs(job_key, action, args_json, status, priority, timeout_s, created_at) VALUES(?,?,?,?,?,?,?)');
                $st->execute(array($jk, 'skill_ai_solve',
                    json_encode(array('queue_id' => $qid, 'budget_tokens' => $budget, 'fingerprint' => (string)$item['fingerprint']), JSON_UNESCAPED_UNICODE),
                    'pending', 5, 1800, v4k_now()));
                v4k_audit('skill_queue_ai', 'skill_queue', $qid, array('budget_tokens' => $budget, 'job_key' => $jk));
                rw_json(array('ok' => true, 'job_key' => $jk, 'budget_tokens' => $budget,
                    'message' => 'Đã giao AI giải nhiệm vụ #' . $qid . ' với ngân sách ' . $budget . ' token.'));
                break;
            }
            if ($op === 'test') {
                $qid = v4k_id1($p, 'queue_id');
                $st = $db->prepare('SELECT * FROM skill_queue WHERE id = ?');
                $st->execute(array($qid));
                $item = $st->fetch();
                if (!$item) { v4k_fail(404, 'queue_not_found', 'Không tìm thấy mục hàng đợi id=' . $qid . '.'); }
                $acc = v4k_param($p, 'account_id', null);
                if ($acc === null || $acc === '') {
                    v4k_fail(400, 'invalid_id', 'Giải thử cần account_id (chạy 1 account thử).');
                }
                $acc = v4k_id1($p, 'account_id');
                $jk = 'v4k-qtest-' . $qid . '-' . $acc . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $st = $db->prepare('INSERT INTO jobs(job_key, account_id, action, args_json, status, priority, timeout_s, created_at) VALUES(?,?,?,?,?,?,?,?)');
                $st->execute(array($jk, $acc, 'skill_queue_test',
                    json_encode(array('queue_id' => $qid, 'account_id' => $acc, 'sample_url' => (string)$item['sample_url']), JSON_UNESCAPED_UNICODE),
                    'pending', 4, 900, v4k_now()));
                v4k_audit('skill_queue_test', 'skill_queue', $qid, array('account_id' => $acc, 'job_key' => $jk));
                rw_json(array('ok' => true, 'job_key' => $jk, 'message' => 'Đã xếp job giải thử 1 account cho mục #' . $qid . '.'));
                break;
            }
            v4k_fail(400, 'unknown_op', 'op không hợp lệ (chấp nhận: skip, guide, ai, test).');
            break;
        }

        /* ==================== TRACE_GET ==================== */
        case 'trace_get': {
            $op = strtolower((string)v4k_param($p, 'op', ''));
            if ($op === 'sample') {
                v4k_require_post();
                $tid = v4k_id1($p, 'trace_id');
                $st = $db->prepare('SELECT * FROM skill_traces WHERE id = ?');
                $st->execute(array($tid));
                $t = $st->fetch();
                if (!$t) { v4k_fail(404, 'trace_not_found', 'Không tìm thấy trace id=' . $tid . '.'); }
                $flag = v4k_bool(v4k_param($p, 'is_sample', true)) ? 1 : 0;
                $st = $db->prepare('UPDATE skill_traces SET is_sample = ? WHERE id = ?');
                $st->execute(array($flag, $tid));
                v4k_audit('skill_trace_sample', 'skill', (int)$t['skill_id'], array('trace_id' => $tid, 'is_sample' => $flag));
                rw_json(array('ok' => true, 'trace_id' => $tid, 'is_sample' => $flag,
                    'message' => $flag ? 'Đã đặt trace làm mẫu chuẩn (dùng cho hồi quy).' : 'Đã bỏ cờ mẫu chuẩn của trace.'));
                break;
            }
            $sid = v4k_param($p, 'skill_id', null);
            if ($sid === null || $sid === '') {
                v4k_fail(400, 'invalid_id', 'Thiếu tham số skill_id.');
            }
            $sid = v4k_id1($p, 'skill_id');
            v4k_row_skill($db, $sid);
            $where = array('skill_id = ?');
            $args = array($sid);
            $filters = array('skill_id' => $sid);
            if (v4k_bool(v4k_param($p, 'sample_only', null))) {
                $where[] = 'is_sample = 1';
                $filters['sample_only'] = true;
            }
            $res = (string)v4k_param($p, 'result', '');
            if ($res !== '') {
                if ($res !== 'pass' && $res !== 'fail') {
                    v4k_fail(422, 'invalid_result', 'result phải là pass hoặc fail.');
                }
                $where[] = 'result = ?';
                $args[] = $res;
                $filters['result'] = $res;
            }
            $page = max(1, (int)v4k_param($p, 'page', 1));
            $per_page = (int)v4k_param($p, 'per_page', 20);
            if ($per_page < 1) { $per_page = 20; }
            if ($per_page > 200) { $per_page = 200; }
            $sqlw = ' WHERE ' . implode(' AND ', $where);
            $st = $db->prepare('SELECT COUNT(*) c FROM skill_traces' . $sqlw);
            $st->execute($args);
            $total = (int)$st->fetch()['c'];
            $st = $db->prepare('SELECT * FROM skill_traces' . $sqlw
                . ' ORDER BY id DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)(($page - 1) * $per_page));
            $st->execute($args);
            $rows = array();
            foreach ($st->fetchAll() as $r) { $rows[] = v4k_map_trace($r); }
            rw_json(array(
                'ok' => true,
                'data' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => (int)ceil($total / max(1, $per_page)),
                'filters_applied' => $filters,
                'generated_at' => rw_db_now()
            ));
            break;
        }

        /* ==================== CLONE (nhan ban skill, muc 6.5) ==================== */
        case 'clone': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $new_key = v4k_text(v4k_param($p, 'new_key', ''), 64);
            if ($new_key === '') {
                $new_key = substr((string)$row['skill_key'], 0, 50) . '-copy';
                $n = 1;
                while (true) {
                    $st = $db->prepare('SELECT id FROM skills WHERE skill_key = ?');
                    $st->execute(array($new_key));
                    if (!$st->fetch()) { break; }
                    $n++;
                    $new_key = substr((string)$row['skill_key'], 0, 45) . '-copy-' . $n;
                    if ($n > 50) { break; }
                }
            } elseif (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,63}$/', $new_key)) {
                v4k_fail(422, 'invalid_skill_key', 'new_key không hợp lệ.');
            }
            $st = $db->prepare('SELECT id FROM skills WHERE skill_key = ?');
            $st->execute(array($new_key));
            if ($st->fetch()) {
                v4k_fail(409, 'skill_key_exists', 'skill_key "' . $new_key . '" đã tồn tại.');
            }
            $name = v4k_text(v4k_param($p, 'name', ''), 200);
            if ($name === '') { $name = v4k_text((string)$row['name'] . ' (bản sao)', 200); }
            $now = v4k_now();
            $st = $db->prepare('INSERT INTO skills(skill_key, name, category, fingerprint, status, difficulty, current_version, rules_json, guide_text, verify_fail, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($new_key, $name, (string)$row['category'], (string)$row['fingerprint'], 'draft',
                (int)$row['difficulty'], '0.1.0', (string)$row['rules_json'], (string)$row['guide_text'], 0, $now, $now));
            $sid = (int)$db->lastInsertId();
            $st = $db->prepare('INSERT INTO skill_versions(skill_id, semver, rules_json, guide_text, changelog, reason, created_by, status, created_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute(array($sid, '0.1.0', (string)$row['rules_json'], (string)$row['guide_text'],
                'Nhân bản từ skill #' . $id, 'clone', v4k_user(), 'draft', $now));
            v4k_audit('skill_clone', 'skill', $sid, array('from' => $id, 'skill_key' => $new_key));
            rw_json(array('ok' => true, 'skill' => array('id' => $sid, 'skill_key' => $new_key, 'name' => $name, 'status' => 'draft'),
                'message' => 'Đã nhân bản skill thành "' . $name . '" (Nháp).'));
            break;
        }

        /* ==================== MERGE (gom skill trung) ==================== */
        case 'merge': {
            v4k_require_post();
            $target = v4k_id1($p, 'target_id');
            $trow = v4k_row_skill($db, $target);
            $sources = v4k_ids($p, 'source_ids');
            if (in_array($target, $sources, true)) {
                v4k_fail(400, 'invalid_id', 'source_ids không được chứa target_id.');
            }
            $reason = v4k_require_reason($p, 'ghép skill trùng');
            foreach ($sources as $sid) {
                $srow = v4k_row_skill($db, $sid);
                /* gop fingerprint vao skill dich */
                $fps = array();
                foreach (explode(',', (string)$trow['fingerprint'] . ',' . (string)$srow['fingerprint']) as $x) {
                    $x = trim($x);
                    if ($x !== '' && !in_array($x, $fps, true)) { $fps[] = v4k_cut($x, 200); }
                    if (count($fps) >= 20) { break; }
                }
                $st = $db->prepare('UPDATE skills SET fingerprint = ?, updated_at = ? WHERE id = ?');
                $st->execute(array(implode(',', $fps), v4k_now(), $target));
                $st = $db->prepare('UPDATE skill_traces SET skill_id = ? WHERE skill_id = ?');
                $st->execute(array($target, $sid));
                $st = $db->prepare('UPDATE skill_tests SET skill_id = ? WHERE skill_id = ?');
                $st->execute(array($target, $sid));
                $st = $db->prepare("UPDATE skills SET status='deprecated', updated_at = ? WHERE id = ?");
                $st->execute(array(v4k_now(), $sid));
                v4k_audit('skill_merge', 'skill', $sid, array('into' => $target, 'reason' => $reason));
            }
            v4k_audit('skill_merge_target', 'skill', $target, array('sources' => implode(',', $sources), 'reason' => $reason));
            $row2 = v4k_row_skill($db, $target);
            rw_json(array('ok' => true, 'skill' => v4k_map_skill($row2, v4k_fail_streak($db, $target)),
                'merged_from' => $sources,
                'message' => 'Đã gộp ' . count($sources) . ' skill vào #' . $target . ' (các bản trùng chuyển sang trạng thái Ngừng).'));
            break;
        }

        /* ==================== FINGERPRINT_ADD ==================== */
        case 'fingerprint_add': {
            v4k_require_post();
            $id = v4k_id1($p);
            $row = v4k_row_skill($db, $id);
            $fp = v4k_text(v4k_param($p, 'fingerprint', ''), 200);
            if ($fp === '') {
                v4k_fail(422, 'invalid_fingerprint', 'Thiếu fingerprint để gắn.');
            }
            $fps = array();
            foreach (explode(',', (string)$row['fingerprint']) as $x) {
                $x = trim($x);
                if ($x !== '') { $fps[] = $x; }
            }
            if (in_array($fp, $fps, true)) {
                rw_json(array('ok' => true, 'fingerprints' => $fps, 'message' => 'Fingerprint đã được gắn từ trước.'));
            }
            if (count($fps) >= 20) {
                v4k_fail(409, 'too_many_fingerprints', 'Một skill tối đa 20 fingerprint.');
            }
            $fps[] = $fp;
            $st = $db->prepare('UPDATE skills SET fingerprint = ?, updated_at = ? WHERE id = ?');
            $st->execute(array(implode(',', $fps), v4k_now(), $id));
            v4k_audit('skill_fingerprint_add', 'skill', $id, array('fingerprint' => $fp));
            rw_json(array('ok' => true, 'fingerprints' => $fps, 'message' => 'Đã gắn thêm fingerprint cho skill.'));
            break;
        }

        default:
            v4k_fail(400, 'unknown_action', 'Thao tác không hợp lệ: "' . v4k_text($action, 30) . '".');
    }
}

if (!defined('RW_V4K_TEST_MODE')) {
    v4k_main();
}
