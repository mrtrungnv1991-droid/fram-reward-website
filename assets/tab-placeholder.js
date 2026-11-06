/* ============================================================
 * tab-placeholder.js — Các tab còn lại của PROMPT UI v4 (mục 1):
 * Tổng quan · Tiến độ & Thống kê (shell chờ API stats) ·
 * Nhiệm vụ & Skill (shell chờ API skills) · Lịch chạy ·
 * Thiết bị · Nhật ký (nối dữ liệu v3 api.php có sẵn).
 * ============================================================ */
(function () {
  'use strict';
  var V3_API = 'api.php';
  var container = null;
  var state = { active: 'overview', data: {}, loaded: {} };

  /* ---- Nạp dữ liệu v3 (api.php?action=...) ---- */
  function v3(action) {
    return RW.api(V3_API + '?action=' + action).then(function (res) {
      return res.ok ? res.data : null;
    });
  }

  /* ============================================================
   * 1. Tổng quan — nối summary + accounts v3
   * ============================================================ */
  function renderOverview() {
    if (!state.data.summary) {
      container.innerHTML = '<div class="rw-skeleton"><div class="sk"></div><div class="sk"></div></div>';
      Promise.all([v3('summary'), v3('accounts&limit=400')]).then(function (r) {
        state.data.summary = r[0] ? (r[0].summary || {}) : {};
        state.data.accounts = r[1] ? (r[1].accounts || []) : [];
        renderOverview();
      });
      return;
    }
    var s = state.data.summary;
    var done = s.ok || 0, total = s.total_accounts || 0;
    var pct = total ? Math.round(done / total * 100) : 0;
    function card(k, v, sub) {
      return '<div class="rw-card"><div class="k">' + RW.esc(k) + '</div><div class="v">' + RW.esc(v) + '</div>' +
        (sub ? '<div class="s">' + RW.esc(sub) + '</div>' : '') + '</div>';
    }
    container.innerHTML =
      '<div class="rw-head"><div><h2>📊 Tổng quan</h2>' +
      '<div class="rw-muted">Cập nhật: ' + RW.fmtTime(s.generated_at, true) + '</div></div>' +
      '<div class="rw-inline"><button class=\"rw-btn\" data-ph-reload>↻ Làm mới</button></div></div>' +
      '<div class="rw-cards">' +
      card('Tài khoản', total) +
      card('Hoàn thành hôm nay', '✅ ' + done + ' · 🟡 ' + (s.partial || 0)) +
      card('Lỗi / Cần xử lý', '⏳ ' + (s.error || 0) + ' · ❌ ' + (s.needs_human || 0)) +
      card('Điểm hôm nay', '+' + (s.points_today || 0)) +
      card('Trung bình', '+' + (s.points_avg || 0) + ' / acc') +
      card('Tốc độ', (s.speed_acc_per_hour || 0) + ' acc/h') +
      card('Lượt chạy', (s.avg_seconds || 0) + 's / acc') +
      card('Scheduler', s.scheduler || 'unknown') +
      '</div>' +
      '<div class="rw-grid2" style="margin-top:.85rem">' +
      '<div class="rw-panel"><div class="rw-panel-pad">' +
      '<div class="rw-section-title"><span>Tiến độ hoàn thành</span><span class="rw-muted">' + pct + '%</span></div>' +
      '<div class="rw-progress"><span style="width:' + pct + '%"></span></div>' +
      '<div class="rw-timing-grid" style="margin-top:.75rem">' +
      '<div class="rw-timing-box"><div class="k">Hoàn thành</div><div class="v">' + done + '/' + total + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Cần người</div><div class="v">' + (s.needs_human || 0) + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Tốc độ</div><div class="v">' + (s.speed_acc_per_hour || 0) + '/h</div></div>' +
      '<div class="rw-timing-box"><div class="k">Ngày</div><div class="v" style="font-size:.85rem">' + RW.esc(s.date || '—') + '</div></div>' +
      '</div></div></div>' +
      '<div class="rw-panel"><div class="rw-panel-pad">' +
      '<div class="rw-section-title">⚠️ Cảnh báo hệ thống</div>' +
      (s.needs_human ? '<div class="rw-warn"><strong>' + s.needs_human + ' tài khoản cần người xác minh</strong> (Microsoft yêu cầu email khôi phục)</div>' : '') +
      (s.error ? '<div class="rw-danger"><strong>' + s.error + ' tài khoản lỗi</strong> — cần kiểm tra lại</div>' : '') +
      (!s.needs_human && !s.error ? '<div class="rw-empty">Không có cảnh báo.</div>' : '') +
      '</div></div></div>' +
      '<div class="rw-panel" style="margin-top:.85rem"><div class="rw-panel-pad">' +
      '<div class="rw-section-title">📋 Phân bố trạng thái</div>' +
      '<div class="rw-timing-grid">' +
      '<div class="rw-timing-box"><div class="k">OK</div><div class="v" style="color:#4ade80">' + (s.ok || 0) + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Partial</div><div class="v" style="color:#facc15">' + (s.partial || 0) + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Error</div><div class="v" style="color:#f87171">' + (s.error || 0) + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Needs human</div><div class="v" style="color:#c084fc">' + (s.needs_human || 0) + '</div></div>' +
      '<div class="rw-timing-box"><div class="k">Điểm hôm nay</div><div class="v">+' + (s.points_today || 0) + '</div></div>' +
      '</div></div></div>' +
      renderAccTable(state.data.accounts || []);
    var rl = container.querySelector('[data-ph-reload]');
    if (rl) rl.addEventListener('click', function () { state.data.summary = null; renderOverview(); });
    bindAccTable();
  }

  function renderAccTable(rows) {
    var body = rows.map(function (r, i) {
      return '<tr><td>' + (i + 1) + '</td>' +
        '<td style="font-family:var(--font-mono);font-size:.74rem">' + RW.esc(r.email) + '</td>' +
        '<td>' + RW.accBadge(r.status) + '</td>' +
        '<td>' + (r.points_start === null ? '<span class="rw-muted">NULL</span>' : RW.num(r.points_start)) + '</td>' +
        '<td>' + (r.points_end === null ? '<span class="rw-muted">NULL</span>' : RW.num(r.points_end)) + '</td>' +
        '<td>' + (r.delta === null ? '<span class="rw-muted">NULL</span>' :
          (r.delta > 0 ? '<b style="color:#4ade80">+' + RW.num(r.delta) + '</b>' : RW.num(r.delta))) + '</td>' +
        '<td>' + RW.num(r.tasks_ok) + '</td><td>' + RW.num(r.tasks_error) + '</td>' +
        '<td class="rw-muted">' + (r.phone ? RW.esc(String(r.phone).replace(/.(?=.{3})/g, '*')) : '—') + '</td>' +
        '<td class="rw-muted">' + (r.proxy ? RW.esc(r.proxy) : '—') + '</td></tr>';
    }).join('');
    return '<div class="rw-panel" style="margin-top:.85rem"><div class="rw-panel-pad">' +
      '<div class="rw-section-title"><span>👤 Tài khoản (tóm tắt)</span><span class="rw-muted">' + rows.length + ' mục</span></div></div>' +
      '<div class="rw-matrix-wrap"><table class="rw-table"><thead><tr>' +
      '<th>#</th><th>Email</th><th>Trạng thái</th><th>Điểm đầu</th><th>Điểm cuối</th><th>Delta</th><th>OK</th><th>Err</th><th>Phone</th><th>Proxy</th>' +
      '</tr></thead><tbody>' + (body || '<tr><td colspan="10" class="rw-empty">Không có dữ liệu.</td></tr>') + '</tbody></table></div></div>';
  }
  function bindAccTable() { /* chỉ hiển thị, không tương tác */ }

  /* ============================================================
   * 4. Lịch chạy — nối dữ liệu v3 (api.php?action=summary + schedule)
   * ============================================================ */
  function renderSchedule() {
    if (!state.data.schedule) {
      container.innerHTML = '<div class="rw-skeleton"><div class="sk"></div></div>';
      v3('summary').then(function (d) {
        state.data.schedule = d || {};
        renderSchedule();
      });
      return;
    }
    var s = (state.data.schedule.summary || state.data.schedule);
    container.innerHTML =
      '<div class="rw-head"><div><h2>📅 Lịch chạy</h2>' +
      '<div class="rw-muted">Dữ liệu từ gateway v3</div></div>' +
      '<div class="rw-inline"><button class="rw-btn" data-sch-reload>↻ Làm mới</button></div></div>' +
      '<div class="rw-grid2">' +
      '<div class="rw-panel"><div class="rw-panel-pad">' +
      '<div class="rw-section-title">⏱️ Trạng thái scheduler</div>' +
      '<div class="rw-kv">' +
      '<dt>Scheduler</dt><dd>' + RW.esc(s.scheduler || 'unknown') + '</dd>' +
      '<dt>Ngày</dt><dd>' + RW.esc(s.date || '—') + '</dd>' +
      '<dt>Hoàn thành</dt><dd>' + (s.ok || 0) + '/' + (s.total_accounts || 0) + '</dd>' +
      '</div></div></div>' +
      '<div class="rw-panel"><div class="rw-panel-pad">' +
      '<div class="rw-section-title">🕐 Khung giờ chạy</div>' +
      '<div class="rw-log">' +
      '<div class="rw-log-item"><span>🌅 07:37 sáng</span><span class="rw-badge running">farm</span></div>' +
      '<div class="rw-log-item"><span>☀️ 14:12 trưa</span><span class="rw-badge running">farm</span></div>' +
      '<div class="rw-log-item"><span>🌙 20:41 tối</span><span class="rw-badge running">farm</span></div>' +
      '<div class="rw-log-item"><span>🔍 23:03 audit</span><span class="rw-badge muted">audit</span></div>' +
      '</div></div></div></div>';
    var rl = container.querySelector('[data-sch-reload]');
    if (rl) rl.addEventListener('click', function () { state.data.schedule = null; renderSchedule(); });
  }

  /* ============================================================
   * 5. Thiết bị — nối dữ liệu v3 (api.php?action=devices + cmd_list)
   * ============================================================ */
  function renderDevices() {
    if (!state.data.devices) {
      container.innerHTML = '<div class="rw-skeleton"><div class="sk"></div><div class="sk"></div></div>';
      Promise.all([v3('devices'), v3('cmd_list&limit=100')]).then(function (r) {
        state.data.devices = r[0] ? (r[0].devices || []) : [];
        state.data.cmds = r[1] ? (r[1].commands || []) : [];
        renderDevices();
      });
      return;
    }
    var devs = state.data.devices || [];
    var cmds = state.data.cmds || [];
    function statusBadge(st) {
      st = String(st || '').toLowerCase();
      if (['online', 'idle', 'ok', 'ready'].includes(st)) return '<span class="rw-badge ok">' + RW.esc(st) + '</span>';
      if (['running', 'busy'].includes(st)) return '<span class="rw-badge running">' + RW.esc(st) + '</span>';
      if (['offline', 'error', 'dead'].includes(st)) return '<span class="rw-badge error">' + RW.esc(st) + '</span>';
      return '<span class="rw-badge muted">' + RW.esc(st || 'unknown') + '</span>';
    }
    container.innerHTML =
      '<div class="rw-head"><div><h2>🛠️ Thiết bị &amp; Lệnh</h2>' +
      '<div class="rw-muted">' + devs.length + ' thiết bị · ' + cmds.length + ' lệnh gần nhất</div></div>' +
      '<div class="rw-inline"><button class="rw-btn" data-dev-reload>↻ Làm mới</button></div></div>' +
      '<div class="rw-panel"><div class="rw-panel-pad"><div class="rw-section-title">Thiết bị</div></div>' +
      '<div class="rw-matrix-wrap"><table class="rw-table"><thead><tr>' +
      '<th>Tên</th><th>Trạng thái</th><th>Platform</th><th>Lệnh cuối</th><th>Cập nhật</th>' +
      '</tr></thead><tbody>' +
      (devs.length ? devs.map(function (d) {
        return '<tr><td><b>' + RW.esc(d.name || d.id) + '</b></td><td>' + statusBadge(d.status) + '</td>' +
          '<td>' + RW.esc(d.platform || '—') + '</td><td>' + RW.esc(d.last_cmd || '—') + '</td>' +
          '<td>' + RW.fmtTimeShort(d.last_seen || d.updated_at) + '</td></tr>';
      }).join('') : '<tr><td colspan="5" class="rw-empty">Chưa có thiết bị.</td></tr>') +
      '</tbody></table></div></div>' +
      '<div class="rw-panel" style="margin-top:.85rem"><div class="rw-panel-pad"><div class="rw-section-title">Lệnh gần nhất</div></div>' +
      '<div class="rw-log">' +
      (cmds.length ? cmds.slice(0, 40).map(function (c) {
        return '<div class="rw-log-item"><span>' + RW.fmtTimeShort(c.ts || c.created_at) + '</span>' +
          '<span><b>' + RW.esc(c.action || c.cmd) + '</b> → ' + RW.esc(c.device || '—') + '</span>' +
          RW.accBadge(c.status) + '</div>';
      }).join('') : '<div class="rw-empty">Chưa có lệnh.</div>') +
      '</div></div>';
    var rl = container.querySelector('[data-dev-reload]');
    if (rl) rl.addEventListener('click', function () { state.data.devices = null; renderDevices(); });
  }

  /* ============================================================
   * 6. Nhật ký — nối dữ liệu v3 (api.php?action=results)
   * ============================================================ */
  function renderLogs() {
    if (!state.data.logs) {
      container.innerHTML = '<div class="rw-skeleton"><div class="sk"></div></div>';
      v3('results').then(function (d) {
        state.data.logs = d ? (d.results || []) : [];
        renderLogs();
      });
      return;
    }
    var logs = state.data.logs || [];
    container.innerHTML =
      '<div class="rw-head"><div><h2>📄 Nhật ký RESULTS</h2>' +
      '<div class="rw-muted">' + logs.length + ' bản ghi gần nhất</div></div>' +
      '<div class="rw-inline"><button class="rw-btn" data-log-reload>↻ Làm mới</button></div></div>' +
      '<div class="rw-panel"><div class="rw-matrix-wrap"><table class="rw-table"><thead><tr>' +
      '<th>Ngày</th><th>Tổng</th><th>OK</th><th>Partial</th><th>Error</th><th>Needs human</th><th>Điểm</th>' +
      '</tr></thead><tbody>' +
      (logs.length ? logs.slice(-40).reverse().map(function (r) {
        return '<tr><td>' + RW.esc(r.date || '—') + '</td>' +
          '<td>' + RW.num(r.total) + '</td>' +
          '<td style="color:#4ade80">' + RW.num(r.ok) + '</td>' +
          '<td style="color:#facc15">' + RW.num(r.partial) + '</td>' +
          '<td style="color:#f87171">' + RW.num(r.error) + '</td>' +
          '<td style="color:#c084fc">' + RW.num(r.needs_human) + '</td>' +
          '<td><b>+' + RW.num(r.points) + '</b></td></tr>';
      }).join('') : '<tr><td colspan="7" class="rw-empty">Chưa có nhật ký.</td></tr>') +
      '</tbody></table></div></div>';
    var rl = container.querySelector('[data-log-reload]');
    if (rl) rl.addEventListener('click', function () { state.data.logs = null; renderLogs(); });
  }

  /* ============================================================
   * Dispatcher
   * ============================================================ */
  var RENDERERS = {
    overview: renderOverview,
    schedule: renderSchedule,
    devices: renderDevices,
    logs: renderLogs
  };

  window.RW_TAB_OTHER = {
    init: function (el, tabName) {
      container = el;
      state.active = tabName || 'overview';
      var fn = RENDERERS[state.active];
      if (fn) fn();
    },
    refresh: function () {
      var fn = RENDERERS[state.active];
      if (fn) fn();
    }
  };
})();
