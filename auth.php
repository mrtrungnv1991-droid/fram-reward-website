<?php
// Xu ly dang nhap + doi mat khau buoc (Muc 17). Require sau security.php.
$err = ''; $notice = ''; $mode = 'dash';
if (isset($_GET['logged'])) { $notice = 'Ban da dang xuat thanh cong.'; }
if (isset($_GET['changed'])) { $notice = 'Da doi mat khau thanh cong.'; }

if (rw_is_logged_in() && rw_need_password_change()) {
    $mode = 'changepw';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rw_csrf_check()) { $err = 'Phien lam viec khong hop le, vui long tai lai trang.'; }
        else {
            $p1 = isset($_POST['p1']) ? (string)$_POST['p1'] : '';
            $p2 = isset($_POST['p2']) ? (string)$_POST['p2'] : '';
            if (strlen($p1) < 10) { $err = 'Mat khau moi phai co it nhat 10 ky tu.'; }
            elseif ($p1 !== $p2) { $err = 'Mat khau nhap lai khong khop.'; }
            elseif (rw_set_password($_SESSION['rw_user'], $p1)) {
                rw_audit('password_changed', array('user' => $_SESSION['rw_user']));
                $_SESSION = array();
                if (ini_get('session.use_cookies')) {
                    $pp = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $pp['path'], $pp['domain'], $pp['secure'], $pp['httponly']);
                }
                session_destroy();
                header('Location: index.php?changed=1'); exit;
            } else { $err = 'Khong doi duoc mat khau, vui long thu lai.'; }
        }
    }
} elseif (rw_is_logged_in()) {
    $mode = 'dash';
} else {
    $mode = 'login';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rw_csrf_check()) { $err = 'Phien lam viec khong hop le, vui long tai lai trang.'; }
        else {
            $rl = rw_rl_status();
            if ($rl['locked']) {
                $err = 'Ban da nhap sai qua nhieu lan. Vui long thu lai sau ' . max(1,(int)ceil(($rl['until']-time())/60)) . ' phut.';
            } else {
                $u = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
                $p = isset($_POST['password']) ? (string)$_POST['password'] : '';
                $users = rw_users();
                $ok = ($u !== '' && $p !== '' && isset($users[$u]) && password_verify($p, $users[$u]['hash']));
                if (!$ok) {
                    rw_rl_fail();
                    rw_audit('login_failed', array('user' => rw_mask($u)));
                    $err = RW_LOGIN_ERR;
                    usleep(500000);
                } else {
                    rw_rl_clear();
                    session_regenerate_id(true);
                    $_SESSION['rw_user'] = $u;
                    $_SESSION['rw_role'] = isset($users[$u]['role']) ? $users[$u]['role'] : 'manager';
                    $_SESSION['rw_full'] = isset($users[$u]['full']) ? $users[$u]['full'] : $u;
                    $_SESSION['rw_login_at'] = time();
                    rw_audit('login_ok', array('user' => $u, 'role' => $_SESSION['rw_role']));
                    header('Location: index.php'); exit;
                }
            }
        }
    }
}
?>
