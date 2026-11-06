<?php
/* ============================================================
 * index.php — Giao diện UI v4 (PROMPT UI v4, mục 1: khung chung).
 * GIỮ NGUYÊN luồng đăng nhập, đổi mật khẩu bắt buộc, CSRF, theme .rw-*.
 * 9 tab: Tổng quan · Tài khoản · Proxy · Phone · Tiến độ & Thống kê ·
 *        Nhiệm vụ & Skill · Lịch chạy · Thiết bị · Nhật ký.
 * ============================================================ */
require_once __DIR__ . '/security.php';
rw_send_security_headers();
rw_start_session_secure();
require_once __DIR__ . '/auth.php';
if ($mode==='dash') { header('Location: dashboard-v7.php', true, 302); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="vi">
<head><meta charset="utf-8">

<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h(rw_csrf_token()) ?>">
<title>Rewards Farm Manager — V7</title>
<link rel="stylesheet" href="assets/rw-ui.css">
<link rel="stylesheet" href="v7-login.css">
</head>
<body>
<div class="rw-wrap">
<?php if ($mode==='changepw'): ?>
  <div class="rw-panel rw-login">
    <h2>🔐 Đổi mật khẩu bắt buộc</h2>
    <div class="rw-warn">Vì lý do bảo mật, bạn phải đổi mật khẩu trước khi sử dụng dashboard.</div>
    <?php if ($err): ?><div class="rw-error"><?= h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(rw_csrf_token()) ?>">
      <div class="rw-field"><label>Mật khẩu mới (tối thiểu 10 ký tự)</label><input class="rw-input" type="password" name="p1" required minlength="10" autocomplete="new-password"></div>
      <div class="rw-field"><label>Nhập lại mật khẩu mới</label><input class="rw-input" type="password" name="p2" required minlength="10" autocomplete="new-password"></div>
      <button class="rw-btn primary" type="submit" style="width:100%;padding:.55rem">Đổi mật khẩu</button>
    </form>
  </div>
<?php elseif ($mode==='login'): ?>
  <div class="rw-panel rw-login">
    <div class="rw-panel-pad">
      <div class="rw-muted">📈 Rewards Farm Manager</div>
      <h2>Đăng nhập</h2>
      <div class="rw-muted">Truy cập cần tài khoản do quản trị viên cấp.</div>
      <?php if($err!==''): ?><div class="rw-alert bad">❌ <?= h($err) ?></div><?php endif; ?>
      <?php if($notice!==''): ?><div class="rw-alert ok">✅ <?= h($notice) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <div class="rw-field"><label>Tên đăng nhập</label><input class="rw-input" type="text" name="username" placeholder="admin" required autofocus></div>
        <div class="rw-field"><label>Mật khẩu</label><input class="rw-input" type="password" name="password" placeholder="••••••••" required></div>
        <input type="hidden" name="csrf" value="<?= h(rw_csrf_token()) ?>">
        <button class="rw-btn primary" type="submit" style="width:100%;padding:.55rem;font-size:.85rem">Đăng nhập →</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <!-- ===== Khung chung dashboard (UI v4) ===== -->
  <div class="rw-head">
    <div>
      <h2>📈 Rewards Farm Manager</h2>
      <div class="rw-muted">Xin chào <b><?= h($_SESSION['rw_full']) ?></b> (<?= h($_SESSION['rw_user']) ?>) · vai trò: <?= h($_SESSION['rw_role']) ?></div>
    </div>
    <div class="rw-inline">
      <button class="rw-btn" id="btnReload">↻ Làm mới</button>
      <a href="logout.php" class="rw-btn">Đăng xuất</a>
    </div>
  </div>

  <!-- Thanh trạng thái cố định: thiết bị online · job · cảnh báo · cập nhật gần nhất · banner vàng nếu cũ >3 phút -->
  <div class="rw-statusbar" id="statusbar" role="status" aria-live="polite">
    <span class="sb-item"><span class="k">🖥 Thiết bị online</span><span class="v" id="sbDevices">—</span></span>
    <span class="sb-item"><span class="k">⏱ Job</span><span class="v" id="sbJobs">—</span></span>
    <span class="sb-item"><span class="k">⚠ Cảnh báo</span><span class="v" id="sbWarn">—</span></span>
    <span class="sb-item"><span class="k">🔄 Cập nhật gần nhất</span><span class="v" id="sbUpdated">—</span></span>
  </div>
  <div id="staleBanner" class="rw-banner-warn" style="display:none" role="alert">⚠ Dữ liệu đã cũ hơn 3 phút — có thể worker đang nghẽn. Bấm "↻ Làm mới" để kiểm tra.</div>

  <!-- 9 tab chính -->
  <div class="rw-tabs" role="tablist" aria-label="Điều hướng chính">
    <button class="rw-tab active" role="tab" aria-selected="true" data-tab="overview">📊 Tổng quan</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="accounts">👤 Tài khoản</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="proxy">🌐 Proxy</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="phone">📱 Phone</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="stats">📈 Tiến độ &amp; Thống kê</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="skills">🧩 Nhiệm vụ &amp; Skill</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="schedule">📅 Lịch chạy</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="devices">🛠️ Thiết bị</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="logs">📄 Nhật ký</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="ai">🤖 AI</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="tasks">🎯 Nhiệm vụ v5</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="vps">🖥 VPS v5</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="security">🛡 Bảo mật</button>
    <button class="rw-tab" role="tab" aria-selected="false" data-tab="accept">✅ Nghiệm thu</button>
  </div>

  <!-- Panes: mỗi tab render qua JS module riêng trong assets/ -->
  <div id="pane-overview" data-pane="overview"></div>
  <div id="pane-accounts" data-pane="accounts" style="display:none"></div>
  <div id="pane-proxy" data-pane="proxy" style="display:none"></div>
  <div id="pane-phone" data-pane="phone" style="display:none"></div>
  <div id="pane-stats" data-pane="stats" style="display:none"></div>
  <div id="pane-skills" data-pane="skills" style="display:none"></div>
  <div id="pane-schedule" data-pane="schedule" style="display:none"></div>
  <div id="pane-devices" data-pane="devices" style="display:none"></div>
  <div id="pane-logs" data-pane="logs" style="display:none"></div>
  <div id="pane-ai" data-pane="ai" style="display:none"></div>
  <div id="pane-tasks" data-pane="tasks" style="display:none"></div>
  <div id="pane-vps" data-pane="vps" style="display:none"></div>
  <div id="pane-security" data-pane="security" style="display:none"></div>
  <div id="pane-accept" data-pane="accept" style="display:none"></div>

  <div class="rw-muted" style="text-align:center;padding:1rem 0">Rewards Farm · UI v4 · dữ liệu đồng bộ từ gateway · tự làm mới mỗi 10 giây (tạm dừng khi tab ẩn) · múi giờ Asia/Bangkok</div>
<?php endif; ?>
</div>
<?php if($mode==='dash'): ?>
<script src="assets/rw-core.js?v=202611042255"></script>
<script src="assets/tab-accounts.js?v=202611042255"></script>
<script src="assets/tab-proxies.js?v=202611042255"></script>
<script src="assets/tab-phones.js?v=202611042255"></script>
<script src="assets/tab-stats.js?v=202611042255"></script>
<script src="assets/tab-skills.js?v=202611042255"></script>
<script src="assets/tab-placeholder.js?v=202611042255"></script>
<script src="assets/tab-ai.js?v=202611042255"></script>
<script src="assets/tab-tasks.js?v=20261104165709"></script>
<script src="assets/tab-vps.js?v=20261104165709"></script>
<script src="assets/tab-security.js?v=202611042255"></script>
<script src="assets/tab-accept.js?v=202611042255"></script>
<script>
/* ============================================================
 * Điều hướng tab + thanh trạng thái + polling 10s (pause khi tab ẩn)
 * ============================================================ */
(function () {
  'use strict';
  var current = 'overview';
  var lastUpdate = Date.now();

  var TABS = {
    overview: { mod: 'RW_TAB_OTHER', arg: 'overview' },
    accounts: { mod: 'RW_TAB_ACCOUNTS' },
    proxy:    { mod: 'RW_TAB_PROXIES' },
    phone:    { mod: 'RW_TAB_PHONES' },
    stats:    { mod: 'RW_TAB_STATS' },
    skills:   { mod: 'RW_TAB_SKILLS' },
    schedule: { mod: 'RW_TAB_OTHER', arg: 'schedule' },
    devices:  { mod: 'RW_TAB_OTHER', arg: 'devices' },
    logs:     { mod: 'RW_TAB_OTHER', arg: 'logs' },
    ai:       { mod: 'RW_TAB_AI' },
    tasks:    { mod: 'RW_TAB_TASKS' },
    vps:      { mod: 'RW_TAB_VPS' },
    security: { mod: 'RW_TAB_SECURITY' },
    accept:   { mod: 'RW_TAB_ACCEPT' }
  };

  function paneEl(name) { return document.getElementById('pane-' + name); }

  function activate(name, skipRender) {
    current = name;
    // Cập nhật aria + class tab
    Array.prototype.forEach.call(document.querySelectorAll('.rw-tab'), function (b) {
      var on = b.getAttribute('data-tab') === name;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    // Hiện/ẩn pane
    Object.keys(TABS).forEach(function (k) {
      var el = paneEl(k);
      if (el) el.style.display = (k === name) ? '' : 'none';
    });
    // Gọi module render (không mất scroll/lọc/chọn: module tự giữ state)
    if (skipRender) return;
    var cfg = TABS[name];
    if (!cfg) return;
    var mod = window[cfg.mod];
    if (mod && typeof mod.init === 'function') {
      mod.init(paneEl(name), cfg.arg);
    }
  }

  // Click tab
  Array.prototype.forEach.call(document.querySelectorAll('.rw-tab'), function (b) {
    b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); });
  });
  // Bàn phím: ← → trên tablist
  document.querySelector('.rw-tabs').addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    var list = Array.prototype.slice.call(document.querySelectorAll('.rw-tab'));
    var idx = list.indexOf(document.activeElement);
    if (idx < 0) return;
    e.preventDefault();
    var next = e.key === 'ArrowRight' ? (idx + 1) % list.length : (idx - 1 + list.length) % list.length;
    list[next].focus();
    activate(list[next].getAttribute('data-tab'));
  });

  // ---- Thanh trạng thái: thiết bị online · job · cảnh báo · cập nhật ----
  function refreshStatus() {
    RW.api('api.php?action=summary').then(function (res) {
      var s = (res && res.ok && res.data && res.data.summary) ? res.data.summary : {};
      var devOnline = s.devices_online;
      var elD = document.getElementById('sbDevices');
      if (elD) elD.textContent = (devOnline === undefined || devOnline === null) ? '—' : String(devOnline);
      var elJ = document.getElementById('sbJobs');
      if (elJ) elJ.textContent = (s.jobs_running || s.running || 0) + ' đang chạy';
      var warns = (s.error || 0) + (s.needs_human || 0);
      var elW = document.getElementById('sbWarn');
      if (elW) {
        elW.textContent = warns ? (warns + ' cảnh báo') : 'không có';
        elW.style.color = warns ? 'var(--rw-err)' : '';
      }
      lastUpdate = Date.now();
      var elU = document.getElementById('sbUpdated');
      if (elU) elU.textContent = RW.fmtTime(new Date().toISOString().slice(0, 19).replace('T', ' '), true);
    });
  }

  // Banner vàng khi dữ liệu cũ > 3 phút (180000 ms)
  setInterval(function () {
    var stale = (Date.now() - lastUpdate) > 180000;
    var banner = document.getElementById('staleBanner');
    if (banner) banner.style.display = stale ? '' : 'none';
  }, 15000);

  // Polling 10s, tự pause khi tab ẩn (RW.startPolling xử lý visibility)
  var poll = RW.startPolling(function () {
    refreshStatus();
    var mod = window[TABS[current] ? TABS[current].mod : ''];
    if (mod && typeof mod.refresh === 'function') mod.refresh();
  });

  // Nút Làm mới
  var btn = document.getElementById('btnReload');
  if (btn) btn.addEventListener('click', function () {
    refreshStatus();
    var mod = window[TABS[current] ? TABS[current].mod : ''];
    if (mod && typeof mod.refresh === 'function') mod.refresh();
    RW.toast('Đã làm mới.', 'ok');
  });

  // Khởi tạo tab đầu tiên + trạng thái ban đầu
  activate('overview');
  refreshStatus();
})();
</script>
<?php endif; ?>
</body>
</html>
