<?php
/* api_v5_relay.php — AI Relay gateway (queue deterministic).
 * Auth: header X-Relay-Token khop voi /secure/relay_token.php.
 * action=pull   -> lay job cho (pending/queued/processing, tries<3), danh dau processing
 * action=done   -> nhan ket qua {id, ok, model, latency_ms, tokens_in, tokens_out, output, error_class}
 * action=health
 * Mo hinh: hosting CHI giu queue; AI worker ngoai (container) goi NIM GLM-5.3 roi push ket qua.
 */
require_once __DIR__ . '/db_v5.php';

$tok_file = dirname(__DIR__) . '/secure/relay_token.php';
$want = file_exists($tok_file) ? include $tok_file : '';
$got = isset($_SERVER['HTTP_X_RELAY_TOKEN']) ? (string)$_SERVER['HTTP_X_RELAY_TOKEN'] : '';
if (!is_string($want) || $want === '' || $got === '' || !hash_equals($want, $got)) {
    rw_v5_out(false, null, 'unauthorized');
}
rw_db_v5_schema();
$db = rw_db();
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

function relay_body() {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : (is_array($_POST) ? $_POST : array());
}

switch ($action) {
    case 'health':
        rw_v5_out(true, array('module' => 'relay', 'status' => 'ok'));
        break;
    case 'pull':
        $st = $db->query("SELECT id, kind, reason, expected_result, filtered_input, tries, status FROM ai_requests WHERE status IN ('pending','queued','processing') AND tries < 3 ORDER BY created_at ASC LIMIT 5");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $up = $db->prepare('UPDATE ai_requests SET status="processing", updated_at=? WHERE id=?');
        foreach ($rows as $r) { $up->execute(array(rw_db_v5_now(), $r['id'])); }
        rw_v5_out(true, array('items' => $rows));
        break;
    case 'done':
        $b = relay_body();
        $id = isset($b['id']) ? (string)$b['id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) { rw_v5_out(false, null, 'bad_id'); }
        if (!empty($b['ok'])) {
            $st = $db->prepare('UPDATE ai_requests SET status="proposed", model=?, latency_ms=?, tokens_in=?, tokens_out=?, budget_actual=?, output_raw=?, output_schema_ok=1, tries=tries+1, error_class="", updated_at=? WHERE id=?');
            $st->execute(array(
                isset($b['model']) ? substr((string)$b['model'], 0, 60) : '',
                (int)(isset($b['latency_ms']) ? $b['latency_ms'] : 0),
                (int)(isset($b['tokens_in']) ? $b['tokens_in'] : 0),
                (int)(isset($b['tokens_out']) ? $b['tokens_out'] : 0),
                (float)(isset($b['cost']) ? $b['cost'] : 0),
                isset($b['output']) ? (string)$b['output'] : '',
                rw_db_v5_now(), $id
            ));
            rw_v5_out(true, array('id' => $id, 'status' => 'proposed'));
        } else {
            $st = $db->prepare('SELECT tries FROM ai_requests WHERE id=?');
            $st->execute(array($id));
            $tries = (int)$st->fetchColumn() + 1;
            $status = $tries >= 3 ? 'error' : 'queued';
            $cls = isset($b['error_class']) ? substr((string)$b['error_class'], 0, 60) : 'network';
            $db->prepare('UPDATE ai_requests SET status=?, error_class=?, tries=?, updated_at=? WHERE id=?')
               ->execute(array($status, $cls, $tries, rw_db_v5_now(), $id));
            rw_v5_out(true, array('id' => $id, 'status' => $status));
        }
        break;
    default:
        rw_v5_out(false, null, 'unknown_action');
}
