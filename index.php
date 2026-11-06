<?php
require_once __DIR__ . '/security.php';
rw_send_security_headers();
rw_start_session_secure();
require_once __DIR__ . '/auth.php';
if ($mode === 'dash') { header('Location: dashboard-v7.php', true, 302); exit; }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="vi">
<head><meta charset="utf-8">

<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rewards Farm Manager — Đăng nhập</title>
<link rel="stylesheet" href="v7-login.css">
</head>
<body>
<div class="rw-wrap">
<?php if ($mode === 'changepw'): ?>
  <div class="rw-panel rw-login">
    <h2>🔐 Đổi mật khẩu bắt buộc</h2>
    <div class="rw-warn">Vì lý do bảo mật, bạn phải đổi mật khẩu trước khi sử dụng dashboard.</div>
    <?php if ($err !== ''): ?><div class="rw-alert bad"><?= h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(rw_csrf_token()) ?>">
      <div class="rw-field"><label for="p1">Mật khẩu mới (tối thiểu 10 ký tự)</label><input id="p1" class="rw-input" type="password" name="p1" required minlength="10" autocomplete="new-password"></div>
      <div class="rw-field"><label for="p2">Nhập lại mật khẩu mới</label><input id="p2" class="rw-input" type="password" name="p2" required minlength="10" autocomplete="new-password"></div>
      <button class="rw-btn primary" type="submit">Đổi mật khẩu</button>
    </form>
  </div>
<?php elseif ($mode === 'login'): ?>
  <div class="rw-panel rw-login">
    <div class="rw-panel-pad">
      <div class="rw-muted">📈 Rewards Farm Manager</div>
      <h2>Đăng nhập</h2>
      <div class="rw-muted">Truy cập cần tài khoản do quản trị viên cấp.</div>
      <?php if ($err !== ''): ?><div class="rw-alert bad"><?= h($err) ?></div><?php endif; ?>
      <?php if ($notice !== ''): ?><div class="rw-alert ok"><?= h($notice) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <div class="rw-field"><label for="username">Tên đăng nhập</label><input id="username" class="rw-input" type="text" name="username" placeholder="admin" required autofocus autocomplete="username"></div>
        <div class="rw-field"><label for="password">Mật khẩu</label><input id="password" class="rw-input" type="password" name="password" required autocomplete="current-password"></div>
        <input type="hidden" name="csrf" value="<?= h(rw_csrf_token()) ?>">
        <button class="rw-btn primary" type="submit">Đăng nhập →</button>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>
</body>
</html>