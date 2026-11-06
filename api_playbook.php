<?php
// api_playbook.php — Muc 4: Playbook CRUD + Distill (deterministic, khong goi AI runtime).
// Duoc include tu api.php SAU khi da dang nhap + CSRF. Tra JSON truc tiep.
// Luu playbook JSON tai RW_SECURE_DIR/playbooks/*.json (NGOAI public_html).
// Tuong thich PHP 7.4.

define('RW_PLAYBOOK_DIR', RW_SECURE_DIR . '/playbooks');

// polyfill mb_* khi host thieu ext-mbstring (chi dung cho ASCII-safe ops)
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}

// bat buoc require db.php (chua rw_db_audit); api.php chi require security.php
require_once __DIR__ . '/db.php';

// audit an toan: loi audit khong duoc pha response API
function rw_pb_audit($action, $detail = array()) {
    if (!function_exists('rw_db_audit')) { return; }
    try { rw_db_audit($action, $detail); } catch (Throwable $e) { /* audit fail silent */ }
}

// ---------- helper ----------
function rw_pb_dir() {
    if (!is_dir(RW_PLAYBOOK_DIR)) { @mkdir(RW_PLAYBOOK_DIR, 0755, true); }
    return RW_PLAYBOOK_DIR;
}
function rw_pb_path($id) {
    // id: chi chu cai so, gach duoi, gach ngang
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$id);
    return rw_pb_dir() . '/' . $id . '.json';
}
function rw_pb_input() {
    $raw = file_get_contents('php://input');
    $p = json_decode($raw, true);
    return is_array($p) ? $p : array();
}
function rw_pb_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
}
function rw_pb_admin_only() {
    if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
}
// tim file playbook theo id: truoc theo filename, sau quet id ben trong JSON
// (vi ten file co the khac id — vd daily_set.json chua id pb_daily_set_default)
function rw_pb_find_file($id) {
    $direct = rw_pb_path($id);
    if (is_file($direct)) { return $direct; }
    foreach (glob(rw_pb_dir() . '/*.json') as $f) {
        $b = basename($f);
        if (strpos($b, '.trash_') === 0 || strpos($b, '.') === 0) { continue; }
        $j = json_decode(file_get_contents($f), true);
        if (is_array($j) && isset($j['id']) && (string)$j['id'] === (string)$id) { return $f; }
    }
    return null;
}
function rw_pb_read($id) {
    $f = rw_pb_find_file($id);
    if (!$f || !is_file($f)) { return null; }
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : null;
}
function rw_pb_list_ids() {
    $out = array();
    foreach (glob(rw_pb_dir() . '/*.json') as $f) {
        $id = basename($f, '.json');
        $out[] = $id;
    }
    sort($out);
    return $out;
}

// ---------- validate schema Playbook ----------
// Schema: version, id, steps[] (type/params/expected/timeout/retry),
//          bien interpolate {account.email}, {task.id} ...
// thu thap string values de kiem tra bien interpolate (bo qua key regex/selector/guide/title)
function rw_pb_collect_strings($v, $key, &$out) {
    if (is_object($v)) { $v = get_object_vars($v); }
    if (is_array($v)) {
        foreach ($v as $k => $item) { rw_pb_collect_strings($item, (string)$k, $out); }
        return;
    }
    if (!is_string($v)) { return; }
    if (preg_match('/regex|pattern|guide|note|title|desc|changelog|example/i', $key)) { return; }
    $out[] = $v;
}
function rw_pb_validate($pb) {
    $errors = array();
    if (!is_array($pb)) { return array('playbook phai la JSON object'); }
    if (empty($pb['id']) || !preg_match('/^[a-zA-Z0-9_\-]+$/', (string)$pb['id'])) {
        $errors[] = 'id bat buoc, chi chu/so/_/-';
    }
    if (!isset($pb['version']) || !is_numeric($pb['version']) || (int)$pb['version'] < 1) {
        $errors[] = 'version bat buoc, so nguyen >= 1';
    }
    if (!isset($pb['steps']) || !is_array($pb['steps']) || !count($pb['steps'])) {
        $errors[] = 'steps bat buoc, mang khac rong';
    } else {
        $allowed_types = array('goto','click','type','type_submit','select','wait','scroll','answer','answer_or_click','loop','verify','press','hover','switch_tab','close_tab');
        foreach ($pb['steps'] as $i => $st) {
            $n = $i + 1;
            if (!is_array($st)) { $errors[] = "steps[$n] phai la object"; continue; }
            $t = isset($st['type']) ? strtolower(trim((string)$st['type'])) : '';
            if ($t === '') { $errors[] = "steps[$n].type bat buoc"; }
            else if (!in_array($t, $allowed_types, true)) { $errors[] = "steps[$n].type '$t' khong hop le (cho phep: " . implode(', ', $allowed_types) . ')'; }
            if (isset($st['params']) && !is_array($st['params']) && !is_object($st['params'])) { $errors[] = "steps[$n].params phai la object"; }
            if (isset($st['expected']) && !is_array($st['expected']) && !is_object($st['expected'])) { $errors[] = "steps[$n].expected phai la object"; }
            if (isset($st['timeout']) && (!is_numeric($st['timeout']) || (int)$st['timeout'] <= 0)) { $errors[] = "steps[$n].timeout phai la so > 0 (ms)"; }
            if (isset($st['retry']) && (!is_numeric($st['retry']) || (int)$st['retry'] < 0)) { $errors[] = "steps[$n].retry phai la so >= 0"; }
        }
    }
    // kiem tra bien interpolate: {a.b} hop le — chi quet string values,
    // bo qua key regex/selector/guide/title de khong bat loi regex quantifier {2,3}
    $strs = array();
    rw_pb_collect_strings($pb, '', $strs);
    foreach ($strs as $s) {
        if (preg_match_all('/\{([^{}]*)\}/', $s, $mm)) {
            foreach ($mm[1] as $var) {
                if ($var === '') { continue; } // bo qua literal {}
                if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $var)) {
                    $errors[] = "bien interpolate '{$var}' khong hop le (dang dung: {account.email}, {task.id})";
                }
            }
        }
    }
    return $errors;
}

