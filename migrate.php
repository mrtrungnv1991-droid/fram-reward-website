<?php
// migrate.php — Muc 15: migrate du lieu cu (JSON + users.php) sang SQLite. CLI hoac admin.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    rw_send_security_headers();
    rw_start_session_secure();
    rw_require_login();
    if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
}

$db = rw_db();
$report = array();

// 1. users.php -> bang users
$RW_USERS = null;
if (is_file(RW_SECURE_DIR . '/users.php')) { include RW_SECURE_DIR . '/users.php'; }
$n = 0;
if (is_array($RW_USERS)) {
    $st = $db->prepare("INSERT INTO users(username, hash, role, full, must_change, created_at, updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(username) DO UPDATE SET hash=excluded.hash, role=excluded.role, full=excluded.full, must_change=excluded.must_change, updated_at=excluded.updated_at");
    foreach ($RW_USERS as $u => $d) {
        $st->execute(array($u,
            isset($d['hash']) ? $d['hash'] : '',
            isset($d['role']) ? $d['role'] : 'manager',
            isset($d['full']) ? $d['full'] : $u,
            isset($d['must_change']) ? (int)$d['must_change'] : 0,
            rw_db_now(), rw_db_now()));
        $n++;
    }
}
$report['users'] = $n;

// 2. accounts.json -> bang accounts
$acc_file = RW_DATA_DIR . '/accounts.json';
$n = 0;
if (is_file($acc_file)) {
    $rows = json_decode(file_get_contents($acc_file), true);
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['email'])) {
                if (rw_db_upsert_account($r)) { $n++; }
            }
        }
    }
}
$report['accounts'] = $n;

// 3. devices.json -> bang devices
$dev_file = RW_DATA_DIR . '/devices.json';
$n = 0;
if (is_file($dev_file)) {
    $rows = json_decode(file_get_contents($dev_file), true);
    if (is_array($rows)) {
        $st = $db->prepare("INSERT INTO devices(device_key, name, os, version, status, cap, last_seen, created_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(device_key) DO UPDATE SET name=excluded.name, os=excluded.os, version=excluded.version, status=excluded.status, cap=excluded.cap, last_seen=excluded.last_seen");
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $key = isset($r['device_key']) ? $r['device_key'] : (isset($r['id']) ? $r['id'] : (isset($r['name']) ? $r['name'] : ''));
            if ($key === '') { continue; }
            $st->execute(array($key,
                isset($r['name']) ? $r['name'] : $key,
                isset($r['os']) ? $r['os'] : '',
                isset($r['version']) ? $r['version'] : '',
                isset($r['status']) ? $r['status'] : 'offline',
                isset($r['cap']) ? (int)$r['cap'] : 2,
                isset($r['last_seen']) ? $r['last_seen'] : null,
                rw_db_now()));
            $n++;
        }
    }
}
$report['devices'] = $n;

// 4. commands.json -> bang commands
$cmd_file = RW_DATA_DIR . '/commands.json';
$n = 0;
if (is_file($cmd_file)) {
    $rows = json_decode(file_get_contents($cmd_file), true);
    if (is_array($rows)) {
        $st = $db->prepare("INSERT INTO commands(cmd_key, device_key, action, args_json, status, created_by, created_at, started_at, finished_at, result_json) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(cmd_key) DO NOTHING");
        foreach ($rows as $key => $r) {
            if (!is_array($r) || !isset($r['action']) || !isset($r['status'])) { continue; }
            $st->execute(array(
                isset($r['id']) ? $r['id'] : $key,
                isset($r['device']) ? $r['device'] : '',
                $r['action'],
                json_encode(isset($r['args']) ? $r['args'] : array()),
                $r['status'],
                isset($r['created_by']) ? $r['created_by'] : '',
                isset($r['created_at']) ? $r['created_at'] : null,
                isset($r['started_at']) ? $r['started_at'] : null,
                isset($r['finished_at']) ? $r['finished_at'] : null,
                isset($r['result']) ? json_encode($r['result']) : null));
            $n++;
        }
    }
}
$report['commands'] = $n;

// 5. results.json -> runs + points_ledger
$res_file = RW_DATA_DIR . '/results.json';
$n = 0;
if (is_file($res_file)) {
    $rows = json_decode(file_get_contents($res_file), true);
    if (is_array($rows)) {
        $stR = $db->prepare("INSERT OR IGNORE INTO runs(run_key, date, status, points_before, points_after, delta, started_at, finished_at) VALUES(?,?,?,?,?,?,?,?)");
        $stL = $db->prepare("INSERT INTO points_ledger(account_id, run_id, ts, points_before, points_after, delta, source, confidence) VALUES((SELECT id FROM accounts WHERE email = ?), NULL, ?, ?, ?, ?, 'imported', 0.5)");
        foreach ($rows as $i => $r) {
            if (!is_array($r)) { continue; }
            $email = str_replace('*', '', isset($r['account']) ? (string)$r['account'] : '');
            $date = isset($r['date']) ? $r['date'] : '';
            $ps = isset($r['points_start']) && $r['points_start'] !== null ? (int)$r['points_start'] : null;
            $pe = isset($r['points_end']) && $r['points_end'] !== null ? (int)$r['points_end'] : null;
            $tot = isset($r['total']) && $r['total'] !== null ? (int)$r['total'] : null;
            $run_key = 'imported-' . $date . '-' . $i;
            $stR->execute(array($run_key, $date, 'ok', $ps, $pe, $tot, $date, $date));
            $stL->execute(array($email, $date . ' 00:00:00', $ps, $pe, $tot));
            $n++;
        }
    }
}
$report['results_imported'] = $n;

// 6. settings mac dinh
$defaults = array(
    'require_proxy' => '1',
    'max_parallel_browsers' => '2',
    'tabs_per_profile' => '1',
    'active_hours' => '06:00-23:30',
    'max_retries_day' => '3',
    'reset_time' => '00:00',
    'stagger_start_sec' => '5-20',
    'auto_cap' => '1',
    'max_accounts_per_phone' => '1',
    'max_profiles_per_proxy' => '1'
);
foreach ($defaults as $k => $v) {
    if (rw_db_setting($k, null) === null) { rw_db_set_setting($k, $v); }
}
$report['settings'] = count($defaults);

// 7. Bao cao
$report['db_path'] = RW_DB_PATH;
$report['db_bytes'] = is_file(RW_DB_PATH) ? filesize(RW_DB_PATH) : 0;
$tables = array('users', 'devices', 'enrollments', 'accounts', 'proxies', 'phones', 'assignments', 'jobs', 'commands', 'runs', 'task_events', 'points_ledger', 'audit_log', 'config_revisions');
foreach ($tables as $t) {
    $r = $db->query("SELECT COUNT(*) c FROM $t")->fetch();
    $report['count_' . $t] = (int)$r['c'];
}

if ($is_cli) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    rw_json(array('ok' => true, 'report' => $report));
}
?>
