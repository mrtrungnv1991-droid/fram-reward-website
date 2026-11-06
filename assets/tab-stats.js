/* ============================================================
 * tab-stats.js — Tab «TIẾN ĐỘ & THỐNG KÊ» (PROMPT UI v4, mục 5).
 * Nối api_v4_stats.php (chỉ-đọc): summary · timeseries · by_task ·
 * by_error · by_account · by_proxy · calendar · pending_tasks · export_csv.
 * Kỳ Ngày / Tuần / Tháng / Tùy chọn + điều hướng ◀ ▶ + chọn ngày;
 * lọc thiết bị, proxy, nhóm, nhà cung cấp, tag; mọi số liệu có so sánh
 * kỳ trước (▲/▼ %). KPI có tooltip công thức (summary.formulas);
 * biểu đồ SVG tự vẽ (đường, cột, cột chồng, donut, lịch nhiệt,
 * sparkline) — null = đứt đoạn, KHÔNG vẽ về 0; phễu tài khoản;
 * bảng account xếp được + nhãn «yếu», «đứt chuỗi»; danh sách còn sót
 * kèm lý do + giờ mở lại; cảnh báo bất thường; độ tin cậy dữ liệu;
 * Xuất CSV đúng bộ lọc. JS thuần, không CDN, theme .rw-*,
 * giờ hiển thị Asia/Bangkok.
 * ============================================================ */
