<?php
// Admin-only Controller connection setup. Credentials stay outside public_html.
require_once __DIR__ . '/security.php';
rw_send_security_headers(); rw_start_session_secure();
if (!rw_is_logged_in()) { header('Location: index.php'); exit; }
if (rw_need_password_change()) { header('Location: index.php'); exit; }
if (!rw_is_admin()) { http_response_code(403); exit('Cần quyền quản trị.'); }
header('Cache-Control: no-store');
$file = RW_SECURE_DIR . '/controller-v7.php';
$existing = is_file($file) ? include $file : null;
$message = ''; $error = ''; $url = is_array($existing) && isset($existing['url']) ? $existing['url'] : '';

function cc_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function cc_endpoint($raw) {
    $raw = trim((string)$raw);
    if (strlen($raw)>240 || !preg_match('~^https://([A-Za-z0-9.-]+)(?:/)?$~D', $raw, $m)) { throw new Exception('Cần URL HTTPS chỉ gồm tên miền, không có đường dẫn hay cổng riêng.'); }
    $host = strtolower($m[1]);
    if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/D', $host) || strpos($host, '..')!==false || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D', $host) || substr($host,-18)==='.trycloudflare.com') { throw new Exception('Tên miền Controller không hợp lệ hoặc là tunnel thử nghiệm.'); }
    $ips = gethostbynamel($host);
    if (!$ips) { throw new Exception('Không phân giải được tên miền Controller.'); }
    foreach ($ips as $ip) { if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { throw new Exception('Tên miền Controller trỏ tới IP riêng hoặc không hợp lệ.'); } }
    return array('url'=>'https://'.$host,'host'=>$host,'ip'=>$ips[0]);
}
function cc_probe($endpoint, $token, $role) {
    if (strlen($token)<24 || strlen($token)>500 || preg_match('/[\r\n]/', $token)) { throw new Exception('Khóa '.$role.' không hợp lệ.'); }
    $curl = curl_init($endpoint['url'].'/api/v1/whoami');
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE=>array($endpoint['host'].':443:'.$endpoint['ip']),
        CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$token,'Accept: application/json'),
    ));
    $body = curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $err=curl_error($curl); curl_close($curl);
    if ($body===false || $status!==200) { throw new Exception('Không xác thực được khóa '.$role.' với Controller HTTPS'.($err ? ' (lỗi kết nối/TLS)' : ' (HTTP '.$status.')').'.'); }
    $decoded=json_decode($body,true);
    if (!is_array($decoded) || !isset($decoded['principal']['role']) || $decoded['principal']['role']!==$role) { throw new Exception('Khóa '.$role.' không đúng vai trò.'); }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    rw_require_csrf();
    try {
        $action=isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action==='disconnect') {
            if (is_file($file) && !unlink($file)) { throw new Exception('Không xóa được cấu hình.'); }
            $existing=null; $url=''; $message='Đã ngắt kết nối Controller.'; rw_audit('controller.disconnect');
        } elseif ($action==='save' || $action==='test') {
            $endpoint=cc_endpoint(isset($_POST['url']) ? $_POST['url'] : ''); $url=$endpoint['url'];
            $admin=isset($_POST['token']) ? trim((string)$_POST['token']) : '';
            $viewer=isset($_POST['viewer_token']) ? trim((string)$_POST['viewer_token']) : '';
            if ($admin==='' && is_array($existing)) { $admin=isset($existing['token']) ? $existing['token'] : ''; }
            if ($viewer==='' && is_array($existing)) { $viewer=isset($existing['viewer_token']) ? $existing['viewer_token'] : ''; }
            if ($admin===$viewer) { throw new Exception('Hai vai trò cần hai khóa khác nhau.'); }
            cc_probe($endpoint,$admin,'admin'); cc_probe($endpoint,$viewer,'viewer');
            if ($action==='save') {
                if (!is_dir(RW_SECURE_DIR) && !mkdir(RW_SECURE_DIR,0700,true)) { throw new Exception('Không tạo được thư mục bảo mật.'); }
                $content="<?php\nreturn ".var_export(array('url'=>$endpoint['url'],'ip'=>$endpoint['ip'],'token'=>$admin,'viewer_token'=>$viewer),true).";\n";
                $temp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
                if (file_put_contents($temp,$content,LOCK_EX)===false) { throw new Exception('Không ghi được cấu hình.'); }
                chmod($temp,0600);
                if (!rename($temp,$file)) { @unlink($temp); throw new Exception('Không thay được cấu hình.'); }
                $existing=array('url'=>$endpoint['url'],'ip'=>$endpoint['ip'],'token'=>$admin,'viewer_token'=>$viewer);
                rw_audit('controller.connect',array('url'=>$endpoint['url']));
                $message='Đã lưu kết nối Controller HTTPS và xác nhận khóa admin/viewer.';
            } else { $message='Kết nối HTTPS và cả hai vai trò đã hợp lệ; chưa lưu thay đổi.'; }
        } else { throw new Exception('Thao tác không hợp lệ.'); }
    } catch (Exception $e) { $error=$e->getMessage(); }
}
?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Kết nối Controller · Rewards Manager</title><style>
body{font:16px/1.5 system-ui,sans-serif;background:#0b1220;color:#e5edf7;margin:0}main{max-width:760px;margin:5vh auto;padding:24px}a{color:#86c5ff}section{background:#162135;border:1px solid #2b3b54;border-radius:16px;padding:26px}label{display:block;margin:18px 0 6px}input{box-sizing:border-box;width:100%;padding:13px;border-radius:9px;border:1px solid #4c617e;background:#0e192b;color:#fff;font:inherit}button{margin:18px 8px 0 0;padding:11px 18px;border:0;border-radius:9px;background:#408bff;color:white;font:inherit;cursor:pointer}button.secondary{background:#30425d}.notice{padding:12px;border-radius:8px;background:#243b4b}.error{background:#542b38}small{color:#b4c2d6}
</style></head><body><main><p><a href="dashboard-v7.php">← Về dashboard V7</a></p><section><h1>Kết nối Controller HTTPS</h1><p class="notice"><?= $existing ? 'Đã lưu endpoint: '.cc_h($url) : 'Chưa có Controller HTTPS. Dashboard đang ở chế độ xem trước.' ?></p><?php if($message): ?><p class="notice" role="status"><?= cc_h($message) ?></p><?php endif; ?><?php if($error): ?><p class="notice error" role="alert"><?= cc_h($error) ?></p><?php endif; ?><p>Nhập tên miền Controller trên VPS và hai khóa API riêng được tạo trong phần Quyền truy cập. Máy local không cần mở ra Internet.</p><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= cc_h(rw_csrf_token()) ?>"><label for="url">URL Controller HTTPS</label><input id="url" name="url" type="url" value="<?= cc_h($url) ?>" placeholder="https://controller.example.com" required><label for="token">Khóa API quản trị riêng</label><input id="token" name="token" type="password" autocomplete="new-password" placeholder="<?= $existing ? 'Để trống nếu giữ khóa hiện tại' : 'Nhập khóa admin' ?>"><label for="viewer">Khóa API chỉ xem riêng</label><input id="viewer" name="viewer_token" type="password" autocomplete="new-password" placeholder="<?= $existing ? 'Để trống nếu giữ khóa hiện tại' : 'Nhập khóa viewer' ?>"><small>Hai khóa chỉ được lưu trong thư mục secure ngoài public_html. Trình duyệt không nhận lại giá trị khóa.</small><div><button name="action" value="test">Thử kết nối</button><button name="action" value="save">Kiểm tra và lưu</button></div></form><?php if($existing): ?><form method="post" onsubmit="return confirm('Ngắt kết nối Controller? Dashboard sẽ trở lại chế độ xem trước.');"><input type="hidden" name="csrf" value="<?= cc_h(rw_csrf_token()) ?>"><button class="secondary" name="action" value="disconnect">Ngắt kết nối</button></form><?php endif; ?></section></main></body></html>
