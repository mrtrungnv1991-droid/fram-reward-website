<?php
/* ============================================================
 * db_v5.php — Schema dùng chung UI v5 (spec v7.txt, 5 prompt).
 * Moi api_v5_*.php require file nay + goi rw_db_v5_schema() lan dau.
 * KHONG dung PostgreSQL — tiep tuc SQLite nhu v4 (db.php).
 * ============================================================ */
require_once __DIR__ . '/db.php';

/* ===== Bridge: nap body (JSON hoac urlencoded) vao _POST neu PHP khong tu parse ===== */
if (empty($_POST) && ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT')) {
    $rw_raw = file_get_contents('php://input');
    if (is_string($rw_raw) && $rw_raw !== '') {
        $rw_ct = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
        if (stripos($rw_ct, 'application/json') !== false) {
            $rw_j = json_decode($rw_raw, true);
            if (is_array($rw_j)) { foreach ($rw_j as $rw_k => $rw_v) { if (is_scalar($rw_v)) { $_POST[$rw_k] = $rw_v; } } }
        } else {
            parse_str($rw_raw, $rw_ps);
            if (is_array($rw_ps)) { foreach ($rw_ps as $rw_k => $rw_v) { if (is_scalar($rw_v)) { $_POST[$rw_k] = $rw_v; } } }
        }
    }
}

