<?php
/* api_v5_vps.php — Prompt 3: VPS & Worker (UI v5). */
require_once __DIR__ . '/security.php';
rw_start_session_secure();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_v5.php';
if (!rw_is_logged_in()) { rw_v5_out(false, null, 'unauthorized'); }
rw_db_v5_schema();
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rw_csrf_check()) { rw_v5_out(false, null, 'csrf_invalid'); }
    if (!rw_v5_role_ok('write')) { rw_v5_out(false, null, 'forbidden'); }
} else if (!rw_v5_role_ok('read')) { rw_v5_out(false, null, 'forbidden'); }
$db = rw_db();

function vps_cmds() { return array('start','pause','stop','check_now','maintenance'); }
function vps_cmd_status() { return array('queued','sent','acked','working','done','error','expired'); }

switch ($action) {
    case 'health': rw_v5_out(true, array('module'=>'vps','status'=>'ok')); break;

    /* ===== Dang ky may bang ma mot lan ===== */
    case 'enroll_create':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $ttl = min(1440, max(5, isset($_POST['ttl_min']) ? (int)$_POST['ttl_min'] : 30));
        $code = strtoupper(bin2hex(random_bytes(4)));
        $exp = date('Y-m-d H:i:s', time() + $ttl * 60);
        $db->prepare('INSERT INTO enroll_codes(code,created_by,expires_at,status) VALUES(?,?,?,?) ON CONFLICT(code) DO NOTHING')->execute(array($code,$_SESSION['rw_user'],$exp,'active'));
        rw_audit('enroll_create', array('code'=>$code));
        rw_v5_out(true, array('code'=>$code,'expires_at'=>$exp,'ttl_min'=>$ttl));
        break;
    case 'enroll_list':
        $st = $db->query('SELECT code, created_by, used_by, device_id, expires_at, used_at, status FROM enroll_codes ORDER BY expires_at DESC LIMIT 50');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'enroll_use':
        // Nguoi so huu goi khi cai may: doi ma mot lan -> token rieng may
        $code = isset($_POST['code']) ? strtoupper((string)$_POST['code']) : '';
        $label = isset($_POST['label']) ? (string)$_POST['label'] : '';
        if (!preg_match('/^[A-F0-9]{8}$/', $code)) { rw_v5_out(false, null, 'bad_code'); }
        $st = $db->prepare('SELECT status, expires_at FROM enroll_codes WHERE code = ?'); $st->execute(array($code)); $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) { rw_v5_out(false, null, 'not_found'); }
        if ($e['status'] !== 'active') { rw_v5_out(false, null, 'used_or_revoked'); }
        if (strtotime($e['expires_at']) < time()) {
            $db->prepare('UPDATE enroll_codes SET status="expired" WHERE code=?')->execute(array($code));
            rw_v5_out(false, null, 'expired');
        }
        $token = bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        $dev = 'dev-'.substr($hash, 0, 8);
        $db->prepare('INSERT INTO device_tokens(token_hash,device_id,label,status,app_version,components,api_compat,last_sync_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array($hash,$dev,$label,'active','v5.0','controller,observer','v5',rw_db_v5_now()));
        $db->prepare('UPDATE enroll_codes SET status="used", used_by=?, device_id=?, used_at=? WHERE code=?')->execute(array($_SESSION['rw_user'],$dev,rw_db_v5_now(),$code));
        rw_audit('enroll_use', array('device_id'=>$dev));
        // Tra token THO mot lan duy nhat cho may — khong luu thong
        rw_v5_out(true, array('device_id'=>$dev,'token'=>$token,'note'=>'token chi hien thi mot lan'));
        break;
    case 'token_list':
        $st = $db->query('SELECT device_id, label, api_compat, app_version, components, last_sync_at, revoked_at, status FROM device_tokens ORDER BY device_id');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'token_revoke':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $dev = isset($_POST['device_id']) ? (string)$_POST['device_id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $dev)) { rw_v5_out(false, null, 'bad_device'); }
        $db->prepare('UPDATE device_tokens SET status="revoked", revoked_at=? WHERE device_id=?')->execute(array(rw_db_v5_now(), $dev));
        // Chan viec moi: xoa lenh queued
        $db->prepare('UPDATE device_commands SET status="expired" WHERE device_id=? AND status="queued"')->execute(array($dev));
        rw_audit('token_revoke', array('device_id'=>$dev));
        rw_v5_out(true, array('device_id'=>$dev,'revoked'=>true));
        break;

    /* ===== Lenh chuan hoa ===== */
    case 'command_create':
        $dev = isset($_POST['device_id']) ? (string)$_POST['device_id'] : '';
        $cmd = isset($_POST['cmd']) ? (string)$_POST['cmd'] : '';
        $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
        $deadline = isset($_POST['deadline']) ? (string)$_POST['deadline'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $dev)) { rw_v5_out(false, null, 'bad_device'); }
        if (!in_array($cmd, vps_cmds(), true)) { rw_v5_out(false, null, 'bad_cmd'); }
        if (strlen($reason) < 3) { rw_v5_out(false, null, 'reason_required'); }
        // khong nhan lenh khi token da bi thu hoi
        $st = $db->prepare('SELECT status FROM device_tokens WHERE device_id = ?'); $st->execute(array($dev)); $ts = $st->fetchColumn();
        if ($ts !== 'active') { rw_v5_out(false, null, 'device_revoked'); }
        // Chong lam 2 lan: khong tao lenh queued trung device+cmd khi da co
        $st = $db->prepare('SELECT id FROM device_commands WHERE device_id = ? AND cmd = ? AND status IN ("queued","sent","acked","working")');
        $st->execute(array($dev, $cmd));
        if ($st->fetchColumn()) { rw_v5_out(false, null, 'duplicate_command'); }
        $id = rw_db_v5_id('dcmd');
        $db->prepare('INSERT INTO device_commands(id,device_id,cmd,revision,created_by,reason,deadline,status,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute(array($id,$dev,$cmd,1,$_SESSION['rw_user'],$reason,$deadline,'queued',rw_db_v5_now()));
        rw_audit('command_create', array('id'=>$id,'cmd'=>$cmd));
        rw_v5_out(true, array('id'=>$id,'status'=>'queued'));
        break;
    case 'command_list':
        $dev = isset($_GET['device_id']) ? (string)$_GET['device_id'] : '';
        $args = array(); $w = '';
        if ($dev !== '') { $w = ' WHERE device_id = ?'; $args[] = $dev; }
        $st = $db->prepare('SELECT * FROM device_commands'.$w.' ORDER BY created_at DESC LIMIT 100'); $st->execute($args);
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'command_ack':
        // May bao nhan/hoan tat lenh (goi tu worker)
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $stt = isset($_POST['status']) ? (string)$_POST['status'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id) || !in_array($stt, vps_cmd_status(), true)) { rw_v5_out(false, null, 'bad_input'); }
        $st = $db->prepare('UPDATE device_commands SET status=?, ack_at=COALESCE(ack_at,?), done_at=CASE WHEN ? IN ("done","error") THEN ? ELSE done_at END, error=? WHERE id=?');
        $st->execute(array($stt, rw_db_v5_now(), $stt, rw_db_v5_now(), isset($_POST['error'])?$_POST['error']:'', $id));
        rw_v5_out(true, array('id'=>$id,'status'=>$stt));
        break;
    case 'command_cancel':
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';
        if (strlen($reason) < 3) { rw_v5_out(false, null, 'reason_required'); }
        $db->prepare('UPDATE device_commands SET status="expired" WHERE id=? AND status="queued"')->execute(array($id));
        rw_audit('command_cancel', array('id'=>$id,'reason'=>$reason));
        rw_v5_out(true, array('id'=>$id));
        break;

    /* ===== Tai nguyen (CPU/RAM/disk) ===== */
    case 'resource_report':
        $dev = isset($_POST['device_id']) ? (string)$_POST['device_id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $dev)) { rw_v5_out(false, null, 'bad_device'); }
        $db->prepare('INSERT INTO resource_samples(device_id,cpu,ram,disk,profiles_open,sessions,sampled_at) VALUES(?,?,?,?,?,?,?)')->execute(array($dev,(float)($_POST['cpu']??0),(float)($_POST['ram']??0),(float)($_POST['disk']??0),(int)($_POST['profiles_open']??0),(int)($_POST['sessions']??0),rw_db_v5_now()));
        rw_v5_out(true, array('device_id'=>$dev));
        break;
    case 'resource_get':
        $dev = isset($_GET['device_id']) ? (string)$_GET['device_id'] : '';
        $st = $db->prepare('SELECT cpu, ram, disk, profiles_open, sessions, sampled_at FROM resource_samples WHERE device_id = ? ORDER BY sampled_at DESC LIMIT 50'); $st->execute(array($dev));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // Mau cu hon 5 phut => hien thi khong xac dinh
        foreach ($rows as &$r) {
            if (strtotime($r['sampled_at']) < time() - 300) { $r['cpu'] = null; $r['ram'] = null; $r['disk'] = null; $r['stale'] = true; }
        }
        rw_v5_out(true, array('items'=>$rows));
        break;

    /* ===== Chuyen ho so giua may ===== */
    case 'transfer_create':
        $acc = isset($_POST['account_id']) ? (string)$_POST['account_id'] : '';
        $from = isset($_POST['from_device']) ? (string)$_POST['from_device'] : '';
        $to = isset($_POST['to_device']) ? (string)$_POST['to_device'] : '';
        if (!preg_match('/^[A-Za-z0-9_@.-]+$/', $acc) || !preg_match('/^[A-Za-z0-9_-]+$/', $from) || !preg_match('/^[A-Za-z0-9_-]+$/', $to)) { rw_v5_out(false, null, 'bad_input'); }
        if ($from === $to) { rw_v5_out(false, null, 'same_device'); }
        // Khong cho 2 may mo cung ho so: kiem ho so dang mo o may nao khac
        $st = $db->prepare('SELECT id FROM profile_transfers WHERE account_id = ? AND status NOT IN ("done","canceled")');
        $st->execute(array($acc));
        if ($st->fetchColumn()) { rw_v5_out(false, null, 'transfer_in_progress'); }
        $id = rw_db_v5_id('ptf');
        $db->prepare('INSERT INTO profile_transfers(id,account_id,from_device,to_device,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute(array($id,$acc,$from,$to,'requested',rw_db_v5_now(),rw_db_v5_now()));
        rw_audit('transfer_create', array('id'=>$id,'account_id'=>$acc));
        rw_v5_out(true, array('id'=>$id,'status'=>'requested'));
        break;
    case 'transfer_step':
        // Tien trinh: old_closed, data_checked, ack_new (tung buoc xac nhan)
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $step = isset($_POST['step']) ? (string)$_POST['step'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id) || !in_array($step, array('old_closed','data_checked','ack_new','done','canceled'), true)) { rw_v5_out(false, null, 'bad_input'); }
        if ($step === 'old_closed') { $db->prepare('UPDATE profile_transfers SET old_closed=1, status="closing", updated_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id)); }
        elseif ($step === 'data_checked') { $db->prepare('UPDATE profile_transfers SET data_checked=1, status="checked", updated_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id)); }
        elseif ($step === 'ack_new') {
            // chi xac nhan khi may cu da dong + du lieu da kiem
            $st = $db->prepare('SELECT old_closed, data_checked FROM profile_transfers WHERE id=?'); $st->execute(array($id)); $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t || !$t['old_closed'] || !$t['data_checked']) { rw_v5_out(false, null, 'prerequisites_not_met'); }
            $db->prepare('UPDATE profile_transfers SET ack_new=1, status="done", updated_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id));
        }
        elseif ($step === 'canceled') { $db->prepare('UPDATE profile_transfers SET status="canceled", updated_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id)); }
        rw_v5_out(true, array('id'=>$id,'step'=>$step));
        break;
    case 'transfer_list':
        $st = $db->query('SELECT * FROM profile_transfers ORDER BY created_at DESC LIMIT 50');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;

    default: rw_v5_out(false, null, 'unknown_action');
}