// ---------- DISTILL (deterministic, khong goi AI) ----------
// Dau vao: log tac vu tho (mang buoc tu lich su runs/task_events).
// Ra: playbook JSON goi y — phat hien pattern: lap URL, so diem, thu tu.
function rw_pb_distill($payload) {
    $task = isset($payload['task']) && is_array($payload['task']) ? $payload['task'] : $payload;
    $steps_raw = isset($payload['steps']) && is_array($payload['steps']) ? $payload['steps'] : array();
    if (!count($steps_raw) && isset($task['steps']) && is_array($task['steps'])) {
        $steps_raw = $task['steps'];
    }
    if (!count($steps_raw)) {
        return array('error' => 'steps_required', 'message' => 'Can mang buoc log tac vu tho (steps) de distill');
    }

    $title = isset($task['title']) ? trim((string)$task['title']) : (isset($task['task_key']) ? trim((string)$task['task_key']) : 'task');
    $task_type = isset($task['type']) ? preg_replace('/[^a-z_]/', '', strtolower((string)$task['type'])) : '';
    $url_hint = isset($task['url']) ? (string)$task['url'] : '';
    $points = isset($task['points']) ? (int)$task['points'] : (isset($task['points_expected']) ? (int)$task['points_expected'] : 0);

    // --- 1) chuan hoa buoc tho -> buoc schema ---
    $clean = array();
    foreach ($steps_raw as $st) {
        if (!is_array($st)) { continue; }
        $act = isset($st['type']) ? $st['type'] : (isset($st['act']) ? $st['act'] : (isset($st['action']) ? $st['action'] : ''));
        $act = strtolower(trim((string)$act));
        if ($act === '') { continue; }
        // map act -> type hop le
        $map = array('navigate' => 'goto', 'open' => 'goto', 'input' => 'type', 'submit' => 'type_submit',
                     'sleep' => 'wait', 'pause' => 'wait', 'check' => 'verify', 'assert' => 'verify');
        if (isset($map[$act])) { $act = $map[$act]; }
        $params = array();
        if (isset($st['params']) && is_array($st['params'])) { $params = $st['params']; }
        else {
            foreach (array('url','sel','selector','text','value','ms','for','check','note','press_enter') as $k) {
                if (isset($st[$k])) { $params[$k === 'selector' ? 'sel' : $k] = $st[$k]; }
            }
        }
        if (isset($params['selector'])) { $params['sel'] = $params['selector']; unset($params['selector']); }
        $expected = (isset($st['expected']) && is_array($st['expected'])) ? $st['expected'] : new stdClass();
        $timeout = isset($st['timeout']) ? (int)$st['timeout'] : 15000;
        $retry = isset($st['retry']) ? (int)$st['retry'] : 1;
        $clean[] = array('type' => $act, 'params' => $params, 'expected' => $expected,
                         'timeout' => $timeout, 'retry' => $retry);
    }
    if (!count($clean)) {
        return array('error' => 'no_valid_steps', 'message' => 'Khong co buoc nao hop le sau chuan hoa');
    }

    // --- 2) phat hien pattern: lap URL ---
    $warnings = array();
    $url_counts = array();
    foreach ($clean as $st) {
        if (isset($st['params']['url'])) {
            $u = preg_replace('/\?.*$/', '', (string)$st['params']['url']);
            $url_counts[$u] = isset($url_counts[$u]) ? $url_counts[$u] + 1 : 1;
        }
    }
    arsort($url_counts);
    $loop_url = '';
    foreach ($url_counts as $u => $c) {
        if ($c >= 2) { $loop_url = $u; break; }
    }
    if ($loop_url !== '') {
        $warnings[] = "Phat hien URL lap {$url_counts[$loop_url]} lan: {$loop_url} — co the dong goi thanh loop";
    }

    // --- 3) phat hien pattern: so diem ---
    $points_notes = array();
    foreach ($steps_raw as $st) {
        if (is_array($st)) {
            foreach (array('points','points_expected','points_earned') as $k) {
                if (isset($st[$k]) && is_numeric($st[$k])) { $points_notes[] = (int)$st[$k]; }
            }
        }
    }
    if ($points <= 0 && count($points_notes)) { $points = max($points_notes); }
    if ($points > 0) {
        $warnings[] = "So diem phat hien duoc: {$points} — gan vao match.points + verify.points_delta";
    }

    // --- 4) phat hien pattern: thu tu (giu nguyen thu tu buoc) ---
    $warnings[] = 'Thu tu buoc duoc giu nguyen theo log (' . count($clean) . ' buoc)';

    // --- 5) tao playbook goi y ---
    $slug = function ($s) { return preg_replace('/[^a-zA-Z0-9_\-]+/', '_', substr((string)$s, 0, 40)); };
    $pid = 'pb_distill_' . $slug($task_type !== '' ? $task_type : $title);
    $title_words = preg_split('/\s+/', mb_strtolower($title));
    $keep = array();
    foreach ($title_words as $w) {
        $w = preg_replace('/[^a-z0-9àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/u', '', $w);
        if (mb_strlen($w) > 2) { $keep[] = preg_quote($w, '/'); }
        if (count($keep) >= 6) { break; }
    }
    $title_regex = count($keep) ? '\\b(?:' . implode('|', $keep) . ')\\b' : '.*';
    $url_pattern = $loop_url !== '' ? $loop_url : ($url_hint !== '' ? preg_replace('/\?.*$/', '', $url_hint) : '*');
    if ($task_type === '') { $task_type = 'unknown'; }

    $pb = array(
        'id' => $pid,
        'version' => 1,
        'task_type' => $task_type,
        'title' => $title,
        'description' => 'Playbook sinh boi distill (deterministic) tu log tac vu tho.',
        'match' => array(
            'type' => $task_type,
            'title_regex' => $title_regex,
            'url_pattern' => $url_pattern,
            'points' => $points,
        ),
        'preconditions' => array('logged_in', 'region: any'),
        'steps' => array(),
        'variables' => array(
            'account.email' => 'lay tu context account hien tai',
            'task.id' => 'id cua task_event dang xu ly',
            'task_url' => 'URL offer (dong theo ngay)',
        ),
        'verify' => $points > 0
            ? array('any_of' => array('task_state: completed', 'points_delta: >0'))
            : array('any_of' => array('task_state: completed')),
        'guide_md' => 'Playbook sinh tu distill — kiem tra lai truoc khi promote thanh verified.',
        'stats' => array('success' => 0, 'fail' => 0, 'last_verified' => null, 'confidence' => 0.0),
        'status' => 'draft',
        'created_by' => 'distill',
        'created_at' => date('Y-m-d H:i:s'),
    );
    // gan id + schema step
    foreach ($clean as $i => $st) {
        $pb['steps'][] = array(
            'id' => $i + 1,
            'type' => $st['type'],
            'params' => $st['params'],
            'expected' => $st['expected'],
            'timeout' => $st['timeout'],
            'retry' => $st['retry'],
        );
    }
    return array('ok' => true, 'playbook' => $pb, 'warnings' => $warnings,
                 'patterns' => array('loop_url' => $loop_url, 'points' => $points,
                                     'step_count' => count($clean), 'task_type' => $task_type));
}

