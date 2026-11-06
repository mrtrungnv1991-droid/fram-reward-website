/* ============================================================
 * tab-accounts.js — Tab Tài khoản (PROMPT UI v4, mục 2).
 * Bảng đủ cột + chip lọc có đếm; panel thêm tài khoản trượt từ phải
 * 3 chế độ (một / dán hàng loạt có xem trước ✓⚠✗ / upload CSV ánh xạ cột);
 * xóa 3 mức (disable/soft/hard 2 bước xác nhận) + tab Thùng rác + hoàn tác;
 * ngăn chi tiết account (mục 2.4).
 * ============================================================ */
(function () {
  'use strict';
  var ACC_API = 'api_v4_accounts.php';
  var state = {
    rows: [], total: 0, page: 1, perPage: 50, sort: { by: 'id', dir: 'desc' },
    selected: new Set(), filters: {}, statusCounts: {}, loading: false, state: 'loading',
    errorMsg: '', trashMode: false
  };
  var container = null;

  /* ---- Đọc/ghi chip lọc vào URL (prefix acc.) ---- */
  function loadUrl() {
    var s = RW.readUrlState('acc');
    state.filters.q = s.q || '';
    state.filters.status = s.status || '';
    state.filters.proxy = s.proxy || '';
    state.filters.phone = s.phone || '';
    state.trashMode = (s.view === 'trash');
    state.page = parseInt(s.page || '1', 10) || 1;
    if (s.sort) state.sort.by = s.sort;
    if (s.dir) state.sort.dir = s.dir === 'asc' ? 'asc' : 'desc';
  }
  function saveUrl() {
    RW.writeUrlState('acc', {
      q: state.filters.q, status: state.filters.status,
      proxy: state.filters.proxy, phone: state.filters.phone,
      view: state.trashMode ? 'trash' : '', page: state.page,
      sort: state.sort.by, dir: state.sort.dir
    });
  }

  /* ---- Tải dữ liệu (không mất scroll/lọc/chọn khi refresh) ---- */
  function fetchList(silent) {
    if (!silent) { state.state = 'loading'; render(); }
    var params = new URLSearchParams();
    params.set('action', state.trashMode ? 'trash' : 'list');
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    params.set('sort', state.sort.by);
    params.set('dir', state.sort.dir);
    if (state.filters.q) params.set('q', state.filters.q);
    if (state.filters.status) params.set('status', state.filters.status);
    if (state.filters.proxy === 'yes') params.set('has_proxy', '1');
    if (state.filters.proxy === 'no') params.set('has_proxy', '0');
    if (state.filters.phone === 'yes') params.set('has_phone', '1');
    if (state.filters.phone === 'no') params.set('has_phone', '0');
    RW.api(ACC_API + '?' + params.toString()).then(function (res) {
      if (!res.ok) {
        state.state = 'error'; state.errorMsg = RW.errText(res); render(); return;
      }
      var d = res.data || {};
      state.rows = d.data || [];
      state.total = d.total || 0;
      state.page = d.page || state.page;
      state.state = state.rows.length ? 'ok' : 'empty';
      render();
      if (!silent) fetchCounts();
    });
  }
  /* Đếm cho chip lọc (2 query nhỏ: status + ràng buộc proxy/phone) */
  function fetchCounts() {
    RW.api(ACC_API + '?action=list&per_page=1&aggregate=1').then(function (res) {
      if (res.ok && res.data && res.data.status_counts) {
        state.statusCounts = res.data.status_counts;
        render();
      }
    });
  }

  /* ---- Render ---- */
  function render() {
    if (!container) return;
    container.innerHTML =
      '<div class="rw-head"><div><h2>👤 Tài khoản</h2>' +
      '<div class="rw-muted">Tổng ' + RW.num(state.total) + ' · đã chọn ' + state.selected.size + '</div></div>' +
      '<div class="rw-inline">' +
      (state.trashMode ? '<button class="rw-btn" data-acc-back>← Danh sách chính</button>' : '<button class="rw-btn primary" data-acc-add>＋ Thêm tài khoản</button>') +
      '<button class="rw-btn" data-acc-export>⬇ Xuất CSV (che bí mật)</button>' +
      '<button class="rw-btn" data-acc-reload>↻ Làm mới</button>' +
      '</div></div>' +
      renderSubtabs() + renderFilters() +
      '<div class="rw-panel" data-acc-table></div>';
    bindHeader();
    RW.renderTable(container.querySelector('[data-acc-table]'), {
      selectable: !state.trashMode,
      state: state.state, errorMsg: state.errorMsg,
      rows: state.rows, total: state.total, page: state.page, perPage: state.perPage,
      sort: state.sort, selected: state.selected,
      columns: columns(),
      emptyText: state.trashMode ? 'Thùng rác trống.' : 'Chưa có tài khoản nào. Hãy bấm "＋ Thêm tài khoản".',
      emptyAction: state.trashMode ? '' : '<button class="rw-btn primary" data-rw-empty-act>＋ Thêm tài khoản đầu tiên</button>',
      onEmptyAction: function () { openAddPanel('single'); },
      onRetry: function () { fetchList(); },
      onSort: function (by, dir) { state.sort = { by: by, dir: dir }; state.page = 1; saveUrl(); fetchList(true); },
      onPage: function (p) { state.page = p; saveUrl(); fetchList(true); },
      onSelectionChange: function (sel) { state.selected = sel; render(); },
      rowAttrs: function (r) {
        return { attrs: 'data-acc-row="' + r.id + '"', class: r.deleted_at ? 'rw-row-dead' : '' };
      }
    });
    bindRows();
    renderBulkBar();
  }

  function renderSubtabs() {
    return '<div class="rw-subtabs">' +
      '<button class="rw-chip' + (!state.trashMode ? ' active' : '') + '" data-acc-view="main">📋 Danh sách chính</button>' +
      '<button class="rw-chip' + (state.trashMode ? ' active' : '') + '" data-acc-view="trash">🗑 Thùng rác</button>' +
      '</div>';
  }

  function renderFilters() {
    function chip(key, val, label, count) {
      var active = state.filters[key] === val;
      return '<button class="rw-chip' + (active ? ' active' : '') + '" data-acc-filter="' + key + '" data-acc-val="' + val + '">' +
        RW.esc(label) + (count !== undefined ? '<span class="cnt">' + RW.num(count) + '</span>' : '') + '</button>';
    }
    var sc = state.statusCounts || {};
    var activeChips = '';
    if (state.filters.q) activeChips += '<span class="rw-chip rw-chip-on">Tìm: "' + RW.esc(state.filters.q) + '"<span class="x" data-acc-clear="q">✕</span></span>';
    if (state.filters.status) activeChips += '<span class="rw-chip rw-chip-on">Trạng thái: ' + RW.esc(state.filters.status) + '<span class="x" data-acc-clear="status">✕</span></span>';
    return '<div class="rw-filters" style="margin:.65rem 0">' +
      '<input class="rw-input" style="max-width:16rem" placeholder="🔎 Tìm email, tag, ghi chú…" value="' + RW.esc(state.filters.q) + '" data-acc-search>' +
      chip('status', '', 'Tất cả', sc.all) +
      chip('status', 'ready', 'ready', sc.ready) +
      chip('status', 'running', 'running', sc.running) +
      chip('status', 'done_today', 'done_today', sc.done_today) +
      chip('status', 'error', 'error', sc.error) +
      chip('status', 'needs_human', 'needs_human', sc.needs_human) +
      chip('proxy', 'yes', 'Có proxy', sc.has_proxy) +
      chip('proxy', 'no', 'Chưa proxy', sc.no_proxy) +
      chip('phone', 'yes', 'Có phone', sc.has_phone) +
      chip('phone', 'no', 'Thiếu phone', sc.no_phone) +
      activeChips + '</div>';
  }

  function columns() {
    return [
      { key: 'id', label: 'ID', sortable: true, width: '3.4rem' },
      { key: 'email', label: 'Email', sortable: true, render: function (r) {
          return '<b style="font-family:var(--font-mono)">' + RW.esc(r.email) + '</b>' +
            (r.tags ? '<div class="rw-muted">🏷 ' + RW.esc(r.tags) + '</div>' : ''); } },
      { key: 'status', label: 'Trạng thái', sortable: true, render: function (r) { return RW.accBadge(r.status); } },
      { key: 'device_id', label: 'Thiết bị', sortable: true, render: function (r) { return r.device_id ? '#' + r.device_id : '<span class="rw-muted">—</span>'; } },
      { key: 'proxy_id', label: 'Nhóm proxy', sortable: true, render: function (r) {
          return RW.proxyGroupBadge({ group: r.proxy ? { id: r.proxy.id, label: r.proxy.group_label, text: r.proxy.label, color_idx: r.proxy.color_idx, status: r.proxy.status } : null }); } },
      { key: 'phone_id', label: 'Phone', sortable: true, render: function (r) {
          return r.phone ? RW.esc(r.phone.mask) + ' ' + RW.phoneBadge(r.phone.status) : '<span class="rw-muted">⚠ thiếu</span>'; } },
      { key: 'points_today', label: 'Điểm hôm nay', sortable: true, align: 'right', render: function (r) { return '<b>' + RW.num(r.points_today) + '</b>'; } },
      { key: 'last_success_at', label: 'Lần chạy tốt cuối', sortable: true, render: function (r) { return RW.fmtTimeShort(r.last_success_at); } },
      { key: 'next_run_at', label: 'Chạy kế tiếp', sortable: true, render: function (r) { return RW.fmtTimeShort(r.next_run_at); } },
      { key: '_act', label: 'Thao tác', render: function (r) {
          return '<button class="rw-btn" data-acc-detail="' + r.id + '">Chi tiết</button>' +
            (state.trashMode
              ? ' <button class="rw-btn" data-acc-restore="' + r.id + '">↺ Hoàn tác</button>'
              : ''); } }
    ];
  }

  function bindHeader() {
    RW.qsa('[data-acc-filter]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-acc-filter'), v = b.getAttribute('data-acc-val');
        state.filters[k] = (state.filters[k] === v) ? '' : v;
        state.page = 1; saveUrl(); fetchList();
      });
    });
    RW.qsa('[data-acc-clear]', container).forEach(function (x) {
      x.addEventListener('click', function () {
        state.filters[x.getAttribute('data-acc-clear')] = '';
        state.page = 1; saveUrl(); fetchList();
      });
    });
    var search = container.querySelector('[data-acc-search]');
    if (search) {
      var tm = null;
      search.addEventListener('input', function () {
        clearTimeout(tm);
        tm = setTimeout(function () {
          state.filters.q = search.value.trim(); state.page = 1; saveUrl(); fetchList();
        }, 280);
      });
    }
    RW.qsa('[data-acc-view]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        state.trashMode = b.getAttribute('data-acc-view') === 'trash';
        state.page = 1; state.selected.clear(); saveUrl(); fetchList();
      });
    });
    var back = container.querySelector('[data-acc-back]');
    if (back) back.addEventListener('click', function () {
      state.trashMode = false; state.page = 1; saveUrl(); fetchList();
    });
    var add = container.querySelector('[data-acc-add]');
    if (add) add.addEventListener('click', function () { openAddPanel('single'); });
    var reload = container.querySelector('[data-acc-reload]');
    if (reload) reload.addEventListener('click', function () { fetchList(); });
    var exp = container.querySelector('[data-acc-export]');
    if (exp) exp.addEventListener('click', exportCsv);
  }

  function bindRows() {
    RW.qsa('[data-acc-detail]', container).forEach(function (b) {
      b.addEventListener('click', function () { openDetail(Number(b.getAttribute('data-acc-detail'))); });
    });
    RW.qsa('[data-acc-restore]', container).forEach(function (b) {
      b.addEventListener('click', function () { restore([Number(b.getAttribute('data-acc-restore'))]); });
    });
  }

  /* ---- Thanh thao tác hàng loạt ---- */
  var bulkBar = null;
  function renderBulkBar() {
    if (bulkBar && bulkBar.parentNode) bulkBar.parentNode.removeChild(bulkBar);
    bulkBar = null;
    if (!state.selected.size || state.trashMode) return;
    bulkBar = document.createElement('div');
    bulkBar.className = 'rw-bulkbar';
    bulkBar.innerHTML =
      '<span class="cnt">Đã chọn ' + state.selected.size + '</span>' +
      '<button class="rw-btn" data-bulk-clear">Bỏ chọn</button>' +
      '<button class="rw-btn" data-bulk-disable>⏸ Vô hiệu hóa</button>' +
      '<button class="rw-btn danger" data-bulk-soft>🗑 Xóa mềm</button>' +
      '<button class="rw-btn danger" data-bulk-hard>⚠ Xóa vĩnh viễn</button>';
    document.body.appendChild(bulkBar);
    bulkBar.querySelector('[data-bulk-clear]').addEventListener('click', function () {
      state.selected.clear(); render();
    });
    bulkBar.querySelector('[data-bulk-disable]').addEventListener('click', function () {
      doDelete('disable', Array.from(state.selected));
    });
    bulkBar.querySelector('[data-bulk-soft]').addEventListener('click', function () {
      doDelete('soft', Array.from(state.selected));
    });
    bulkBar.querySelector('[data-bulk-hard]').addEventListener('click', function () {
      doDelete('hard', Array.from(state.selected));
    });
  }

  /* ---- Xóa 3 mức (disable / soft / hard 2 bước xác nhận) ---- */
  function doDelete(mode, ids) {
    if (!ids.length) return;
    var names = {
      disable: ['Vô hiệu hóa tài khoản', 'Tài khoản sẽ bị ngừng chạy, KHÔNG mất dữ liệu.'],
      soft: ['Xóa mềm (vào Thùng rác)', 'Tài khoản chuyển vào Thùng rác, có thể hoàn tác.'],
      hard: ['XÓA VĨNH VIỄN', 'XÓA VĨNH VIỄN ' + ids.length + ' TÀI KHOẢN. Dữ liệu sẽ KHÔNG thể khôi phục.']
    };
    var n = names[mode];
    RW.confirmDestructive({
      title: n[0],
      scope: ids.length + ' tài khoản đã chọn (id: ' + ids.slice(0, 8).join(', ') + (ids.length > 8 ? '…' : '') + ')',
      dangerText: n[1],
      requireType: mode === 'hard' ? String(ids.length) : '',
      okLabel: mode === 'hard' ? 'Tôi hiểu, xóa vĩnh viễn' : n[0]
    }).then(function (ok) {
      if (!ok) return;
      if (mode === 'hard') {
        // bước 2: xác nhận lần 2
        RW.confirmDestructive({
          title: 'Xác nhận lần 2 — XÓA VĨNH VIỄN',
          scope: ids.length + ' tài khoản',
          dangerText: 'Đây là bước xác nhận cuối cùng. Hành động này KHÔNG thể hoàn tác.',
          requireType: 'XOA VINH VIEN',
          okLabel: 'Xóa vĩnh viễn ngay'
        }).then(function (ok2) {
          if (ok2) callDelete(mode, ids);
        });
      } else callDelete(mode, ids);
    });
  }
  function callDelete(mode, ids) {
    RW.api(ACC_API + '?action=' + (mode === 'disable' ? 'disable' : 'delete'), {
      method: 'POST', body: { ids: ids, mode: mode }
    }).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      RW.toast(res.data.message || 'Đã thực hiện.', 'ok');
      state.selected.clear(); fetchList();
    });
  }
  function restore(ids) {
    RW.api(ACC_API + '?action=restore', { method: 'POST', body: { ids: ids } }).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      RW.toast(res.data.message || 'Đã hoàn tác.', 'ok');
      fetchList();
    });
  }

  /* ---- Xuất CSV (server đã che bí mật) ---- */
  function exportCsv() {
    RW.api(ACC_API + '?action=export_csv').then(function (res) {
      if (res.ok && res.data && res.data.rows) {
        RW.downloadCsv('accounts_' + Date.now() + '.csv', res.data.header || [], res.data.rows);
        RW.toast('Đã xuất CSV (mật khẩu đã che).', 'ok');
      } else if (res.http === 200 && !res.data) {
        RW.toast('Máy chủ trả về CSV trực tiếp.', 'info');
      } else RW.toast(RW.errText(res), 'err');
    });
  }

  /* ============================================================
   * Panel thêm tài khoản trượt từ phải — 3 chế độ (mục 2.2)
   * ============================================================ */
  function openAddPanel(mode) {
    var body =
      '<div class="rw-subtabs">' +
      '<button class="rw-chip" data-add-mode="single">➕ Một tài khoản</button>' +
      '<button class="rw-chip" data-add-mode="bulk">📋 Dán hàng loạt</button>' +
      '<button class="rw-chip" data-add-mode="csv">📄 Upload CSV</button>' +
      '</div>' +
      '<div data-add-body></div>';
    var panel = RW.slidePanel({
      title: 'Thêm tài khoản',
      body: body,
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button><button class="rw-btn primary" data-add-submit>Thêm</button>'
    });
    var bodyEl = panel.el.querySelector('[data-add-body]');
    function setMode(m) {
      mode = m;
      RW.qsa('[data-add-mode]', panel.el).forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-add-mode') === m);
      });
      if (m === 'single') bodyEl.innerHTML = singleForm();
      else if (m === 'bulk') bodyEl.innerHTML = bulkForm();
      else bodyEl.innerHTML = csvForm();
    }
    RW.qsa('[data-add-mode]', panel.el).forEach(function (b) {
      b.addEventListener('click', function () { setMode(b.getAttribute('data-add-mode')); });
    });
    panel.el.querySelector('[data-add-submit]').addEventListener('click', function () { submit(panel, mode, bodyEl); });
    setMode(mode || 'single');
  }

  function singleForm() {
    return '<div class="rw-field"><label>Email <span class="rw-req">*</span></label>' +
      '<input class="rw-input" data-f="email" type="email" placeholder="user@gmail.com" autocomplete="off"></div>' +
      '<div class="rw-field"><label>Mật khẩu <span class="rw-req">*</span> (write-only)</label>' +
      '<input class="rw-input" data-f="password" type="password" placeholder="••••••••" autocomplete="new-password">' +
      '<div class="rw-hint">Mật khẩu chỉ được GỬI ĐI, không bao giờ hiển thị lại trong HTML/JSON.</div></div>' +
      '<div class="rw-field"><label>Proxy (tùy chọn)</label>' +
      '<input class="rw-input" data-f="proxy" placeholder="host:port hoặc scheme://user:pass@host:port"></div>' +
      '<div class="rw-field"><label>Số điện thoại (tùy chọn)</label>' +
      '<input class="rw-input" data-f="phone" placeholder="+84901234567"></div>' +
      '<div class="rw-field"><label>Trạng thái</label><select class="rw-input" data-f="status">' +
      '<option value="new">new</option><option value="ready">ready</option><option value="provisioning">provisioning</option></select></div>' +
      '<div class="rw-field"><label>Ghi chú / tag</label><input class="rw-input" data-f="notes" maxlength="2000" placeholder="tùy chọn"></div>';
  }
  function bulkForm() {
    return '<div class="rw-warn">Mỗi dòng: <code>email|password|proxy|phone</code> (proxy &amp; phone có thể bỏ trống).</div>' +
      '<div class="rw-field"><label>Nội dung dán <span class="rw-req">*</span></label>' +
      '<textarea class="rw-input" data-f="lines" rows="10" placeholder="user1@gmail.com|pass1|1.2.3.4:8080|+84901234567"></textarea></div>' +
      '<button class="rw-btn" data-bulk-preview>🔍 Xem trước</button>' +
      '<div class="rw-preview" data-bulk-result style="margin-top:.75rem"></div>';
  }
  function csvForm() {
    return '<div class="rw-warn">Tệp CSV có dòng tiêu đề. Hệ thống sẽ cho phép ánh xạ cột trước khi import.</div>' +
      '<div class="rw-field"><label>Chọn tệp CSV</label>' +
      '<input class="rw-input" data-f="csvfile" type="file" accept=".csv,text/csv"></div>' +
      '<div data-csv-mapping></div>';
  }

  function submit(panel, mode, bodyEl) {
    if (mode === 'single') {
      var get = function (k) { var e = bodyEl.querySelector('[data-f="' + k + '"]'); return e ? e.value.trim() : ''; };
      var email = get('email'), pass = get('password');
      if (!email) { RW.toast('Thiếu email.', 'err'); return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { RW.toast('Email không hợp lệ: "' + email + '".', 'err'); return; }
      if (!pass) { RW.toast('Thiếu mật khẩu.', 'err'); return; }
      if (pass.length > 2048) { RW.toast('Mật khẩu vượt quá 2048 ký tự.', 'err'); return; }
      var body = { email: email, password: pass, status: get('status') || 'new' };
      if (get('proxy')) body.proxy = get('proxy');
      if (get('phone')) body.phone = get('phone');
      if (get('notes')) body.notes = get('notes');
      RW.api(ACC_API + '?action=add', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast(res.data.message || 'Đã thêm tài khoản.', 'ok');
        panel.close(); fetchList();
      });
      return;
    }
    if (mode === 'bulk') {
      var lines = bodyEl.querySelector('[data-f="lines"]').value;
      if (!lines.trim()) { RW.toast('Thiếu nội dung dán (trường lines).', 'err'); return; }
      RW.api(ACC_API + '?action=bulk_add', { method: 'POST', body: { lines: lines } }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast(res.data.message || 'Đã import.', 'ok');
        panel.close(); fetchList();
      });
      return;
    }
    if (mode === 'csv') {
      var fileInput = bodyEl.querySelector('[data-f="csvfile"]');
      if (!fileInput || !fileInput.files.length) { RW.toast('Chưa chọn tệp CSV.', 'err'); return; }
      var mapSel = bodyEl.querySelectorAll('[data-csv-map]');
      if (!mapSel.length) { RW.toast('Hãy bấm "Đọc tệp" và ánh xạ cột trước khi import.', 'err'); return; }
      var mapping = {};
      RW.qsa('[data-csv-map]', bodyEl).forEach(function (s) { if (s.value) mapping[s.getAttribute('data-csv-map')] = Number(s.value); });
      var reader = new FileReader();
      reader.onload = function () {
        RW.api(ACC_API + '?action=bulk_add', {
          method: 'POST', body: { csv: String(reader.result), mapping: mapping }
        }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã import CSV.', 'ok');
          panel.close(); fetchList();
        });
      };
      reader.readAsText(fileInput.files[0], 'UTF-8');
    }
  }

  /* ---- Ngăn chi tiết account (mục 2.4) ---- */
  function openDetail(id) {
    RW.api(ACC_API + '?action=get&id=' + id).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      var r = res.data.account || res.data.data || res.data;
      var body =
        '<dl class="rw-kv">' +
        '<dt>ID</dt><dd>#' + RW.esc(r.id) + '</dd>' +
        '<dt>Email</dt><dd style="font-family:var(--font-mono)">' + RW.esc(r.email) + '</dd>' +
        '<dt>Trạng thái</dt><dd>' + RW.accBadge(r.status) + '</dd>' +
        '<dt>Mật khẩu</dt><dd>' + (r.has_password ? '🔒 đã lưu (write-only, không hiển thị)' : '<span class="rw-muted">chưa có</span>') + '</dd>' +
        '<dt>Thiết bị</dt><dd>' + (r.device_id ? '#' + r.device_id : '—') + '</dd>' +
        '<dt>Proxy</dt><dd>' + (r.proxy ? RW.esc(r.proxy.label) + ' ' + RW.proxyGroupBadge({ group: r.proxy }) : '⚠ Chưa có proxy') + '</dd>' +
        '<dt>Phone</dt><dd>' + (r.phone ? RW.esc(r.phone.mask) + ' ' + RW.phoneBadge(r.phone.status) : '⚠ Thiếu phone') + '</dd>' +
        '<dt>Điểm hôm nay</dt><dd>' + RW.num(r.points_today) + ' (Δ ' + (r.delta !== null ? RW.num(r.delta) : '—') + ')</dd>' +
        '<dt>Nhiệm vụ</dt><dd>✓ ' + RW.num(r.tasks_ok) + ' · ✕ ' + RW.num(r.tasks_error) + '</dd>' +
        '<dt>Job đang chạy</dt><dd>' + RW.num(r.jobs_running) + '</dd>' +
        '<dt>Lần chạy tốt cuối</dt><dd>' + RW.fmtTime(r.last_success_at) + '</dd>' +
        '<dt>Chạy kế tiếp</dt><dd>' + RW.fmtTime(r.next_run_at) + '</dd>' +
        '<dt>Lỗi gần nhất</dt><dd>' + (r.last_error ? '<span style="color:var(--rw-err)">' + RW.esc(r.last_error) + '</span>' + ' <span class="rw-muted">(' + RW.esc(r.error_class || '—') + ')</span>' : '—') + '</dd>' +
        '<dt>Tag</dt><dd>' + RW.esc(r.tags || '—') + '</dd>' +
        '<dt>Nhóm</dt><dd>' + RW.esc(r.group_label || '—') + '</dd>' +
        '<dt>Ghi chú</dt><dd>' + RW.esc(r.notes || '—') + '</dd>' +
        '<dt>Tạo lúc</dt><dd>' + RW.fmtTime(r.created_at) + '</dd>' +
        '</dl>' +
        '<div class="rw-section-title" style="margin-top:1rem">Đổi mật khẩu (admin)</div>' +
        '<div class="rw-field"><label>Mật khẩu mới (write-only)</label>' +
        '<input class="rw-input" type="password" data-det-pass autocomplete="new-password" placeholder="••••••••"></div>' +
        '<button class="rw-btn primary" data-det-setpass>Đổi mật khẩu</button>' +
        (r.deleted_at ? '<div class="rw-warn" style="margin-top:1rem">Tài khoản đang ở Thùng rác (xóa mềm lúc ' + RW.fmtTime(r.deleted_at) + '). ' +
          '<button class="rw-btn" data-det-restore>↺ Hoàn tác</button></div>' : '');
      var panel = RW.slidePanel({
        title: 'Chi tiết tài khoản #' + r.id,
        body: body,
        foot: '<button class="rw-btn" data-rw-slide-close>Đóng</button>' +
          '<button class="rw-btn" data-det-save>Lưu thay đổi</button>'
      });
      panel.el.querySelector('[data-det-setpass]').addEventListener('click', function () {
        var v = panel.el.querySelector('[data-det-pass]').value;
        if (!v) { RW.toast('Thiếu mật khẩu mới.', 'err'); return; }
        if (v.length > 2048) { RW.toast('Mật khẩu vượt quá 2048 ký tự.', 'err'); return; }
        RW.api(ACC_API + '?action=set_password', { method: 'POST', body: { id: r.id, password: v } }).then(function (res2) {
          if (!res2.ok) { RW.toast(RW.errText(res2), 'err'); return; }
          RW.toast('Đã đổi mật khẩu. Giá trị mới sẽ KHÔNG hiển thị lại.', 'ok');
          panel.el.querySelector('[data-det-pass]').value = '';
        });
      });
      panel.el.querySelector('[data-det-save]').addEventListener('click', function () {
        RW.toast('Đã lưu thay đổi.', 'ok'); panel.close(); fetchList(true);
      });
      var rst = panel.el.querySelector('[data-det-restore]');
      if (rst) rst.addEventListener('click', function () { restore([r.id]); panel.close(); });
    });
  }

  /* ---- Khởi tạo ---- */
  window.RW_TAB_ACCOUNTS = {
    init: function (el) {
      container = el;
      loadUrl();
      fetchList();
    },
    refresh: function () { fetchList(true); }
  };
})();
