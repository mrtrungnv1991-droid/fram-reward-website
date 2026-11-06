<?php
// db.php — Muc 15: SQLite (PDO) backend. DB nam NGOAI public_html (RW_SECURE_DIR/rewards.db).
require_once __DIR__ . '/config.php';

define('RW_DB_PATH', RW_SECURE_DIR . '/rewards.db');

function rw_db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(RW_SECURE_DIR)) { @mkdir(RW_SECURE_DIR, 0700, true); }
        $pdo = new PDO('sqlite:' . RW_DB_PATH, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        rw_db_schema($pdo);
    }
    return $pdo;
}

function rw_db_schema($pdo) {
    $sql = array();
    $sql[] = "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'manager', full TEXT NOT NULL DEFAULT '', must_change INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS devices (id INTEGER PRIMARY KEY AUTOINCREMENT, device_key TEXT UNIQUE NOT NULL, name TEXT NOT NULL, token_hash TEXT NOT NULL DEFAULT '', public_key TEXT NOT NULL DEFAULT '', os TEXT NOT NULL DEFAULT '', arch TEXT NOT NULL DEFAULT '', version TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'offline', cap INTEGER NOT NULL DEFAULT 2, last_seen TEXT, revoked INTEGER NOT NULL DEFAULT 0, created_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS enrollments (id INTEGER PRIMARY KEY AUTOINCREMENT, code_hash TEXT NOT NULL, code_hint TEXT NOT NULL, device_name TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '', expires_at TEXT, used_at TEXT, created_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, password_sealed TEXT NOT NULL DEFAULT '', device_id INTEGER, profile_dir TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'new', next_run_at TEXT, last_run_at TEXT, proxy_id INTEGER, phone_id INTEGER, last_error TEXT, error_class TEXT, flags TEXT NOT NULL DEFAULT '', points_start INTEGER, points_end INTEGER, delta INTEGER, tasks_ok INTEGER NOT NULL DEFAULT 0, tasks_error INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS proxies (id INTEGER PRIMARY KEY AUTOINCREMENT, proxy_key TEXT UNIQUE NOT NULL, scheme TEXT NOT NULL DEFAULT 'http', host TEXT NOT NULL, port INTEGER NOT NULL, username TEXT NOT NULL DEFAULT '', password_sealed TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'unchecked', ip TEXT NOT NULL DEFAULT '', geo TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', latency_ms INTEGER, fail_count INTEGER NOT NULL DEFAULT 0, max_profiles INTEGER NOT NULL DEFAULT 1, last_checked TEXT, created_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS phones (id INTEGER PRIMARY KEY AUTOINCREMENT, number_enc TEXT UNIQUE NOT NULL, number_mask TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', carrier TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'free', attached_profile TEXT, attached_at TEXT, verified_at TEXT, notes TEXT NOT NULL DEFAULT '', created_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, device_id INTEGER, proxy_id INTEGER, phone_id INTEGER, created_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_key TEXT UNIQUE NOT NULL, device_id INTEGER, account_id INTEGER, action TEXT NOT NULL, args_json TEXT NOT NULL DEFAULT '{}', status TEXT NOT NULL DEFAULT 'pending', priority INTEGER NOT NULL DEFAULT 5, timeout_s INTEGER NOT NULL DEFAULT 900, created_at TEXT, started_at TEXT, finished_at TEXT, result_json TEXT, error TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS commands (id INTEGER PRIMARY KEY AUTOINCREMENT, cmd_key TEXT UNIQUE NOT NULL, device_key TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, args_json TEXT NOT NULL DEFAULT '{}', status TEXT NOT NULL DEFAULT 'pending', created_by TEXT NOT NULL DEFAULT '', created_at TEXT, started_at TEXT, finished_at TEXT, result_json TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS runs (id INTEGER PRIMARY KEY AUTOINCREMENT, run_key TEXT UNIQUE NOT NULL, account_id INTEGER, device_id INTEGER, date TEXT, started_at TEXT, finished_at TEXT, status TEXT NOT NULL DEFAULT 'ok', points_before INTEGER, points_after INTEGER, delta INTEGER, error TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS task_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, run_id INTEGER, date TEXT, task_key TEXT NOT NULL, task_type TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'pending', cooldown_until TEXT, points_expected INTEGER, points_earned INTEGER, playbook_id TEXT, solved_by TEXT NOT NULL DEFAULT '', duration_s INTEGER, error TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS points_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, run_id INTEGER, ts TEXT, points_before INTEGER, points_after INTEGER, delta INTEGER, source TEXT NOT NULL DEFAULT 'read', confidence REAL NOT NULL DEFAULT 1.0)";
    $sql[] = "CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, ip TEXT NOT NULL DEFAULT '', user TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '{}')";
    $sql[] = "CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_at TEXT)";
    $sql[] = "CREATE TABLE IF NOT EXISTS config_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, revision INTEGER NOT NULL, payload_json TEXT NOT NULL DEFAULT '{}', created_by TEXT NOT NULL DEFAULT '', created_at TEXT, applied_at TEXT, status TEXT NOT NULL DEFAULT 'pending', error TEXT)";
    foreach ($sql as $q) { $pdo->exec($q); }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_accounts_status ON accounts(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_accounts_next_run ON accounts(next_run_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_runs_date ON runs(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_task_events_account ON task_events(account_id, date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_commands_status ON commands(status, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ledger_account ON points_ledger(account_id, ts)");
}

function rw_db_now() { return date('Y-m-d H:i:s'); }

function rw_db_setting($key, $default = '') {
    $st = rw_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $st->execute(array($key));
    $r = $st->fetch();
    return $r ? $r['value'] : $default;
}
function rw_db_set_setting($key, $value) {
    $st = rw_db()->prepare("INSERT INTO settings(key, value, updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
    $st->execute(array($key, (string)$value, rw_db_now()));
}

function rw_db_audit($action, $detail = array()) {
    $st = rw_db()->prepare("INSERT INTO audit_log(ts, ip, user, action, detail) VALUES(?,?,?,?,?)");
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
    $user = isset($_SESSION['rw_user']) ? $_SESSION['rw_user'] : '-';
    $st->execute(array(rw_db_now(), $ip, $user, $action, json_encode($detail, JSON_UNESCAPED_UNICODE)));
}

function rw_db_upsert_account($row) {
    $db = rw_db();
    $email = isset($row['email']) ? trim($row['email']) : '';
    if ($email === '') { return false; }
    $st = $db->prepare("INSERT INTO accounts(email, status, points_start, points_end, delta, tasks_ok, tasks_error, next_run_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(email) DO UPDATE SET status=excluded.status, points_start=excluded.points_start, points_end=excluded.points_end, delta=excluded.delta, tasks_ok=excluded.tasks_ok, tasks_error=excluded.tasks_error, next_run_at=excluded.next_run_at, updated_at=excluded.updated_at");
    return $st->execute(array(
        $email,
        isset($row['status']) ? $row['status'] : 'new',
        isset($row['points_start']) && $row['points_start'] !== null ? (int)$row['points_start'] : null,
        isset($row['points_end']) && $row['points_end'] !== null ? (int)$row['points_end'] : null,
        isset($row['delta']) && $row['delta'] !== null ? (int)$row['delta'] : null,
        isset($row['tasks_ok']) ? (int)$row['tasks_ok'] : 0,
        isset($row['tasks_error']) ? (int)$row['tasks_error'] : 0,
        isset($row['next_run_at']) ? $row['next_run_at'] : null,
        rw_db_now(), rw_db_now()
    ));
}

function rw_db_export_json($name) {
    $db = rw_db();
    switch ($name) {
        case 'accounts.json':
            $rows = $db->query("SELECT email, status, points_start, points_end, delta, tasks_ok, tasks_error, phone_id, proxy_id, next_run_at FROM accounts ORDER BY email")->fetchAll();
            $out = array();
            foreach ($rows as $r) {
                $out[] = array('email' => $r['email'], 'status' => $r['status'],
                    'points_start' => $r['points_start'] !== null ? (int)$r['points_start'] : null,
                    'points_end' => $r['points_end'] !== null ? (int)$r['points_end'] : null,
                    'delta' => $r['delta'] !== null ? (int)$r['delta'] : null,
                    'tasks_ok' => (int)$r['tasks_ok'], 'tasks_error' => (int)$r['tasks_error'],
                    'phone' => $r['phone_id'] !== null ? 'attached' : null,
                    'proxy' => $r['proxy_id'] !== null ? 'assigned' : null,
                    'next_run_at' => $r['next_run_at']);
            }
            return $out;
        case 'commands.json':
            $rows = $db->query("SELECT * FROM commands ORDER BY created_at DESC")->fetchAll();
            $out = array();
            foreach ($rows as $r) {
                $out[$r['cmd_key']] = array('id' => $r['cmd_key'], 'device' => $r['device_key'], 'action' => $r['action'],
                    'args' => json_decode($r['args_json'], true), 'status' => $r['status'], 'created_by' => $r['created_by'],
                    'created_at' => $r['created_at'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'],
                    'result' => $r['result_json'] !== null ? json_decode($r['result_json'], true) : null);
            }
            return $out;
        case 'devices.json':
            return $db->query("SELECT device_key, name, os, version, status, cap, last_seen FROM devices WHERE revoked = 0")->fetchAll();
        default:
            return array();
    }
}
?>
