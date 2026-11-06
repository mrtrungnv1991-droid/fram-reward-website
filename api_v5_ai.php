<?php
/* api_v5_ai.php — Prompt 1: Hang cho AI ho tro (UI v5). */
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

function ai_filter($s) {
    $s = (string)$s;
    // Loai mat khau, cookie, token, PII truoc khi gui AI
    $s = preg_replace('/(password|passwd|pwd)\s*[=:]\s*\S+/i', '$1=***', $s);
    $s = preg_replace('/(cookie|set-cookie)\s*[=:]\s*[^\n;]+/i', '$1=***', $s);
    $s = preg_replace('/(token|secret|api[_-]?key|authorization|bearer)\s*[=:]\s*\S+/i', '$1=***', $s);
    $s = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '***@***', $s);
    $s = preg_replace('/\+?\d{2,3}[\s.-]?\d{3}[\s.-]?\d{3,4}/', '***', $s);
    return $s;
}
function ai_budget_hold($db, $amount) {
    // Giu cho ngan sach bang transaction; khong vuot planned
    $db->beginTransaction();
    $st = $db->query("SELECT id, planned, held, actual FROM ai_budget WHERE scope='global' ORDER BY id LIMIT 1");
    $b = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$b) {
        $db->exec("INSERT INTO ai_budget(scope,currency,planned,held,actual,updated_at) VALUES('global','USD',0,0,0,'".rw_db_v5_now()."')");
        $b = array('id'=>$db->lastInsertId(),'planned'=>0,'held'=>0,'actual'=>0);
    }
    if ((float)$b['held'] + $amount > (float)$b['planned']) { $db->rollBack(); return false; }
    $st2 = $db->prepare('UPDATE ai_budget SET held = held + ?, updated_at = ? WHERE id = ?');
    $st2->execute(array($amount, rw_db_v5_now(), $b['id']));
    $db->commit();
    return true;
}
function ai_nim_provider($req) {
    // NIM provider THAT: z-ai/glm-5.3-flash — key luu ngoai public_html (secure/nim_key.php)
    $key_file = dirname(__DIR__) . '/secure/nim_key.php';
    if (!file_exists($key_file)) return array('ok'=>false,'error'=>'no_api_key');
    $key = include $key_file;
    if (!is_string($key) || $key === '') return array('ok'=>false,'error'=>'no_api_key');
    $system = 'Ban la tro ly AI cua he thong Rewards Farm Manager. Nhiem vu: tra loi cau hoi, huong dan viet quy trinh farm Rewards da tai khoan ro rang theo tung buoc, khuyen nghi loi giai deterministic. Tra loi tieng Viet, ngan gon, dung chuyen mon.';
    $user = isset($req['filtered_input']) ? $req['filtered_input'] : ai_filter($req['reason']);
    $payload = json_encode(array(
        'model' => 'z-ai/glm-5.3-flash',
        'messages' => array(array('role'=>'system','content'=>$system), array('role'=>'user','content'=>$user)),
        'max_tokens' => 2048,
        'temperature' => 0.4
    ));
    $t0 = microtime(true);
    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $key, 'Content-Type: application/json'),
        CURLOPT_POSTFIELDS => $payload
    ));
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $latency = (int)round((microtime(true) - $t0) * 1000);
    if ($resp === false || $http !== 200) return array('ok'=>false,'error'=>'http_'.$http.' '.$err);
    $j = json_decode($resp, true);
    if (!$j || !isset($j['choices'][0]['message'])) return array('ok'=>false,'error'=>'bad_response');
    $msg = $j['choices'][0]['message'];
    $content = isset($msg['content']) ? $msg['content'] : null;
    if ($content === null || trim($content) === '') {
        // Mo hinh reasoning: content null khi het token — dung reasoning_content lam cau tra loi
        $content = isset($msg['reasoning_content']) ? $msg['reasoning_content'] : '';
    }
    if (trim($content) === '') return array('ok'=>false,'error'=>'empty_completion');
    $usage = isset($j['usage']) ? $j['usage'] : array('prompt_tokens'=>0,'completion_tokens'=>0);
    return array(
        'ok' => true,
        'model' => $j['model'],
        'latency_ms' => $latency,
        'tokens_in' => (int)$usage['prompt_tokens'],
        'tokens_out' => (int)$usage['completion_tokens'],
        'cost' => 0.0,
        'output' => json_encode(array('answer' => $content, 'reasoning' => isset($msg['reasoning_content']) ? true : false), JSON_UNESCAPED_UNICODE)
    );
}