(function () {
  'use strict';

  var API = 'api_v4_stats.php';
  var container = null;
  var poller = null;
  var built = false;

  var state = {
    period: 'day',            // day | week | month | custom
    date: '',                 // YYYY-MM-DD (day/week/month)
    from: '', to: '',         // custom
    bucket: 'auto',           // auto | hour | day
    filters: { device_id: '', proxy_id: '', group_label: '', provider: '', tag: '' },
    sort: { by: 'points', dir: 'desc' },
    page: 1,
    perPage: 25,
    pendingPage: 1,
    exportWhat: 'by_account'
  };

  var data = {
    summary: null, timeseries: null, byTask: null, byError: null,
    byAccount: null, byProxy: null, calendar: null, pending: null
  };

  var KEYS = ['summary', 'timeseries', 'byTask', 'byError', 'byAccount', 'byProxy', 'calendar', 'pending'];
  var load = {};
  KEYS.forEach(function (k) { load[k] = { state: 'loading', errorMsg: '' }; });

  /* ================= Ngày tháng (Asia/Bangkok) ================= */
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function bkkNow() {
    return new Date(Date.now() + (7 * 60 + new Date().getTimezoneOffset()) * 60000);
  }
  function todayIso() {
    var d = bkkNow();
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }
  function addDays(iso, n) {
    var p = String(iso || '').split('-');
    if (p.length !== 3) return iso;
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    if (isNaN(d.getTime())) return iso;
    d.setUTCDate(d.getUTCDate() + n);
    return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
  }
  function addMonths(iso, n) {
    var p = String(iso || '').split('-');
    if (p.length !== 3) return iso;
    var lastDay = new Date(Date.UTC(+p[0], +p[1], 0)).getUTCDate();
    var day = Math.min(+p[2] || 1, lastDay);
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, day));
    if (isNaN(d.getTime())) return iso;
    d.setUTCMonth(d.getUTCMonth() + n);
    return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
  }
  function fmtDateVN(iso) {
    if (!iso) return '—';
    var p = String(iso).split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : String(iso);
  }
  function periodLabel() {
    if (state.period === 'custom') return fmtDateVN(state.from) + ' → ' + fmtDateVN(state.to);
    return fmtDateVN(state.date);
  }

  /* ================= Định dạng số / tỉ lệ =================
   * Quy ước khớp api_v4_stats.php:
   *   - các key *_rate, completion_rate, skill_coverage_rate: 0..1 → pct()
   *   - share_pct, progress_pct, delta_pct: đã là % (0..100) → pct100()
   *   - null = không có mẫu / chưa xác định → hiển thị «—», KHÔNG bịa số 0
   * ============================================================== */
  function pct(v, digits) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
    return (Number(v) * 100).toFixed(digits === undefined ? 0 : digits) + '%';
  }
  function pct100(v, digits) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
    return Number(v).toFixed(digits === undefined ? 1 : digits) + '%';
  }
  function dec(v, digits) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
    return Number(v).toLocaleString('vi-VN', { maximumFractionDigits: digits === undefined ? 1 : digits });
  }
  function num(v) { return (v === null || v === undefined) ? '—' : RW.num(v); }
  function numSigned(v) {
    if (v === null || v === undefined) return '—';
    return (v < 0 ? '−' : '+') + RW.num(Math.abs(v));
  }
  function shortNum(v) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    if (Math.abs(v) >= 1000000) return dec(v / 1000000) + 'M';
    if (Math.abs(v) >= 1000) return dec(v / 1000) + 'k';
    return dec(v, 0);
  }
  function fmtDur(s) {
    if (s === null || s === undefined || isNaN(s)) return '—';
    s = Math.round(Number(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (h) return h + 'h' + pad2(m) + 'm';
    if (m) return m + 'm' + pad2(sec) + 's';
    return sec + 's';
  }
  /* So sánh kỳ trước: ▲/▼ %; invert=true cho metric «càng thấp càng tốt» (tỉ lệ lỗi) */
  function deltaTag(cmp, invert) {
    if (!cmp || cmp.delta_pct === null || cmp.delta_pct === undefined) {
      return '<span class="rw-muted" title="Kỳ trước không có số liệu để so sánh.">· không so sánh</span>';
    }
    if (cmp.direction === 'flat') return '<span class="rw-muted">→ 0%</span>';
    var up = cmp.direction === 'up';
    var good = invert ? !up : up;
    return '<span class="rw-badge ' + (good ? 'ok' : 'error') + '" title="Kỳ trước: ' + RW.esc(dec(cmp.prev)) + '">' +
      (up ? '▲ ' : '▼ ') + pct100(Math.abs(cmp.delta_pct)) + '</span>';
  }

  /* ================= Đọc/ghi kỳ + bộ lọc vào URL (prefix stats.) ================= */
  function loadUrl() {
    var s = RW.readUrlState('stats');
    if (s.period === 'day' || s.period === 'week' || s.period === 'month' || s.period === 'custom') {
      state.period = s.period;
    }
    state.date = s.date || todayIso();
    state.from = s.from || '';
    state.to = s.to || '';
    state.filters.device_id = s.device_id || '';
    state.filters.proxy_id = s.proxy_id || '';
    state.filters.group_label = s.group_label || '';
    state.filters.provider = s.provider || '';
    state.filters.tag = s.tag || '';
    if (s.sort) state.sort.by = s.sort;
    state.sort.dir = (s.dir === 'asc') ? 'asc' : 'desc';
    state.page = parseInt(s.page || '1', 10) || 1;
  }
  function saveUrl() {
    RW.writeUrlState('stats', {
      period: state.period, date: state.date, from: state.from, to: state.to,
      device_id: state.filters.device_id, proxy_id: state.filters.proxy_id,
      group_label: state.filters.group_label, provider: state.filters.provider,
      tag: state.filters.tag, sort: state.sort.by, dir: state.sort.dir, page: state.page
    });
  }

  /* ================= Tham số API (kỳ + bộ lọc — khớp rws_filters) ================= */
  function baseParams() {
    var p = new URLSearchParams();
    p.set('period', state.period);
    if (state.period === 'custom' && state.from && state.to) {
      p.set('from', state.from);
      p.set('to', state.to);
      p.set('date', state.to);
    } else {
      p.set('date', state.date);
    }
    var f = state.filters;
    if (f.device_id) p.set('device_id', f.device_id);
    if (f.proxy_id !== '' && f.proxy_id !== null) p.set('proxy_id', f.proxy_id);
    if (f.group_label) p.set('group_label', f.group_label);
    if (f.provider) p.set('provider', f.provider);
    if (f.tag) p.set('tag', f.tag);
    return p;
  }

  /* ================= Tải dữ liệu (mỗi action 1 hàm) ================= */
  function apiGet(key, extra, apply) {
    var p = baseParams();
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return RW.api(API + '?' + p.toString()).then(function (res) {
      if (!res.ok) {
        load[key].state = 'error';
        load[key].errorMsg = RW.errText(res);
        render();
        return null;
      }
      apply(res.data || {});
      render();
      return res.data;
    });
  }
  function fetchSummary(silent) {
    if (!silent) { load.summary.state = 'loading'; render(); }
    return apiGet('summary', { action: 'summary' }, function (d) {
      data.summary = d;
      load.summary.state = 'ok';
    });
  }
  function fetchTimeseries(silent) {
    if (!silent) { load.timeseries.state = 'loading'; render(); }
    return apiGet('timeseries', { action: 'timeseries', bucket: state.bucket }, function (d) {
      data.timeseries = d;
      load.timeseries.state = (d.series || []).length ? 'ok' : 'empty';
    });
  }
  function fetchByTask(silent) {
    if (!silent) { load.byTask.state = 'loading'; render(); }
    return apiGet('byTask', { action: 'by_task' }, function (d) {
      data.byTask = d;
      load.byTask.state = (d.data_available === false || !(d.rows || []).length) ? 'empty' : 'ok';
    });
  }
  function fetchByError(silent) {
    return apiGet('byError', { action: 'by_error' }, function (d) {
      data.byError = d;
      load.byError.state = (d.rows || []).length ? 'ok' : 'empty';
    });
  }
  function fetchByAccount(silent) {
    if (!silent) { load.byAccount.state = 'loading'; render(); }
    return apiGet('byAccount', {
      action: 'by_account', sort: state.sort.by, dir: state.sort.dir,
      page: String(state.page), per_page: String(state.perPage)
    }, function (d) {
      data.byAccount = d;
      load.byAccount.state = (d.rows || []).length ? 'ok' : 'empty';
    });
  }
  function fetchByProxy(silent) {
    return apiGet('byProxy', { action: 'by_proxy', per_page: '200' }, function (d) {
      data.byProxy = d;
      load.byProxy.state = (d.rows || []).length ? 'ok' : 'empty';
    });
  }
  function fetchCalendar(silent) {
    if (state.period !== 'month' && state.period !== 'custom') {
      data.calendar = null;
      load.calendar.state = 'empty';
      return Promise.resolve(null);
    }
    return apiGet('calendar', { action: 'calendar' }, function (d) {
      data.calendar = d;
      load.calendar.state = (d.days || []).length ? 'ok' : 'empty';
    });
  }
  function fetchPending(silent) {
    return apiGet('pending', {
      action: 'pending_tasks', page: String(state.pendingPage), per_page: '50'
    }, function (d) {
      /* renderTable cần r.id duy nhất: tự ghép từ loại + account + ngày + task */
      var rows = (d.rows || []).map(function (r, i) {
        r.id = r.type + '-' + (r.account_id || 0) + '-' + (r.date || '') + '-' + (r.task_key || i);
        return r;
      });
      d.rows = rows;
      data.pending = d;
      load.pending.state = rows.length ? 'ok' : 'empty';
    });
  }
  function fetchAll(silent) {
    fetchSummary(silent);
    fetchTimeseries(silent);
    fetchByTask(silent);
    fetchByError(silent);
    fetchByAccount(silent);
    fetchByProxy(silent);
    fetchCalendar(silent);
    fetchPending(silent);
  }
  /* ================= Biểu đồ SVG tự vẽ (JS thuần, null = đứt đoạn) ================= */
  var C = {
    grid: '#8a8a94', axis: '#66666e', label: '#66666e',
    line: '#4f7cff', point: '#4f7cff', target: '#e0a52e',
    forecast: '#4f7cff', ok: '#2fae6b', err: '#e05252',
    warn: '#e0a52e', need: '#b06fd6', info: '#3aa3d6', muted: '#9a9aa4'
  };
  function svgOpen(w, h, cls) {
    return '<svg class="rw-svg ' + (cls || '') + '" viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" role="img" preserveAspectRatio="xMidYMid meet">';
  }
  function svgText(x, y, txt, anchor, size, color) {
    return '<text x="' + x + '" y="' + y + '" text-anchor="' + (anchor || 'middle') +
      '" font-size="' + (size || 11) + '" fill="' + (color || C.label) + '">' + RW.esc(txt) + '</text>';
  }
  /* Nhãn trục không chồng: thưa nhãn (mỗi step cái) */
  function labelStride(n, maxLabels) {
    return Math.max(1, Math.ceil(n / (maxLabels || 8)));
  }

  /* Biểu đồ đường/cột: series [{label, points (null=đứt đoạn), runs, target, confidence}] */
  function chartSeries(series, opts) {
    opts = opts || {};
    var W = 920, H = 260, padL = 52, padR = 16, padT = 16, padB = 42;
    var iw = W - padL - padR, ih = H - padT - padB;
    var pts = series || [];
    if (!pts.length) return '<div class="rw-empty">Chưa có số liệu để vẽ.</div>';
    var vals = [];
    pts.forEach(function (p) {
      if (p.points !== null && p.points !== undefined && !isNaN(p.points)) vals.push(Number(p.points));
      if (p.target) vals.push(Number(p.target));
    });
    if (opts.forecastPoint) vals.push(Number(opts.forecastPoint));
    var maxV = vals.length ? Math.max.apply(null, vals) : 1;
    var minV = 0;
    if (maxV <= 0) maxV = 1;
    var stepX = pts.length > 1 ? iw / (pts.length - 1) : 0;
    function X(i) { return pts.length > 1 ? padL + i * stepX : padL + iw / 2; }
    function Y(v) { return padT + ih - ((v - minV) / (maxV - minV)) * ih; }
    var out = svgOpen(W, H, 'rw-chart-series');
    /* lưới ngang + nhãn trục Y */
    for (var g = 0; g <= 4; g++) {
      var gv = minV + (maxV - minV) * g / 4;
      var gy = Y(gv);
      out += '<line x1="' + padL + '" y1="' + gy + '" x2="' + (W - padR) + '" y2="' + gy + '" stroke="' + C.grid + '" stroke-width="0.6" stroke-dasharray="3,4"/>';
      out += svgText(padL - 6, gy + 4, shortNum(gv), 'end', 10);
    }
    /* đường mục tiêu (nếu có) */
    var tgt = (pts[0] && pts[0].target) ? Number(pts[0].target) : 0;
    if (tgt > 0) {
      out += '<line x1="' + padL + '" y1="' + Y(tgt) + '" x2="' + (W - padR) + '" y2="' + Y(tgt) +
        '" stroke="' + C.target + '" stroke-width="1.5" stroke-dasharray="6,4"/>';
      out += svgText(W - padR, Y(tgt) - 5, 'mục tiêu ' + shortNum(tgt), 'end', 10, C.target);
    }
    /* đường gấp khúc: tách đoạn theo null (đứt đoạn, KHÔNG nối về 0) */
    var seg = [], segs = [];
    pts.forEach(function (p, i) {
      if (p.points === null || p.points === undefined || isNaN(p.points)) {
        if (seg.length) { segs.push(seg); seg = []; }
        return;
      }
      seg.push({ x: X(i), y: Y(Number(p.points)) });
    });
    if (seg.length) segs.push(seg);
    segs.forEach(function (s) {
      if (s.length === 1) {
        out += '<circle cx="' + s[0].x + '" cy="' + s[0].y + '" r="3.5" fill="' + C.point + '"/>';
        return;
      }
      var d = s.map(function (q, i) { return (i ? 'L' : 'M') + q.x + ' ' + q.y; }).join(' ');
      out += '<path d="' + d + '" fill="none" stroke="' + C.line + '" stroke-width="2" stroke-linejoin="round"/>';
      s.forEach(function (q) { out += '<circle cx="' + q.x + '" cy="' + q.y + '" r="3" fill="' + C.point + '"/>'; });
    });
    /* dấu chấm trống tại ô null = ngày chưa xác định điểm */
    pts.forEach(function (p, i) {
      if (p.points === null || p.points === undefined) {
        out += '<circle cx="' + X(i) + '" cy="' + Y(0) + '" r="3" fill="none" stroke="' + C.warn + '" stroke-width="1.2" stroke-dasharray="2,2"/>';
      }
    });
    /* nhãn trục X thưa để không chồng */
    var stride = labelStride(pts.length, 8);
    pts.forEach(function (p, i) {
      if (i % stride !== 0 && i !== pts.length - 1) return;
      out += svgText(X(i), H - padB + 18, p.label || '', 'middle', 10);
    });
    /* tooltip bằng <title> trên từng điểm */
    pts.forEach(function (p, i) {
      if (p.points === null || p.points === undefined) return;
      out += '<circle cx="' + X(i) + '" cy="' + Y(Number(p.points)) + '" r="8" fill="transparent"><title>' +
        RW.esc((p.label || '') + ': ' + num(p.points) + ' điểm' +
          (p.runs !== undefined ? ' · ' + num(p.runs) + ' run' : '') +
          (p.confidence ? ' · độ tin cậy: ' + p.confidence : '')) + '</title></circle>';
    });
    out += '</svg>';
    return out;
  }

  /* Cột 24 giờ (chế độ Ngày) */
  function chartBars(series) {
    var W = 920, H = 200, padL = 52, padR = 12, padT = 12, padB = 34;
    var iw = W - padL - padR, ih = H - padT - padB;
    var pts = series || [];
    if (!pts.length) return '<div class="rw-empty">Chưa có số liệu theo giờ.</div>';
    var maxV = Math.max.apply(null, pts.map(function (p) { return Number(p.points) || 0; }).concat([1]));
    var bw = Math.min(28, (iw / pts.length) * 0.72);
    var out = svgOpen(W, H, 'rw-chart-bars');
    pts.forEach(function (p, i) {
      var cx = padL + (i + 0.5) * (iw / pts.length);
      var v = Number(p.points) || 0;
      var h = (v / maxV) * ih;
      out += '<rect x="' + (cx - bw / 2) + '" y="' + (padT + ih - h) + '" width="' + bw + '" height="' + h +
        '" fill="' + C.line + '" rx="2"><title>' + RW.esc((p.label || '') + ': ' + num(p.points) + ' điểm') + '</title></rect>';
      if (p.points === null || p.points === undefined) {
        out += '<rect x="' + (cx - bw / 2) + '" y="' + (padT + ih - 6) + '" width="' + bw + '" height="6" fill="none" stroke="' + C.warn + '" stroke-dasharray="2,2"/>';
      }
      if (i % labelStride(pts.length, 12) === 0) {
        out += svgText(cx, H - padB + 16, p.label || '', 'middle', 9);
      }
    });
    out += '</svg>';
    return out;
  }

  /* Cột chồng (hoàn thành theo nhóm nhiệm vụ) */
  function chartStackedBars(rows) {
    var W = 460, H = 230, padL = 44, padR = 10, padT = 10, padB = 48;
    var iw = W - padL - padR, ih = H - padT - padB;
    if (!rows.length) return '<div class="rw-empty">Chưa có dữ liệu nhiệm vụ.</div>';
    var maxV = Math.max.apply(null, rows.map(function (r) { return Number(r.available) || 0; }).concat([1]));
    var bw = Math.min(38, (iw / rows.length) * 0.6);
    var segs = [
      { key: 'done', label: 'done', color: C.ok },
      { key: 'failed', label: 'failed', color: C.err },
      { key: 'needs_human', label: 'needs_human', color: C.need },
      { key: 'pending', label: 'pending', color: C.warn }
    ];
    var out = svgOpen(W, H, 'rw-chart-stacked');
    rows.forEach(function (r, i) {
      var cx = padL + (i + 0.5) * (iw / rows.length);
      var y = padT + ih;
      segs.forEach(function (s) {
        var v = Number(r[s.key]) || 0;
        if (!v) return;
        var h = (v / maxV) * ih;
        y -= h;
        out += '<rect x="' + (cx - bw / 2) + '" y="' + y + '" width="' + bw + '" height="' + h +
          '" fill="' + s.color + '"><title>' + RW.esc(r.group + ' · ' + s.label + ': ' + v) + '</title></rect>';
      });
      var lbl = r.group || '';
      out += '<text x="' + cx + '" y="' + (H - padB + 14) + '" text-anchor="end" font-size="9" fill="' + C.label +
        '" transform="rotate(-45 ' + cx + ' ' + (H - padB + 14) + ')">' + RW.esc(lbl) + '</text>';
    });
    out += '</svg>';
    out += '<div class="rw-legend">' + segs.map(function (s) {
      return '<span><span class="rw-dot" style="background:' + s.color + '"></span>' + s.label + '</span>';
    }).join('') + '</div>';
    return out;
  }

  /* Donut tỉ lệ lỗi theo nguyên nhân */
  function chartDonut(rows) {
    if (!rows.length) return '<div class="rw-empty">Kỳ này không ghi nhận lỗi nào. 🎉</div>';
    var total = rows.reduce(function (a, r) { return a + (Number(r.count) || 0); }, 0) || 1;
    var W = 240, H = 240, cx = 120, cy = 120, R = 82, r0 = 48;
    var colors = { proxy: C.err, login: C.warn, verify: C.need, captcha: '#e07f2e', timeout: C.info, browser: C.line, other: C.muted };
    var out = svgOpen(W, H, 'rw-chart-donut');
    var angle = -Math.PI / 2;
    rows.forEach(function (r) {
      var frac = (Number(r.count) || 0) / total;
      var a2 = angle + frac * Math.PI * 2;
      var large = frac > 0.5 ? 1 : 0;
      var x1 = cx + R * Math.cos(angle), y1 = cy + R * Math.sin(angle);
      var x2 = cx + R * Math.cos(a2), y2 = cy + R * Math.sin(a2);
      var x3 = cx + r0 * Math.cos(a2), y3 = cy + r0 * Math.sin(a2);
      var x4 = cx + r0 * Math.cos(angle), y4 = cy + r0 * Math.sin(angle);
      if (frac >= 0.9999) {
        out += '<circle cx="' + cx + '" cy="' + cy + '" r="' + ((R + r0) / 2) + '" fill="none" stroke="' + (colors[r.class] || C.muted) +
          '" stroke-width="' + (R - r0) + '"><title>' + RW.esc(r.label + ': ' + r.count) + '</title></circle>';
      } else {
        out += '<path d="M' + x1 + ' ' + y1 + ' A' + R + ' ' + R + ' 0 ' + large + ' 1 ' + x2 + ' ' + y2 +
          ' L' + x3 + ' ' + y3 + ' A' + r0 + ' ' + r0 + ' 0 ' + large + ' 0 ' + x4 + ' ' + y4 + ' Z" fill="' +
          (colors[r.class] || C.muted) + '"><title>' + RW.esc(r.label + ': ' + r.count + ' (' + pct100(r.share_pct) + ')') + '</title></path>';
      }
      angle = a2;
    });
    out += svgText(cx, cy - 4, String(total), 'middle', 22, C.err);
    out += svgText(cx, cy + 14, 'tổng lỗi', 'middle', 10);
    out += '</svg>';
    out += '<div class="rw-legend">' + rows.map(function (r) {
      return '<span title="' + RW.esc(r.label + ': ' + r.count) + '"><span class="rw-dot" style="background:' +
        (colors[r.class] || C.muted) + '"></span>' + RW.esc(r.label) + ' (' + pct100(r.share_pct) + ')</span>';
    }).join('') + '</div>';
    return out;
  }

  /* Lịch nhiệt (tháng) — mỗi ô = 1 ngày, màu theo mức điểm */
  function calendarHeat(cal) {
    var days = (cal && cal.days) || [];
    if (!days.length) return '<div class="rw-empty">Không có dữ liệu lịch.</div>';
    var maxV = Math.max.apply(null, days.map(function (d) { return Number(d.points) || 0; }).concat([1]));
    function cellColor(d) {
      if (d.confidence === 'unknown_points' || d.confidence === 'no_runs') return 'rgba(224,165,46,0.18)';
      if (d.confidence === 'future') return 'transparent';
      var v = (Number(d.points) || 0) / maxV;
      return 'rgba(79,124,255,' + (0.12 + v * 0.78).toFixed(2) + ')';
    }
    var html = '<div class="rw-cards" style="grid-template-columns:repeat(7,1fr);gap:4px">';
    ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].forEach(function (w) {
      html += '<div class="rw-muted" style="text-align:center;font-size:.72rem">' + w + '</div>';
    });
    var firstWd = days.length ? (Number(days[0].weekday) || 1) : 1;
    for (var b = 1; b < firstWd; b++) html += '<div></div>';
    days.forEach(function (d) {
      var isToday = d.is_today ? 'box-shadow:0 0 0 2px var(--rw-err)' : '';
      html += '<div title="' + RW.esc(fmtDateVN(d.date) + ' · ' + num(d.points) + ' điểm · ' + num(d.runs) + ' run · ' + (d.confidence || '')) +
        '" style="background:' + cellColor(d) + ';border:1px solid var(--rw-line,#444);border-radius:6px;padding:.45rem .25rem;text-align:center;' + isToday + '">' +
        '<div style="font-size:.72rem">' + (d.date ? d.date.slice(8) : '') + '</div>' +
        '<div style="font-size:.78rem;font-weight:600">' + (d.points === null || d.points === undefined ? '⚠' : shortNum(d.points)) + '</div>' +
        '</div>';
    });
    html += '</div>';
    html += '<div class="rw-legend" style="margin-top:.5rem"><span>Ít điểm</span>' +
      '<span><span class="rw-dot" style="background:rgba(79,124,255,0.2)"></span></span>' +
      '<span><span class="rw-dot" style="background:rgba(79,124,255,0.55)"></span></span>' +
      '<span><span class="rw-dot" style="background:rgba(79,124,255,0.9)"></span></span>' +
      '<span>Nhiều điểm</span>' +
      '<span><span class="rw-dot" style="background:rgba(224,165,46,0.3)"></span>chưa xác định điểm</span></div>';
    return html;
  }

  /* Sparkline nhỏ cho bảng account (null = đứt đoạn) */
  function sparkline(spark) {
    var pts = (spark || []).map(Number);
    if (!pts.length) return '<span class="rw-muted">—</span>';
    var W = 90, H = 26, pad = 3;
    var nums = pts.filter(function (v) { return !isNaN(v); });
    var maxV = nums.length ? Math.max.apply(null, nums) : 1;
    if (maxV <= 0) maxV = 1;
    var stepX = pts.length > 1 ? (W - pad * 2) / (pts.length - 1) : 0;
    var out = svgOpen(W, H, 'rw-spark');
    var seg = [], segs = [];
    pts.forEach(function (v, i) {
      if (isNaN(v)) { if (seg.length) { segs.push(seg); seg = []; } return; }
      seg.push({ x: pad + i * stepX, y: H - pad - (v / maxV) * (H - pad * 2) });
    });
    if (seg.length) segs.push(seg);
    segs.forEach(function (s) {
      if (s.length === 1) { out += '<circle cx="' + s[0].x + '" cy="' + s[0].y + '" r="2" fill="' + C.line + '"/>'; return; }
      out += '<path d="' + s.map(function (q, i) { return (i ? 'L' : 'M') + q.x + ' ' + q.y; }).join(' ') +
        '" fill="none" stroke="' + C.line + '" stroke-width="1.6"/>';
    });
    out += '</svg>';
    return out;
  }

  /* ================= KPI card (có tooltip công thức) ================= */
  function kpiCard(label, value, cmp, formula, sub, invert) {
    return '<div class="rw-panel rw-panel-pad" style="min-width:0">' +
      '<div class="rw-muted" style="font-size:.78rem" title="' + RW.esc(formula || '') + '">' +
      RW.esc(label) + ' <span aria-hidden="true">ⓘ</span></div>' +
      '<div style="font-size:1.5rem;font-weight:700;line-height:1.25;word-break:break-word">' + value + '</div>' +
      '<div style="margin-top:.15rem">' + deltaTag(cmp, invert) + '</div>' +
      (sub ? '<div class="rw-muted" style="font-size:.74rem;margin-top:.2rem">' + sub + '</div>' : '') +
      '</div>';
  }

  /* ================= Phễu tài khoản ================= */
  function funnelHtml(f) {
    if (!f || !f.labels || !f.labels.length) return '<div class="rw-empty">Chưa có dữ liệu phễu.</div>';
    var vals = [];
    var keys = ['total', 'eligible', 'ran', 'done', 'with_points', 'excluded_disabled_locked'];
    var maxV = 1;
    f.labels.forEach(function (lb, i) {
      var v = Number(f[keys[i]]) || 0;
      vals.push({ label: lb, v: v });
      if (v > maxV) maxV = v;
    });
    return '<div class="rw-kv">' + vals.map(function (x) {
      var w = Math.max(2, (x.v / maxV) * 100);
      return '<div class="rw-kv-row" style="margin:.28rem 0;align-items:center;gap:.5rem">' +
        '<div style="flex:0 0 12rem;font-size:.82rem" class="rw-muted">' + RW.esc(x.label) + '</div>' +
        '<div style="flex:1;position:relative;height:1.35rem;background:rgba(79,124,255,0.12);border-radius:6px;overflow:hidden">' +
        '<div style="position:absolute;inset:0 auto 0 0;width:' + w + '%;background:rgba(79,124,255,0.55);border-radius:6px"></div>' +
        '<div style="position:absolute;inset:0;display:flex;align-items:center;padding-left:.5rem;font-size:.78rem;font-weight:600">' + num(x.v) + '</div>' +
        '</div></div>';
    }).join('') + '</div>';
  }

  /* ================= Cảnh báo bất thường ================= */
  function anomaliesHtml(list) {
    if (!list || !list.length) return '';
    return '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">⚠ Phát hiện bất thường</div>' +
      '<ul style="margin:.45rem 0 0 1.15rem">' + list.map(function (a) {
        var txt = (typeof a === 'string') ? a : (a.message || a.text || JSON.stringify(a));
        return '<li style="margin:.25rem 0">' + RW.esc(txt) + '</li>';
      }).join('') + '</ul></div>';
  }

  /* ================= Độ tin cậy dữ liệu ================= */
  function confidenceHtml(dc) {
    if (!dc) return '';
    var warns = dc.storage_warnings || [];
    var unk = dc.points_unknown_runs;
    var out = '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0">' +
      '<div class="rw-section-title">🔍 Độ tin cậy dữ liệu</div>';
    out += '<div class="rw-hint" style="margin-top:.35rem">Run chưa xác định điểm: <b>' + num(unk) + '</b>' +
      (unk ? ' — các mốc thời gian này được đánh dấu ⚠ để không đọc nhầm là «điểm thấp».' : '') + '</div>';
    if (warns.length) {
      out += '<ul style="margin:.4rem 0 0 1.15rem">' + warns.map(function (w) {
        return '<li class="rw-muted" style="margin:.18rem 0">' + RW.esc(w) + '</li>';
      }).join('') + '</ul>';
    }
    out += '</div>';
    return out;
  }

  /* ================= Thông báo + công thức (notices/formulas) ================= */
  function noticesHtml(list) {
    if (!list || !list.length) return '';
    return '<div class="rw-alert rw-warn" style="margin:.55rem 0">' +
      '<b>ℹ Thông báo:</b><ul style="margin:.35rem 0 0 1.15rem">' + list.map(function (n) {
        return '<li>' + RW.esc(n) + '</li>';
      }).join('') + '</ul></div>';
  }
  /* ================= Thanh kỳ + bộ lọc + nút ================= */
  function renderToolbar() {
    function seg(val, label) {
      return '<button class="rw-chip' + (state.period === val ? ' active' : '') + '" data-st-period="' + val + '">' + label + '</button>';
    }
    function navStep() {
      return state.period === 'day' ? 1 : (state.period === 'week' ? 7 : 0);
    }
    var dateInput = '';
    if (state.period === 'custom') {
      dateInput = '<input class="rw-input" type="date" style="max-width:8.5rem" value="' + RW.esc(state.from) + '" data-st-from aria-label="Từ ngày">' +
        '<span class="rw-muted">→</span>' +
        '<input class="rw-input" type="date" style="max-width:8.5rem" value="' + RW.esc(state.to) + '" data-st-to aria-label="Đến ngày">';
    } else {
      dateInput = '<button class="rw-btn" data-st-nav="-1" aria-label="Kỳ trước">◀</button>' +
        '<input class="rw-input" type="date" style="max-width:8.5rem" value="' + RW.esc(state.date) + '" data-st-date aria-label="Chọn ngày">' +
        '<button class="rw-btn" data-st-nav="1" aria-label="Kỳ sau">▶</button>' +
        '<button class="rw-btn" data-st-nav="0" title="Về hôm nay">Hôm nay</button>';
    }
    return '<div class="rw-filters" style="margin:.65rem 0;flex-wrap:wrap;gap:.45rem;align-items:center">' +
      '<span class="rw-muted">Kỳ:</span>' + seg('day', 'Ngày') + seg('week', 'Tuần') + seg('month', 'Tháng') + seg('custom', 'Tùy chọn') +
      dateInput +
      '<span class="rw-muted">Lọc:</span>' +
      '<input class="rw-input" style="max-width:7.5rem" placeholder="ID thiết bị" value="' + RW.esc(state.filters.device_id) + '" data-st-f="device_id" aria-label="Lọc theo thiết bị">' +
      '<input class="rw-input" style="max-width:7.5rem" placeholder="ID proxy" value="' + RW.esc(state.filters.proxy_id) + '" data-st-f="proxy_id" aria-label="Lọc theo proxy">' +
      '<input class="rw-input" style="max-width:8.5rem" placeholder="Nhóm" value="' + RW.esc(state.filters.group_label) + '" data-st-f="group_label" aria-label="Lọc theo nhóm">' +
      '<input class="rw-input" style="max-width:8.5rem" placeholder="Nhà cung cấp" value="' + RW.esc(state.filters.provider) + '" data-st-f="provider" aria-label="Lọc theo nhà cung cấp">' +
      '<input class="rw-input" style="max-width:7.5rem" placeholder="Tag" value="' + RW.esc(state.filters.tag) + '" data-st-f="tag" aria-label="Lọc theo tag">' +
      '<button class="rw-btn" data-st-apply-filter>Áp dụng</button>' +
      '<button class="rw-btn" data-st-clear-filter>Xóa lọc</button>' +
      '<select class="rw-input" style="max-width:10.5rem" data-st-export-what aria-label="Loại dữ liệu xuất CSV">' +
      ['by_account', 'timeseries', 'by_task', 'by_error', 'calendar', 'pending_tasks', 'summary'].map(function (w) {
        return '<option value="' + w + '"' + (state.exportWhat === w ? ' selected' : '') + '>' + w + '</option>';
      }).join('') + '</select>' +
      '<button class="rw-btn primary" data-st-export>⬇ Xuất CSV</button>' +
      '<button class="rw-btn" data-st-reload>↻ Làm mới</button>' +
      '</div>';
  }

  /* ================= Khối KPI chính (mục 5.3) ================= */
  function renderKpis() {
    var s = data.summary;
    if (load.summary.state === 'loading') return '<div class="rw-panel rw-panel-pad rw-muted">Đang tải KPI…</div>';
    if (load.summary.state === 'error' || !s) {
      return '<div class="rw-panel rw-panel-pad"><div class="rw-alert bad">⚠ ' + RW.esc(load.summary.errorMsg || 'Không tải được KPI.') +
        '</div><button class="rw-btn" data-st-retry="summary">↻ Thử lại</button></div>';
    }
    var k = s.kpi || {};
    var cmp = s.compare || {};
    var f = s.formulas || {};
    var g = s.goals || {};
    var pointsVal;
    if (k.points_gained === null || k.points_gained === undefined) {
      pointsVal = '<span class="rw-warn" title="Tất cả run trong kỳ đều chưa xác định được điểm trước/sau.">⚠ Chưa xác định</span>' +
        (k.points_unknown_runs ? '<div class="rw-muted" style="font-size:.74rem">' + num(k.points_unknown_runs) + ' run chưa xác định điểm</div>' : '');
    } else {
      pointsVal = numSigned(k.points_gained) +
        (k.points_unknown_runs ? '<div class="rw-muted" style="font-size:.74rem">' + num(k.points_unknown_runs) + ' run chưa xác định điểm</div>' : '');
    }
    var avgSub = '<span class="rw-muted">(' + dec(k.avg_points_per_total) + ' /tổng account)</span>' +
      (state.period !== 'day' && k.avg_points_per_active_per_day !== undefined ?
        '<div class="rw-muted" style="font-size:.72rem">' + dec(k.avg_points_per_active_per_day) + ' /acc/ngày</div>' : '');
    var goalBar = '';
    if (g.target_points_per_day) {
      var p = g.progress_pct;
      goalBar = '<div class="rw-progress" style="margin:.55rem 0;height:.72rem;border-radius:6px;overflow:hidden;background:rgba(128,128,128,0.2)">' +
        '<div style="width:' + Math.min(100, Number(p) || 0) + '%;height:100%;background:' + (g.below_70_warning ? 'var(--rw-warn,#e0a52e)' : 'var(--rw-ok,#2fae6b)') + '"></div></div>' +
        '<div class="rw-muted" style="font-size:.76rem">Mục tiêu: ' + dec(g.target_points_per_day) + ' điểm/ngày' +
        (g.target_period_points ? ' · ' + dec(g.target_period_points) + ' điểm/kỳ' : '') +
        ' · Tiến độ <b style="color:' + (g.below_70_warning ? 'var(--rw-warn,#e0a52e)' : 'var(--rw-ok,#2fae6b)') + '">' + pct100(g.progress_pct) + '</b>' +
        (g.below_70_warning ? ' <span class="rw-badge warn">⚠ dưới 70% mục tiêu</span>' : '') + '</div>';
    }
    var eta = k.eta_today;
    var etaLine = '';
    if (eta) {
      etaLine = '<div class="rw-panel rw-panel-pad" style="margin:.55rem 0"><div class="rw-section-title">⏱ ETA hoàn thành hôm nay</div>' +
        '<div style="margin-top:.35rem">Còn <b>' + num(eta.remaining_accounts) + '</b> account · đã xong <b>' + num(eta.done_today) + '</b>' +
        ' · Ước lượng <b>' + dec(eta.eta_hours, 1) + ' giờ</b>' +
        ' (hoàn thành lúc <b>' + RW.esc(eta.eta_at ? RW.fmtTime(eta.eta_at) : '—') + '</b>)</div></div>';
    }
    var forecast = s.forecast;
    var forecastLine = '';
    if (forecast && forecast.projected_period_points !== null && forecast.projected_period_points !== undefined) {
      forecastLine = '<div class="rw-hint" style="margin:.45rem 0">Dự báo cuối kỳ: <b>' + num(forecast.projected_period_points) + '</b> điểm' +
        ' (trung bình ' + dec(forecast.avg_per_day) + ' điểm/ngày · đã qua ' + num(forecast.days_elapsed) + '/' + num(forecast.days_total) + ' ngày)' +
        (forecast.target_period_points ? ' · mục tiêu ' + num(forecast.target_period_points) : '') + '</div>';
    }
    return '<div class="rw-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:.65rem">' +
      kpiCard('Điểm thăng', pointsVal, cmp.points_gained, f.points_gained) +
      kpiCard('Điểm TB / acc hoạt động', dec(k.avg_points_per_active), cmp.avg_points_per_active, f.avg_points_per_active, avgSub) +
      kpiCard('Tỉ lệ hoàn thành nhiệm vụ', pct(k.task_completion_rate), cmp.task_completion_rate, f.task_completion_rate,
        'Nhiệm vụ: ' + num(k.tasks_done) + ' done / ' + num(k.tasks_available) + ' khả dụng') +
      kpiCard('Tỉ lệ run OK', pct(k.run_success_rate), cmp.run_success_rate, f.run_success_rate,
        num(k.runs_ok) + ' ok / ' + num(k.runs_total) + ' run · tỉ lệ lỗi ' + pct(k.run_error_rate)) +
      kpiCard('Tỉ lệ hoàn tất ngày', pct(k.day_completion_rate), cmp.day_completion_rate, f.day_completion_rate,
        'Account hoạt động: ' + num(k.active_accounts) + ' / ' + num(k.total_accounts) +
        ' · đủ ĐK: ' + num(k.eligible_accounts) + ' · bị khóa/tắt: ' + num(k.disabled_or_locked_accounts)) +
      kpiCard('Thời gian TB / account', fmtDur(k.avg_duration_s), cmp.avg_duration_s, f.avg_duration_s,
        'Throughput: ' + dec(k.throughput_per_hour, 2) + ' acc/giờ') +
      kpiCard('Tỉ lệ phủ skill', pct(k.skill_coverage_rate), cmp.skill_coverage_rate, f.skill_coverage_rate,
        'Lượt AI tiêu thụ: ' + num(k.ai_calls)) +
      '</div>' + goalBar + etaLine + forecastLine;
  }

  /* ================= Biểu đồ theo kỳ ================= */
  function renderChart() {
    var ts = data.timeseries;
    if (load.timeseries.state === 'loading') return '<div class="rw-panel rw-panel-pad rw-muted">Đang tải biểu đồ…</div>';
    if (load.timeseries.state === 'error') {
      return '<div class="rw-panel rw-panel-pad"><div class="rw-alert bad">⚠ ' + RW.esc(load.timeseries.errorMsg) +
        '</div><button class="rw-btn" data-st-retry="timeseries">↻ Thử lại</button></div>';
    }
    if (!ts || !(ts.series || []).length) {
      return '<div class="rw-panel rw-panel-pad"><div class="rw-empty">Chưa có số liệu chuỗi thời gian cho kỳ này.</div></div>';
    }
    var series = ts.series.map(function (r) {
      return { label: r.bucket || r.date || '', points: (r.points === null ? null : Number(r.points)),
        runs: r.runs, target: r.target, confidence: r.confidence };
    });
    var chart = (ts.bucket === 'hour') ? chartBars(series) : chartSeries(series);
    var warn = '';
    if (ts.warnings && ts.warnings.length) {
      warn = '<div class="rw-hint" style="margin-top:.45rem">⚠ ' + ts.warnings.map(RW.esc).join(' · ') + '</div>';
    }
    var legend = '<div class="rw-legend" style="margin-top:.45rem">' +
      '<span><span class="rw-dot" style="background:' + C.line + '"></span>Điểm thăng</span>' +
      '<span><span class="rw-dot" style="background:' + C.target + '"></span>Đường mục tiêu</span>' +
      '<span><span class="rw-dot" style="background:transparent;border:1.5px dashed ' + C.warn + '"></span>⚠ Chưa xác định điểm (đứt đoạn)</span>' +
      '</div>';
    return '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">📈 Điểm theo thời gian' +
      (ts.target_points_per_day ? ' · mục tiêu ' + dec(ts.target_points_per_day) + ' điểm/ngày' : '') + '</div>' +
      chart + legend + warn + '</div>';
  }

  /* ================= Phễu + nhóm nhiệm vụ + lỗi ================= */
  function renderFunnelAndTasks() {
    var s = data.summary || {};
    var bt = data.byTask;
    var be = data.byError;
    var left = '<div class="rw-panel rw-panel-pad"><div class="rw-section-title">🔻 Phễu tài khoản</div>' +
      '<div style="margin-top:.55rem">' + funnelHtml(s.funnel) + '</div></div>';
    var mid = '';
    if (load.byTask.state === 'loading') mid = '<div class="rw-panel rw-panel-pad rw-muted">Đang tải nhóm nhiệm vụ…</div>';
    else if (load.byTask.state === 'error') mid = '<div class="rw-panel rw-panel-pad"><div class="rw-alert bad">⚠ ' + RW.esc(load.byTask.errorMsg) +
      '</div><button class="rw-btn" data-st-retry="byTask">↻ Thử lại</button></div>';
    else if (!bt || !(bt.rows || []).length) mid = '<div class="rw-panel rw-panel-pad"><div class="rw-empty">Chưa có dữ liệu nhiệm vụ (task_events).</div></div>';
    else {
      mid = '<div class="rw-panel rw-panel-pad"><div class="rw-section-title">📊 Hoàn thành theo nhóm nhiệm vụ</div>' +
        '<div style="margin-top:.55rem">' + chartStackedBars(bt.rows) + '</div>' +
        '<div class="rw-hint" style="margin-top:.35rem">Tổng: ' + num((bt.totals || {}).available) + ' khả dụng · ' +
        num((bt.totals || {}).done) + ' done · tỉ lệ ' + pct((bt.totals || {}).completion_rate) + '</div></div>';
    }
    var right = '';
    if (load.byError.state === 'loading') right = '<div class="rw-panel rw-panel-pad rw-muted">Đang tải lỗi…</div>';
    else if (load.byError.state === 'error') right = '<div class="rw-panel rw-panel-pad"><div class="rw-alert bad">⚠ ' + RW.esc(load.byError.errorMsg) +
      '</div><button class="rw-btn" data-st-retry="byError">↻ Thử lại</button></div>';
    else if (!be || !(be.rows || []).length) right = '<div class="rw-panel rw-panel-pad"><div class="rw-empty">Kỳ này không ghi nhận lỗi nào. 🎉</div></div>';
    else {
      right = '<div class="rw-panel rw-panel-pad"><div class="rw-section-title">🍩 Lỗi theo nguyên nhân</div>' +
        '<div style="margin-top:.55rem">' + chartDonut(be.rows) + '</div>' +
        '<div class="rw-hint" style="margin-top:.35rem">Tổng lỗi: <b>' + num(be.error_total) + '</b>' +
        (be.error_total_prev ? ' · kỳ trước: ' + num(be.error_total_prev) : '') + '</div></div>';
    }
    return '<div class="rw-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:.65rem;margin:.85rem 0">' +
      left + mid + right + '</div>';
  }

  /* ================= Lịch nhiệt (chỉ tháng/tùy chọn) ================= */
  function renderCalendar() {
    if (state.period !== 'month' && state.period !== 'custom') return '';
    var cal = data.calendar;
    if (load.calendar.state === 'loading') return '<div class="rw-panel rw-panel-pad rw-muted">Đang tải lịch…</div>';
    if (load.calendar.state === 'error') return '<div class="rw-panel rw-panel-pad"><div class="rw-alert bad">⚠ ' + RW.esc(load.calendar.errorMsg) +
      '</div><button class="rw-btn" data-st-retry="calendar">↻ Thử lại</button></div>';
    if (!cal || !(cal.days || []).length) return '';
    return '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">🗓 Lịch nhiệt' +
      (cal.month ? ' ' + RW.esc(cal.month) : '') + ' · tổng ' + num(cal.month_total_points) + ' điểm</div>' +
      '<div style="margin-top:.55rem">' + calendarHeat(cal) + '</div></div>';
  }

  /* ================= Bảng account (xếp được, nhãn «yếu»/«đứt chuỗi») ================= */
  function renderAccountTable() {
    var ba = data.byAccount;
    var days = (ba && ba.days) || [];
    function labelTags(labels) {
      if (!labels || !labels.length) return '';
      return labels.map(function (l) {
        var cls = (l === 'yếu') ? 'warn' : 'need';
        return '<span class="rw-badge ' + cls + '" title="' +
          RW.esc(l === 'yếu' ? 'Thuộc 10% account có điểm thấp nhất trong kỳ.' : '≥ 3 ngày liên tiếp không có điểm.') + '">' +
          RW.esc(l) + '</span>';
      }).join(' ');
    }
    var cols = [
      { key: 'email', label: 'Email (đã che)', sortable: true, render: function (r) {
        return '<span title="' + RW.esc(r.email) + '">' + RW.esc(r.email) + '</span>' +
          (r.group_label ? '<div class="rw-muted" style="font-size:.72rem">' + RW.esc(r.group_label) + '</div>' : '');
      } },
      { key: 'points', label: 'Điểm kỳ', sortable: true, align: 'right', render: function (r) {
        return (r.points === null || r.points === undefined)
          ? '<span class="rw-warn" title="Run của account này chưa xác định được điểm trước/sau.">⚠ —</span>'
          : '<b>' + num(r.points) + '</b>';
      } },
      { key: 'avg_per_day', label: 'TB/ngày', sortable: true, align: 'right', render: function (r) { return dec(r.avg_per_day); } },
      { key: 'spark', label: 'Sparkline (' + (days.length || 0) + ' ngày)', render: function (r) {
        return sparkline(r.spark);
      } },
      { key: 'ok_rate', label: 'Tỉ lệ OK', sortable: true, align: 'right', render: function (r) { return pct(r.ok_rate); } },
      { key: 'error_rate', label: 'Tỉ lệ lỗi', sortable: true, align: 'right', render: function (r) { return pct(r.error_rate); } },
      { key: 'streak_days', label: 'Chuỗi ngày', sortable: true, align: 'right', render: function (r) {
        return num(r.streak_days) + (r.gap_days ? ' <span class="rw-muted">(gap ' + num(r.gap_days) + ')</span>' : '');
      } },
      { key: 'runs', label: 'Run (ok/err/unk)', sortable: true, align: 'right', render: function (r) {
        return '<span class="rw-muted" style="font-size:.78rem">' + num(r.runs) + ' (' + num(r.runs_ok) + '/' + num(r.runs_err) + '/' + num(r.runs_unknown) + ')</span>';
      } },
      { key: 'last_run_at', label: 'Lần cuối', sortable: true, render: function (r) {
        return '<span style="font-size:.8rem">' + RW.fmtTimeShort(r.last_run_at) + '</span>';
      } },
      { key: 'labels', label: 'Nhãn', render: function (r) { return labelTags(r.labels); } }
    ];
    var box = container.querySelector('[data-st-acc-table]');
    if (!box) return;
    RW.renderTable(box, {
      state: load.byAccount.state === 'empty' ? 'ok' : load.byAccount.state,
      errorMsg: load.byAccount.errorMsg,
      rows: (ba && ba.rows) || [],
      total: (ba && ba.total) || 0,
      page: state.page, perPage: state.perPage,
      sort: state.sort, selected: new Set(),
      columns: cols,
      emptyText: 'Chưa có account nào trong kỳ này.',
      onRetry: function () { fetchByAccount(); },
      onSort: function (by, dir) { state.sort = { by: by, dir: dir }; state.page = 1; saveUrl(); fetchByAccount(); },
      onPage: function (p) { state.page = p; saveUrl(); fetchByAccount(); },
      rowAttrs: function (r) {
        return { attrs: 'data-st-acc-row="' + r.id + '" tabindex="0" role="button"' };
      }
    });
    /* bấm hàng / Enter mở ngăn chi tiết account */
    Array.prototype.forEach.call(box.querySelectorAll('[data-st-acc-row]'), function (tr) {
      function open() {
        var id = tr.getAttribute('data-st-acc-row');
        var row = ((ba && ba.rows) || []).filter(function (x) { return String(x.id) === String(id); })[0];
        if (row) openAccountPanel(row);
      }
      tr.addEventListener('click', open);
      tr.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
  }

  /* ================= Ngăn chi tiết account (mục 2.4) ================= */
  function openAccountPanel(r) {
    var days = (data.byAccount && data.byAccount.days) || [];
    var body = '<div class="rw-kv">' +
      '<div><b>ID</b> ' + num(r.id) + '</div>' +
      '<div><b>Email (đã che)</b> ' + RW.esc(r.email) + '</div>' +
      '<div><b>Trạng thái</b> ' + RW.accBadge(r.status) + (r.blocked ? ' <span class="rw-badge error">🔒 bị chặn</span>' : '') + '</div>' +
      '<div><b>Nhóm</b> ' + RW.esc(r.group_label || '—') + '</div>' +
      '<div><b>Tag</b> ' + RW.esc(r.tags || '—') + '</div>' +
      '<div><b>Điểm kỳ</b> ' + ((r.points === null || r.points === undefined) ? '<span class="rw-warn">⚠ chưa xác định</span>' : num(r.points)) + '</div>' +
      '<div><b>TB/ngày</b> ' + dec(r.avg_per_day) + '</div>' +
      '<div><b>Run</b> ' + num(r.runs) + ' (ok ' + num(r.runs_ok) + ' · err ' + num(r.runs_err) + ' · unknown ' + num(r.runs_unknown) + ')</div>' +
      '<div><b>Tỉ lệ OK / lỗi</b> ' + pct(r.ok_rate) + ' / ' + pct(r.error_rate) + '</div>' +
      '<div><b>Chuỗi ngày</b> ' + num(r.streak_days) + ' · gap ' + num(r.gap_days) + '</div>' +
      '<div><b>Lần cuối chạy</b> ' + RW.fmtTime(r.last_run_at) + '</div>' +
      '</div>' +
      '<div class="rw-section-title" style="margin-top:.85rem">Điểm theo ngày</div>' +
      '<div style="margin-top:.45rem">' + sparkline(r.spark) + '</div>' +
      (days.length ? '<div class="rw-hint" style="margin-top:.35rem">Ngày: ' + days.map(RW.esc).join(' · ') + '</div>' : '') +
      '<div class="rw-hint" style="margin-top:.65rem">⚠ = ngày có run nhưng chưa xác định được điểm — không nên đọc là «0 điểm».</div>';
    RW.slidePanel({ title: 'Chi tiết account #' + r.id, body: body });
  }

  /* ================= Bảng nhiệm vụ còn sót (pending_tasks) ================= */
  function renderPendingTable() {
    var pd = data.pending;
    var cols = [
      { key: 'type', label: 'Loại', render: function (r) {
        return r.type === 'account'
          ? '<span class="rw-badge warn">👤 account</span>'
          : '<span class="rw-badge need">📋 nhiệm vụ</span>';
      } },
      { key: 'email', label: 'Email (đã che)', render: function (r) { return RW.esc(r.email || '—'); } },
      { key: 'date', label: 'Ngày', render: function (r) { return fmtDateVN(r.date); } },
      { key: 'task_group', label: 'Nhóm nhiệm vụ', render: function (r) {
        return RW.esc(r.task_group || (r.task_key || '—'));
      } },
      { key: 'status', label: 'Trạng thái', render: function (r) { return '<span class="rw-badge warn">' + RW.esc(r.status || 'pending') + '</span>'; } },
      { key: 'reason', label: 'Lý do', render: function (r) {
        return '<span title="' + RW.esc(r.reason || '') + '">' + RW.esc(r.reason || '—') + '</span>' +
          (r.reason_code ? ' <span class="rw-muted" style="font-size:.72rem">[' + RW.esc(r.reason_code) + ']</span>' : '');
      } },
      { key: 'retry_at', label: 'Giờ mở lại', align: 'right', render: function (r) {
        return '<span style="font-size:.8rem">' + RW.fmtTimeShort(r.retry_at) + '</span>' +
          (r.hours_until !== null && r.hours_until !== undefined
            ? '<div class="rw-muted" style="font-size:.72rem">còn ' + dec(r.hours_until, 1) + 'h</div>' : '');
      } }
    ];
    var box = container.querySelector('[data-st-pending-table]');
    if (!box) return;
    RW.renderTable(box, {
      state: load.pending.state === 'empty' ? 'ok' : load.pending.state,
      errorMsg: load.pending.errorMsg,
      rows: (pd && pd.rows) || [],
      total: (pd && pd.total) || 0,
      page: state.pendingPage, perPage: 50,
      selected: new Set(),
      columns: cols,
      emptyText: pd && pd.scope === 'today'
        ? 'Hôm nay không còn account/nhiệm vụ nào sót. 🎉'
        : 'Kỳ này không còn account/nhiệm vụ nào sót.',
      onRetry: function () { fetchPending(); },
      onPage: function (p) { state.pendingPage = p; fetchPending(); }
    });
  }

  /* ================= Bảng proxy hiệu năng (compact) ================= */
  function renderProxyTable() {
    var bp = data.byProxy;
    var cols = [
      { key: 'proxy_mask', label: 'Proxy (đã che)', render: function (r) {
        return RW.esc(r.proxy_mask || ('#' + r.proxy_id)) +
          (r.label ? '<div class="rw-muted" style="font-size:.72rem">' + RW.esc(r.label) + '</div>' : '');
      } },
      { key: 'proxy_status', label: 'Trạng thái', render: function (r) {
        var map = { ok: 'ok', alive: 'ok', dead: 'error', bad: 'error', unknown: 'muted' };
        return '<span class="rw-badge ' + (map[r.proxy_status] || 'muted') + '">' + RW.esc(r.proxy_status || '?') + '</span>';
      } },
      { key: 'latency_ms', label: 'Độ trễ', align: 'right', render: function (r) {
        return (r.latency_ms === null || r.latency_ms === undefined) ? '—' : num(r.latency_ms) + ' ms';
      } },
      { key: 'accounts', label: 'Acc', align: 'right', render: function (r) { return num(r.accounts); } },
      { key: 'runs', label: 'Run (ok/err)', align: 'right', render: function (r) {
        return num(r.runs) + ' <span class="rw-muted">(' + num(r.runs_ok) + '/' + num(r.runs_err) + ')</span>';
      } },
      { key: 'ok_rate', label: 'OK', align: 'right', render: function (r) { return pct(r.ok_rate); } },
      { key: 'error_rate', label: 'Lỗi', align: 'right', render: function (r) { return pct(r.error_rate); } },
      { key: 'points', label: 'Điểm', align: 'right', render: function (r) { return num(r.points); } }
    ];
    var box = container.querySelector('[data-st-proxy-table]');
    if (!box) return;
    RW.renderTable(box, {
      state: load.byProxy.state === 'empty' ? 'ok' : load.byProxy.state,
      errorMsg: load.byProxy.errorMsg,
      rows: (bp && bp.rows) || [],
      total: (bp && bp.total) || 0,
      page: 1, perPage: 50,
      selected: new Set(),
      columns: cols,
      emptyText: 'Không có proxy nào trong kỳ này.',
      onRetry: function () { fetchByProxy(); }
    });
  }

  /* ================= Render tổng thể ================= */
  function render() {
    if (!container) return;
    var s = data.summary || {};
    var periodInfo = s.period || {};
    var banner = '';
    if (periodInfo.timezone) {
      banner = '<div class="rw-muted" style="font-size:.76rem;margin-top:.35rem">Kỳ: ' + RW.esc(periodLabel()) +
        ' · ' + num(periodInfo.days) + ' ngày' +
        (periodInfo.prev ? ' · so với ' + fmtDateVN(periodInfo.prev.from) + ' → ' + fmtDateVN(periodInfo.prev.to) : '') +
        ' · múi giờ ' + RW.esc(periodInfo.timezone) +
        (periodInfo.reset_time ? ' · reset ' + RW.esc(periodInfo.reset_time) : '') +
        (s.generated_at ? ' · dữ liệu cập nhật ' + RW.fmtTimeShort(s.generated_at) : '') + '</div>';
    }
    container.innerHTML =
      '<div class="rw-head"><div><h2>📈 Tiến độ &amp; Thống kê</h2>' + banner + '</div></div>' +
      renderToolbar() +
      noticesHtml(s.notices) +
      anomaliesHtml(s.anomalies) +
      '<div data-st-kpi>' + renderKpis() + '</div>' +
      '<div data-st-chart>' + renderChart() + '</div>' +
      renderFunnelAndTasks() +
      renderCalendar() +
      confidenceHtml(s.data_confidence) +
      '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">📋 Bảng account trong kỳ' +
      ' <span class="rw-muted" style="font-size:.76rem">(bấm hàng để xem chi tiết · xếp theo cột)</span></div>' +
      '<div data-st-acc-table style="margin-top:.55rem"></div></div>' +
      '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">⏳ Nhiệm vụ / account còn sót</div>' +
      '<div data-st-pending-table style="margin-top:.55rem"></div></div>' +
      '<div class="rw-panel rw-panel-pad" style="margin:.85rem 0"><div class="rw-section-title">🌐 Hiệu năng theo proxy</div>' +
      '<div data-st-proxy-table style="margin-top:.55rem"></div></div>' +
      renderFormulas(s.formulas);
    bindToolbar();
    renderAccountTable();
    renderPendingTable();
    renderProxyTable();
  }

  /* ================= Bảng công thức (tooltip mục 5.2) ================= */
  function renderFormulas(formulas) {
    if (!formulas) return '';
    var keys = Object.keys(formulas);
    if (!keys.length) return '';
    return '<details class="rw-panel rw-panel-pad" style="margin:.85rem 0"><summary class="rw-section-title" style="cursor:pointer">📖 Công thức tính toán (mục 5.2)</summary>' +
      '<div class="rw-kv" style="margin-top:.55rem">' + keys.map(function (k) {
        return '<div style="margin:.32rem 0"><b style="font-size:.82rem">' + RW.esc(k) + '</b>' +
          '<div class="rw-muted" style="font-size:.8rem">' + RW.esc(formulas[k]) + '</div></div>';
      }).join('') + '</div></details>';
  }

  /* ================= Bind sự kiện thanh công cụ ================= */
  function bindToolbar() {
    Array.prototype.forEach.call(container.querySelectorAll('[data-st-period]'), function (b) {
      b.addEventListener('click', function () {
        state.period = b.getAttribute('data-st-period');
        if (state.period === 'custom' && (!state.from || !state.to)) {
          state.from = addDays(todayIso(), -6);
          state.to = todayIso();
        }
        state.page = 1; state.pendingPage = 1;
        saveUrl(); fetchAll();
      });
    });
    Array.prototype.forEach.call(container.querySelectorAll('[data-st-nav]'), function (b) {
      b.addEventListener('click', function () {
        var dir = b.getAttribute('data-st-nav');
        if (dir === '0') {
          state.date = todayIso();
        } else if (state.period === 'day') {
          state.date = addDays(state.date, dir === '1' ? 1 : -1);
        } else if (state.period === 'week') {
          state.date = addDays(state.date, dir === '1' ? 7 : -7);
        } else if (state.period === 'month') {
          state.date = addMonths(state.date, dir === '1' ? 1 : -1);
        }
        state.page = 1; state.pendingPage = 1;
        saveUrl(); fetchAll();
      });
    });
    var dateIn = container.querySelector('[data-st-date]');
    if (dateIn) dateIn.addEventListener('change', function () {
      if (dateIn.value) { state.date = dateIn.value; state.page = 1; saveUrl(); fetchAll(); }
    });
    var fromIn = container.querySelector('[data-st-from]');
    if (fromIn) fromIn.addEventListener('change', function () { state.from = fromIn.value; });
    var toIn = container.querySelector('[data-st-to]');
    if (toIn) toIn.addEventListener('change', function () { state.to = toIn.value; });
    var applyBtn = container.querySelector('[data-st-apply-filter]');
    if (applyBtn) applyBtn.addEventListener('click', function () {
      ['device_id', 'proxy_id', 'group_label', 'provider', 'tag'].forEach(function (k) {
        var el2 = container.querySelector('[data-st-f="' + k + '"]');
        state.filters[k] = el2 ? el2.value.trim() : '';
      });
      if (state.period === 'custom' && (!state.from || !state.to || state.from > state.to)) {
        RW.toast('Khoảng tùy chọn chưa hợp lệ: cần từ ngày ≤ đến ngày.', 'err');
        return;
      }
      state.page = 1; state.pendingPage = 1;
      saveUrl(); fetchAll();
    });
    var clearBtn = container.querySelector('[data-st-clear-filter]');
    if (clearBtn) clearBtn.addEventListener('click', function () {
      state.filters = { device_id: '', proxy_id: '', group_label: '', provider: '', tag: '' };
      state.page = 1; saveUrl(); fetchAll();
    });
    var whatSel = container.querySelector('[data-st-export-what]');
    if (whatSel) whatSel.addEventListener('change', function () { state.exportWhat = whatSel.value; });
    var expBtn = container.querySelector('[data-st-export]');
    if (expBtn) expBtn.addEventListener('click', function () {
      var p = baseParams();
      p.set('action', 'export_csv');
      p.set('what', state.exportWhat);
      /* server trả text/csv + Content-Disposition → mở/tải trực tiếp */
      window.location.href = API + '?' + p.toString();
      RW.toast('Đang xuất CSV (' + state.exportWhat + ') với đúng bộ lọc hiện tại…', 'info');
    });
    var reloadBtn = container.querySelector('[data-st-reload]');
    if (reloadBtn) reloadBtn.addEventListener('click', function () { fetchAll(true); });
    Array.prototype.forEach.call(container.querySelectorAll('[data-st-retry]'), function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-st-retry');
        var map = { summary: fetchSummary, timeseries: fetchTimeseries, byTask: fetchByTask,
          byError: fetchByError, byAccount: fetchByAccount, byProxy: fetchByProxy,
          calendar: fetchCalendar, pending: fetchPending };
        if (map[key]) map[key]();
      });
    });
  }

  /* ================= Khởi tạo ================= */
  window.RW_TAB_STATS = {
    init: function (el) {
      container = el;
      if (!built) {
        built = true;
        loadUrl();
        fetchAll();
        /* polling 10s: chỉ refresh dữ liệu, KHÔNG đụng filter/scroll/chọn */
        poller = RW.startPolling(function () { fetchAll(true); });
      } else {
        render();
      }
    },
    refresh: function () { fetchAll(true); }
  };
})();
