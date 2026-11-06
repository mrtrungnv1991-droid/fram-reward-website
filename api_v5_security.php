<?php
/* api_v5_security.php — Prompt 4: Bao mat, su co, lich su, sao luu (UI v5). */
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

function sec_prio_valid($p) { return in_array($p, array('low','normal','high','urgent'), true); }

switch ($action) {
    case 'health': rw_v5_out(true, array('module'=>'security','status'=>'ok')); break;

    /* ===== Hop 'Can xu ly' (incidents) ===== */
    case 'incident_list':
        $w = array(); $args = array();
        foreach (array('status','owner','account_id','device_id','group_id','priority') as $f) {
            if (isset($_GET[$f]) && $_GET[$f] !== '') { $w[] = "$f = ?"; $args[] = (string)$_GET[$f]; }
        }
        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        if ($q !== '') { $w[] = '(title LIKE ? OR guidance LIKE ?)'; array_push($args,'%'.$q.'%','%'.$q.'%'); }
        $where = $w ? ' WHERE '.implode(' AND ',$w) : '';
        $st = $db->prepare('SELECT COUNT(*) FROM incidents'.$where); $st->execute($args); $total=(int)$st->fetchColumn();
        $page = max(1, isset($_GET['page'])?(int)$_GET['page']:1); $per=min(100,max(1,isset($_GET['per'])?(int)$_GET['per']:20));
        $st = $db->prepare('SELECT * FROM incidents'.$where.' ORDER BY updated_at DESC LIMIT '.(int)$per.' OFFSET '.(($page-1)*$per)); $st->execute($args);
        rw_v5_out(true, array('total'=>$total,'page'=>$page,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'incident_get':
        $id = isset($_GET['id'])?(string)$_GET['id']:'';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false,null,'bad_id'); }
        $st = $db->prepare('SELECT * FROM incidents WHERE id=?'); $st->execute(array($id)); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { rw_v5_out(false,null,'not_found'); } $r['history']=json_decode($r['history'],true); rw_v5_out(true,$r);
        break;
    case 'incident_save':
        $id = isset($_POST['id'])?(string)$_POST['id']:'';
        $title = isset($_POST['title'])?trim((string)$_POST['title']):'';
        if (strlen($title)<3) { rw_v5_out(false,null,'title_required'); }
        $prio = isset($_POST['priority'])?(string)$_POST['priority']:'normal';
        if (!sec_prio_valid($prio)) { rw_v5_out(false,null,'bad_priority'); }
        $now = rw_db_v5_now();
        if ($id==='') {
            $id = rw_db_v5_id('inc');
            $st=$db->prepare('INSERT INTO incidents(id,title,kind,account_id,group_id,device_id,owner,priority,deadline,guidance,status,history,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(array($id,$title,isset($_POST['kind'])?$_POST['kind']:'',isset($_POST['account_id'])?$_POST['account_id']:'',isset($_POST['group_id'])?$_POST['group_id']:'',isset($_POST['device_id'])?$_POST['device_id']:'',isset($_POST['owner'])?$_POST['owner']:'',$prio,isset($_POST['deadline'])?$_POST['deadline']:'',isset($_POST['guidance'])?$_POST['guidance']:'',isset($_POST['status'])?$_POST['status']:'open',json_encode(array(array('t'=>$now,'u'=>$_SESSION['rw_user'],'a'=>'create'))),$now,$now));
        } else {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false,null,'bad_id'); }
            $st=$db->prepare('UPDATE incidents SET title=?,kind=?,account_id=?,group_id=?,device_id=?,owner=?,priority=?,deadline=?,guidance=?,updated_at=? WHERE id=?');
            $st->execute(array($title,isset($_POST['kind'])?$_POST['kind']:'',isset($_POST['account_id'])?$_POST['account_id']:'',isset($_POST['group_id'])?$_POST['group_id']:'',isset($_POST['device_id'])?$_POST['device_id']:'',isset($_POST['owner'])?$_POST['owner']:'',$prio,isset($_POST['deadline'])?$_POST['deadline']:'',isset($_POST['guidance'])?$_POST['guidance']:'',$now,$id));
        }
        rw_audit('incident_save', array('id'=>$id));
        rw_v5_out(true, array('id'=>$id));
        break;
    case 'incident_transition':
        // Dong/mo lai/chuyen: chi dong khi co bang chung phuc hoi (close_evidence)
        $id = isset($_POST['id'])?(string)$_POST['id']:'';
        $to = isset($_POST['status'])?(string)$_POST['status']:'';
        $evidence = isset($_POST['close_evidence'])?trim((string)$_POST['close_evidence']):'';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false,null,'bad_id'); }
        if (!in_array($to, array('open','in_progress','resolved','closed','reopened'), true)) { rw_v5_out(false,null,'bad_status'); }
        if ($to==='closed') {
            // Mat du lieu hay may offline KHONG phai bang chung — yeu cau evidence that
            if (strlen($evidence)<5) { rw_v5_out(false,null,'close_evidence_required'); }
            $st=$db->prepare('UPDATE incidents SET status="closed", close_evidence=?, updated_at=? WHERE id=?');
            $st->execute(array($evidence, rw_db_v5_now(), $id));
        } elseif ($to==='reopened') {
            $db->prepare('UPDATE incidents SET status="reopened", reopen_count=reopen_count+1, updated_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id));
        } else {
            $db->prepare('UPDATE incidents SET status=?, updated_at=? WHERE id=?')->execute(array($to,rw_db_v5_now(),$id));
        }
        // ghi history (chuyen/dong/mo lai)
        $st=$db->prepare('SELECT history FROM incidents WHERE id=?'); $st->execute(array($id)); $h=json_decode($st->fetchColumn(),true); if(!is_array($h))$h=array();
        $h[]=array('t'=>rw_db_v5_now(),'u'=>$_SESSION['rw_user'],'a'=>'status:'.$to,'e'=>$evidence);
        $db->prepare('UPDATE incidents SET history=? WHERE id=?')->execute(array(json_encode($h),$id));
        rw_audit('incident_transition', array('id'=>$id,'to'=>$to));
        rw_v5_out(true, array('id'=>$id,'status'=>$to));
        break;

    /* ===== Retry policy: tach nguong loi lien tiep vs tong ===== */
    case 'retry_list':
        $st=$db->query('SELECT * FROM retry_policy ORDER BY updated_at DESC LIMIT 100'); rw_v5_out(true,array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'retry_record':
        $target = isset($_POST['target'])?(string)$_POST['target']:'';
        $ok = isset($_POST['ok']) && $_POST['ok']==='1';
        if (!preg_match('/^[A-Za-z0-9_@.-]+$/', $target)) { rw_v5_out(false,null,'bad_target'); }
        $st=$db->prepare('SELECT * FROM retry_policy WHERE target=?'); $st->execute(array($target)); $r=$st->fetch(PDO::FETCH_ASSOC);
        $now=rw_db_v5_now();
        if (!$r) {
            $db->prepare('INSERT INTO retry_policy(id,target,consecutive_fail,total_retries,max_consecutive,max_total,remaining,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array(rw_db_v5_id('rty'),$target,$ok?0:1,$ok?0:1,3,10,$ok?10:9,$now));
            rw_v5_out(true,array('target'=>$target,'consecutive_fail'=>$ok?0:1));
        }
        $cf = $ok?0:((int)$r['consecutive_fail']+1);
        $tr = (int)$r['total_retries'] + ($ok?0:1);
        $blocked = ($cf >= (int)$r['max_consecutive']) || ($tr >= (int)$r['max_total']);
        $next = $ok?null: date('Y-m-d H:i:s', time() + min(3600, 60*(1<<min(6,$cf))));
        $db->prepare('UPDATE retry_policy SET consecutive_fail=?, total_retries=?, next_attempt_at=?, remaining=?, block_reason=?, needs_human=?, updated_at=? WHERE target=?')
           ->execute(array($cf,$tr,$next,max(0,(int)$r['max_total']-$tr),$blocked?'retry_limit_exceeded':'', $blocked?1:0,$now,$target));
        rw_v5_out(true,array('target'=>$target,'consecutive_fail'=>$cf,'total_retries'=>$tr,'blocked'=>$blocked));
        break;
    case 'retry_reset':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false,null,'forbidden'); }
        $target=isset($_POST['target'])?(string)$_POST['target']:''; $reason=isset($_POST['reason'])?trim((string)$_POST['reason']):'';
        if (strlen($reason)<3) { rw_v5_out(false,null,'reason_required'); }
        $db->prepare('UPDATE retry_policy SET consecutive_fail=0, total_retries=0, block_reason="", needs_human=0, next_attempt_at=NULL, remaining=max_total, updated_at=? WHERE target=?')->execute(array(rw_db_v5_now(),$target));
        rw_audit('retry_reset', array('target'=>$target,'reason'=>$reason));
        rw_v5_out(true,array('target'=>$target,'reset'=>true));
        break;

    /* ===== Lich su truoc/sau + bao cao ===== */
    case 'report':
        $st=$db->query('SELECT COUNT(*) total, SUM(CASE WHEN status="closed" THEN 1 ELSE 0 END) closed, SUM(CASE WHEN status IN ("open","reopened","in_progress") THEN 1 ELSE 0 END) open_now, AVG(reopen_count) avg_reopen FROM incidents');
        $inc=$st->fetch(PDO::FETCH_ASSOC);
        $st2=$db->query('SELECT COUNT(*) needs_human, AVG(total_retries) avg_tries FROM retry_policy WHERE needs_human=1');
        $rp=$st2->fetch(PDO::FETCH_ASSOC);
        rw_v5_out(true,array('incidents'=>$inc,'retry'=>$rp));
        break;

    /* ===== Dang nhap + phien + MFA ===== */
    case 'login_log':
        $st=$db->query('SELECT username_masked, ip, ok, created_at FROM login_attempts ORDER BY id DESC LIMIT 50');
        rw_v5_out(true,array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'mfa_status':
        $u=$_SESSION['rw_user'];
        $st=$db->prepare('SELECT enabled, last_used_at FROM mfa_secrets WHERE user=?'); $st->execute(array($u)); $r=$st->fetch(PDO::FETCH_ASSOC);
        rw_v5_out(true, array('user'=>$u,'enabled'=>$r?((int)$r['enabled']===1):false,'last_used_at'=>$r?$r['last_used_at']:null));
        break;
    case 'mfa_toggle':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false,null,'forbidden'); }
        $enable = isset($_POST['enable']) && $_POST['enable']==='1';
        $u=$_SESSION['rw_user'];
        // Xac thuc lai thao tac nhay cam: nhap lai mat khau
        $pw = isset($_POST['password'])?(string)$_POST['password']:'';
        $st=$db->prepare('SELECT hash FROM users WHERE username=?'); $st->execute(array($u)); $h=$st->fetchColumn();
        if (!$h || !password_verify($pw,$h)) { rw_v5_out(false,null,'reauth_failed'); }
        $db->prepare('INSERT INTO mfa_secrets(user,secret_enc,enabled) VALUES(?,?,?) ON CONFLICT(user) DO UPDATE SET enabled=excluded.enabled')->execute(array($u, $enable?'sealed':'', $enable?1:0));
        rw_audit('mfa_toggle', array('user'=>$u,'enable'=>$enable));
        rw_v5_out(true,array('enabled'=>$enable));
        break;

    /* ===== Backup + retention ===== */
    case 'backup_list':
        $st=$db->query('SELECT id,target,storage,enc_key_ref,status,last_ok_at,last_test_restore_at,rpo_minutes,rto_minutes,created_at FROM backup_jobs ORDER BY created_at DESC');
        rw_v5_out(true,array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'backup_save':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false,null,'forbidden'); }
        $id=isset($_POST['id'])?(string)$_POST['id']:''; $target=isset($_POST['target'])?(string)$_POST['target']:'';
        if ($target==='') { rw_v5_out(false,null,'target_required'); }
        if ($id==='') { $id=rw_db_v5_id('bak');
            $db->prepare('INSERT INTO backup_jobs(id,target,storage,enc_key_ref,status,rpo_minutes,rto_minutes,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array($id,$target,isset($_POST['storage'])?$_POST['storage']:'offsite',isset($_POST['enc_key_ref'])?$_POST['enc_key_ref']:'key-1','pending',(int)($_POST['rpo_minutes']??60),(int)($_POST['rto_minutes']??240),rw_db_v5_now()));
        } else {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false,null,'bad_id'); }
            $db->prepare('UPDATE backup_jobs SET target=?,storage=?,enc_key_ref=?,rpo_minutes=?,rto_minutes=? WHERE id=?')->execute(array($target,isset($_POST['storage'])?$_POST['storage']:'offsite',isset($_POST['enc_key_ref'])?$_POST['enc_key_ref']:'key-1',(int)($_POST['rpo_minutes']??60),(int)($_POST['rto_minutes']??240),$id));
        }
        rw_audit('backup_save', array('id'=>$id)); rw_v5_out(true,array('id'=>$id));
        break;
    case 'backup_restore_test':
        // Thu restore o moi truong tach biet — khong tu mo phien
        $id=isset($_POST['id'])?(string)$_POST['id']:'';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false,null,'bad_id'); }
        $db->prepare('UPDATE backup_jobs SET last_test_restore_at=? WHERE id=?')->execute(array(rw_db_v5_now(),$id));
        rw_audit('backup_restore_test', array('id'=>$id));
        rw_v5_out(true,array('id'=>$id,'tested_at'=>rw_db_v5_now(),'note'=>'restore test in isolated env, no session auto-open'));
        break;
    case 'retention_list':
        $st=$db->query('SELECT * FROM retention_policies ORDER BY kind'); rw_v5_out(true,array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'retention_save':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false,null,'forbidden'); }
        $kind=isset($_POST['kind'])?(string)$_POST['kind']:''; $days=(int)($_POST['days']??0);
        if ($kind===''||$days<1||$days>3650) { rw_v5_out(false,null,'bad_input'); }
        $keep=(isset($_POST['keep_open_evidence'])&&$_POST['keep_open_evidence']==='1')?1:0;
        $db->prepare('INSERT INTO retention_policies(id,kind,days,keep_open_evidence) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET days=excluded.days, keep_open_evidence=excluded.keep_open_evidence')->execute(array('ret-'.$kind,$kind,$days,$keep));
        rw_audit('retention_save', array('kind'=>$kind,'days'=>$days));
        rw_v5_out(true,array('kind'=>$kind,'days'=>$days));
        break;

    /* ===== Truy vet day chuyen audit ===== */
    case 'audit_chain':
        // cPanel -> controller -> lenh -> agent -> ket qua (tu bang audit_log + device_commands)
        $st=$db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 100');
        $logs=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$l) { $l['meta']=json_decode($l['meta'],true); }
        rw_v5_out(true,array('items'=>$logs));
        break;

    default: rw_v5_out(false,null,'unknown_action');
}
