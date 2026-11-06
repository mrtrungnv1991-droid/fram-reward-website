/* ============================================================
 * rw-core.js — Component dùng chung UI v4 (JS thuần, không CDN).
 * Gồm: escape, format giờ Asia/Bangkok, fetch wrapper (CSRF + lỗi),
 * toast, modal xác nhận phá hủy (gõ lại số lượng), panel trượt,
 * bảng dùng chung (chọn nhiều + shift-click, tìm kiếm, chip lọc lưu URL,
 * sắp xếp, phân trang, xuất CSV), 4 trạng thái dữ liệu, polling 10s có
 * pause khi tab ẩn, ProxyGroupBadge (mục 3.4), huy hiệu đồng bộ config.
 * ============================================================ */
(function (global) {
  'use strict';

  /* ================= Tiện ích cơ bản ================= */
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
  }
  function el(id) { return document.getElementById(id); }
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* Múi giờ hiển thị Asia/Bangkok (UTC+7) cho mọi timestamp */
  var TZ = 'Asia/Bangkok';
  function fmtTime(v, withSec) {
    if (!v) return '—';
    var d = new Date(String(v).replace(' ', 'T') + (String(v).slice(-1) === 'Z' ? '' : 'Z'));
    if (isNaN(d.getTime())) return String(v);
    try {
      return d.toLocaleString('vi-VN', {
        timeZone: TZ, hour12: false,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
        second: withSec ? '2-digit' : undefined
      });
    } catch (e) { return String(v); }
  }
  function fmtTimeShort(v) {
    if (!v) return '—';
    var d = new Date(String(v).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return String(v);
    try {
      return d.toLocaleString('vi-VN', { timeZone: TZ, hour12: false, hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
    } catch (e) { return String(v); }
  }
  function minutesAgo(v) {
    if (!v) return null;
    var d = new Date(String(v).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return null;
    return Math.floor((Date.now() - d.getTime()) / 60000);
  }
  function num(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('vi-VN');
  }

  /* ================= CSRF + fetch wrapper ================= */
  function csrfTok() {
    var m = document.querySelector('meta[name=csrf]');
    return m ? m.content : '';
  }
  /**
   * Gọi API và trả object. Không throw: luôn trả {ok, data|error, http}.
   * Tự chuyển hướng về login khi 401.
   */
  function api(url, opts) {
    opts = opts || {};
    var method = (opts.method || 'GET').toUpperCase();
    var init = { method: method, credentials: 'same-origin', headers: {} };
    if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrfTok();
      if (typeof opts.body === 'string') {
        init.body = opts.body; // gui nguyen body string (urlencoded/form)
      } else {
        init.body = JSON.stringify(opts.body || {});
      }
    }
    // Ton trong headers tuy chinh cua goi (vi du Content-Type urlencoded)
    if (opts.headers) {
      Object.keys(opts.headers).forEach(function (k) {
        if (k.toLowerCase() === 'content-type') { init.headers['Content-Type'] = opts.headers[k]; }
        else if (k.toLowerCase() === 'x-csrf-token') { init.headers['X-CSRF-Token'] = opts.headers[k]; }
        else { init.headers[k] = opts.headers[k]; }
      });
    }
    return fetch(url, init).then(function (r) {
      if (r.status === 401) { location.href = 'index.php'; return { ok: false, http: 401, error: { message: 'Phiên đã hết hạn.' } }; }
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { j = null; }
        return { ok: r.ok && (j ? (j.ok !== false) : false), http: r.status, data: j, error: (j && j.error) ? j.error : (r.ok ? null : { message: 'HTTP ' + r.status }) };
      });
    }).catch(function (e) {
      return { ok: false, http: 0, error: { message: 'Lỗi mạng: ' + e.message } };
    });
  }
  function errText(res) {
    if (!res) return 'Lỗi không xác định.';
    var e = res.error;
    if (!e) return 'Lỗi không xác định (HTTP ' + res.http + ').';
    return (e.message || e.code || ('Lỗi HTTP ' + res.http)) + '';
  }

  /* ================= Toast (3 giây, hỗ trợ bàn phím) ================= */
  var toastBox = null;
  function toast(msg, type) {
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'rw-toasts';
      toastBox.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastBox);
    }
    var t = document.createElement('div');
    t.className = 'rw-toast ' + (type || 'info');
    t.setAttribute('role', type === 'err' ? 'alert' : 'status');
    t.textContent = msg;
    toastBox.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 3000);
  }

  /* ================= Hộp xác nhận phá hủy ================= */
  /**
   * confirmDestructive({title, scope, dangerText, confirmCount, confirmWord, requireType,
   *   okLabel, onOk}). requireType = số mà user phải gõ lại (số lượng) hoặc chuỗi.
   * Trả về Promise<boolean>.
   */
  function confirmDestructive(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var wrap = document.createElement('div');
      wrap.className = 'rw-modal open';
      wrap.setAttribute('role', 'dialog');
      wrap.setAttribute('aria-modal', 'true');
      wrap.setAttribute('aria-label', opts.title || 'Xác nhận');
      var required = opts.requireType;
      var canType = (required !== undefined && required !== null && required !== '');
      wrap.innerHTML =
        '<div class="rw-modal-box">' +
        '<h3>' + esc(opts.title || 'Xác nhận thao tác phá hủy') + '</h3>' +
        '<div class="rw-danger">' + esc(opts.dangerText || '') + '</div>' +
        (opts.scope ? '<div class="rw-warn">Phạm vi: ' + esc(opts.scope) + '</div>' : '') +
        (canType ? '<div class="rw-field"><label>Để xác nhận, hãy gõ lại: <b style="color:var(--rw-err)">' + esc(String(required)) + '</b></label>' +
          '<input class="rw-input" id="rwConfirmType" autocomplete="off" placeholder="' + esc(String(required)) + '"></div>' : '') +
        '<div class="rw-inline" style="justify-content:flex-end;margin-top:1rem">' +
        '<button class="rw-btn" id="rwConfirmCancel">Hủy</button>' +
        '<button class="rw-btn danger" id="rwConfirmOk"' + (canType ? ' disabled' : '') + '>' + esc(opts.okLabel || 'Xác nhận xóa') + '</button>' +
        '</div></div>';
      document.body.appendChild(wrap);
      var okBtn = qs('#rwConfirmOk', wrap);
      var input = qs('#rwConfirmType', wrap);
      function close(v) {
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
        document.removeEventListener('keydown', onKey);
        resolve(v);
      }
      function onKey(e) { if (e.key === 'Escape') close(false); }
      document.addEventListener('keydown', onKey);
      qs('#rwConfirmCancel', wrap).addEventListener('click', function () { close(false); });
      okBtn.addEventListener('click', function () {
        if (canType && String(input.value).trim() !== String(required)) {
          toast('Chuỗi xác nhận chưa khớp.', 'err');
          return;
        }
        close(true);
      });
      if (canType) {
        input.addEventListener('input', function () {
          okBtn.disabled = String(input.value).trim() !== String(required);
        });
        input.focus();
      } else { okBtn.focus(); }
    });
  }

  /* ================= Panel trượt từ phải ================= */
  var openPanels = [];
  function slidePanel(opts) {
    opts = opts || {};
    var scrim = document.createElement('div');
    scrim.className = 'rw-scrim';
    var panel = document.createElement('div');
    panel.className = 'rw-slide';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', opts.title || 'Bảng điều khiển');
    panel.innerHTML =
      '<div class="rw-slide-head"><b>' + esc(opts.title || '') + '</b>' +
      '<button class="rw-btn" data-rw-slide-close aria-label="Đóng">✕</button></div>' +
      '<div class="rw-slide-body">' + (opts.body || '') + '</div>' +
      (opts.foot ? '<div class="rw-slide-foot">' + opts.foot + '</div>' : '');
    document.body.appendChild(scrim);
    document.body.appendChild(panel);
    requestAnimationFrame(function () { scrim.classList.add('open'); panel.classList.add('open'); });
    var apiObj = {
      el: panel, scrim: scrim,
      close: function () {
        scrim.classList.remove('open'); panel.classList.remove('open');
        setTimeout(function () {
          if (panel.parentNode) panel.parentNode.removeChild(panel);
          if (scrim.parentNode) scrim.parentNode.removeChild(scrim);
        }, 260);
        openPanels = openPanels.filter(function (x) { return x !== apiObj; });
      }
    };
    scrim.addEventListener('click', apiObj.close);
    qsa('[data-rw-slide-close]', panel).forEach(function (b) { b.addEventListener('click', apiObj.close); });
    openPanels.push(apiObj);
    return apiObj;
  }

  /* ================= Bảng dùng chung ================= */
  /**
   * rwTable.render(container, config) — config:
   *   id, columns[{key,label,sortable,render,width,align}], rows[], total, page, perPage,
   *   stateKey (để lưu URL), sort{by,dir}, selected (Set id), onSelectionChange,
   *   onSort(by,dir), onPage(page), rowAttrs(row) -> {attrs}, emptyText, emptyAction,
   *   state ('loading'|'empty'|'error'|'ok'), errorMsg, onRetry, groupRows (fn trả group header trước row)
   * Tính năng: chọn nhiều + shift-click, header checkbox, giữ scroll khi render lại.
   */
  function renderTable(container, cfg) {
    var scrollWrap = qs('.rw-matrix-wrap', container);
    var prevScrollTop = scrollWrap ? scrollWrap.scrollTop : 0;
    var prevScrollLeft = scrollWrap ? scrollWrap.scrollLeft : 0;

    if (cfg.state === 'loading') {
      container.innerHTML = '<div class="rw-skeleton" aria-busy="true">' +
        Array(7).join('<div class="sk"></div>') + '<div class="sk" style="width:60%"></div></div>';
      return;
    }
    if (cfg.state === 'error') {
      container.innerHTML = '<div class="rw-errorbox"><div class="rw-alert bad">⚠ ' + esc(cfg.errorMsg || 'Không tải được dữ liệu.') + '</div>' +
        '<button class="rw-btn" data-rw-retry>↻ Thử lại</button></div>';
      var rb = qs('[data-rw-retry]', container);
      if (rb && cfg.onRetry) rb.addEventListener('click', cfg.onRetry);
      return;
    }
    var rows = cfg.rows || [];
    if (!rows.length && !cfg.hideEmpty) {
      container.innerHTML = '<div class="rw-empty">' + esc(cfg.emptyText || 'Không có dữ liệu.') +
        (cfg.emptyAction ? '<div class="rw-empty-act">' + cfg.emptyAction + '</div>' : '') + '</div>';
      if (cfg.onEmptyAction) {
        var eb = qs('[data-rw-empty-act]', container);
        if (eb) eb.addEventListener('click', cfg.onEmptyAction);
      }
      return;
    }
    var cols = cfg.columns || [];
    var selected = cfg.selected || new Set();
    var allIds = rows.map(function (r) { return r.id; });
    var allSel = allIds.length && allIds.every(function (i) { return selected.has(i); });

    var head = '<tr>';
    if (cfg.selectable) {
      head += '<th style="width:2.1rem"><input type="checkbox" class="rw-check" data-rw-sel-all aria-label="Chọn tất cả hàng"' + (allSel ? ' checked' : '') + '></th>';
    }
    cols.forEach(function (c) {
      var sortState = 'none';
      if (cfg.sort && c.sortable) {
        sortState = cfg.sort.by === c.key ? (cfg.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
      }
      head += '<th' + (c.sortable ? ' class="rw-sortable" tabindex="0" role="columnheader" aria-sort="' + sortState + '" data-rw-sort="' + esc(c.key) + '"' : '') +
        (c.width ? ' style="width:' + esc(c.width) + '"' : '') + '>' + esc(c.label) + '</th>';
    });
    head += '</tr>';

    var body = '';
    rows.forEach(function (r, idx) {
      if (cfg.groupRows) {
        var gh = cfg.groupRows(r, idx);
        if (gh) body += gh;
      }
      var extra = cfg.rowAttrs ? (cfg.rowAttrs(r) || {}) : {};
      var cls = [];
      if (selected.has(r.id)) cls.push('rw-row-selected');
      if (extra.class) cls.push(extra.class);
      body += '<tr data-rw-id="' + esc(r.id) + '" data-rw-idx="' + idx + '"' +
        (cls.length ? ' class="' + cls.join(' ') + '"' : '') +
        (extra.attrs ? ' ' + extra.attrs : '') + '>';
      if (cfg.selectable) {
        body += '<td><input type="checkbox" class="rw-check" data-rw-sel="' + esc(r.id) + '" aria-label="Chọn hàng"' + (selected.has(r.id) ? ' checked' : '') + '></td>';
      }
      cols.forEach(function (c) {
        var val = c.render ? c.render(r, idx) : esc(r[c.key]);
        body += '<td' + (c.align ? ' style="text-align:' + c.align + '"' : '') + '>' + val + '</td>';
      });
      body += '</tr>';
    });

    var html = '<div class="rw-matrix-wrap" tabindex="0" role="region" aria-label="Bảng dữ liệu">' +
      '<table class="rw-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>' +
      (cfg.pager !== false ? renderPager(cfg) : '');
    container.innerHTML = html;

    var wrap = qs('.rw-matrix-wrap', container);
    if (wrap) { wrap.scrollTop = prevScrollTop; wrap.scrollLeft = prevScrollLeft; }

    /* --- Chọn nhiều + shift-click --- */
    if (cfg.selectable) {
      var selAll = qs('[data-rw-sel-all]', container);
      if (selAll) {
        selAll.addEventListener('change', function () {
          if (selAll.checked) allIds.forEach(function (i) { selected.add(i); });
          else allIds.forEach(function (i) { selected.delete(i); });
          if (cfg.onSelectionChange) cfg.onSelectionChange(selected);
          renderTable(container, cfg);
        });
      }
      qsa('[data-rw-sel]', container).forEach(function (cb) {
        cb.addEventListener('click', function (e) {
          var id = isNaN(Number(cb.getAttribute('data-rw-sel'))) ? cb.getAttribute('data-rw-sel') : Number(cb.getAttribute('data-rw-sel'));
          var idx = Number(cb.closest('tr').getAttribute('data-rw-idx'));
          if (e.shiftKey && cfg._lastSelIdx !== undefined && cfg._lastSelIdx !== null) {
            var a = Math.min(cfg._lastSelIdx, idx), b = Math.max(cfg._lastSelIdx, idx);
            for (var i = a; i <= b; i++) {
              var rid = rows[i] ? rows[i].id : null;
              if (rid !== null) { if (cb.checked) selected.add(rid); else selected.delete(rid); }
            }
          } else {
            if (cb.checked) selected.add(id); else selected.delete(id);
          }
          cfg._lastSelIdx = idx;
          if (cfg.onSelectionChange) cfg.onSelectionChange(selected);
          renderTable(container, cfg);
        });
      });
    }

    /* --- Sắp xếp --- */
    qsa('[data-rw-sort]', container).forEach(function (th) {
      function doSort() {
        var key = th.getAttribute('data-rw-sort');
        var dir = 'asc';
        if (cfg.sort && cfg.sort.by === key) dir = cfg.sort.dir === 'asc' ? 'desc' : 'asc';
        if (cfg.onSort) cfg.onSort(key, dir);
      }
      th.addEventListener('click', doSort);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doSort(); }
      });
    });

    /* --- Phân trang --- */
    qsa('[data-rw-page]', container).forEach(function (b) {
      b.addEventListener('click', function () {
        var p = Number(b.getAttribute('data-rw-page'));
        if (cfg.onPage && p >= 1) cfg.onPage(p);
      });
    });
  }

  function renderPager(cfg) {
    var total = cfg.total || 0, perPage = cfg.perPage || 50, page = cfg.page || 1;
    var pages = Math.max(1, Math.ceil(total / perPage));
    var from = total ? (page - 1) * perPage + 1 : 0;
    var to = Math.min(page * perPage, total);
    var btns = '';
    var add = function (p, label, disabled, active) {
      btns += '<button class="rw-btn' + (active ? ' primary' : '') + '" data-rw-page="' + p + '"' + (disabled ? ' disabled' : '') + '>' + label + '</button>';
    };
    add(page - 1, '◀', page <= 1, false);
    var start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
    if (start > 1) { add(1, '1', false, false); if (start > 2) btns += '<span class="rw-muted">…</span>'; }
    for (var p = start; p <= end; p++) add(p, String(p), false, p === page);
    if (end < pages) { if (end < pages - 1) btns += '<span class="rw-muted">…</span>'; add(pages, String(pages), false, false); }
    add(page + 1, '▶', page >= pages, false);
    return '<div class="rw-pager"><span class="rw-muted">Hiển thị ' + from + '–' + to + ' / ' + num(total) + '</span><div class="pg">' + btns + '</div></div>';
  }

  /* ================= Chip lọc lưu vào URL ================= */
  function readUrlState(key) {
    var params = new URLSearchParams(location.search);
    var prefix = key + '.';
    var out = {};
    params.forEach(function (v, k) {
      if (k.indexOf(prefix) === 0) out[k.slice(prefix.length)] = v;
    });
    return out;
  }
  function writeUrlState(key, state) {
    var params = new URLSearchParams(location.search);
    var prefix = key + '.';
    Object.keys(params).forEach(function (k) { if (k.indexOf(prefix) === 0) params.delete(k); });
    Object.keys(state || {}).forEach(function (k) {
      var v = state[k];
      if (v !== '' && v !== null && v !== undefined) params.set(prefix + k, v);
    });
    var qs2 = params.toString();
    history.replaceState(null, '', location.pathname + (qs2 ? '?' + qs2 : ''));
  }

  /* ================= ProxyGroupBadge (mục 3.4) ================= */
  /**
   * Tính nhóm proxy ổn định: color_idx = hash(proxy_id) mod 12, nhãn P01..P12.
   * opts: {group:{id,label,color_idx,size,status,exit_ip,latency,over,subnet_dup,same_exit}, size, maxProfiles}
   */
  function hash12(id) {
    var h = 0, s = String(id);
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) >>> 0; }
    return h % 12;
  }
  function proxyGroupBadge(opts) {
    opts = opts || {};
    var g = opts.group;
    if (!g || !g.id) {
      return '<span class="rw-pg rw-pg-none" title="Chưa có proxy"><span class="icon">⚠</span><span class="tag">Chưa có proxy</span></span>';
    }
    var colorIdx = (g.color_idx !== undefined && g.color_idx !== null) ? Number(g.color_idx) : hash12(g.id);
    if (colorIdx < 0 || colorIdx > 11) colorIdx = hash12(g.id);
    var tag = g.label || ('P' + ('0' + (colorIdx + 1)).slice(-2));
    var size = opts.size || g.size || 1;
    var cls = ['rw-pg', 'rw-pg-' + ('0' + (colorIdx + 1)).slice(-2)];
    var title = 'Nhóm proxy ' + tag + ' · ' + (g.text || g.host || ('#' + g.id));
    if (size <= 1 && !g.over && !g.same_exit) {
      cls = ['rw-pg', 'rw-pg-solo'];
      title = 'Proxy riêng (không trùng) · ' + (g.text || ('#' + g.id));
    }
    if (g.over) { cls.push('rw-pg-over'); title += ' · VƯỢT sức chứa (TRÙNG ×' + size + ')'; }
    if (g.subnet_dup) { cls.push('rw-pg-subnet'); title += ' · cùng dải /24'; }
    var inner = '<span class="icon">' + (g.dead ? '☠' : (g.same_exit ? '⧉' : '◆')) + '</span>' +
      '<span class="tag">' + esc(tag) + '</span>';
    if (size > 1) inner += '<span class="sep">·</span><span>×' + size + '</span>';
    if (g.over) inner += '<span class="sep">·</span><span>TRÙNG</span>';
    if (g.dead) inner += '<span class="sep">·</span><span>dead</span>';
    if (g.same_exit) inner += '<span class="sep">·</span><span>cùng IP</span>';
    return '<span class="' + cls.join(' ') + '" title="' + esc(title) + '">' + inner + '</span>';
  }
  /** Trạng thái proxy dạng dot + chữ (không dùng riêng màu). */
  function proxyStatusDot(status) {
    var s = String(status || 'unchecked').toLowerCase();
    var map = {
      alive: ['alive', '● alive'], slow: ['slow', '◐ slow'],
      dead: ['dead', '✕ dead'], unchecked: ['unchecked', '○ chưa kiểm']
    };
    var m = map[s] || map.unchecked;
    return '<span class="rw-dot ' + m[0] + '"></span> ' + esc(m[1]);
  }

  /* ================= Huy hiệu đồng bộ config ================= */
  function syncBadge(status, rev, detail) {
    var s = String(status || 'pending');
    var icon = s === 'applied' ? '✓' : (s === 'fail' ? '⚠' : '⏳');
    var label = s === 'applied' ? ('Đã áp dụng (rev ' + esc(rev || '?') + ')') :
      (s === 'fail' ? 'Lỗi: ' + esc(detail || 'không rõ') : 'Đang chờ worker');
    var cls = s === 'applied' ? 'applied' : (s === 'fail' ? 'fail' : 'pending');
    return '<span class="rw-sync ' + cls + '"' + (s === 'fail' && detail ? ' title="' + esc(detail) + '"' : '') + '>' +
      '<span class="icon">' + icon + '</span>' + label + '</span>';
  }

  /* ================= Polling 10s, pause khi tab ẩn, không mất scroll/lọc/chọn ================= */
  function startPolling(tick) {
    var timer = null;
    function run() {
      if (document.hidden) return; // pause khi tab ẩn
      tick();
    }
    function schedule() {
      if (timer) clearInterval(timer);
      timer = setInterval(run, 10000);
    }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { run(); schedule(); }
    });
    schedule();
    return { stop: function () { if (timer) clearInterval(timer); }, runNow: run };
  }

  /* ================= Xuất CSV (che bí mật — đã che từ server) ================= */
  function downloadCsv(filename, header, rows) {
    function cell(v) {
      v = v === null || v === undefined ? '' : String(v);
      return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }
    var csv = '\ufeff' + header.map(cell).join(',') + '\n' +
      rows.map(function (r) { return r.map(cell).join(','); }).join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { if (a.parentNode) a.parentNode.removeChild(a); URL.revokeObjectURL(a.href); }, 500);
  }

  /* ================= Trạng thái badge cho account/phone ================= */
  var ACC_STATUS = {
    new: ['muted', '✚ new'], provisioning: ['running', '⚙ provisioning'],
    ready: ['ok', '● ready'], running: ['running', '▶ running'],
    done_today: ['ok', '✓ done_today'], cooling: ['warn', '⏳ cooling'],
    partial: ['warn', '◐ partial'], error: ['error', '✕ error'],
    needs_human: ['needs', '👤 needs_human'], needs_recovery: ['needs', '🔧 needs_recovery'],
    ok: ['ok', '✓ ok'], locked: ['error', '🔒 locked'],
    disabled: ['muted', '⏸ disabled'], unknown: ['muted', '? unknown']
  };
  function accBadge(st) {
    var m = ACC_STATUS[String(st || 'unknown')] || ACC_STATUS.unknown;
    return '<span class="rw-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }
  var PHONE_STATUS = {
    free: ['ok', '○ rảnh'], attached: ['running', '● đã gắn'],
    pending_verify: ['warn', '⏳ chờ xác minh'], verified: ['ok', '✓ đã xác minh'],
    burned: ['error', '🔥 burned'], needs_check: ['needs', '🔍 cần kiểm']
  };
  function phoneBadge(st) {
    var m = PHONE_STATUS[String(st || 'free')] || ['muted', String(st || '?')];
    return '<span class="rw-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  /* ================= Xuất ra namespace RW ================= */
  global.RW = {
    esc: esc, el: el, qs: qs, qsa: qsa,
    fmtTime: fmtTime, fmtTimeShort: fmtTimeShort, minutesAgo: minutesAgo, num: num,
    csrfTok: csrfTok, api: api, errText: errText,
    toast: toast, confirmDestructive: confirmDestructive, slidePanel: slidePanel,
    renderTable: renderTable, readUrlState: readUrlState, writeUrlState: writeUrlState,
    proxyGroupBadge: proxyGroupBadge, hash12: hash12, proxyStatusDot: proxyStatusDot,
    syncBadge: syncBadge, startPolling: startPolling, downloadCsv: downloadCsv,
    accBadge: accBadge, phoneBadge: phoneBadge
  };
})(window);