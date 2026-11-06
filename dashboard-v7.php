<?php
// Uses the existing login, CSRF, idle timeout, and mandatory password-change flow.
require_once __DIR__ . '/security.php';
rw_send_security_headers(); rw_start_session_secure();
if (!rw_is_logged_in()) { header('Location: index.php'); exit; }
if (rw_need_password_change()) { header('Location: index.php'); exit; }
$html = file_get_contents(__DIR__ . '/v7-index.html');
$html = str_replace('href="app.css"', 'href="v7-app.css"', $html);
foreach (array('pool.js','observers.js','operations.js','notifications.js','access.js','releases.js','system.js') as $asset) {
    $html = str_replace('src="'.$asset.'"', 'src="v7-'.$asset.'"', $html);
}
$bootstrap = '<script>window.RW_API_BASE="bridge-v7.php?path=";window.RW_CSRF=' . json_encode(rw_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$html = str_replace('src="v7-observers.js"', 'src="v7-observers.js?v=20261105-hosts1"', $html);
$html = str_replace('<script src="app.js"></script>', $bootstrap . '<script src="v7-app.js"></script>', $html);
$html = str_replace('src="v7-app.js"', 'src="v7-app.js?v=20261105-hosts2"', $html);
if (rw_is_admin()) { $html = str_replace('<button id="logout"', '<a href="controller-connection-v7.php" style="display:block;padding:10px 0;color:#86c5ff">⚙ Kết nối Controller HTTPS</a><button id="logout"', $html); }
echo $html;