function rw_db_v5_schema() {
    $db = rw_db();
    $sql = array();

    /* ---- Prompt 1: Hang cho AI + ngan sach + duyet ---- */
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_requests (
        id TEXT PRIMARY KEY,
        requester TEXT NOT NULL,
        kind TEXT NOT NULL,
        reason TEXT NOT NULL,
        expected_result TEXT DEFAULT '',
        link_type TEXT DEFAULT '',
        link_id TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        dedupe_key TEXT DEFAULT '',
        budget_est REAL DEFAULT 0,
        budget_held REAL DEFAULT 0,
        budget_actual REAL DEFAULT 0,
        model TEXT DEFAULT '',
        latency_ms INTEGER DEFAULT 0,
        tokens_in INTEGER DEFAULT 0,
        tokens_out INTEGER DEFAULT 0,
        filtered_input TEXT DEFAULT '',
        output_raw TEXT DEFAULT '',
        output_schema_ok INTEGER DEFAULT 0,
        error_class TEXT DEFAULT '',
        tries INTEGER DEFAULT 0,
        next_try_at TEXT,
        reviewer TEXT DEFAULT '',
        review_action TEXT DEFAULT '',
        review_reason TEXT DEFAULT '',
        draft_skill_id TEXT DEFAULT '',
        incident_id TEXT DEFAULT '',
        created_at TEXT,
        updated_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_budget (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL DEFAULT 'global',
        currency TEXT NOT NULL DEFAULT 'USD',
        planned REAL DEFAULT 0,
        held REAL DEFAULT 0,
        actual REAL DEFAULT 0,
        updated_at TEXT
    )";

    /* ---- Prompt 2: Danh muc nhiem vu + skill lifecycle + fixture ---- */
    $sql[] = "CREATE TABLE IF NOT EXISTS task_catalog (
        code TEXT PRIMARY KEY,
        kind TEXT NOT NULL,
        market TEXT DEFAULT '',
        language TEXT DEFAULT '',
        conditions TEXT DEFAULT '',
        evidence TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'unrecognized',
        sensitivity TEXT DEFAULT 'normal',
        skill_code TEXT DEFAULT '',
        source TEXT DEFAULT 'manual',
        evidence_hash TEXT DEFAULT '',
        created_at TEXT,
        updated_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS skill_versions (
        id TEXT PRIMARY KEY,
        skill_code TEXT NOT NULL,
        semver TEXT NOT NULL,
        body TEXT NOT NULL,
        diff TEXT DEFAULT '',
        origin TEXT DEFAULT 'human',
        origin_ref TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        tester TEXT DEFAULT '',
        tested_at TEXT,
        approver TEXT DEFAULT '',
        approved_at TEXT,
        approve_reason TEXT DEFAULT '',
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS skill_fixtures (
        id TEXT PRIMARY KEY,
        skill_code TEXT NOT NULL,
        name TEXT NOT NULL,
        preconditions TEXT DEFAULT '',
        steps TEXT DEFAULT '',
        postconditions TEXT DEFAULT '',
        evidence TEXT DEFAULT '',
        failure_modes TEXT DEFAULT '',
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS fixture_runs (
        id TEXT PRIMARY KEY,
        fixture_id TEXT NOT NULL,
        skill_version_id TEXT NOT NULL,
        result TEXT NOT NULL DEFAULT 'fail',
        report TEXT DEFAULT '',
        runner TEXT DEFAULT 'local',
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS skill_rollouts (
        id TEXT PRIMARY KEY,
        skill_code TEXT NOT NULL,
        semver TEXT NOT NULL,
        ring TEXT NOT NULL DEFAULT 'canary',
        action TEXT NOT NULL,
        reason TEXT DEFAULT '',
        actor TEXT DEFAULT '',
        created_at TEXT
    )";

    /* ---- Prompt 3: VPS & Worker (lenh, tai nguyen, chuyen ho so) ---- */
    $sql[] = "CREATE TABLE IF NOT EXISTS enroll_codes (
        code TEXT PRIMARY KEY,
        created_by TEXT DEFAULT '',
        used_by TEXT DEFAULT '',
        device_id TEXT DEFAULT '',
        expires_at TEXT,
        used_at TEXT,
        status TEXT NOT NULL DEFAULT 'active'
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS device_tokens (
        token_hash TEXT PRIMARY KEY,
        device_id TEXT NOT NULL,
        label TEXT DEFAULT '',
        api_compat TEXT DEFAULT '',
        app_version TEXT DEFAULT '',
        components TEXT DEFAULT '',
        last_sync_at TEXT,
        revoked_at TEXT,
        status TEXT NOT NULL DEFAULT 'active'
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS device_commands (
        id TEXT PRIMARY KEY,
        device_id TEXT NOT NULL,
        cmd TEXT NOT NULL,
        revision INTEGER NOT NULL DEFAULT 1,
        created_by TEXT DEFAULT '',
        reason TEXT DEFAULT '',
        deadline TEXT,
        status TEXT NOT NULL DEFAULT 'queued',
        sent_at TEXT,
        ack_at TEXT,
        done_at TEXT,
        error TEXT DEFAULT '',
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS resource_samples (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL,
        cpu REAL, ram REAL, disk REAL,
        profiles_open INTEGER, sessions INTEGER,
        sampled_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS profile_transfers (
        id TEXT PRIMARY KEY,
        account_id TEXT DEFAULT '',
        from_device TEXT DEFAULT '',
        to_device TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'requested',
        old_closed INTEGER DEFAULT 0,
        data_checked INTEGER DEFAULT 0,
        ack_new INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS vps_incidents (
        id TEXT PRIMARY KEY,
        device_id TEXT DEFAULT '',
        kind TEXT NOT NULL,
        detail TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open',
        created_at TEXT
    )";

    /* ---- Prompt 4: Su co, retry, lich su, backup, mfa ---- */
    $sql[] = "CREATE TABLE IF NOT EXISTS incidents (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        kind TEXT DEFAULT '',
        account_id TEXT DEFAULT '',
        group_id TEXT DEFAULT '',
        device_id TEXT DEFAULT '',
        owner TEXT DEFAULT '',
        priority TEXT NOT NULL DEFAULT 'normal',
        deadline TEXT,
        guidance TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open',
        reopen_count INTEGER DEFAULT 0,
        close_evidence TEXT DEFAULT '',
        history TEXT DEFAULT '[]',
        created_at TEXT,
        updated_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS retry_policy (
        id TEXT PRIMARY KEY,
        target TEXT NOT NULL,
        consecutive_fail INTEGER DEFAULT 0,
        total_retries INTEGER DEFAULT 0,
        max_consecutive INTEGER DEFAULT 3,
        max_total INTEGER DEFAULT 10,
        next_attempt_at TEXT,
        remaining INTEGER DEFAULT 0,
        block_reason TEXT DEFAULT '',
        needs_human INTEGER DEFAULT 0,
        updated_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username_masked TEXT DEFAULT '',
        ip TEXT DEFAULT '',
        ok INTEGER DEFAULT 0,
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS mfa_secrets (
        user TEXT PRIMARY KEY,
        secret_enc TEXT DEFAULT '',
        enabled INTEGER DEFAULT 0,
        last_used_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS backup_jobs (
        id TEXT PRIMARY KEY,
        target TEXT NOT NULL,
        storage TEXT DEFAULT '',
        enc_key_ref TEXT DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        last_ok_at TEXT,
        last_test_restore_at TEXT,
        rpo_minutes INTEGER DEFAULT 0,
        rto_minutes INTEGER DEFAULT 0,
        created_at TEXT
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS retention_policies (
        id TEXT PRIMARY KEY,
        kind TEXT NOT NULL,
        days INTEGER NOT NULL,
        keep_open_evidence INTEGER DEFAULT 1
    )";

    /* ---- Prompt 5: Nghiem thu VPS ---- */
    $sql[] = "CREATE TABLE IF NOT EXISTS acceptance_runs (
        id TEXT PRIMARY KEY,
        target_vps TEXT DEFAULT '',
        source_version TEXT DEFAULT '',
        package_hash TEXT DEFAULT '',
        started_at TEXT,
        finished_at TEXT,
        verdict TEXT NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT ''
    )";
    $sql[] = "CREATE TABLE IF NOT EXISTS acceptance_checks (
        id TEXT PRIMARY KEY,
        run_id TEXT NOT NULL,
        step TEXT NOT NULL,
        result TEXT NOT NULL DEFAULT 'pending',
        evidence TEXT DEFAULT '',
        checked_at TEXT,
        executor TEXT DEFAULT ''
    )";

    foreach ($sql as $s) { $db->exec($s); }
    return $db;
}

function rw_db_v5_now() { return date('Y-m-d H:i:s'); }
function rw_db_v5_id($prefix) {
    return $prefix . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}
/* Phan quyen: admin lam tat ca; manager chi doc + thao tac thuong;
   viewer (neu co) chi doc. Goi o moi action API. */
function rw_v5_role_ok($need) {
    $role = isset($_SESSION['rw_role']) ? $_SESSION['rw_role'] : '';
    if ($role === 'admin') return true;
    if ($need === 'read') return in_array($role, array('admin','manager','viewer'), true);
    if ($need === 'write') return in_array($role, array('admin','manager'), true);
    if ($need === 'admin') return false;
    return false;
}
/* Output JSON dung chuan {ok, data | error} */
function rw_v5_out($ok, $data, $err = '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($ok ? array('ok' => true, 'data' => $data) : array('ok' => false, 'error' => $err));
    exit;
}
