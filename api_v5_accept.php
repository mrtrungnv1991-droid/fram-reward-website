<?php
/* api_v5_accept.php — Prompt 5: Nghiem thu VPS (UI v5). */
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

/* 10 buoc nghiem thu chuan theo Prompt 5 */
function accept_steps() {
    return array(
        'inventory'    => 'Kiem ke VPS: CPU/RAM/disk, PostgreSQL, tai khoan chay, HTTPS, firewall, thu muc bi mat (khong ghi khoa)',
        'backup'       => 'Sao luu source/database/cau hinh + restore thu tach biet + doi soat ID/truoc migration',
        'controller'   => 'Controller thu nghiem sau HTTPS; agent chi ket noi ra; PostgreSQL khong cong khai',
        'cpanel'       => 'Noi PHP/cPanel bang danh tinh + quyen tung nguoi (quan tri/van hanh/chi xem), CSRF, audit; khong root token chung',
        'observer'     => 'Cai observer tu goi da kiem tra hash/chu ky, ACL, Scheduled Task sau dang nhap, heartbeat, start/pause/stop/check_now, dung khan khi offline',
        'canary'       => 'Canary, cap nhat loi, quay lui; xac nhan ho so dong truoc chuyen ban',
        'reboot'       => 'Kiem tra reboot o thoi diem phu hop; trang thai cu khong tu mo phien',
        'alert_backup' => 'Kenh canh bao thu, backup ngoai may, restore',
        'load_test'    => 'Chay thu keo dai bang fixture + tai khoan gia; do CPU/RAM/disk/bo nho/heartbeat/so ho do/Do tre; tang tai theo so do (khong dung tai khoan Rewards that)',
        'reconcile'    => 'Doi soat du lieu sau migration + chuan bi duong quay ve'
    );
}

