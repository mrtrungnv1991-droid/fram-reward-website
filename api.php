<?php
require_once __DIR__ . '/security.php';
rw_send_security_headers();
rw_start_session_secure();
rw_require_api_login();
// Muc 17.5: bat buoc CSRF cho moi POST
rw_require_csrf();

$action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', $_GET['action']) : '';

function rw_uid($p){ return $p . '-' . dechex(time()) . '-' . substr(bin2hex(random_bytes(4)),0,8); }
function rw_now(){ return date('c'); }

switch ($action) {
    case 'summary':
        $s = rw_read_data('summary.json', array());
        if (!is_array($s) || !count($s)) {
            $s = array('generated_at' => null, 'total_accounts' => 0, 'ok' => 0, 'partial' => 0,
                       'error' => 0, 'needs_human' => 0, 'points_today' => 0, 'points_avg' => 0,
                       'speed_acc_per_hour' => 0, 'avg_seconds' => 0, 'scheduler' => 'unknown');
        }
        rw_json(array('summary' => $s));
        break;

    case 'accounts':
        $a = rw_read_data('accounts.json', array());
        $limit = isset($_GET['limit']) ? max(1, min(500, intval($_GET['limit']))) : 200;
        $offset = max(0, isset($_GET['offset']) ? intval($_GET['offset']) : 0);
        $status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', $_GET['status']) : '';
        if ($status !== '' && is_array($a)) {
            $a = array_values(array_filter($a, function ($r) use ($status) {
                return isset($r['status']) && $r['status'] === $status;
            }));
        }
        if (!is_array($a)) { $a = array(); }
        $total = count($a);
        $slice = array_slice($a, $offset, $limit);
        $clean = array();
        foreach ($slice as $r) {
            $clean[] = array(
                'email' => isset($r['email']) ? $r['email'] : '',
                'status' => isset($r['status']) ? $r['status'] : 'unknown',
                'points_start' => isset($r['points_start']) ? $r['points_start'] : null,
                'points_end' => isset($r['points_end']) ? $r['points_end'] : null,
                'delta' => isset($r['delta']) ? $r['delta'] : null,
                'tasks_ok' => isset($r['tasks_ok']) ? $r['tasks_ok'] : 0,
                'tasks_error' => isset($r['tasks_error']) ? $r['tasks_error'] : 0,
                'phone' => isset($r['phone']) ? $r['phone'] : null,
                'proxy' => isset($r['proxy']) ? $r['proxy'] : null,
                'next_run_at' => isset($r['next_run_at']) ? $r['next_run_at'] : null,
            );
        }
        rw_json(array('total' => $total, 'offset' => $offset, 'returned' => count($clean), 'accounts' => $clean));
        break;

    case 'results':
        $r = rw_read_data('results.json', array());
        if (!is_array($r)) { $r = array(); }
        rw_json(array('total' => count($r), 'results' => $r));
        break;

    case 'tasks':
        $t = rw_read_data('tasks.json', array());
        if (!is_array($t)) { $t = array(); }
        rw_json(array('total' => count($t), 'tasks' => $t));
        break;

    case 'csrf':
        rw_json(array('csrf' => rw_csrf_token()));
        break;

    case 'health':
        rw_json(array('ok' => true, 'csrf' => rw_csrf_token(), 'auth' => 'session', 'user' => $_SESSION['rw_user'],
                      'role' => isset($_SESSION['rw_role']) ? $_SESSION['rw_role'] : '',
                      'data_dir' => RW_DATA_DIR, 'time' => date('c')));
        break;

    /* ====== Dong bo du lieu tu worker .exe ====== */
    case 'push':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) { rw_json(array('error' => 'bad_payload'), 400); }
        $file = isset($payload['file']) ? basename((string)$payload['file']) : '';
        $allowed = array('summary.json', 'accounts.json', 'tasks.json', 'results.json');
        if (!in_array($file, $allowed, true)) { rw_json(array('error' => 'file_not_allowed', 'allowed' => $allowed), 400); }
        if (!array_key_exists('data', $payload)) { rw_json(array('error' => 'no_data'), 400); }
        $json = json_encode($payload['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) { rw_json(array('error' => 'json_encode_failed'), 400); }
        $dest = RW_DATA_DIR . '/' . $file;
        $ok = file_put_contents($dest, $json, LOCK_EX);
        if ($ok === false) { rw_json(array('error' => 'write_failed', 'path' => $dest), 500); }
        @chmod($dest, 0644);
        rw_json(array('ok' => true, 'file' => $file, 'bytes' => $ok, 'at' => rw_now(), 'by' => $_SESSION['rw_user']));
        break;

    /* ====== THIET BI: worker dang ky / heartbeat ====== */
    case 'devices':
        $d = rw_read_data('devices.json', array());
        if (!is_array($d)) { $d = array(); }
        $out = array();
        foreach ($d as $id => $dev) {
            $out[] = array('id' => $id,
                'name' => isset($dev['name']) ? $dev['name'] : $id,
                'os' => isset($dev['os']) ? $dev['os'] : '',
                'version' => isset($dev['version']) ? $dev['version'] : '',
                'host' => isset($dev['host']) ? $dev['host'] : '',
                'gateway' => isset($dev['gateway']) ? $dev['gateway'] : '',
                'last_seen' => isset($dev['last_seen']) ? $dev['last_seen'] : null,
                'online' => (isset($dev['last_seen']) && (time() - strtotime($dev['last_seen'])) < 180),
                'last_info' => isset($dev['last_info']) ? $dev['last_info'] : null);
        }
        rw_json(array('total' => count($out), 'devices' => $out));
        break;

    case 'device_heartbeat':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        $p = json_decode(file_get_contents('php://input'), true);
        if (!is_array($p) || empty($p['id'])) { rw_json(array('error' => 'bad_payload'), 400); }
        $id = preg_replace('/[^a-zA-Z0-9_\-.]/', '', (string)$p['id']);
        if ($id === '') { rw_json(array('error' => 'bad_id'), 400); }
        $d = rw_read_data('devices.json', array());
        if (!is_array($d)) { $d = array(); }
        $prev = isset($d[$id]) ? $d[$id] : array();
        $d[$id] = array(
            'name' => isset($p['name']) ? substr((string)$p['name'],0,64) : (isset($prev['name'])?$prev['name']:$id),
            'os' => isset($p['os']) ? substr((string)$p['os'],0,32) : '',
            'version' => isset($p['version']) ? substr((string)$p['version'],0,16) : '',
            'host' => isset($p['host']) ? substr((string)$p['host'],0,64) : '',
            'gateway' => isset($p['gateway']) ? substr((string)$p['gateway'],0,128) : (isset($prev['gateway'])?$prev['gateway']:''),
            'last_seen' => rw_now(),
            'last_info' => isset($p['info']) && is_array($p['info']) ? $p['info'] : null);
        rw_write_data('devices.json', $d);
        rw_json(array('ok' => true, 'id' => $id, 'at' => rw_now()));
        break;

    /* ====== LENH: web day lenh, worker poll + bao ket qua ====== */
    case 'cmd_list':
        $c = rw_read_data('commands.json', array());
        if (!is_array($c)) { $c = array(); }
        $dev = isset($_GET['device']) ? preg_replace('/[^a-zA-Z0-9_\-.]/', '', $_GET['device']) : '';
        $st = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', $_GET['status']) : '';
        $limit = isset($_GET['limit']) ? max(1, min(200, intval($_GET['limit']))) : 50;
        // Muc 17.7: loc entry khong hop le (chi giu dict co id) de khong loi sort
        $rows = array();
        foreach ($c as $r) {
            if (is_array($r) && isset($r['id']) && isset($r['action']) && isset($r['status'])) { $rows[] = $r; }
        }
        usort($rows, function ($a, $b) { return strcmp($b['id'], $a['id']); });
        if ($dev !== '') { $rows = array_values(array_filter($rows, function ($r) use ($dev){ return $r['device'] === $dev; })); }
        if ($st !== '') { $rows = array_values(array_filter($rows, function ($r) use ($st){ return $r['status'] === $st; })); }
        $pending = 0; $running = 0; $done = 0; $err = 0;
        foreach ($rows as $r) { if ($r['status']==='pending') $pending++; elseif ($r['status']==='running') $running++; elseif ($r['status']==='done') $done++; elseif ($r['status']==='error') $err++; }
        rw_json(array('total' => count($rows), 'returned' => min($limit, count($rows)),
                      'stats' => array('pending'=>$pending,'running'=>$running,'done'=>$done,'error'=>$err),
                      'commands' => array_slice($rows, 0, $limit)));
        break;

    case 'cmd_enqueue':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        if (!rw_is_admin()) { rw_json(array('error' => 'admin_required'), 403); }
        $p = json_decode(file_get_contents('php://input'), true);
        if (!is_array($p)) { $p = $_POST; }
        $device = isset($p['device']) ? preg_replace('/[^a-zA-Z0-9_\-.]/', '', (string)$p['device']) : '';
        $cmd = isset($p['action']) ? preg_replace('/[^a-z_]/', '', (string)$p['action']) : '';
        $allowed = array('sync','status','farm_batch','farm_account','farm_all','daily_run','daily_slot',
                         'farm_recover','jobs_list','job_stop','restart_worker','update_data','exec');
        if ($device === '') { rw_json(array('error' => 'device_required', 'allowed_actions' => $allowed), 400); }
        if (!in_array($cmd, $allowed, true)) { rw_json(array('error' => 'action_not_allowed', 'allowed' => $allowed), 400); }
        $args = isset($p['args']) && is_array($p['args']) ? $p['args'] : array();
        $c = rw_read_data('commands.json', array());
        if (!is_array($c)) { $c = array(); }
        if (count($c) > 800) { $c = array_slice($c, -500); }
        $id = rw_uid('cmd');
        $c[$id] = array('id' => $id, 'device' => $device, 'action' => $cmd, 'args' => $args,
                        'status' => 'pending', 'created_at' => rw_now(), 'created_by' => $_SESSION['rw_user'],
                        'started_at' => null, 'finished_at' => null, 'result' => null);
        rw_write_data('commands.json', $c);
        rw_json(array('ok' => true, 'id' => $id, 'device' => $device, 'action' => $cmd, 'status' => 'pending'));
        break;

    case 'cmd_poll':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        $p = json_decode(file_get_contents('php://input'), true);
        if (!is_array($p) || empty($p['device'])) { rw_json(array('error' => 'bad_payload'), 400); }
        $device = preg_replace('/[^a-zA-Z0-9_\-.]/', '', (string)$p['device']);
        $c = rw_read_data('commands.json', array());
        if (!is_array($c)) { $c = array(); }
        $out = array(); $max = 3;
        foreach ($c as $k => &$r) {
            if (count($out) >= $max) break;
            if ($r['device'] === $device && $r['status'] === 'pending') {
                $r['status'] = 'running'; $r['started_at'] = rw_now();
                $out[] = $r;
            }
        }
        unset($r);
        if (count($out)) { rw_write_data('commands.json', $c); }
        rw_json(array('ok' => true, 'count' => count($out), 'commands' => $out));
        break;

    case 'cmd_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        $p = json_decode(file_get_contents('php://input'), true);
        if (!is_array($p) || empty($p['id'])) { rw_json(array('error' => 'bad_payload'), 400); }
        $id = preg_replace('/[^a-z0-9\-.]/', '', (string)$p['id']);
        $status = isset($p['status']) ? preg_replace('/[^a-z_]/', '', (string)$p['status']) : 'done';
        if (!in_array($status, array('done','error','canceled'), true)) { $status = 'done'; }
        $c = rw_read_data('commands.json', array());
        if (!isset($c[$id])) { rw_json(array('error' => 'not_found', 'id' => $id), 404); }
        $c[$id]['status'] = $status;
        $c[$id]['finished_at'] = rw_now();
        $c[$id]['result'] = isset($p['result']) ? $p['result'] : null;
        rw_write_data('commands.json', $c);
        rw_json(array('ok' => true, 'id' => $id, 'status' => $status));
        break;

    case 'cmd_cancel':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rw_json(array('error' => 'post_required'), 405); }
        if (!rw_is_admin()) { rw_json(array('error' => 'admin_required'), 403); }
        $p = json_decode(file_get_contents('php://input'), true);
        if (!is_array($p)) { $p = $_POST; }
        $id = isset($p['id']) ? preg_replace('/[^a-z0-9\-.]/', '', (string)$p['id']) : '';
        $c = rw_read_data('commands.json', array());
        if (!isset($c[$id])) { rw_json(array('error' => 'not_found'), 404); }
        if ($c[$id]['status'] === 'pending') { $c[$id]['status'] = 'canceled'; $c[$id]['finished_at'] = rw_now(); rw_write_data('commands.json', $c); }
        rw_json(array('ok' => true, 'id' => $id, 'status' => $c[$id]['status']));
        break;

    default:
        // Muc 12: dispatch sang api_crud.php cho cac action CRUD (account/proxy/phone/config)
        $crud_actions = array('account_list','account_add','account_bulk','account_update','account_delete',
            'proxy_list','proxy_add','proxy_bulk','proxy_assign','proxy_test','proxy_delete',
            'phone_list','phone_add','phone_import','phone_assign','phone_unassign',
            'config_get','config_apply','config_pull','config_applied');
        if (in_array($action, $crud_actions, true)) {
            require __DIR__ . '/api_crud.php';
            break;
        }
        // Muc 4: dispatch sang api_playbook.php cho cac action playbook (CRUD + distill)
        $playbook_actions = array('playbook_list','playbook_get','playbook_save','playbook_delete','playbook_distill');
        if (in_array($action, $playbook_actions, true)) {
            require __DIR__ . '/api_playbook.php';
            break;
        }
        rw_json(array('error' => 'unknown_action', 'available' => array('summary','accounts','results','tasks','health','push',
            'devices','device_heartbeat','cmd_list','cmd_enqueue','cmd_poll','cmd_report','cmd_cancel',
            'account_list','account_add','account_bulk','account_update','account_delete',
            'proxy_list','proxy_add','proxy_bulk','proxy_assign','proxy_test','proxy_delete',
            'phone_list','phone_add','phone_import','phone_assign','phone_unassign',
            'config_get','config_apply','config_pull','config_applied')), 400);
}