// ===================== DISPATCH =====================
switch ($action) {
    /* ---------- LIST ---------- */
    case 'playbook_list':
        $rows = array();
        foreach (rw_pb_list_ids() as $id) {
            $pb = rw_pb_read($id);
            if (!$pb) { continue; }
            $rows[] = array(
                'id' => isset($pb['id']) ? $pb['id'] : $id,
                'version' => isset($pb['version']) ? (int)$pb['version'] : 1,
                'task_type' => isset($pb['task_type']) ? $pb['task_type'] : (isset($pb['match']['type']) ? $pb['match']['type'] : ''),
                'title' => isset($pb['title']) ? $pb['title'] : '',
                'status' => isset($pb['status']) ? $pb['status'] : 'draft',
                'step_count' => isset($pb['steps']) && is_array($pb['steps']) ? count($pb['steps']) : 0,
                'points' => isset($pb['match']['points']) ? $pb['match']['points'] : null,
                'stats' => isset($pb['stats']) ? $pb['stats'] : null,
                'created_by' => isset($pb['created_by']) ? $pb['created_by'] : '',
                'created_at' => isset($pb['created_at']) ? $pb['created_at'] : null,
            );
        }
        rw_json(array('total' => count($rows), 'playbooks' => $rows));
        break;

    /* ---------- GET ---------- */
    case 'playbook_get':
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        $pb = rw_pb_read($id);
        if (!$pb) { rw_json(array('error' => 'not_found', 'id' => $id), 404); }
        rw_json(array('ok' => true, 'playbook' => $pb));
        break;

    /* ---------- SAVE (validate schema) ---------- */
    case 'playbook_save':
        rw_pb_post_only();
        rw_pb_admin_only();
        $p = rw_pb_input();
        $pb = isset($p['playbook']) && is_array($p['playbook']) ? $p['playbook'] : $p;
        $errors = rw_pb_validate($pb);
        if (count($errors)) {
            rw_json(array('error' => 'schema_invalid', 'details' => $errors), 422);
        }
        $id = (string)$pb['id'];
        $f = rw_pb_find_file($id);
        if (!$f) { $f = rw_pb_path($id); }
        $json = json_encode($pb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) { rw_json(array('error' => 'json_encode_failed'), 400); }
        $existed = is_file($f);
        if (file_put_contents($f, $json, LOCK_EX) === false) {
            rw_json(array('error' => 'write_failed', 'path' => $f), 500);
        }
        @chmod($f, 0644);
        rw_pb_audit('playbook_save', array('id' => $id, 'version' => isset($pb['version']) ? $pb['version'] : 1, 'existed' => $existed));
        rw_json(array('ok' => true, 'id' => $id, 'created' => !$existed, 'path' => $f));
        break;

    /* ---------- DELETE ---------- */
    case 'playbook_delete':
        rw_pb_post_only();
        rw_pb_admin_only();
        $p = rw_pb_input();
        $id = isset($p['id']) ? (string)$p['id'] : '';
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
            rw_json(array('error' => 'id_required'), 422);
        }
        $f = rw_pb_find_file($id);
        if (!$f || !is_file($f)) { rw_json(array('error' => 'not_found', 'id' => $id), 404); }
        // backup truoc khi xoa (Muc 17.8)
        $bak = rw_pb_dir() . '/.trash_' . $id . '_' . date('YmdHis') . '.json';
        @rename($f, $bak);
        rw_pb_audit('playbook_delete', array('id' => $id, 'backup' => basename($bak)));
        rw_json(array('ok' => true, 'id' => $id, 'backup' => basename($bak)));
        break;

    /* ---------- DISTILL (deterministic) ---------- */
    case 'playbook_distill':
        rw_pb_post_only();
        rw_pb_admin_only();
        $p = rw_pb_input();
        $out = rw_pb_distill($p);
        if (isset($out['error'])) {
            $code = $out['error'] === 'steps_required' ? 422 : 422;
            rw_json($out, $code);
        }
        // luu neu co save=true (status draft)
        if (!empty($p['save'])) {
            $pb = $out['playbook'];
            $errors = rw_pb_validate($pb);
            if (count($errors)) { rw_json(array('error' => 'schema_invalid', 'details' => $errors, 'playbook' => $pb), 422); }
            $f = rw_pb_find_file($pb['id']);
            if (!$f) { $f = rw_pb_path($pb['id']); }
            $json = json_encode($pb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            file_put_contents($f, $json, LOCK_EX);
            @chmod($f, 0644);
            $out['saved'] = true;
            $out['path'] = $f;
            rw_pb_audit('playbook_distill_save', array('id' => $pb['id']));
        }
        rw_json($out);
        break;

    default:
        rw_json(array('error' => 'unknown_playbook_action', 'available' => array(
            'playbook_list', 'playbook_get', 'playbook_save', 'playbook_delete', 'playbook_distill')), 400);
}
?>