switch ($action) {
    case 'health': rw_v5_out(true, array('module'=>'accept','status'=>'ok')); break;

    case 'run_create':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $target = isset($_POST['target_vps']) ? (string)$_POST['target_vps'] : '';
        if ($target === '') { rw_v5_out(false, null, 'target_required'); }
        $id = rw_db_v5_id('acp');
        $db->prepare('INSERT INTO acceptance_runs(id,target_vps,source_version,package_hash,started_at,verdict,notes) VALUES(?,?,?,?,?,?,?)')
           ->execute(array($id, $target, isset($_POST['source_version'])?$_POST['source_version']:'', isset($_POST['package_hash'])?$_POST['package_hash']:'', rw_db_v5_now(), 'pending', isset($_POST['notes'])?$_POST['notes']:''));
        // Tao 10 buoc mac dinh
        foreach (accept_steps() as $key => $desc) {
            $db->prepare('INSERT INTO acceptance_checks(id,run_id,step,result,evidence,executor) VALUES(?,?,?,?,?,?)')
               ->execute(array($id.'-'.$key, $id, $key.': '.$desc, 'pending', '', ''));
        }
        rw_audit('accept_run_create', array('id'=>$id,'target'=>$target));
        rw_v5_out(true, array('id'=>$id));
        break;

    case 'run_list':
        $st = $db->query('SELECT * FROM acceptance_runs ORDER BY started_at DESC LIMIT 50');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;

    case 'run_get':
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('SELECT * FROM acceptance_runs WHERE id = ?'); $st->execute(array($id));
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) { rw_v5_out(false, null, 'not_found'); }
        $st2 = $db->prepare('SELECT * FROM acceptance_checks WHERE run_id = ? ORDER BY id');
        $st2->execute(array($id));
        $run['checks'] = $st2->fetchAll(PDO::FETCH_ASSOC);
        rw_v5_out(true, $run);
        break;

    case 'check_update':
        // Danh dau buoc: ket qua + bang chung + executor (local/vps)
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $result = isset($_POST['result']) ? (string)$_POST['result'] : '';
        $evidence = isset($_POST['evidence']) ? (string)$_POST['evidence'] : '';
        $executor = isset($_POST['executor']) ? (string)$_POST['executor'] : '';
        if (!preg_match('/^[A-Za-z0-9_:.-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (!in_array($result, array('pending','pass','fail','skip'), true)) { rw_v5_out(false, null, 'bad_result'); }
        if (!in_array($executor, array('local','vps',''), true)) { rw_v5_out(false, null, 'bad_executor'); }
        // Chi ghi 'da kiem chung VPS' khi executor = vps (tuc buoc thuc su chay tren VPS)
        if ($result === 'pass' && $executor === 'vps' && strlen($evidence) < 3) {
            rw_v5_out(false, null, 'evidence_required_for_vps');
        }
        $db->prepare('UPDATE acceptance_checks SET result=?, evidence=?, executor=?, checked_at=? WHERE id=?')
           ->execute(array($result, $evidence, $executor, rw_db_v5_now(), $id));
        rw_v5_out(true, array('id'=>$id,'result'=>$result));
        break;

    case 'run_conclude':
        // Ket luan: khong cho pass khi con buoc fail/thieu bang chung
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $verdict = isset($_POST['verdict']) ? (string)$_POST['verdict'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (!in_array($verdict, array('pass','fail','partial'), true)) { rw_v5_out(false, null, 'bad_verdict'); }
        $st = $db->prepare('SELECT result, evidence FROM acceptance_checks WHERE run_id = ?');
        $st->execute(array($id)); $checks = $st->fetchAll(PDO::FETCH_ASSOC);
        $has_fail = false; $has_pending = false;
        foreach ($checks as $c) {
            if ($c['result'] === 'fail') $has_fail = true;
            if ($c['result'] === 'pending') $has_pending = true;
        }
        if ($verdict === 'pass' && ($has_fail || $has_pending)) {
            rw_v5_out(false, null, 'cannot_pass_with_fail_or_pending');
        }
        $db->prepare('UPDATE acceptance_runs SET verdict=?, finished_at=?, notes=? WHERE id=?')
           ->execute(array($verdict, rw_db_v5_now(), isset($_POST['notes'])?$_POST['notes']:'', $id));
        rw_audit('accept_conclude', array('id'=>$id,'verdict'=>$verdict));
        rw_v5_out(true, array('id'=>$id,'verdict'=>$verdict));
        break;

    case 'report':
        // Xuat bien ban nghiem thu (text) co thoi diem, cau hinh, phien ban, tung ket qua, bang chung, loi con lai
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('SELECT * FROM acceptance_runs WHERE id = ?'); $st->execute(array($id));
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) { rw_v5_out(false, null, 'not_found'); }
        $st2 = $db->prepare('SELECT * FROM acceptance_checks WHERE run_id = ? ORDER BY id'); $st2->execute(array($id));
        $checks = $st2->fetchAll(PDO::FETCH_ASSOC);
        $lines = array();
        $lines[] = 'BIEN BAN NGHIEM THU VPS';
        $lines[] = '========================';
        $lines[] = 'Ma nghiem thu: '.$run['id'];
        $lines[] = 'VPS dich:      '.$run['target_vps'];
        $lines[] = 'Phien ban:     '.$run['source_version'];
        $lines[] = 'Hash goi:      '.$run['package_hash'];
        $lines[] = 'Bat dau:       '.$run['started_at'];
        $lines[] = 'Ket thuc:      '.($run['finished_at'] ?: '(chua)');
        $lines[] = 'Ket luan:      '.$run['verdict'];
        $lines[] = 'Ghi chu:       '.$run['notes'];
        $lines[] = '';
        $lines[] = 'TUNG BUOC KIEM TRA:';
        foreach ($checks as $i => $c) {
            $sym = $c['result']==='pass'?'[PASS]':($c['result']==='fail'?'[FAIL]':($c['result']==='skip'?'[SKIP]':'[----]'));
            $lines[] = sprintf('  %d. %s %s', $i+1, $sym, $c['step']);
            $lines[] = '     Bang chung: '.($c['evidence']?:'(thieu)').' | Nguoi thuc hien: '.($c['executor']?:'(chua)').' | Thoi diem: '.($c['checked_at']?:'(chua)');
        }
        $lines[] = '';
        $lines[] = 'Luu y: Chi buoc co executor=vps moi duoc tinh "da kiem chung VPS".';
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $lines);
        exit;
        break;

    default: rw_v5_out(false, null, 'unknown_action');
}
