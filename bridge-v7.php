<?php
require_once __DIR__ . '/security.php';
rw_send_security_headers(); rw_start_session_secure(); rw_require_api_login();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function v7_reply($body, $status = 200) { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
if (rw_need_password_change()) { v7_reply(array('error'=>'Bạn cần đổi mật khẩu trước.'),403); }
$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
if (strlen($path)>2000 || !preg_match('~^(whoami|summary|pool-status|observers|operations|releases|notifications|access|maintenance|accounts|proxies|groups|schedules|jobs|workers|skills|events|ai|banned)(/[A-Za-z0-9_.:-]+){0,3}(\?[^\r\n#]*)?$~D', $path)) { v7_reply(array('error'=>'Đường dẫn không hợp lệ'),400); }
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method,array('GET','POST'),true)) { v7_reply(array('error'=>'method_not_allowed'),405); }
if ($method==='POST') { rw_require_csrf(); if (!rw_is_admin()) { v7_reply(array('error'=>'Cần quyền quản trị'),403); } }
$configFile = RW_SECURE_DIR . '/controller-v7.php';
$config = is_file($configFile) ? include $configFile : null;
if (!$config) {
    // Staging uses a read-only projection of v4. It never creates live farm jobs.
    if ($method !== 'GET') { v7_reply(array('error'=>'Bản xem trước chỉ đọc. Máy chủ điều phối chưa được kết nối.'),503); }
    require_once __DIR__ . '/db.php';
    $db=rw_db(); $parts=explode('?', $path, 2); $resource=$parts[0]; $params=array();
    if (isset($parts[1])) { parse_str($parts[1],$params); }
    if ($resource==='whoami') { v7_reply(array('principal'=>array('id'=>'cpanel-preview','role'=>rw_is_admin()?'admin':'viewer','worker_ids'=>null),'preview'=>true)); }
    if (strpos($resource,'operations/')===0) {
        if ($resource==='operations/summary') { v7_reply(array('cases'=>array(),'stops'=>array(),'alerts'=>array(),'circuits'=>array(),'cost_report'=>array('rows'=>array()),'config'=>array('enabled'=>false,'cooldown_minutes'=>60,'failure_threshold'=>3,'retry_minutes'=>15,'delivery'=>'unavailable','outbound_enabled'=>false),'preview'=>true)); }
        if ($resource==='operations/alerts/config') { v7_reply(array('enabled'=>false,'cooldown_minutes'=>60,'failure_threshold'=>3,'retry_minutes'=>15,'preview'=>true)); }
        v7_reply(array('rows'=>array(),'preview'=>true));
    }
    if ($resource==='observers') { v7_reply(array('rows'=>array(),'mode'=>'read_only','preview'=>true)); }
    if ($resource==='releases') { v7_reply(array('rows'=>array(),'targets'=>array(),'api_version'=>1,'package_source'=>'not_connected','preview'=>true)); }
    if ($resource==='notifications/channels') { v7_reply(array('rows'=>array(),'allowed_hosts'=>array(),'preview'=>true)); }
    if ($resource==='access/users') { v7_reply(array('rows'=>array(),'preview'=>true)); }
    if ($resource==='maintenance') { v7_reply(array('policy'=>array('backups_enabled'=>false,'retention_enabled'=>false,'rpo_minutes'=>1440,'rto_minutes'=>60,'event_days'=>90,'observation_days'=>90,'backup_days'=>30),'status'=>array('last_backup_at'=>null,'failures'=>0),'backups'=>array(),'directory_configured'=>false,'rto_verified'=>false,'preview'=>true)); }
    $poolPreview=($resource==='pool-status');
    if ($poolPreview) { $resource='accounts'; }
    $limit=max(1,min(200,isset($params['limit'])?(int)$params['limit']:50));
    $offset=max(0,isset($params['offset'])?(int)$params['offset']:0);
    $search=isset($params['q'])?substr((string)$params['q'],0,200):'';
    if ($resource==='summary') {
        $n=(int)$db->query('SELECT count(*) FROM accounts WHERE deleted_at IS NULL')->fetchColumn();
        $devices=(int)$db->query('SELECT count(*) FROM devices WHERE revoked=0')->fetchColumn();
        $attention=(int)$db->query("SELECT count(*) FROM accounts WHERE deleted_at IS NULL AND status IN ('needs_human','needs_recovery','error')")->fetchColumn();
        v7_reply(array('accounts'=>$n,'attention_accounts'=>$attention,'workers'=>array('total'=>$devices,'online'=>0),'jobs'=>array(),'proxy_alerts'=>0,'generated_at'=>gmdate('c'),'preview'=>true));
    }
    if ($resource==='ai') { v7_reply(array('payload'=>array('daily_budget_usd'=>5,'max_request_usd'=>1),'configured'=>false,'preview'=>true)); }
    $sqls=array(
        'accounts'=>array("SELECT CAST(id AS TEXT) id,email name,CAST(device_id AS TEXT) worker_id,CAST(proxy_id AS TEXT) proxy_id,group_label group_id,status,1 enabled,updated_at FROM accounts WHERE deleted_at IS NULL",'email'),
        'workers'=>array("SELECT CAST(id AS TEXT) id,name,cap,last_seen,0 enabled FROM devices WHERE revoked=0",'name'),
        'proxies'=>array("SELECT CAST(id AS TEXT) id,proxy_key name,status,host,port,scheme,username,last_checked FROM proxies WHERE 1=1",'proxy_key'),
        'groups'=>array("SELECT group_label id,group_label name,count(*) members FROM accounts WHERE deleted_at IS NULL AND group_label IS NOT NULL AND group_label<>''",'group_label'),
        'events'=>array("SELECT id,user actor,action kind,ts created_at,detail FROM audit_log WHERE 1=1",'action')
    );
    if (!isset($sqls[$resource])) { v7_reply(array('rows'=>array(),'total'=>0,'limit'=>$limit,'offset'=>$offset,'preview'=>true)); }
    list($sql,$column)=$sqls[$resource]; $args=array();
    if ($search!=='') { $sql.=' AND '.$column.' LIKE ?'; $args[]='%'.$search.'%'; }
    if ($resource==='groups') { $sql.=' GROUP BY group_label'; }
    $count=$db->prepare('SELECT count(*) FROM ('.$sql.') t');$count->execute($args);$total=(int)$count->fetchColumn();
    $statement=$db->prepare($sql.' ORDER BY id LIMIT '.$limit.' OFFSET '.$offset);$statement->execute($args);$rows=$statement->fetchAll();
    foreach($rows as &$row) {
        if ($resource==='accounts') { $row['payload']=array();$row['enabled']=true;$row['zone']='Asia/Bangkok';$row['reset_time']='00:00'; }
        if ($resource==='workers') { $row['info']=array();$row['enabled']=false; }
        if ($resource==='proxies') { $row['payload']=array('host'=>$row['host'],'port'=>$row['port'],'scheme'=>$row['scheme'],'username'=>$row['username'],'last_checked'=>$row['last_checked']);unset($row['host'],$row['port'],$row['scheme'],$row['username']); }
        if ($resource==='groups') { $row['enabled']=false;$row['cap']=50;$row['payload']=array(); }
    }unset($row);
    if ($poolPreview) { foreach($rows as &$row) { $row['state']='unchecked';$row['snapshot']=null;$row['remaining']=null; } unset($row);v7_reply(array('rows'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset,'mode'=>'read_only','stale_after_minutes'=>30,'preview'=>true)); }
    v7_reply(array('rows'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset,'preview'=>true));
}
$base = isset($config['url']) ? rtrim($config['url'],'/') : '';
$url = parse_url($base);
if (!$url || $url['scheme']!=='https' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['path']) || isset($url['port']) || empty($config['token']) || empty($config['viewer_token']) || !isset($config['ip']) || !filter_var($config['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { v7_reply(array('error'=>'Cấu hình điều phối không hợp lệ'),503); }
$controllerToken=rw_is_admin()?$config['token']:(isset($config['viewer_token'])?$config['viewer_token']:'');
if ($controllerToken==='') { v7_reply(array('error'=>'Chưa cấp khóa chỉ xem cho người dùng hosting.'),403); }
$curl=curl_init($base.'/api/v1/'.$path);
curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>55,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_RESOLVE=>array($url['host'].':443:'. $config['ip']),CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$controllerToken,'Content-Type: application/json')));
if ($method==='POST') { curl_setopt($curl,CURLOPT_POSTFIELDS,file_get_contents('php://input')); }
$result=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
if ($result===false || $code===0) { v7_reply(array('error'=>'Chưa kết nối được máy chủ điều phối'),503); }
http_response_code($code); echo $result;