switch ($action) {
    case 'health':
        rw_v5_out(true, array('module'=>'ai','status'=>'ok','test_mode'=>getenv('RW_V5K_TEST_MODE')==='1'));
        break;
    case 'list':
        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $per = min(100, max(1, isset($_GET['per']) ? (int)$_GET['per'] : 20));
        $where = array(); $args = array();
        if ($q !== '') { $where[] = '(reason LIKE ? OR expected_result LIKE ?)'; array_push($args, '%'.$q.'%', '%'.$q.'%'); }
        if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
        $w = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $st = $db->prepare('SELECT COUNT(*) FROM ai_requests'.$w);
        $st->execute($args); $total = (int)$st->fetchColumn();
        $st = $db->prepare('SELECT id, requester, kind, reason, status, model, latency_ms, tokens_in, tokens_out, budget_actual, error_class, tries, reviewer, review_action, created_at, updated_at FROM ai_requests'.$w.' ORDER BY created_at DESC LIMIT '.(int)$per.' OFFSET '.(($page-1)*$per));
        $st->execute($args);
        rw_v5_out(true, array('total'=>$total,'page'=>$page,'per'=>$per,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'get':
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('SELECT * FROM ai_requests WHERE id = ?');
        $st->execute(array($id)); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { rw_v5_out(false, null, 'not_found'); }
        // khong tra filtered_input/output_raw day du neu khong quyen admin
        if (!rw_v5_role_ok('admin')) { unset($r['filtered_input']); }
        rw_v5_out(true, $r);
        break;
    case 'create':
        $kind = isset($_POST['kind']) ? (string)$_POST['kind'] : '';
        // Normalize kind: chấp nhận cả tiếng Việt lẫn English
        $kind_map = array('gợi ý'=>'suggest','phan tich'=>'analyze','phân tích'=>'analyze','rut gon'=>'distill','rút gọn'=>'distill','kiem duyet'=>'review','kiểm duyệt'=>'review','suggest'=>'suggest','analyze'=>'analyze','distill'=>'distill','review'=>'review');
        $kind_key = strtolower(trim($kind));
        $kind = isset($kind_map[$kind_key]) ? $kind_map[$kind_key] : $kind;
        if ($kind === '') { $kind = 'suggest'; } // mac dinh khi thieu kind
        $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
        $expected = isset($_POST['expected_result']) ? (string)$_POST['expected_result'] : '';
        $link_type = isset($_POST['link_type']) ? (string)$_POST['link_type'] : '';
        $link_id = isset($_POST['link_id']) ? (string)$_POST['link_id'] : '';
        if (!in_array($kind, array('suggest','analyze','distill','review'), true)) { rw_v5_out(false, null, 'bad_kind:'.gettype($kind)); }
        if (strlen($reason) < 5) { rw_v5_out(false, null, 'reason_required'); }
        // Chong trung theo bang chung (dedupe_key = kind + link + ly do)
        $dedupe = sha1($kind.'|'.$link_type.'|'.$link_id.'|'.$reason);
        $st = $db->prepare('SELECT id FROM ai_requests WHERE dedupe_key = ? AND status NOT IN ("error","canceled")');
        $st->execute(array($dedupe));
        if ($st->fetchColumn()) { rw_v5_out(false, null, 'duplicate'); }
        $budget = 1.0; // du tru mac dinh
        if (!ai_budget_hold($db, $budget)) { rw_v5_out(false, null, 'budget_exceeded'); }
        $id = rw_db_v5_id('ai');
        $filtered = ai_filter($reason."\n".$expected);
        $st = $db->prepare('INSERT INTO ai_requests(id,requester,kind,reason,expected_result,link_type,link_id,status,dedupe_key,budget_est,budget_held,filtered_input,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(array($id, $_SESSION['rw_user'], $kind, $reason, $expected, $link_type, $link_id, 'pending', $dedupe, $budget, $budget, $filtered, rw_db_v5_now(), rw_db_v5_now()));
        rw_v5_out(true, array('id'=>$id,'status'=>'pending'));
        break;
    case 'process':
        // Goi nha cung cap that: chi quan tri vien duoc phep kich hoat.
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('SELECT * FROM ai_requests WHERE id = ?');
        $st->execute(array($id)); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { rw_v5_out(false, null, 'not_found'); }
        if ($r['status'] !== 'pending') { rw_v5_out(false, null, 'not_pending'); }
        if ((int)$r['tries'] >= 3) { rw_v5_out(false, null, 'max_tries'); }
        $claim = $db->prepare('UPDATE ai_requests SET status="processing", updated_at=? WHERE id=? AND status="pending"');
        $claim->execute(array(rw_db_v5_now(), $id));
        if ($claim->rowCount() !== 1) { rw_v5_out(false, null, 'already_claimed'); }
        $res = ai_nim_provider($r);
        if ($res['ok']) {
            $ok_schema = json_decode($res['output'], true) !== null ? 1 : 0;
            $db->prepare('UPDATE ai_requests SET status=?, model=?, latency_ms=?, tokens_in=?, tokens_out=?, budget_actual=?, output_raw=?, output_schema_ok=?, tries=tries+1, error_class=?, updated_at=? WHERE id=?')
               ->execute(array($ok_schema ? 'proposed' : 'needs_more', $res['model'], $res['latency_ms'], $res['tokens_in'], $res['tokens_out'], $res['cost'], $res['output'], $ok_schema, $ok_schema ? '' : 'bad_schema', rw_db_v5_now(), $id));
            rw_v5_out(true, array('id'=>$id,'status'=>$ok_schema?'proposed':'needs_more','model'=>$res['model']));
        } else {
            $db->prepare('UPDATE ai_requests SET status="error", error_class=?, tries=tries+1, updated_at=? WHERE id=?')
               ->execute(array(isset($res['error']) ? substr($res['error'], 0, 100) : 'provider_unavailable', rw_db_v5_now(), $id));
            rw_v5_out(false, null, 'provider_unavailable');
        }
        break;
    case 'cancel':
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        $st = $db->prepare('UPDATE ai_requests SET status="canceled", updated_at=? WHERE id=? AND status IN ("pending","needs_more","processing")');
        $st->execute(array(rw_db_v5_now(), $id));
        rw_audit('ai_cancel', array('id'=>$id));
        rw_v5_out(true, array('id'=>$id,'canceled'=>$st->rowCount()>0));
        break;
    case 'review':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $act = isset($_POST['review_action']) ? (string)$_POST['review_action'] : '';
        $rreason = isset($_POST['review_reason']) ? (string)$_POST['review_reason'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (!in_array($act, array('accept','amend','reject'), true)) { rw_v5_out(false, null, 'bad_action'); }
        $st = $db->prepare('UPDATE ai_requests SET reviewer=?, review_action=?, review_reason=?, updated_at=? WHERE id=?');
        $st->execute(array($_SESSION['rw_user'], $act, $rreason, rw_db_v5_now(), $id));
        rw_audit('ai_review', array('id'=>$id,'action'=>$act));
        rw_v5_out(true, array('id'=>$id,'review_action'=>$act));
        break;
    case 'budget':
        $st = $db->query('SELECT scope, currency, planned, held, actual FROM ai_budget ORDER BY id');
        rw_v5_out(true, array('items'=>$st->fetchAll(PDO::FETCH_ASSOC)));
        break;
    case 'budget_set':
        if (!rw_v5_role_ok('admin')) { rw_v5_out(false, null, 'forbidden'); }
        $planned = isset($_POST['planned']) ? (float)$_POST['planned'] : -1;
        if ($planned < 0 || $planned > 100000) { rw_v5_out(false, null, 'bad_planned'); }
        $st = $db->prepare('UPDATE ai_budget SET planned=?, updated_at=? WHERE scope="global"');
        $st->execute(array($planned, rw_db_v5_now()));
        if (!$st->rowCount()) { $db->prepare('INSERT INTO ai_budget(scope,currency,planned,held,actual,updated_at) VALUES("global","USD",?,0,0,?)')->execute(array($planned, rw_db_v5_now())); }
        rw_audit('ai_budget_set', array('planned'=>$planned));
        rw_v5_out(true, array('planned'=>$planned));
        break;
    default:
        rw_v5_out(false, null, 'unknown_action');
}
