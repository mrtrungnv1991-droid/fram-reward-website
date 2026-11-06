<?php
// api_crud.php — Muc 12: CRUD account / proxy / phone + config revision (SQLite).
// Duoc include tu api.php SAU khi da dang nhap + CSRF. Tra JSON truc tiep.
require_once __DIR__ . '/db.php';

function rw_crud_input() {
    $raw = file_get_contents('php://input');
    $p = json_decode($raw, true);
    return is_array($p) ? $p : array();
}
function rw_crud_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
}
function rw_crud_admin_only() {
    if (!rw_is_admin()) { rw_json(array('error' => 'admin_only'), 403); }
}
// Phan loai loi (error_class) cho account
function rw_crud_classify_error($msg) {
    $m = strtolower((string)$msg);
    if (strpos($m, 'login') !== false && (strpos($m, 'fail') !== false || strpos($m, 'sai') !== false)) return 'login_failed';
    if (strpos($m, 'recover') !== false || strpos($m, 'khôi phục') !== false) return 'needs_recovery';
    if (strpos($m, 'captcha') !== false) return 'captcha';
    if (strpos($m, 'proxy') !== false) return 'proxy_error';
    return 'unknown';
}

switch ($action) {
    /* ===================== ACCOUNTS ===================== */
    case 'account_list':
        $rows = rw_db()->query("SELECT id, email, status, device_id, proxy_id, phone_id, points_start, points_end, delta, tasks_ok, tasks_error, next_run_at, last_run_at, last_error, error_class, flags, created_at, updated_at FROM accounts ORDER BY email")->fetchAll();
        rw_json(array('total' => count($rows), 'accounts' => $rows));
        break;

    case 'account_add':
        rw_crud_post_only();
        $p = rw_crud_input();
        $email = isset($p['email']) ? trim((string)$p['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { rw_json(array('error' => 'invalid_email'), 422); }
        $st = rw_db()->prepare("INSERT INTO accounts(email, password_sealed, device_id, proxy_id, phone_id, status, next_run_at, flags, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(email) DO UPDATE SET password_sealed=excluded.password_sealed, updated_at=excluded.updated_at");
        $st->execute(array($email,
            isset($p['password_sealed']) ? (string)$p['password_sealed'] : '',
            isset($p['device_id']) ? (int)$p['device_id'] : null,
            isset($p['proxy_id']) ? (int)$p['proxy_id'] : null,
            isset($p['phone_id']) ? (int)$p['phone_id'] : null,
            isset($p['status']) ? preg_replace('/[^a-z_]/', '', (string)$p['status']) : 'new',
            isset($p['next_run_at']) ? (string)$p['next_run_at'] : null,
            isset($p['flags']) ? (string)$p['flags'] : '',
            rw_db_now(), rw_db_now()));
        rw_db_audit('account_add', array('email' => $email));
        rw_json(array('ok' => true, 'email' => $email));
        break;

    case 'account_bulk':
        rw_crud_post_only();
        $p = rw_crud_input();
        $lines = isset($p['lines']) ? (string)$p['lines'] : '';
        if ($lines === '' && isset($p['items']) && is_array($p['items'])) {
            foreach ($p['items'] as $it) {
                $e = isset($it['email']) ? trim((string)$it['email']) : '';
                if ($e !== '') { $lines .= $e . "\n"; }
            }
        }
        $added = 0; $skipped = 0; $errors = array();
        foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // ho tro dinh dang: email | password_sealed | note
            $parts = array_map('trim', explode('|', $line));
            $email = $parts[0];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; $errors[] = array('line' => $line, 'error' => 'invalid_email'); continue; }
            $pwd = isset($parts[1]) ? $parts[1] : '';
            $st = rw_db()->prepare("INSERT INTO accounts(email, password_sealed, status, created_at, updated_at) VALUES(?,?, 'new', ?, ?) ON CONFLICT(email) DO UPDATE SET password_sealed=excluded.password_sealed, updated_at=excluded.updated_at");
            $st->execute(array($email, $pwd, rw_db_now(), rw_db_now()));
            $added++;
        }
        rw_db_audit('account_bulk', array('added' => $added, 'skipped' => $skipped));
        rw_json(array('ok' => true, 'added' => $added, 'skipped' => $skipped, 'errors' => $errors));
        break;

    case 'account_update':
        rw_crud_post_only();
        $p = rw_crud_input();
        $id = isset($p['id']) ? (int)$p['id'] : 0;
        if ($id <= 0) { rw_json(array('error' => 'id_required'), 422); }
        $st = rw_db()->prepare("SELECT * FROM accounts WHERE id = ?");
        $st->execute(array($id));
        $cur = $st->fetch();
        if (!$cur) { rw_json(array('error' => 'not_found'), 404); }
        $fields = array('status' => 's', 'next_run_at' => 's', 'proxy_id' => 'i', 'phone_id' => 'i', 'device_id' => 'i',
                        'flags' => 's', 'last_error' => 's', 'error_class' => 's',
                        'points_start' => 'i', 'points_end' => 'i', 'delta' => 'i', 'tasks_ok' => 'i', 'tasks_error' => 'i');
        $sets = array(); $vals = array();
        foreach ($fields as $f => $t) {
            if (array_key_exists($f, $p)) {
                $sets[] = "$f = ?";
                $v = $p[$f];
                if ($v === null) { $vals[] = null; }
                else if ($t === 'i') { $vals[] = (int)$v; }
                else { $vals[] = (string)$v; }
            }
        }
        if (!count($sets)) { rw_json(array('error' => 'nothing_to_update'), 422); }
        $sets[] = 'updated_at = ?'; $vals[] = rw_db_now();
        $vals[] = $id;
        $st = rw_db()->prepare("UPDATE accounts SET " . implode(', ', $sets) . " WHERE id = ?");
        $st->execute($vals);
        rw_db_audit('account_update', array('id' => $id, 'fields' => array_keys($p)));
        rw_json(array('ok' => true, 'id' => $id));
        break;

    case 'account_delete':
        rw_crud_post_only();
        rw_crud_admin_only();
        $p = rw_crud_input();
        $id = isset($p['id']) ? (int)$p['id'] : 0;
        if ($id <= 0) { rw_json(array('error' => 'id_required'), 422); }
        $st = rw_db()->prepare("DELETE FROM accounts WHERE id = ?");
        $st->execute(array($id));
        rw_db_audit('account_delete', array('id' => $id));
        rw_json(array('ok' => true, 'deleted' => $st->rowCount()));
        break;

    /* ===================== PROXIES ===================== */
    case 'proxy_list':
        $rows = rw_db()->query("SELECT id, proxy_key, scheme, host, port, username, status, ip, geo, country, latency_ms, fail_count, max_profiles, last_checked, created_at FROM proxies ORDER BY id")->fetchAll();
        rw_json(array('total' => count($rows), 'proxies' => $rows));
        break;

    case 'proxy_add':
        rw_crud_post_only();
        $p = rw_crud_input();
        $scheme = isset($p['scheme']) ? preg_replace('/[^a-z]/', '', strtolower((string)$p['scheme'])) : 'http';
        if (!in_array($scheme, array('http', 'https', 'socks4', 'socks5'), true)) { rw_json(array('error' => 'invalid_scheme', 'allowed' => array('http','https','socks4','socks5')), 422); }
        $host = isset($p['host']) ? trim((string)$p['host']) : '';
        $port = isset($p['port']) ? (int)$p['port'] : 0;
        if ($host === '' || $port <= 0 || $port > 65535) { rw_json(array('error' => 'invalid_host_port'), 422); }
        $user = isset($p['username']) ? (string)$p['username'] : '';
        $pass_sealed = isset($p['password_sealed']) ? (string)$p['password_sealed'] : '';
        $maxp = isset($p['max_profiles']) ? (int)$p['max_profiles'] : 1;
        $key = $scheme . '://' . $host . ':' . $port . ($user !== '' ? ' (' . $user . ')' : '');
        $st = rw_db()->prepare("INSERT INTO proxies(proxy_key, scheme, host, port, username, password_sealed, status, max_profiles, created_at) VALUES(?,?,?,?,?,?,'unchecked',?,?) ON CONFLICT(proxy_key) DO UPDATE SET scheme=excluded.scheme, host=excluded.host, port=excluded.port, username=excluded.username, password_sealed=excluded.password_sealed, max_profiles=excluded.max_profiles");
        $st->execute(array($key, $scheme, $host, $port, $user, $pass_sealed, $maxp, rw_db_now()));
        rw_db_audit('proxy_add', array('proxy_key' => $key));
        rw_json(array('ok' => true, 'proxy_key' => $key));
        break;

    case 'proxy_bulk':
        rw_crud_post_only();
        $p = rw_crud_input();
        $text = isset($p['lines']) ? (string)$p['lines'] : '';
        $added = 0; $skipped = 0; $errors = array();
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // dinh dang: scheme://user:pass@host:port  hoac  host:port
            $scheme = 'http';
            if (preg_match('/^(https?|socks[45]):\/\/(.*)$/i', $line, $m)) {
                $scheme = strtolower($m[1]); $rest = $m[2];
            } else { $rest = $line; }
            $user = ''; $pass = '';
            if (strpos($rest, '@') !== false) {
                list($cred, $rest) = explode('@', $rest, 2);
                $cp = explode(':', $cred, 2);
                $user = $cp[0]; $pass = isset($cp[1]) ? $cp[1] : '';
            }
            $hp = explode(':', $rest);
            $host = $hp[0]; $port = isset($hp[1]) ? (int)$hp[1] : 0;
            if ($host === '' || $port <= 0 || $port > 65535) { $skipped++; $errors[] = array('line' => $line, 'error' => 'invalid_host_port'); continue; }
            $key = $scheme . '://' . $host . ':' . $port . ($user !== '' ? ' (' . $user . ')' : '');
            $st = rw_db()->prepare("INSERT INTO proxies(proxy_key, scheme, host, port, username, password_sealed, status, max_profiles, created_at) VALUES(?,?,?,?,?,?,'unchecked',1,?) ON CONFLICT(proxy_key) DO NOTHING");
            $st->execute(array($key, $scheme, $host, $port, $user, $pass, rw_db_now()));
            $added++;
        }
        rw_db_audit('proxy_bulk', array('added' => $added, 'skipped' => $skipped));
        rw_json(array('ok' => true, 'added' => $added, 'skipped' => $skipped, 'errors' => $errors));
        break;

    case 'proxy_assign':
        rw_crud_post_only();
        $p = rw_crud_input();
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        $proxy_id = isset($p['proxy_id']) ? (int)$p['proxy_id'] : 0;
        $acc_id2 = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        if ($acc_id <= 0 || $proxy_id <= 0) { rw_json(array('error' => 'account_id_and_proxy_id_required'), 422); }
        // kiem tra gioi han max_profiles cua proxy (mac dinh 1: 1 proxy = 1 account)
        $st = rw_db()->prepare("SELECT max_profiles FROM proxies WHERE id = ?");
        $st->execute(array($proxy_id));
        $pr = $st->fetch();
        if (!$pr) { rw_json(array('error' => 'proxy_not_found'), 404); }
        $maxp = (int)$pr['max_profiles'];
        $st = rw_db()->prepare("SELECT COUNT(*) c FROM accounts WHERE proxy_id = ? AND id != ?");
        $st->execute(array($proxy_id, $acc_id));
        $used = (int)$st->fetch()['c'];
        if ($used >= $maxp) { rw_json(array('error' => 'proxy_capacity_full', 'max_profiles' => $maxp), 409); }
        $st = rw_db()->prepare("UPDATE accounts SET proxy_id = ?, updated_at = ? WHERE id = ?");
        $st->execute(array($proxy_id, rw_db_now(), $acc_id));
        rw_db_audit('proxy_assign', array('account_id' => $acc_id, 'proxy_id' => $proxy_id));
        rw_json(array('ok' => true, 'account_id' => $acc_id, 'proxy_id' => $proxy_id));
        break;

    case 'proxy_test':
        rw_crud_post_only();
        $p = rw_crud_input();
        $proxy_id = isset($p['proxy_id']) ? (int)$p['proxy_id'] : 0;
        if ($proxy_id <= 0) { rw_json(array('error' => 'proxy_id_required'), 422); }
        $st = rw_db()->prepare("SELECT * FROM proxies WHERE id = ?");
        $st->execute(array($proxy_id));
        $pr = $st->fetch();
        if (!$pr) { rw_json(array('error' => 'proxy_not_found'), 404); }
        // Test TCP connection nhanh (khong thuc su lay IP — giu nguyen kernel cua hosting).
        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        $fp = @fsockopen($pr['host'], (int)$pr['port'], $errno, $errstr, 5);
        $latency = $fp ? (int)round((microtime(true) - $t0) * 1000) : 0;
        $status = $fp ? 'ok' : 'down';
        if ($fp) fclose($fp);
        $st = rw_db()->prepare("UPDATE proxies SET status = ?, latency_ms = ?, last_checked = ?, fail_count = CASE WHEN ? = 'down' THEN fail_count + 1 ELSE 0 END WHERE id = ?");
        $st->execute(array($status, $latency, rw_db_now(), $status, $proxy_id));
        rw_json(array('ok' => true, 'proxy_id' => $proxy_id, 'status' => $status, 'latency_ms' => $latency, 'error' => $errstr !== '' ? $errstr : null));
        break;

    case 'proxy_delete':
        rw_crud_post_only();
        rw_crud_admin_only();
        $p = rw_crud_input();
        $pid = isset($p['proxy_id']) ? (int)$p['proxy_id'] : 0;
        if ($pid <= 0) { rw_json(array('error' => 'proxy_id_required'), 422); }
        // thao proxy khoi account truoc khi xoa
        $st = rw_db()->prepare("UPDATE accounts SET proxy_id = NULL, updated_at = ? WHERE proxy_id = ?");
        $st->execute(array(rw_db_now(), $pid));
        $freed = $st->rowCount();
        $st = rw_db()->prepare("DELETE FROM proxies WHERE id = ?");
        $st->execute(array($pid));
        rw_db_audit('proxy_delete', array('proxy_id' => $pid, 'freed_accounts' => $freed));
        rw_json(array('ok' => true, 'proxy_id' => $pid, 'deleted' => $st->rowCount(), 'freed_accounts' => $freed));
        break;

    /* ===================== PHONES ===================== */
    case 'phone_list':
        $rows = rw_db()->query("SELECT id, number_mask, country, carrier, status, attached_profile, attached_at, verified_at, notes, created_at FROM phones ORDER BY id")->fetchAll();
        rw_json(array('total' => count($rows), 'phones' => $rows));
        break;

    case 'phone_add':
        rw_crud_post_only();
        $p = rw_crud_input();
        $number = isset($p['number']) ? preg_replace('/[^0-9+]/', '', (string)$p['number']) : '';
        if (strlen($number) < 8) { rw_json(array('error' => 'invalid_number'), 422); }
        $mask = substr($number, 0, 3) . '****' . substr($number, -2);
        $country = isset($p['country']) ? preg_replace('/[^A-Za-z]/', '', (string)$p['country']) : '';
        $carrier = isset($p['carrier']) ? preg_replace('/[^A-Za-z0-9 .-]/', '', (string)$p['carrier']) : '';
        $notes = isset($p['notes']) ? mb_substr((string)$p['notes'], 0, 250) : '';
        // ma hoa so nguyen ban (khong lu thang de an toan — giu gia tri vi day la sso can lay lai)
        $enc = base64_encode($number);
        $st = rw_db()->prepare("INSERT INTO phones(number_enc, number_mask, country, carrier, status, notes, created_at) VALUES(?,?,?,?,'free',?,?) ON CONFLICT(number_enc) DO UPDATE SET country=excluded.country, carrier=excluded.carrier, notes=excluded.notes");
        $st->execute(array($enc, $mask, $country, $carrier, $notes, rw_db_now()));
        rw_db_audit('phone_add', array('mask' => $mask));
        rw_json(array('ok' => true, 'number_mask' => $mask));
        break;

    case 'phone_import':
        rw_crud_post_only();
        $p = rw_crud_input();
        $text = isset($p['lines']) ? (string)$p['lines'] : '';
        $added = 0; $skipped = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $number = preg_replace('/[^0-9+]/', '', $parts[0]);
            if (strlen($number) < 8) { $skipped++; continue; }
            $mask = substr($number, 0, 3) . '****' . substr($number, -2);
            $country = isset($parts[1]) ? preg_replace('/[^A-Za-z]/', '', $parts[1]) : '';
            $carrier = isset($parts[2]) ? preg_replace('/[^A-Za-z0-9 .-]/', '', $parts[2]) : '';
            $st = rw_db()->prepare("INSERT INTO phones(number_enc, number_mask, country, carrier, status, created_at) VALUES(?,?,?,?,'free',?) ON CONFLICT(number_enc) DO NOTHING");
            $st->execute(array(base64_encode($number), $mask, $country, $carrier, rw_db_now()));
            $added++;
        }
        rw_db_audit('phone_import', array('added' => $added, 'skipped' => $skipped));
        rw_json(array('ok' => true, 'added' => $added, 'skipped' => $skipped));
        break;

    case 'phone_assign':
        rw_crud_post_only();
        $p = rw_crud_input();
        $acc_id = isset($p['account_id']) ? (int)$p['account_id'] : 0;
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($acc_id <= 0 || $phone_id <= 0) { rw_json(array('error' => 'account_id_and_phone_id_required'), 422); }
        // kiem tra phone co dang free khong (moi phone = toi da 1 account)
        $st = rw_db()->prepare("SELECT status, attached_profile FROM phones WHERE id = ?");
        $st->execute(array($phone_id));
        $ph = $st->fetch();
        if (!$ph) { rw_json(array('error' => 'phone_not_found'), 404); }
        if ($ph['status'] !== 'free' && $ph['attached_profile'] !== (string)$acc_id) {
            rw_json(array('error' => 'phone_in_use', 'attached_profile' => $ph['attached_profile']), 409);
        }
        $st = rw_db()->prepare("UPDATE phones SET status = 'attached', attached_profile = ?, attached_at = ? WHERE id = ?");
        $st->execute(array((string)$acc_id, rw_db_now(), $phone_id));
        $st = rw_db()->prepare("UPDATE accounts SET phone_id = ?, updated_at = ? WHERE id = ?");
        $st->execute(array($phone_id, rw_db_now(), $acc_id));
        rw_db_audit('phone_assign', array('account_id' => $acc_id, 'phone_id' => $phone_id));
        rw_json(array('ok' => true, 'account_id' => $acc_id, 'phone_id' => $phone_id));
        break;

    case 'phone_unassign':
        rw_crud_post_only();
        $p = rw_crud_input();
        $phone_id = isset($p['phone_id']) ? (int)$p['phone_id'] : 0;
        if ($phone_id <= 0) { rw_json(array('error' => 'phone_id_required'), 422); }
        $st = rw_db()->prepare("UPDATE phones SET status = 'free', attached_profile = NULL, attached_at = NULL WHERE id = ?");
        $st->execute(array($phone_id));
        $st = rw_db()->prepare("UPDATE accounts SET phone_id = NULL, updated_at = ? WHERE phone_id = ?");
        $st->execute(array(rw_db_now(), $phone_id));
        rw_db_audit('phone_unassign', array('phone_id' => $phone_id));
        rw_json(array('ok' => true, 'phone_id' => $phone_id, 'freed' => $st->rowCount()));
        break;

    /* ===================== CONFIG REVISION (12.5) ===================== */
    case 'config_get':
        $settings = array();
        foreach (rw_db()->query("SELECT key, value FROM settings")->fetchAll() as $r) { $settings[$r['key']] = $r['value']; }
        $rev = rw_db_setting('config_revision', '1');
        $profiles = rw_db()->query("SELECT id, email, status, device_id, proxy_id, phone_id, next_run_at, flags FROM accounts ORDER BY email")->fetchAll();
        rw_json(array('revision' => (int)$rev, 'settings' => $settings, 'profiles' => $profiles));
        break;

    case 'config_apply':
        rw_crud_post_only();
        rw_crud_admin_only();
        $p = rw_crud_input();
        $settings = isset($p['settings']) && is_array($p['settings']) ? $p['settings'] : array();
        if (!count($settings)) { rw_json(array('error' => 'settings_required'), 422); }
        $allowed_keys = array('require_proxy', 'max_parallel_browsers', 'tabs_per_profile', 'active_hours',
                              'max_retries_day', 'reset_time', 'stagger_start_sec', 'auto_cap',
                              'max_accounts_per_phone', 'max_profiles_per_proxy');
        $applied = array();
        foreach ($settings as $k => $v) {
            $k = preg_replace('/[^a-z_]/', '', strtolower((string)$k));
            if (!in_array($k, $allowed_keys, true)) continue;
            rw_db_set_setting($k, (string)$v);
            $applied[$k] = (string)$v;
        }
        $rev = (int)rw_db_setting('config_revision', '1') + 1;
        rw_db_set_setting('config_revision', (string)$rev);
        // luu config_revisions de worker keo (config_pull)
        $st = rw_db()->prepare("INSERT INTO config_revisions(device_id, revision, payload_json, created_by, created_at, status) VALUES(?,?,?,?,?,'pending')");
        $st->execute(array(null, $rev, json_encode(array('settings' => $applied), JSON_UNESCAPED_UNICODE),
            isset($_SESSION['rw_user']) ? $_SESSION['rw_user'] : '-', rw_db_now()));
        rw_db_audit('config_apply', array('revision' => $rev, 'keys' => array_keys($applied)));
        rw_json(array('ok' => true, 'revision' => $rev, 'applied' => $applied));
        break;

    case 'config_pull':
        // worker keo cau hinh (thuong POST nhung GET cung duoc de test)
        $rev_current = (int)rw_db_setting('config_revision', '1');
        $rev_client = isset($_GET['revision']) ? (int)$_GET['revision'] : 0;
        if ($rev_client >= $rev_current && $rev_client > 0) {
            rw_json(array('revision' => $rev_current, 'changed' => false));
            break;
        }
        $settings = array();
        foreach (rw_db()->query("SELECT key, value FROM settings")->fetchAll() as $r) { $settings[$r['key']] = $r['value']; }
        $profiles = rw_db()->query("SELECT id, email, status, device_id, proxy_id, phone_id, next_run_at, flags FROM accounts ORDER BY email")->fetchAll();
        rw_json(array('revision' => $rev_current, 'changed' => true, 'settings' => $settings, 'profiles' => $profiles));
        break;

    case 'config_applied':
        rw_crud_post_only();
        $p = rw_crud_input();
        $rev = isset($p['revision']) ? (int)$p['revision'] : 0;
        $status = isset($p['status']) ? preg_replace('/[^a-z_]/', '', (string)$p['status']) : 'ok';
        $error = isset($p['error']) ? mb_substr((string)$p['error'], 0, 250) : null;
        $st = rw_db()->prepare("UPDATE config_revisions SET applied_at = ?, status = ?, error = ? WHERE revision = ? AND status = 'pending'");
        $st->execute(array(rw_db_now(), $status, $error, $rev));
        rw_json(array('ok' => true, 'revision' => $rev, 'status' => $status));
        break;

    default:
        // khong phai action CRUD → tra lai de api.php xu ly (hoac bao loi)
        rw_json(array('error' => 'unknown_crud_action', 'available' => array(
            'account_list','account_add','account_bulk','account_update','account_delete',
            'proxy_list','proxy_add','proxy_bulk','proxy_assign','proxy_test',
            'phone_list','phone_add','phone_import','phone_assign','phone_unassign',
            'config_get','config_apply','config_pull','config_applied')), 400);
}
?>
