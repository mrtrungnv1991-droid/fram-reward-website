<?php
/* api_v5_tasks.php — Prompt 2: Danh muc nhiem vu + vong doi skill + fixture (UI v5). */
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

function task_status_valid($s) { return in_array($s, array('unrecognized','needs_human','has_draft','released'), true); }
function skill_status_valid($s) { return in_array($s, array('draft','struct_valid','fixture_tested','pending_approval','released','revoked'), true); }

switch ($action) {
    case 'health': rw_v5_out(true, array('module'=>'tasks','status'=>'ok')); break;

    /* ===== Danh muc nhiem vu ===== */
    case 'task_list':
        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $per = min(100, max(1, isset($_GET['per']) ? (int)$_GET['per'] : 20));
        $where = array(); $args = array();
        if ($q !== '') { $where[] = '(code LIKE ? OR kind LIKE ? OR skill_code LIKE ?)'; array_push($args, '%'.$q.'%', '%'.$q.'%', '%'.$q.'%'); }
        if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
        $w = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $st = $db->prepare('SELECT COUNT(*) FROM task_catalog'.$w); $st->execute($args); $total = (int)$st->fetchColumn();
        $st = $db->prepare('SELECT * FROM task_catalog'.$w.' ORDER BY code LIMIT '.(int)$per.' OFFSET '.(($page-1)*$per)); $st->execute($args);
        rw_v5_out(true, array('total'=>$total,'page'=>$page,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'task_get':
        $code = isset($_GET['code']) ? (string)$_GET['code'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $code)) { rw_v5_out(false, null, 'bad_code'); }
        $st = $db->prepare('SELECT * FROM task_catalog WHERE code = ?'); $st->execute(array($code)); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { rw_v5_out(false, null, 'not_found'); } rw_v5_out(true, $r);
        break;
    case 'task_save':
        $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $code)) { rw_v5_out(false, null, 'bad_code'); }
        $kind = isset($_POST['kind']) ? (string)$_POST['kind'] : '';
        if ($kind === '') { rw_v5_out(false, null, 'kind_required'); }
        $status = isset($_POST['status']) ? (string)$_POST['status'] : 'unrecognized';
        if (!task_status_valid($status)) { rw_v5_out(false, null, 'bad_status'); }
        $ev = isset($_POST['evidence']) ? (string)$_POST['evidence'] : '';
        $evh = sha1($ev);
        // Chong trung theo nguon/bang chung: cung evhash + khac code => duplicate
        $st = $db->prepare('SELECT code FROM task_catalog WHERE evidence_hash = ? AND evidence != "" AND code != ?');
        $st->execute(array($evh, $code));
        if ($st->fetchColumn()) { rw_v5_out(false, null, 'duplicate_evidence'); }
        $now = rw_db_v5_now();
        $st = $db->prepare('INSERT INTO task_catalog(code,kind,market,language,conditions,evidence,status,sensitivity,skill_code,source,evidence_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(code) DO UPDATE SET kind=excluded.kind, market=excluded.market, language=excluded.language, conditions=excluded.conditions, evidence=excluded.evidence, status=excluded.status, sensitivity=excluded.sensitivity, skill_code=excluded.skill_code, evidence_hash=excluded.evidence_hash, updated_at=excluded.updated_at');
        $st->execute(array($code,$kind,isset($_POST['market'])?$_POST['market']:'',isset($_POST['language'])?$_POST['language']:'',isset($_POST['conditions'])?$_POST['conditions']:'',$ev,$status,isset($_POST['sensitivity'])?$_POST['sensitivity']:'normal',isset($_POST['skill_code'])?$_POST['skill_code']:'manual',$evh,$now,$now));
        rw_audit('task_save', array('code'=>$code));
        rw_v5_out(true, array('code'=>$code,'status'=>$status));
        break;

    /* ===== Skill versions (bat bien, semver) ===== */
    case 'skill_list':
        $st = $db->query('SELECT skill_code, MAX(created_at) AS last FROM skill_versions GROUP BY skill_code ORDER BY skill_code');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'skill_versions':
        $sc = isset($_GET['skill_code']) ? (string)$_GET['skill_code'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $sc)) { rw_v5_out(false, null, 'bad_code'); }
        $st = $db->prepare('SELECT id, skill_code, semver, status, origin, tester, tested_at, approver, approved_at, created_at FROM skill_versions WHERE skill_code = ? ORDER BY created_at DESC');
        $st->execute(array($sc));
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'skill_get':
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('SELECT * FROM skill_versions WHERE id = ?'); $st->execute(array($id)); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { rw_v5_out(false, null, 'not_found'); } rw_v5_out(true, $r);
        break;
    case 'skill_create':
        // Tao ban moi (sua = tao ban moi, luon bat bien)
        $sc = isset($_POST['skill_code']) ? (string)$_POST['skill_code'] : '';
        $semver = isset($_POST['semver']) ? (string)$_POST['semver'] : '';
        $body = isset($_POST['body']) ? (string)$_POST['body'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $sc)) { rw_v5_out(false, null, 'bad_code'); }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $semver)) { rw_v5_out(false, null, 'bad_semver'); }
        if (trim($body) === '') { rw_v5_out(false, null, 'body_required'); }
        // semver phai tang: khong trung ban da co
        $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_code = ? AND semver = ?');
        $st->execute(array($sc, $semver));
        if ($st->fetchColumn()) { rw_v5_out(false, null, 'semver_exists'); }
        // tinh diff so voi ban gan nhat
        $st = $db->prepare('SELECT body FROM skill_versions WHERE skill_code = ? ORDER BY created_at DESC LIMIT 1');
        $st->execute(array($sc)); $prev = $st->fetchColumn();
        $diff = $prev === false ? '(ban dau)' : substr($prev, 0, 200).' => '.substr($body, 0, 200);
        $id = rw_db_v5_id('skv');
        // struct_valid do kiem tra cau truc don gian: co it nhat 1 buoc
        $struct_ok = (strpos($body, 'step') !== false || strpos($body, 'bước') !== false || strlen($body) > 30) ? 1 : 0;
        $status = $struct_ok ? 'struct_valid' : 'draft';
        $st = $db->prepare('INSERT INTO skill_versions(id,skill_code,semver,body,diff,origin,origin_ref,status,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute(array($id,$sc,$semver,$body,$diff,isset($_POST['origin'])?$_POST['origin']:'human',isset($_POST['origin_ref'])?$_POST['origin_ref']:'',$status,rw_db_v5_now()));
        rw_audit('skill_create', array('skill_code'=>$sc,'semver'=>$semver));
        rw_v5_out(true, array('id'=>$id,'status'=>$status,'semver'=>$semver));
        break;
    case 'skill_approve':
        // Duyet: chi khi co fixture run PASS gan dung version
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (strlen($reason) < 3) { rw_v5_out(false, null, 'reason_required'); }
        $st = $db->prepare('SELECT status FROM skill_versions WHERE id = ?'); $st->execute(array($id)); $cur = $st->fetchColumn();
        if (!$cur) { rw_v5_out(false, null, 'not_found'); }
        if ($cur !== 'fixture_tested') { rw_v5_out(false, null, 'need_fixture_test'); }
        $st = $db->prepare('UPDATE skill_versions SET status="released", approver=?, approved_at=?, approve_reason=? WHERE id=?');
        $st->execute(array($_SESSION['rw_user'], rw_db_v5_now(), $reason, $id));
        rw_audit('skill_approve', array('id'=>$id));
        rw_v5_out(true, array('id'=>$id,'status'=>'released'));
        break;
    case 'skill_revoke':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (strlen($reason) < 3) { rw_v5_out(false, null, 'reason_required'); }
        $st = $db->prepare('UPDATE skill_versions SET status="revoked" WHERE id=?');
        $st->execute(array($id));
        // Chan luot moi: cap nhat rollout revoked
        $st2 = $db->prepare('SELECT skill_code, semver FROM skill_versions WHERE id=?'); $st2->execute(array($id)); $sv = $st2->fetch(PDO::FETCH_ASSOC);
        if ($sv) { $db->prepare('INSERT INTO skill_rollouts(id,skill_code,semver,ring,action,reason,actor,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array(rw_db_v5_id('rol'),$sv['skill_code'],$sv['semver'],'canary','revoke',$reason,$_SESSION['rw_user'],rw_db_v5_now())); }
        rw_audit('skill_revoke', array('id'=>$id,'reason'=>$reason));
        rw_v5_out(true, array('id'=>$id,'status'=>'revoked'));
        break;
    case 'skill_rollback':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $sc = isset($_POST['skill_code']) ? (string)$_POST['skill_code'] : '';
        $to_ver = isset($_POST['to_semver']) ? (string)$_POST['to_semver'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $sc) || !preg_match('/^\d+\.\d+\.\d+$/', $to_ver)) { rw_v5_out(false, null, 'bad_input'); }
        // Chi quay lui ve ban tung duoc duyet (released truoc do)
        $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_code=? AND semver=? AND status="released"');
        $st->execute(array($sc, $to_ver));
        if (!$st->fetchColumn()) { rw_v5_out(false, null, 'not_approved_version'); }
        $db->prepare('INSERT INTO skill_rollouts(id,skill_code,semver,ring,action,reason,actor,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array(rw_db_v5_id('rol'),$sc,$to_ver,'stable','rollback','rollback to approved version',$_SESSION['rw_user'],rw_db_v5_now()));
        rw_audit('skill_rollback', array('skill_code'=>$sc,'to'=>$to_ver));
        rw_v5_out(true, array('skill_code'=>$sc,'active'=>$to_ver));
        break;

    /* ===== Fixture + chay thu ===== */
    case 'fixture_save':
        $sc = isset($_POST['skill_code']) ? (string)$_POST['skill_code'] : '';
        $name = isset($_POST['name']) ? (string)$_POST['name'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $sc) || $name === '') { rw_v5_out(false, null, 'bad_input'); }
        $id = rw_db_v5_id('fix');
        $st = $db->prepare('INSERT INTO skill_fixtures(id,skill_code,name,preconditions,steps,postconditions,evidence,failure_modes,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute(array($id,$sc,$name,isset($_POST['preconditions'])?$_POST['preconditions']:'',isset($_POST['steps'])?$_POST['steps']:'',isset($_POST['postconditions'])?$_POST['postconditions']:'',isset($_POST['evidence'])?$_POST['evidence']:'',isset($_POST['failure_modes'])?$_POST['failure_modes']:'',rw_db_v5_now()));
        rw_v5_out(true, array('id'=>$id));
        break;
    case 'fixture_list':
        $sc = isset($_GET['skill_code']) ? (string)$_GET['skill_code'] : '';
        $st = $db->prepare('SELECT * FROM skill_fixtures WHERE skill_code = ? ORDER BY created_at'); $st->execute(array($sc));
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'fixture_run':
        // No fixture runner is connected to this endpoint. Never mint a PASS from a form POST.
        rw_v5_out(false, null, 'fixture_runner_unavailable');
        break;
    case 'fixture_runs':
        $vid = isset($_GET['skill_version_id']) ? (string)$_GET['skill_version_id'] : '';
        $st = $db->prepare('SELECT * FROM fixture_runs WHERE skill_version_id = ? ORDER BY created_at DESC'); $st->execute(array($vid));
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;

    /* ===== Rollout (canary/stable) ===== */
    case 'rollout':
        $sc = isset($_POST['skill_code']) ? (string)$_POST['skill_code'] : '';
        $semver = isset($_POST['semver']) ? (string)$_POST['semver'] : '';
        $ring = isset($_POST['ring']) ? (string)$_POST['ring'] : 'canary';
        $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $sc) || !preg_match('/^\d+\.\d+\.\d+$/', $semver)) { rw_v5_out(false, null, 'bad_input'); }
        if (!in_array($ring, array('canary','stable'), true)) { rw_v5_out(false, null, 'bad_ring'); }
        // Chi phat hanh ban da duoc duyet (released)
        $st = $db->prepare('SELECT id FROM skill_versions WHERE skill_code=? AND semver=? AND status="released"');
        $st->execute(array($sc,$semver));
        if (!$st->fetchColumn()) { rw_v5_out(false, null, 'not_released'); }
        $db->prepare('INSERT INTO skill_rollouts(id,skill_code,semver,ring,action,reason,actor,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array(rw_db_v5_id('rol'),$sc,$semver,$ring,'deploy',$reason,$_SESSION['rw_user'],rw_db_v5_now()));
        rw_audit('skill_rollout', array('skill_code'=>$sc,'semver'=>$semver,'ring'=>$ring));
        rw_v5_out(true, array('skill_code'=>$sc,'semver'=>$semver,'ring'=>$ring));
        break;
    case 'rollout_list':
        $sc = isset($_GET['skill_code']) ? (string)$_GET['skill_code'] : '';
        $st = $db->prepare('SELECT * FROM skill_rollouts WHERE skill_code = ? ORDER BY created_at DESC'); $st->execute(array($sc));
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;

    default: rw_v5_out(false, null, 'unknown_action');
}
