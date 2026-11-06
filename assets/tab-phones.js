/* ============================================================
 * tab-phones.js — Tab Phone (PROMPT UI v4, mục 4).
 * Bảng che số (chỉ number_mask, KHÔNG bao giờ hiện số gốc);
 * import E.164 (một / hàng loạt có xem trước ✓⚠✗);
 * gắn / gỡ / chuyển / burned / đã xác minh + lịch sử thao tác;
 * tổng hợp cảnh báo thiếu phone (mục 4.4).
 * ============================================================ */
(function () {
  'use strict';
  var PH_API = 'api_v4_phones.php';
  var state = {
    rows: [], total: 0, page: 1, perPage: 50, sort: { by: 'id', dir: 'desc' },
    selected: new Set(), filters: {}, state: 'loading', errorMsg: '',
    summary: null, warning: ''
  };
  var container = null;

  function loadUrl() {
    var s = RW.readUrlState('ph');
    state.filters.q = s.q || '';
    state.filters.status = s.status || '';
    state.filters.assigned = s.assigned || '';
    state.filters.country = s.country || '';
    state.page = parseInt(s.page || '1', 10) || 1;
    if (s.sort) state.sort.by = s.sort;
    if (s.dir) state.sort.dir = s.dir === 'asc' ? 'asc' : 'desc';
  }
  function saveUrl() {
    RW.writeUrlState('ph', {
      q: state.filters.q, status: state.filters.status,
      assigned: state.filters.assigned, country: state.filters.country,
      page: state.page, sort: state.sort.by, dir: state.sort.dir
    });
  }

  function fetchList(silent) {
    if (!silent) { state.state = 'loading'; render(); }
    var params = new URLSearchParams();
    params.set('action', 'list');
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    params.set('sort', state.sort.by);
    params.set('dir', state.sort.dir);
    if (state.filters.q) params.set('q', state.filters.q);
    if (state.filters.status) params.set('status', state.filters.status);
    if (state.filters.assigned) params.set('assigned', state.filters.assigned);
    if (state.filters.country) params.set('country', state.filters.country);
    RW.api(PH_API + '?' + params.toString()).then(function (res) {
      if (!res.ok) { state.state = 'error'; state.errorMsg = RW.errText(res); render(); return; }
      var d = res.data || {};
      state.rows = d.phones || [];
      state.total = d.total || 0;
      state.state = state.rows.length ? 'ok' : 'empty';
      render();
      if (!silent) fetchSummary();
    });
  }

  /* ---- Tổng hợp + cảnh báo thiếu phone (mục 4.4) ---- */
  function fetchSummary() {
    RW.api(PH_API + '?action=summary').then(function (res) {
      if (!res.ok) return;
      state.summary = res.data.summary || null;
      state.warning = res.data.warning || '';
      render();
    });
  }

  /* ---- Render ---- */
  function render() {
    if (!container) return;
    container.innerHTML =
      '<div class="rw-head"><div><h2>📱 Phone</h2>' +
      '<div class="rw-muted">Tổng ' + RW.num(state.total) + ' · đã chọn ' + state.selected.size +
      ' · số điện thoại luôn được che, KHÔNG hiển thị nguyên bản</div></div>' +
      '<div class="rw-inline">' +
      '<button class="rw-btn primary" data-ph-add>＋ Thêm phone</button>' +
      '<button class="rw-btn" data-ph-import>📄 Import E.164</button>' +
      '<button class="rw-btn" data-ph-export>⬇ Xuất CSV</button>' +
      '<button class="rw-btn" data-ph-reload>↻ Làm mới</button>' +
      '</div></div>' +
      renderSummary() + renderFilters() +
      '<div class="rw-panel" data-ph-table></div>';
    bindHeader();
    RW.renderTable(container.querySelector('[data-ph-table]'), {
      selectable: true,
      state: state.state, errorMsg: state.errorMsg,
      rows: state.rows, total: state.total, page: state.page, perPage: state.perPage,
      sort: state.sort, selected: state.selected,
      columns: columns(),
      emptyText: 'Chưa có phone nào. Hãy "＋ Thêm phone" hoặc "📄 Import E.164".',
      emptyAction: '<button class="rw-btn primary" data-rw-empty-act>＋ Thêm phone đầu tiên</button>',
      onEmptyAction: function () { openAddPanel('single'); },
      onRetry: function () { fetchList(); },
      onSort: function (by, dir) { state.sort = { by: by, dir: dir }; state.page = 1; saveUrl(); fetchList(true); },
      onPage: function (p) { state.page = p; saveUrl(); fetchList(true); },
      onSelectionChange: function (sel) { state.selected = sel; render(); },
      rowAttrs: function (r) {
        return { attrs: 'data-ph-row="' + r.id + '"', class: r.status === 'burned' ? 'rw-row-dead' : '' };
      }
    });
    bindRows();
    renderBulkBar();
  }

  function renderSummary() {
    var s = state.summary;
    if (!s) return '';
    var warn = state.warning
      ? '<div class="rw-alert warn" role="alert">⚠ ' + RW.esc(state.warning) + '</div>' : '';
    return warn +
      '<div class="rw-stats">' +
      stat('Tổng phone', s.total !== undefined ? s.total : state.total) +
      stat('Rảnh (free)', s.phones_free) +
      stat('Đã gắn', s.phones_attached) +
      stat('Chờ xác minh', s.phones_pending_verify) +
      stat('Đã xác minh', s.phones_verified) +
      stat('Burned', s.phones_burned) +
      stat('Cần kiểm', s.phones_needs_check) +
      stat('Acc có phone', s.accounts_with_phone) +
      stat('Acc thiếu phone', s.accounts_without_phone) +
      '</div>';
  }
  function stat(label, v) {
    return '<div class="rw-stat"><div class="v">' + RW.num(v) + '</div><div class="k">' + RW.esc(label) + '</div></div>';
  }

  function renderFilters() {
    function chip(key, val, label) {
      var active = state.filters[key] === val;
      return '<button class="rw-chip' + (active ? ' active' : '') + '" data-ph-filter="' + key + '" data-ph-val="' + val + '">' + RW.esc(label) + '</button>';
    }
    return '<div class="rw-filters" style="margin:.65rem 0">' +
      '<input class="rw-input" style="max-width:16rem" placeholder="🔎 Tìm mask, carrier, ghi chú…" value="' + RW.esc(state.filters.q) + '" data-ph-search>' +
      chip('status', '', 'Tất cả') +
      chip('status', 'free', 'free') +
      chip('status', 'attached', 'attached') +
      chip('status', 'pending_verify', 'pending_verify') +
      chip('status', 'verified', 'verified') +
      chip('status', 'burned', 'burned') +
      chip('status', 'needs_check', 'needs_check') +
      chip('assigned', 'free', '○ Chưa gắn') +
      chip('assigned', 'busy', '● Đã gắn') +
      '</div>';
  }

  function columns() {
    return [
      { key: 'id', label: 'ID', sortable: true, width: '3.2rem' },
      { key: 'number_mask', label: 'Số (đã che)', sortable: true, render: function (r) {
          return '<span style="font-family:var(--font-mono)">' + RW.esc(r.number_mask || '****') + '</span>' +
            (r.carrier ? '<div class="rw-muted">' + RW.esc(r.carrier) + '</div>' : ''); } },
      { key: 'status', label: 'Trạng thái', sortable: true, render: function (r) { return RW.phoneBadge(r.status); } },
      { key: 'country', label: 'Quốc gia', render: function (r) {
          return RW.esc(r.country_code || r.country || '—'); } },
      { key: 'used', label: 'Đang gắn', render: function (r) {
          return (r.used_count || 0) + '/' + (r.max_accounts || 1) +
            (r.attached_email ? '<div class="rw-muted">👤 ' + RW.esc(r.attached_email) + '</div>' : ''); } },
      { key: 'verified_at', label: 'Xác minh', sortable: true, render: function (r) { return RW.fmtTimeShort(r.verified_at); } },
      { key: 'attached_at', label: 'Gắn lúc', sortable: true, render: function (r) { return RW.fmtTimeShort(r.attached_at); } },
      { key: '_act', label: 'Thao tác', render: function (r) {
          return '<button class="rw-btn" data-ph-status="' + r.id + '">⚙ Trạng thái</button>' +
            (r.used_count ? ' <button class="rw-btn" data-ph-unassign="' + r.id + '">⎋ Gỡ</button>' : '') +
            ' <button class="rw-btn" data-ph-hist="' + r.id + '">🕘 Lịch sử</button>' +
            ' <button class="rw-btn danger" data-ph-del="' + r.id + '">✕</button>'; } }
    ];
  }

  function bindHeader() {
    RW.qsa('[data-ph-filter]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-ph-filter'), v = b.getAttribute('data-ph-val');
        state.filters[k] = (state.filters[k] === v) ? '' : v;
        state.page = 1; saveUrl(); fetchList();
      });
    });
    var search = container.querySelector('[data-ph-search]');
    if (search) {
      var tm = null;
      search.addEventListener('input', function () {
        clearTimeout(tm);
        tm = setTimeout(function () { state.filters.q = search.value.trim(); state.page = 1; saveUrl(); fetchList(); }, 280);
      });
    }
    var add = container.querySelector('[data-ph-add]');
    if (add) add.addEventListener('click', function () { openAddPanel('single'); });
    var imp = container.querySelector('[data-ph-import]');
    if (imp) imp.addEventListener('click', function () { openAddPanel('bulk'); });
    var reload = container.querySelector('[data-ph-reload]');
    if (reload) reload.addEventListener('click', function () { fetchList(); });
    var exp = container.querySelector('[data-ph-export]');
    if (exp) exp.addEventListener('click', exportCsv);
  }

  function bindRows() {
    RW.qsa('[data-ph-status]', container).forEach(function (b) {
      b.addEventListener('click', function () { openStatusDialog(Number(b.getAttribute('data-ph-status'))); });
    });
    RW.qsa('[data-ph-unassign]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var id = Number(b.getAttribute('data-ph-unassign'));
        RW.confirmDestructive({
          title: 'Gỡ phone khỏi tài khoản',
          dangerText: 'Phone sẽ được giải phóng (status → free) và gỡ khỏi mọi tài khoản đang gắn.',
          okLabel: 'Gỡ ngay'
        }).then(function (ok) {
          if (!ok) return;
          RW.api(PH_API + '?action=unassign', { method: 'POST', body: { phone_id: id } }).then(function (res) {
            if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
            RW.toast(res.data.message || 'Đã gỡ phone.', 'ok');
            fetchList();
          });
        });
      });
    });
    RW.qsa('[data-ph-hist]', container).forEach(function (b) {
      b.addEventListener('click', function () { openHistory(Number(b.getAttribute('data-ph-hist'))); });
    });
    RW.qsa('[data-ph-del]', container).forEach(function (b) {
      b.addEventListener('click', function () { doDelete([Number(b.getAttribute('data-ph-del'))]); });
    });
  }

  /* ---- Thanh thao tác hàng loạt ---- */
  var bulkBar = null;
  function renderBulkBar() {
    if (bulkBar && bulkBar.parentNode) bulkBar.parentNode.removeChild(bulkBar);
    bulkBar = null;
    if (!state.selected.size) return;
    bulkBar = document.createElement('div');
    bulkBar.className = 'rw-bulkbar';
    bulkBar.innerHTML =
      '<span class="cnt">Đã chọn ' + state.selected.size + '</span>' +
      '<button class="rw-btn" data-phb-clear>Bỏ chọn</button>' +
      '<button class="rw-btn" data-phb-verified>✓ Đánh dấu đã xác minh</button>' +
      '<button class="rw-btn" data-phb-burned>🔥 Đánh dấu burned</button>' +
      '<button class="rw-btn" data-phb-free>⎋ Gỡ + free</button>' +
      '<button class="rw-btn danger" data-phb-del>✕ Xóa</button>';
    document.body.appendChild(bulkBar);
    bulkBar.querySelector('[data-phb-clear]').addEventListener('click', function () { state.selected.clear(); render(); });
    bulkBar.querySelector('[data-phb-verified]').addEventListener('click', function () { bulkStatus('verified'); });
    bulkBar.querySelector('[data-phb-burned]').addEventListener('click', function () { bulkStatus('burned'); });
    bulkBar.querySelector('[data-phb-free]').addEventListener('click', function () { bulkStatus('free'); });
    bulkBar.querySelector('[data-phb-del]').addEventListener('click', function () { doDelete(Array.from(state.selected)); });
  }

  function bulkStatus(status) {
    var ids = Array.from(state.selected);
    if (!ids.length) return;
    var label = { verified: 'đã xác minh', burned: 'burned', free: 'rảnh (gỡ khỏi tài khoản)' }[status] || status;
    RW.confirmDestructive({
      title: 'Đổi trạng thái hàng loạt → ' + status,
      dangerText: ids.length + ' phone sẽ được đánh dấu "' + label + '".',
      okLabel: 'Áp dụng'
    }).then(function (ok) {
      if (!ok) return;
      var pending = ids.length, failed = 0;
      ids.forEach(function (id) {
        RW.api(PH_API + '?action=set_status', { method: 'POST', body: { phone_id: id, status: status } }).then(function (res) {
          if (!res.ok) failed++;
          if (--pending === 0) {
            RW.toast(failed ? ('Hoàn tất với ' + failed + ' lỗi.') : ('Đã đổi trạng thái ' + ids.length + ' phone.'), failed ? 'warn' : 'ok');
            state.selected.clear(); fetchList();
          }
        });
      });
    });
  }

  /* ---- Xóa phone (mặc định soft; hard cần xác nhận 2 bước) ---- */
  function doDelete(ids) {
    if (!ids.length) return;
    RW.confirmDestructive({
      title: 'Xóa phone',
      scope: ids.length + ' phone (id: ' + ids.slice(0, 8).join(', ') + ')',
      dangerText: 'Phone sẽ bị gỡ khỏi tài khoản và đưa vào thùng rác (xóa mềm).',
      requireType: String(ids.length),
      okLabel: 'Xóa mềm'
    }).then(function (ok) {
      if (!ok) return;
      // hard delete (admin, 2 bước)
      RW.confirmDestructive({
        title: 'Xóa vĩnh viễn (hard)?',
        dangerText: 'Chọn "Xóa vĩnh viễn" để xóa hẳn khỏi DB (admin). Chọn "Chỉ xóa mềm" nếu muốn giữ.',
        okLabel: 'Xóa vĩnh viễn'
      }).then(function (hard) {
        var pending = ids.length, failed = 0;
        ids.forEach(function (id) {
          RW.api(PH_API + '?action=remove', { method: 'POST', body: { phone_id: id, hard: hard ? 1 : 0 } }).then(function (res) {
            if (!res.ok) failed++;
            if (--pending === 0) {
              RW.toast(failed ? ('Hoàn tất với ' + failed + ' lỗi.') : 'Đã xóa ' + ids.length + ' phone.', failed ? 'warn' : 'ok');
              state.selected.clear(); fetchList();
            }
          });
        });
      });
    });
  }

  /* ---- Dialog đổi trạng thái 1 phone (burned / verified / free / …) ---- */
  function openStatusDialog(phoneId) {
    var row = state.rows.filter(function (r) { return r.id === phoneId; })[0] || {};
    var body =
      '<div class="rw-field"><label>Trạng thái mới</label>' +
      '<select class="rw-input" data-phf-status>' +
      ['free', 'attached', 'pending_verify', 'verified', 'burned', 'needs_check'].map(function (s) {
        return '<option value="' + s + '"' + (row.status === s ? ' selected' : '') + '>' + s + '</option>';
      }).join('') + '</select></div>' +
      '<div class="rw-field"><label>Ghi chú (tùy chọn)</label>' +
      '<input class="rw-input" data-phf-note maxlength="250" placeholder="ví dụ: SIM hỏng, đổi số…"></div>' +
      '<div class="rw-warn">Chọn "free" sẽ tự động gỡ phone khỏi mọi tài khoản đang gắn.</div>';
    var panel = RW.slidePanel({
      title: 'Đổi trạng thái phone #' + phoneId + ' (' + RW.esc(row.number_mask || '?') + ')',
      body: body,
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button><button class="rw-btn primary" data-phf-save>Lưu</button>'
    });
    panel.el.querySelector('[data-phf-save]').addEventListener('click', function () {
      var st = panel.el.querySelector('[data-phf-status]').value;
      var note = panel.el.querySelector('[data-phf-note]').value.trim();
      var body = { phone_id: phoneId, status: st };
      if (note) body.note = note;
      RW.api(PH_API + '?action=set_status', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast('Đã đổi trạng thái → ' + st + '.', 'ok');
        panel.close(); fetchList();
      });
    });
  }

  /* ---- Lịch sử thao tác 1 phone ---- */
  function openHistory(phoneId) {
    var panel = RW.slidePanel({ title: 'Lịch sử phone #' + phoneId, body: '<div data-ph-hist-body><div class="rw-skeleton"><div class="sk"></div></div></div>' });
    RW.api(PH_API + '?action=history&phone_id=' + phoneId).then(function (res) {
      var el = panel.el.querySelector('[data-ph-hist-body]');
      if (!res.ok) { el.innerHTML = '<div class="rw-alert bad">' + RW.esc(RW.errText(res)) + '</div>'; return; }
      var rows = res.data.history || [];
      if (!rows.length) { el.innerHTML = '<div class="rw-empty">Chưa có lịch sử.</div>'; return; }
      el.innerHTML = '<table class="rw-table"><thead><tr><th>Thời điểm</th><th>Hành động</th><th>Chi tiết</th><th>Người làm</th></tr></thead><tbody>' +
        rows.map(function (h) {
          return '<tr><td>' + RW.fmtTime(h.ts, true) + '</td><td><b>' + RW.esc(h.action || '') + '</b></td>' +
            '<td>' + RW.esc(h.note || '') + (h.account_id ? '<div class="rw-muted">acc #' + h.account_id + ' ' + RW.esc(h.account_email || '') + '</div>' : '') + '</td>' +
            '<td>' + RW.esc(h.actor || '—') + '</td></tr>';
        }).join('') + '</tbody></table>';
    });
  }

  /* ---- Panel thêm phone / Import E.164 (2 chế độ) ---- */
  function openAddPanel(mode) {
    var body = '<div class="rw-subtabs">' +
      '<button class="rw-chip" data-phm="single">➕ Một phone</button>' +
      '<button class="rw-chip" data-phm="bulk">📄 Import E.164 (hàng loạt)</button>' +
      '</div><div data-phm-body></div>';
    var panel = RW.slidePanel({
      title: 'Thêm phone',
      body: body,
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button><button class="rw-btn primary" data-phm-submit>Thêm</button>'
    });
    var bodyEl = panel.el.querySelector('[data-phm-body]');
    function setMode(m) {
      mode = m;
      RW.qsa('[data-phm]', panel.el).forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-phm') === m); });
      if (m === 'single') {
        bodyEl.innerHTML =
          '<div class="rw-warn">Nhập theo chuẩn E.164, ví dụ <code>+84901234567</code>. Số sẽ được lưu ở dạng mã hóa + mask.</div>' +
          '<div class="rw-field"><label>Số điện thoại <span class="rw-req">*</span></label>' +
          '<input class="rw-input" data-f="number" placeholder="+84901234567" autocomplete="off"></div>' +
          '<div class="rw-field"><label>Mã quốc gia mặc định</label>' +
          '<input class="rw-input" data-f="default_country" value="VN" maxlength="2"></div>' +
          '<div class="rw-field"><label>Nhà mạng (carrier)</label><input class="rw-input" data-f="carrier" placeholder="Viettel / Mobifone…"></div>' +
          '<div class="rw-field"><label>Ghi chú</label><input class="rw-input" data-f="notes" maxlength="250"></div>' +
          '<div class="rw-field"><label>Số account tối đa / phone</label><input class="rw-input" data-f="max_accounts" type="number" min="0" value="0"><div class="rw-hint">0 = theo cấu hình hệ thống.</div></div>';
      } else {
        bodyEl.innerHTML =
          '<div class="rw-warn">Mỗi dòng 1 số E.164. Bấm "Xem trước" để phân loại ✓ hợp lệ / ⚠ trùng / ✗ lỗi trước khi import.</div>' +
          '<div class="rw-field"><label>Danh sách số</label>' +
          '<textarea class="rw-input" data-f="lines" rows="10" placeholder="+84901234567\n+84912345678"></textarea></div>' +
          '<div class="rw-field"><label>Mã quốc gia mặc định</label><input class="rw-input" data-f="default_country" value="VN" maxlength="2"></div>' +
          '<button class="rw-btn" data-phm-preview>🔍 Xem trước</button>' +
          '<div data-phm-result style="margin-top:.75rem"></div>';
        bodyEl.querySelector('[data-phm-preview]').addEventListener('click', function () {
          var lines = bodyEl.querySelector('[data-f="lines"]').value;
          if (!lines.trim()) { RW.toast('Thiếu danh sách số.', 'err'); return; }
          RW.api(PH_API + '?action=import_preview', {
            method: 'POST',
            body: { lines: lines, default_country: bodyEl.querySelector('[data-f="default_country"]').value.trim() || 'VN' }
          }).then(function (res) {
            if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
            var d = res.data;
            var c = d.counts || {};
            bodyEl.querySelector('[data-phm-result]').innerHTML =
              '<div class="rw-alert ok">Tổng ' + (d.total || 0) + ' · Hợp lệ ' + (c.ok || 0) +
              ' · Trùng ' + (c.dup || c.duplicate || 0) + ' · Lỗi ' + (c.error || c.errors || 0) + '</div>' +
              renderPreview(d.preview || []);
          });
        });
      }
    }
    RW.qsa('[data-phm]', panel.el).forEach(function (b) {
      b.addEventListener('click', function () { setMode(b.getAttribute('data-phm')); });
    });
    panel.el.querySelector('[data-phm-submit]').addEventListener('click', function () {
      if (mode === 'single') {
        var get = function (k) { var e = bodyEl.querySelector('[data-f="' + k + '"]'); return e ? e.value.trim() : ''; };
        var num = get('number');
        if (!num) { RW.toast('Thiếu số điện thoại (trường number).', 'err'); return; }
        if (!/^\+?[0-9]{6,16}$/.test(num)) { RW.toast('Số không đúng chuẩn E.164: "' + num + '".', 'err'); return; }
        var body = { number: num, default_country: get('default_country') || 'VN' };
        if (get('carrier')) body.carrier = get('carrier');
        if (get('notes')) body.notes = get('notes');
        if (get('max_accounts')) body.max_accounts = parseInt(get('max_accounts'), 10) || 0;
        RW.api(PH_API + '?action=add', { method: 'POST', body: body }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast('Đã thêm phone ' + (res.data.number_mask || '') + '.', 'ok');
          panel.close(); fetchList();
        });
      } else {
        var lines = bodyEl.querySelector('[data-f="lines"]').value;
        if (!lines.trim()) { RW.toast('Thiếu danh sách số.', 'err'); return; }
        RW.api(PH_API + '?action=import', {
          method: 'POST',
          body: { lines: lines, default_country: bodyEl.querySelector('[data-f="default_country"]').value.trim() || 'VN' }
        }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          var d = res.data || {};
          RW.toast(d.message || ('Đã import ' + (d.created || d.imported || 0) + ' phone.'), 'ok');
          panel.close(); fetchList();
        });
      }
    });
    setMode(mode || 'single');
  }

  /* Bảng xem trước import: ✓ hợp lệ / ⚠ trùng / ✗ lỗi — chỉ hiện mask */
  function renderPreview(items) {
    return '<table class="rw-table"><thead><tr><th>#</th><th>Số (đã che)</th><th>KQ</th><th>Lý do</th></tr></thead><tbody>' +
      items.map(function (it, i) {
        var st = it.status || (it.error ? 'error' : (it.duplicate ? 'dup' : 'ok'));
        var icon = st === 'ok' ? '<span class="pv ok">✓</span>' :
          (st === 'dup' || st === 'duplicate') ? '<span class="pv dup">⚠</span>' : '<span class="pv err">✗</span>';
        return '<tr><td>' + (it.no || (i + 1)) + '</td>' +
          '<td style="font-family:var(--font-mono)">' + RW.esc(it.number_mask || it.mask || '****') + '</td>' +
          '<td>' + icon + '</td><td>' + RW.esc(it.error || it.reason || (st === 'dup' ? 'Trùng lô / đã có trong DB' : '')) + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  function exportCsv() {
    RW.api(PH_API + '?action=export_csv').then(function (res) {
      if (res.ok && res.data && res.data.rows) {
        RW.downloadCsv('phones_' + Date.now() + '.csv', res.data.header || [], res.data.rows);
        RW.toast('Đã xuất CSV (số đã che).', 'ok');
      } else if (res.ok && res.data && res.data.csv) {
        RW.toast('Máy chủ trả CSV.', 'info');
      } else RW.toast(RW.errText(res), 'err');
    });
  }

  /* ---- Khởi tạo ---- */
  window.RW_TAB_PHONES = {
    init: function (el) {
      container = el;
      loadUrl();
      fetchList();
    },
    refresh: function () { fetchList(true); }
  };
})();
