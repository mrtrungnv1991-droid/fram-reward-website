/* ============================================================
 * tab-proxies.js — Tab Proxy (PROMPT UI v4, mục 3).
 * Pool bảng; nhập 3 chế độ (một/nhiều/dải host:20000-20199);
 * màn Gán proxy 2 cột (auto-assign có xem trước, kéo-thả, gán theo lô,
 * thay proxy dead); ProxyGroupBadge (mục 3.4) + công tắc Gom theo proxy +
 * Chỉ hiện nhóm trùng + Tách nhóm + legend; tab phụ hiệu năng (3.5).
 * ============================================================ */
(function () {
  'use strict';
  var PX_API = 'api_v4_proxies.php';
  var state = {
    rows: [], total: 0, page: 1, perPage: 50, sort: { by: 'id', dir: 'asc' },
    selected: new Set(), filters: {}, state: 'loading', errorMsg: '',
    groupMode: false, onlyDup: false, splitGroups: false, view: 'pool'
  };
  var container = null;

  function loadUrl() {
    var s = RW.readUrlState('px');
    state.filters.q = s.q || '';
    state.filters.status = s.status || '';
    state.filters.assigned = s.assigned || '';
    state.groupMode = s.group === '1';
    state.onlyDup = s.dup === '1';
    state.splitGroups = s.split === '1';
    state.view = s.view || 'pool';
    state.page = parseInt(s.page || '1', 10) || 1;
    if (s.sort) state.sort.by = s.sort;
    if (s.dir) state.sort.dir = s.dir === 'asc' ? 'asc' : 'desc';
  }
  function saveUrl() {
    RW.writeUrlState('px', {
      q: state.filters.q, status: state.filters.status, assigned: state.filters.assigned,
      group: state.groupMode ? '1' : '', dup: state.onlyDup ? '1' : '', split: state.splitGroups ? '1' : '',
      view: state.view, page: state.page, sort: state.sort.by, dir: state.sort.dir
    });
  }

  function fetchList(silent) {
    if (!silent) { state.state = 'loading'; render(); }
    var params = new URLSearchParams();
    params.set('action', 'list');
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.filters.q) params.set('q', state.filters.q);
    if (state.filters.status) params.set('status', state.filters.status);
    if (state.filters.assigned) params.set('assigned', state.filters.assigned);
    RW.api(PX_API + '?' + params.toString()).then(function (res) {
      if (!res.ok) { state.state = 'error'; state.errorMsg = RW.errText(res); render(); return; }
      var d = res.data || {};
      state.rows = d.proxies || [];
      state.total = d.total || 0;
      state.state = state.rows.length ? 'ok' : 'empty';
      render();
    });
  }

  /* ---- Render ---- */
  function render() {
    if (!container) return;
    container.innerHTML =
      '<div class="rw-head"><div><h2>🌐 Pool proxy</h2>' +
      '<div class="rw-muted">Tổng ' + RW.num(state.total) + ' · đã chọn ' + state.selected.size + '</div></div>' +
      '<div class="rw-inline">' +
      '<button class="rw-btn primary" data-px-add>＋ Thêm proxy</button>' +
      '<button class="rw-btn" data-px-assign>⇄ Gán proxy</button>' +
      '<button class="rw-btn" data-px-view="pool">📋 Pool</button>' +
      '<button class="rw-btn" data-px-view="perf">⚡ Hiệu năng</button>' +
      '<button class="rw-btn" data-px-reload>↻ Làm mới</button>' +
      '</div></div>' +
      renderControls() +
      '<div class="rw-panel" data-px-table></div>' + renderLegend();
    bindHeader();
    if (state.view === 'perf') { renderPerf(); return; }
    RW.renderTable(container.querySelector('[data-px-table]'), {
      selectable: true,
      state: state.state, errorMsg: state.errorMsg,
      rows: state.rows, total: state.total, page: state.page, perPage: state.perPage,
      sort: state.sort, selected: state.selected,
      columns: columns(),
      emptyText: 'Chưa có proxy nào. Bấm "＋ Thêm proxy".',
      emptyAction: '<button class="rw-btn primary" data-rw-empty-act>＋ Thêm proxy đầu tiên</button>',
      onEmptyAction: function () { openAddPanel('single'); },
      onRetry: function () { fetchList(); },
      onSort: function (by, dir) { state.sort = { by: by, dir: dir }; state.page = 1; saveUrl(); fetchList(true); },
      onPage: function (p) { state.page = p; saveUrl(); fetchList(true); },
      onSelectionChange: function (sel) { state.selected = sel; render(); },
      rowAttrs: function (r) {
        return { class: r.status === 'dead' ? 'rw-row-dead' : '', attrs: 'data-px-row="' + r.id + '"' };
      },
      groupRows: state.groupMode ? groupHeader : null
    });
    renderBulkBar();
  }

  /* ---- Hàng tiêu đề nhóm thu gọn (công tắc Gom theo proxy) ---- */
  var collapsedGroups = new Set();
  function groupHeader(row, idx) {
    if (idx === 0) return ''; // nhóm render theo row hiện tại
    var prev = state.rows[idx - 1];
    if (!prev || (prev.group_label || '') !== (row.group_label || '')) {
      var gk = row.group_label || ('P' + ('0' + ((RW.hash12(row.id) % 12) + 1)).slice(-2));
      var isCollapsed = collapsedGroups.has(gk);
      var size = state.rows.filter(function (r) { return (r.group_label || '') === (row.group_label || ''); }).length;
      return '<tr class="rw-group-head" data-px-group="' + RW.esc(gk) + '"><td colspan="12" style="cursor:pointer">' +
        (isCollapsed ? '▸' : '▾') + ' Nhóm ' + RW.esc(gk) + ' · ' + size + ' proxy' +
        ' <span class="rw-muted">(bấm để thu gọn/mở rộng)</span></td></tr>';
    }
    return '';
  }

  function renderControls() {
    return '<div class="rw-filters" style="margin:.65rem 0">' +
      '<input class="rw-input" style="max-width:16rem" placeholder="🔎 Tìm host, IP, nhãn…" value="' + RW.esc(state.filters.q || '') + '" data-px-search>' +
      '<button class="rw-chip' + (state.filters.status === 'alive' ? ' active' : '') + '" data-px-status="alive">● alive</button>' +
      '<button class="rw-chip' + (state.filters.status === 'slow' ? ' active' : '') + '" data-px-status="slow">◐ slow</button>' +
      '<button class="rw-chip' + (state.filters.status === 'dead' ? ' active' : '') + '" data-px-status="dead">✕ dead</button>' +
      '<button class="rw-chip' + (state.filters.assigned === 'free' ? ' active' : '') + '" data-px-assignf="free">○ Chưa gán</button>' +
      '<button class="rw-chip' + (state.filters.assigned === 'busy' ? ' active' : '') + '" data-px-assignf="busy">● Đã gán</button>' +
      '<span style="width:1rem"></span>' +
      '<button class="rw-chip' + (state.groupMode ? ' active' : '') + '" data-px-toggle="group">🔗 Gom theo proxy</button>' +
      '<button class="rw-chip' + (state.onlyDup ? ' active' : '') + '" data-px-toggle="dup">⚠ Chỉ hiện nhóm trùng</button>' +
      '<button class="rw-chip' + (state.splitGroups ? ' active' : '') + '" data-px-toggle="split">✂ Tách nhóm</button>' +
      '</div>';
  }

  function renderLegend() {
    return '<div class="rw-legend">' +
      '<span><span class="rw-dot alive"></span> alive (sống)</span>' +
      '<span><span class="rw-dot slow"></span> slow (chậm)</span>' +
      '<span><span class="rw-dot dead"></span> dead (chết — sọc đỏ)</span>' +
      '<span><span class="rw-dot unchecked"></span> chưa kiểm</span>' +
      '<span>◆ = nhóm nhiều proxy</span><span>⧉ = trùng IP thoát</span>' +
      '<span>TRÙNG ×N = vượt sức chứa</span><span>gạch dưới = cùng dải /24</span>' +
      '</div>';
  }

  function columns() {
    return [
      { key: 'id', label: 'ID', sortable: true, width: '3.2rem' },
      { key: 'group_label', label: 'Nhóm', render: function (r) {
          return RW.proxyGroupBadge({
            group: { id: r.id, label: r.group_label, color_idx: r.color_idx, text: r.mask || r.label,
                     size: r.used_count || 1, over: r.used_count > (r.max_profiles || 1),
                     dead: r.status === 'dead' }
          }); } },
      { key: 'host', label: 'Proxy (đã che)', sortable: true, render: function (r) {
          return '<span style="font-family:var(--font-mono)">' + RW.esc(r.mask || r.proxy_key || '—') + '</span>' +
            '<div class="rw-muted">sub: ' + RW.esc(r.subnet || '—') + '</div>'; } },
      { key: 'status', label: 'Trạng thái', sortable: true, render: function (r) { return RW.proxyStatusDot(r.status); } },
      { key: 'exit_ip', label: 'IP thoát', render: function (r) {
          return (r.exit_ip ? RW.esc(r.exit_ip) : '<span class="rw-muted">—</span>') +
            (r.same_exit ? ' <span class="rw-badge warn">⧉ trùng IP</span>' : ''); } },
      { key: 'latency_ms', label: 'Độ trễ', sortable: true, align: 'right', render: function (r) {
          return r.latency_ms !== null && r.latency_ms !== undefined ? RW.num(r.latency_ms) + ' ms' : '—'; } },
      { key: 'used_count', label: 'Sức chứa', sortable: true, render: function (r) {
          var u = r.used_count || 0, m = r.max_profiles || 1;
          return '<span' + (u > m ? ' style="color:var(--rw-err)"' : '') + '>' + u + '/' + m + '</span>' +
            (u > m ? ' <span class="rw-badge error">TRÙNG ×' + u + '</span>' : ''); } },
      { key: 'country', label: 'Quốc gia', render: function (r) { return RW.esc(r.geo || r.country || '—'); } },
      { key: 'last_checked', label: 'Kiểm cuối', sortable: true, render: function (r) { return RW.fmtTimeShort(r.last_checked); } },
      { key: '_act', label: 'Thao tác', render: function (r) {
          return '<button class="rw-btn" data-px-test="' + r.id + '">🔍 Kiểm</button>' +
          ' <button class="rw-btn danger" data-px-del="' + r.id + '">✕ Xóa</button>'; } }
    ];
  }

  function bindHeader() {
    var search = container.querySelector('[data-px-search]');
    if (search) {
      var tm = null;
      search.addEventListener('input', function () {
        clearTimeout(tm);
        tm = setTimeout(function () { state.filters.q = search.value.trim(); state.page = 1; saveUrl(); fetchList(); }, 280);
      });
    }
    RW.qsa('[data-px-status]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-px-status');
        state.filters.status = state.filters.status === v ? '' : v;
        state.page = 1; saveUrl(); fetchList();
      });
    });
    RW.qsa('[data-px-assignf]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-px-assignf');
        state.filters.assigned = state.filters.assigned === v ? '' : v;
        state.page = 1; saveUrl(); fetchList();
      });
    });
    RW.qsa('[data-px-toggle]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-px-toggle');
        if (k === 'group') state.groupMode = !state.groupMode;
        if (k === 'dup') state.onlyDup = !state.onlyDup;
        if (k === 'split') state.splitGroups = !state.splitGroups;
        saveUrl(); render();
      });
    });
    RW.qsa('[data-px-view]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        state.view = b.getAttribute('data-px-view'); saveUrl(); render();
      });
    });
    var add = container.querySelector('[data-px-add]');
    if (add) add.addEventListener('click', function () { openAddPanel('single'); });
    var assign = container.querySelector('[data-px-assign]');
    if (assign) assign.addEventListener('click', openAssignScreen);
    var reload = container.querySelector('[data-px-reload]');
    if (reload) reload.addEventListener('click', function () { fetchList(); });
    RW.qsa('[data-px-test]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var id = Number(b.getAttribute('data-px-test'));
        b.disabled = true; b.textContent = 'Đang kiểm…';
        RW.api(PX_API + '?action=test', { method: 'POST', body: { ids: [id] } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); } else { RW.toast(res.data.message || 'Đã kiểm.', 'ok'); fetchList(true); }
        });
      });
    });
    RW.qsa('[data-px-del]', container).forEach(function (b) {
      b.addEventListener('click', function () { doDelete([Number(b.getAttribute('data-px-del'))]); });
    });
  }

  var bulkBar = null;
  function renderBulkBar() {
    if (bulkBar && bulkBar.parentNode) bulkBar.parentNode.removeChild(bulkBar);
    bulkBar = null;
    if (!state.selected.size) return;
    bulkBar = document.createElement('div');
    bulkBar.className = 'rw-bulkbar';
    bulkBar.innerHTML = '<span class="cnt">Đã chọn ' + state.selected.size + '</span>' +
      '<button class="rw-btn" data-px-bulk-clear>Bỏ chọn</button>' +
      '<button class="rw-btn" data-px-bulk-test>🔍 Kiểm hàng loạt</button>' +
      '<button class="rw-btn danger" data-px-bulk-del>✕ Xóa đã chọn</button>';
    document.body.appendChild(bulkBar);
    bulkBar.querySelector('[data-px-bulk-clear]').addEventListener('click', function () { state.selected.clear(); render(); });
    bulkBar.querySelector('[data-px-bulk-test]').addEventListener('click', function () {
      RW.api(PX_API + '?action=test', { method: 'POST', body: { ids: Array.from(state.selected) } }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); } else { RW.toast(res.data.message || 'Đã kiểm.', 'ok'); state.selected.clear(); fetchList(); }
      });
    });
    bulkBar.querySelector('[data-px-bulk-del]').addEventListener('click', function () { doDelete(Array.from(state.selected)); });
  }

  function doDelete(ids) {
    if (!ids.length) return;
    RW.confirmDestructive({
      title: 'Xóa proxy',
      scope: ids.length + ' proxy (id: ' + ids.slice(0, 8).join(', ') + ')',
      dangerText: 'Proxy bị xóa sẽ được gỡ khỏi các tài khoản đang dùng. KHÔNG thể hoàn tác.',
      requireType: String(ids.length),
      okLabel: 'Xóa proxy'
    }).then(function (ok) {
      if (!ok) return;
      RW.api(PX_API + '?action=remove', { method: 'POST', body: { ids: ids } }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast(res.data.message || 'Đã xóa.', 'ok');
        state.selected.clear(); fetchList();
      });
    });
  }

  /* ---- Panel thêm proxy: 3 chế độ (một / nhiều / dải host:20000-20199) ---- */
  function openAddPanel(mode) {
    var body = '<div class="rw-subtabs">' +
      '<button class="rw-chip" data-pxm="single">➕ Một proxy</button>' +
      '<button class="rw-chip" data-pxm="bulk">📋 Nhiều dòng</button>' +
      '<button class="rw-chip" data-pxm="range">🔢 Dải host:port</button>' +
      '</div><div data-pxm-body></div>';
    var panel = RW.slidePanel({
      title: 'Thêm proxy',
      body: body,
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button><button class="rw-btn primary" data-pxm-submit>Thêm</button>'
    });
    var bodyEl = panel.el.querySelector('[data-pxm-body]');
    function setMode(m) {
      mode = m;
      RW.qsa('[data-pxm]', panel.el).forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-pxm') === m); });
      if (m === 'single') {
        bodyEl.innerHTML = '<div class="rw-field"><label>Chuỗi proxy <span class="rw-req">*</span></label>' +
          '<input class="rw-input" data-f="line" placeholder="host:port hoặc scheme://user:pass@host:port">' +
          '<div class="rw-hint">Mật khẩu proxy (nếu có) chỉ được GỬI ĐI, sẽ KHÔNG hiển thị lại.</div></div>' +
          '<div class="rw-field"><label>Nhãn</label><input class="rw-input" data-f="label" placeholder="tùy chọn"></div>';
      } else if (m === 'bulk') {
        bodyEl.innerHTML = '<div class="rw-field"><label>Mỗi dòng 1 proxy</label>' +
          '<textarea class="rw-input" data-f="lines" rows="10" placeholder="1.2.3.4:8080&#10;socks5://user:pass@5.6.7.8:1080"></textarea></div>' +
          '<button class="rw-btn" data-pxm-preview>🔍 Xem trước</button><div data-pxm-result style="margin-top:.75rem"></div>';
      } else {
        bodyEl.innerHTML = '<div class="rw-warn">Dạng: <code>host:port_start-port_end</code> — ví dụ <code>proxy.example.com:20000-20199</code></div>' +
          '<div class="rw-field"><label>Dải proxy <span class="rw-req">*</span></label>' +
          '<input class="rw-input" data-f="range" placeholder="proxy.example.com:20000-20199"></div>' +
          '<button class="rw-btn" data-pxm-expand>🔍 Mở rộng dải</button><div data-pxm-result style="margin-top:.75rem"></div>';
      }
      var pvBtn = bodyEl.querySelector('[data-pxm-preview]');
      if (pvBtn) pvBtn.addEventListener('click', function () {
        var lines = bodyEl.querySelector('[data-f="lines"]').value;
        if (!lines.trim()) { RW.toast('Thiếu nội dung.', 'err'); return; }
        RW.api(PX_API + '?action=bulk_preview', { method: 'POST', body: { lines: lines } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          var d = res.data;
          bodyEl.querySelector('[data-pxm-result]').innerHTML =
            '<div class="rw-alert ok">Hợp lệ ' + (d.valid || 0) + ' · Trùng ' + (d.duplicate || 0) + ' · Lỗi ' + (d.error || 0) + '</div>' +
            renderPreviewItems(d.items || []);
        });
      });
      var exBtn = bodyEl.querySelector('[data-pxm-expand]');
      if (exBtn) exBtn.addEventListener('click', function () {
        var range = bodyEl.querySelector('[data-f="range"]').value;
        if (!range.trim()) { RW.toast('Thiếu dải proxy.', 'err'); return; }
        RW.api(PX_API + '?action=range_expand', { method: 'POST', body: { range: range } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          var d = res.data;
          bodyEl.querySelector('[data-pxm-result]').innerHTML =
            '<div class="rw-alert ok">Sẽ tạo ' + (d.total || (d.items || []).length) + ' proxy từ dải.</div>' +
            renderPreviewItems((d.items || []).slice(0, 50));
        });
      });
    }
    RW.qsa('[data-pxm]', panel.el).forEach(function (b) {
      b.addEventListener('click', function () { setMode(b.getAttribute('data-pxm')); });
    });
    panel.el.querySelector('[data-pxm-submit]').addEventListener('click', function () {
      if (mode === 'single') {
        var line = bodyEl.querySelector('[data-f="line"]').value.trim();
        if (!line) { RW.toast('Thiếu chuỗi proxy.', 'err'); return; }
        var body = { line: line };
        var lbl = bodyEl.querySelector('[data-f="label"]');
        if (lbl && lbl.value.trim()) body.label = lbl.value.trim();
        RW.api(PX_API + '?action=add', { method: 'POST', body: body }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã thêm.', 'ok'); panel.close(); fetchList();
        });
      } else if (mode === 'bulk') {
        var lines = bodyEl.querySelector('[data-f="lines"]').value;
        if (!lines.trim()) { RW.toast('Thiếu nội dung.', 'err'); return; }
        RW.api(PX_API + '?action=bulk_add', { method: 'POST', body: { lines: lines } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã thêm.', 'ok'); panel.close(); fetchList();
        });
      } else {
        var range = bodyEl.querySelector('[data-f="range"]').value.trim();
        if (!range) { RW.toast('Thiếu dải proxy.', 'err'); return; }
        RW.api(PX_API + '?action=range_expand', { method: 'POST', body: { range: range, apply: 1 } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã tạo proxy từ dải.', 'ok'); panel.close(); fetchList();
        });
      }
    });
    setMode(mode || 'single');
  }

  function renderPreviewItems(items) {
    return '<table class="rw-table"><thead><tr><th>#</th><th>Dòng</th><th>KQ</th><th>Lý do</th></tr></thead><tbody>' +
      items.map(function (it) {
        var icon = it.status === 'ok' ? '<span class="pv ok">✓</span>' : it.status === 'duplicate' ? '<span class="pv dup">⚠</span>' : '<span class="pv err">✗</span>';
        return '<tr><td>' + (it.no || '') + '</td><td style="font-family:var(--font-mono)">' + RW.esc(it.line || '') + '</td>' +
          '<td>' + icon + '</td><td>' + RW.esc(it.reason || '') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  /* ============================================================
   * Màn Gán proxy 2 cột (mục 3.3): auto-assign xem trước, kéo-thả,
   * gán theo lô, thay proxy dead.
   * ============================================================ */
  function openAssignScreen() {
    var body =
      '<div class="rw-warn">Kéo proxy (cột trái) thả vào tài khoản (cột phải), hoặc dùng auto-assign có xem trước.</div>' +
      '<div class="rw-inline" style="margin:.75rem 0">' +
      '<button class="rw-btn primary" data-as-auto>⚙ Auto-assign (xem trước)</button>' +
      '<button class="rw-btn" data-as-batch>⇄ Gán theo lô</button>' +
      '<button class="rw-btn" data-as-replace>🔄 Thay proxy dead</button>' +
      '</div>' +
      '<div data-as-result style="margin:.75rem 0"></div>' +
      '<div class="rw-grid2"><div><div class="rw-section-title">Proxy chưa đầy</div><div data-as-proxies></div></div>' +
      '<div><div class="rw-section-title">Tài khoản chưa có proxy</div><div data-as-accounts></div></div></div>';
    var panel = RW.slidePanel({ title: 'Gán proxy', body: body });

    // Tải 2 cột
    RW.api(PX_API + '?action=list&assigned=free&per_page=100').then(function (res) {
      var el = panel.el.querySelector('[data-as-proxies]');
      if (!res.ok) { el.innerHTML = '<div class="rw-alert bad">' + RW.esc(RW.errText(res)) + '</div>'; return; }
      var rows = (res.data.proxies || []);
      if (!rows.length) { el.innerHTML = '<div class="rw-empty">Không có proxy trống.</div>'; return; }
      el.innerHTML = rows.map(function (r) {
        return '<div class="rw-log-item" draggable="true" data-as-drag-proxy="' + r.id + '" style="cursor:grab">' +
          RW.proxyGroupBadge({ group: { id: r.id, label: r.group_label, color_idx: r.color_idx, text: r.mask } }) +
          '<span class="rw-muted">' + (r.used_count || 0) + '/' + (r.max_profiles || 1) + '</span></div>';
      }).join('');
      bindDrag(panel);
    });
    RW.api('api_v4_accounts.php?action=list&has_proxy=0&per_page=100').then(function (res) {
      var el = panel.el.querySelector('[data-as-accounts]');
      if (!res.ok) { el.innerHTML = '<div class="rw-alert bad">' + RW.esc(RW.errText(res)) + '</div>'; return; }
      var rows = (res.data.data || []);
      if (!rows.length) { el.innerHTML = '<div class="rw-empty">Tất cả tài khoản đã có proxy.</div>'; return; }
      el.innerHTML = rows.map(function (r) {
        return '<div class="rw-log-item" data-as-drop="' + r.id + '"><span style="font-family:var(--font-mono)">' + RW.esc(r.email) + '</span>' +
          RW.accBadge(r.status) + '</div>';
      }).join('');
      bindDrag(panel);
    });

    panel.el.querySelector('[data-as-auto]').addEventListener('click', function () {
      RW.api(PX_API + '?action=auto_assign_preview', { method: 'POST', body: {} }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        var d = res.data;
        var plan = d.plan || d.assignments || [];
        panel.el.querySelector('[data-as-result]').innerHTML =
          '<div class="rw-alert ok">Xem trước: ' + (d.total || plan.length) + ' cặp sẽ được gán.</div>' +
          '<button class="rw-btn primary" data-as-apply>✓ Áp dụng</button>' +
          '<table class="rw-table" style="margin-top:.55rem"><thead><tr><th>Tài khoản</th><th>→ Proxy</th></tr></thead><tbody>' +
          plan.slice(0, 40).map(function (p) {
            return '<tr><td>' + RW.esc(p.email || p.account_id) + '</td><td>' + RW.esc(p.proxy_label || p.proxy_id) + '</td></tr>';
          }).join('') + '</tbody></table>';
        var ap = panel.el.querySelector('[data-as-apply]');
        if (ap) ap.addEventListener('click', function () {
          RW.api(PX_API + '?action=auto_assign_apply', { method: 'POST', body: {} }).then(function (res2) {
            if (!res2.ok) { RW.toast(RW.errText(res2), 'err'); return; }
            RW.toast(res2.data.message || 'Đã gán.', 'ok');
            panel.close(); fetchList();
          });
        });
      });
    });
    panel.el.querySelector('[data-as-replace]').addEventListener('click', function () {
      RW.confirmDestructive({
        title: 'Thay proxy dead',
        dangerText: 'Tất cả tài khoản đang dùng proxy dead sẽ được gán lại proxy sống.',
        okLabel: 'Thay ngay'
      }).then(function (ok) {
        if (!ok) return;
        RW.api(PX_API + '?action=replace_dead', { method: 'POST', body: {} }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã thay.', 'ok'); panel.close(); fetchList();
        });
      });
    });
    panel.el.querySelector('[data-as-batch]').addEventListener('click', function () {
      if (!state.selected.size) { RW.toast('Hãy chọn proxy trong bảng trước khi gán theo lô.', 'warn'); return; }
      var ids = Array.from(state.selected);
      RW.confirmDestructive({
        title: 'Gán theo lô',
        dangerText: ids.length + ' proxy đã chọn sẽ được gán cho các tài khoản chưa có proxy.',
        okLabel: 'Gán ngay'
      }).then(function (ok) {
        if (!ok) return;
        RW.api(PX_API + '?action=assign', { method: 'POST', body: { proxy_ids: ids, mode: 'batch' } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast(res.data.message || 'Đã gán.', 'ok'); panel.close(); fetchList();
        });
      });
    });
  }

  /* Kéo-thả: proxy -> tài khoản */
  function bindDrag(panel) {
    RW.qsa('[data-as-drag-proxy]', panel.el).forEach(function (src) {
      src.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', src.getAttribute('data-as-drag-proxy'));
        src.classList.add('rw-dragging');
      });
      src.addEventListener('dragend', function () { src.classList.remove('rw-dragging'); });
    });
    RW.qsa('[data-as-drop]', panel.el).forEach(function (tgt) {
      tgt.addEventListener('dragover', function (e) { e.preventDefault(); tgt.classList.add('rw-drop-ok'); });
      tgt.addEventListener('dragleave', function () { tgt.classList.remove('rw-drop-ok'); });
      tgt.addEventListener('drop', function (e) {
        e.preventDefault();
        tgt.classList.remove('rw-drop-ok');
        var pid = Number(e.dataTransfer.getData('text/plain'));
        var aid = Number(tgt.getAttribute('data-as-drop'));
        if (!pid || !aid) return;
        RW.api(PX_API + '?action=assign', { method: 'POST', body: { proxy_id: pid, account_id: aid } }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast('Đã gán proxy #' + pid + ' → tài khoản #' + aid + '.', 'ok');
          tgt.classList.add('rw-drop-busy');
        });
      });
    });
  }

  /* ---- Tab phụ hiệu năng theo proxy (mục 3.5) ---- */
  function renderPerf() {
    var el = container.querySelector('[data-px-table]');
    el.innerHTML = '<div class="rw-skeleton"><div class="sk"></div><div class="sk"></div></div>';
    RW.api('api_v4_config.php?action=status').then(function (res) {
      if (!res.ok) { el.innerHTML = '<div class="rw-alert bad">' + RW.esc(RW.errText(res)) + '</div>'; return; }
      var rows = state.rows.map(function (r) {
        var okRate = r.used_count ? Math.round(((r.used_count - (r.fail_count || 0)) / r.used_count) * 100) : 100;
        return '<tr><td>' + RW.esc(r.mask || r.id) + '</td><td>' + RW.proxyStatusDot(r.status) + '</td>' +
          '<td>' + (r.latency_ms !== null && r.latency_ms !== undefined ? RW.num(r.latency_ms) + ' ms' : '—') + '</td>' +
          '<td>' + (r.fail_count || 0) + '</td><td>' + okRate + '%</td>' +
          '<td>' + (r.last_checked ? RW.fmtTimeShort(r.last_checked) : '—') + '</td></tr>';
      }).join('');
      el.innerHTML = '<div class="rw-section-title">⚡ Hiệu năng theo proxy</div>' +
        '<table class="rw-table"><thead><tr><th>Proxy</th><th>Trạng thái</th><th>Độ trễ</th><th>Lỗi</th><th>OK%</th><th>Kiểm cuối</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table>';
    });
  }

  /* ---- Khởi tạo ---- */
  window.RW_TAB_PROXIES = {
    init: function (el) {
      container = el;
      loadUrl();
      fetchList();
    },
    refresh: function () { fetchList(true); }
  };
})();
