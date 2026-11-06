/* ============================================================
 * tab-skills.js — Tab «Nhiệm vụ & Skill» (PROMPT UI v4, mục 6).
 *
 * Nối API api_v4_skills.php (contract lấy THEO FILE API):
 *   GET  ?action=summary | list | get | queue_unmatched | trace_get | export
 *   POST ?action=create | update | compile_guide | test_regression |
 *        test_live | verify | publish | rollback | deprecate | import |
 *        clone | merge | fingerprint_add
 *   POST ?action=queue_unmatched&op=skip|guide|ai|test
 *   POST ?action=trace_get&op=sample
 *
 * Ghi chú lệch contract (file API thắng):
 *   - create cần thêm skill_key + semver (mặc định 0.1.0).
 *   - queue op dùng queue_id; op=skip dùng ids[]; op=test bắt buộc account_id.
 *   - test_live enqueue dùng account_ids[] (tối đa 5) + device_id (API không có proxy_id).
 *   - trace_get (danh sách) truyền skill_id.
 *   - action=get không kèm rules_json của version cũ -> diff 2 version
 *     lấy qua action=export&id= (gói có chữ ký).
 *
 * Kết xuất TOÀN BỘ vào el (pane #pane-skills). JS thuần, theme .rw-*,
 * tiếng Việt, giờ hiển thị Asia/Bangkok (RW.fmtTime). Không lộ bí mật.
 * ============================================================ */
(function () {
  'use strict';

  var SK_API = 'api_v4_skills.php';

  /* ---- Enum đồng bộ với api_v4_skills.php ---- */
  var CATS = [
    ['search', 'Search'], ['click_offer', 'Click / Offer'], ['article', 'Đọc báo'],
    ['quiz_poll', 'Quiz / Poll'], ['game', 'Game'], ['other', 'Khác']
  ];
  var CAT_LABEL = {};
  CATS.forEach(function (c) { CAT_LABEL[c[0]] = c[1]; });
  var SKILL_STATUS = { draft: 'Nháp', verified: 'Verified', needs_review: 'Cần xem lại', deprecated: 'Ngừng' };
  var VERSION_STATUS = {
    draft: 'Nháp', verified: 'Verified', published: 'Đã phát hành',
    rolled_back: 'Đã rollback', needs_review: 'Cần xem lại', deprecated: 'Ngừng'
  };
  var QUEUE_STATUS = { pending: 'Chờ xử lý', pending_ai: 'Đang giao AI', solved: 'Đã có skill', skipped: 'Đã bỏ qua' };
  var STEP_ACTIONS = ['goto', 'click', 'type', 'select', 'wait', 'scroll', 'read', 'answer', 'loop'];
  var SEMVER_RE = /^\d+\.\d+\.\d+$/;
  var SKILL_KEY_RE = /^[a-z0-9][a-z0-9_\-]{1,63}$/;

  /* ---- State ---- */
  var state = {
    summary: null, sumState: 'loading', sumError: '',
    rows: [], total: 0, page: 1, perPage: 25, sort: { by: 'risk_score', dir: 'desc' },
    filters: { q: '', category: '', status: '', difficulty_min: '', difficulty_max: '', risk_only: '' },
    listState: 'loading', listError: '',
    qrows: [], qtotal: 0, qpage: 1, qperPage: 25, qsort: { by: 'last_seen', dir: 'desc' },
    qfilters: { q: '', category: '', status: '', difficulty_min: '', difficulty_max: '' },
    qState: 'loading', qError: '', qBy: { pending: 0, pending_ai: 0, solved: 0, skipped: 0 }
  };
  var container = null;
  var poller = null;

  /* ---- State của panel chi tiết skill ---- */
  var detail = null;
  var detailPanel = null;
  var detailTab = 'rules';
  var rulesMode = 'form';
  var rulesWorking = { steps: [] };
  var rawText = '';
  var guideText = '';
  var compileResult = null;
  var lastTest = null;
  var lastGates = null;
  var compareResult = null;
  var expandedTrace = 0;
  var validateMsg = null;

  /* ============================================================
   * Helper dùng chung
   * ============================================================ */
  function cut(s, n) {
    s = String(s === null || s === undefined ? '' : s);
    return s.length > n ? s.slice(0, n - 1) + '\u2026' : s;
  }
  function pct(v) {
    if (v === null || v === undefined || v === '') return '\u2014';
    return Math.round(Number(v) * 100) + '%';
  }
  function nf(v) {
    return (v === null || v === undefined || v === '') ? '\u2014' : RW.num(v);
  }
  function badge(cls, text, title) {
    return '<span class="rw-badge ' + cls + '"' + (title ? ' title="' + RW.esc(title) + '"' : '') + '>' + RW.esc(text) + '</span>';
  }
  function skillBadge(st, label) {
    var map = { draft: 'muted', verified: 'ok', needs_review: 'warn', deprecated: 'error' };
    return badge(map[st] || 'muted', label || SKILL_STATUS[st] || st || '\u2014');
  }
  function versionBadge(st, label) {
    var map = { draft: 'muted', verified: 'ok', published: 'running', rolled_back: 'warn', needs_review: 'warn', deprecated: 'error' };
    return badge(map[st] || 'muted', label || VERSION_STATUS[st] || st || '\u2014');
  }
  function queueBadge(st, label) {
    var map = { pending: 'muted', pending_ai: 'running', solved: 'ok', skipped: 'muted' };
    return badge(map[st] || 'muted', label || QUEUE_STATUS[st] || st || '\u2014');
  }
  function riskBadge(label, score) {
    var map = { '\u1ed2n \u0111\u1ecbnh': 'ok', 'Theo d\u00f5i': 'running', 'S\u1eafp h\u1ecfng': 'warn', 'C\u1ea7n xem l\u1ea1i ngay': 'error' };
    return badge(map[label] || 'muted', (label || '\u2014') + (score !== undefined && score !== null ? ' (' + score + ')' : ''), 'risk_score=' + score);
  }
  function difficultyBadge(label, val) {
    var map = { 'D\u1ec5': 'ok', 'V\u1eeba': 'running', 'Kh\u00f3': 'warn', 'Kh\u00f4ng t\u1ef1 \u0111\u1ed9ng \u0111\u01b0\u1ee3c': 'error' };
    return badge(map[label] || 'muted', (label || '\u2014') + (val !== undefined && val !== null && val !== '' ? ' \u00b7 ' + val : ''));
  }
  function safeLink(url) {
    var u = String(url || '').trim();
    if (/^https?:\/\//i.test(u)) {
      return '<a href="' + RW.esc(u) + '" target="_blank" rel="noopener noreferrer">' + RW.esc(cut(u, 42)) + '</a>';
    }
    return '<span class="rw-muted">' + RW.esc(cut(u, 42) || '\u2014') + '</span>';
  }
  function catOptions(sel) {
    return CATS.map(function (c) {
      return '<option value="' + c[0] + '"' + (c[0] === sel ? ' selected' : '') + '>' + RW.esc(c[1]) + '</option>';
    }).join('');
  }
  function actionOptions(sel) {
    return STEP_ACTIONS.map(function (a) {
      return '<option value="' + a + '"' + (a === sel ? ' selected' : '') + '>' + a + '</option>';
    }).join('');
  }
  function tryJson(s) {
    if (s !== null && typeof s === 'object') return s;
    try { return JSON.parse(String(s || '')); } catch (e) { return null; }
  }
  function fval(root, sel) {
    var e = root.querySelector(sel);
    return e ? String(e.value).trim() : '';
  }
  function fchecked(root, sel) {
    var e = root.querySelector(sel);
    return !!(e && e.checked);
  }
  function normalizeRules(r) {
    var out = { steps: [] };
    if (r && typeof r === 'object') {
      Object.keys(r).forEach(function (k) { out[k] = r[k]; });
      out.steps = Object.prototype.toString.call(r.steps) === '[object Array]' ? r.steps.slice() : [];
    }
    return out;
  }
  function parseVarsText(txt) {
    var out = {};
    String(txt || '').split('\n').forEach(function (line) {
      var i = line.indexOf('=');
      if (i <= 0) return;
      var k = line.slice(0, i).trim();
      var v = line.slice(i + 1).trim();
      if (k) out[k] = v;
    });
    return out;
  }
  function varsToText(vars) {
    if (!vars || typeof vars !== 'object') return '';
    return Object.keys(vars).map(function (k) { return k + ' = ' + vars[k]; }).join('\n');
  }

  /* ============================================================
   * Validate schema rules phía client (soi gương v4k_rules_parse)
   * ============================================================ */
  function validateRules(obj) {
    var errors = [];
    var warnings = [];
    if (!obj || Object.prototype.toString.call(obj) !== '[object Object]') {
      errors.push('rules phải là object JSON có trường steps.');
      return { errors: errors, warnings: warnings };
    }
    if (Object.prototype.toString.call(obj.steps) !== '[object Array]') {
      errors.push('rules_json phải có trường steps (mảng các bước).');
      return { errors: errors, warnings: warnings };
    }
    if (!obj.steps.length) errors.push('rules_json.steps rỗng — cần ít nhất 1 bước.');
    if (obj.steps.length > 100) errors.push('Quá nhiều bước (tối đa 100).');
    obj.steps.forEach(function (st, i) {
      var no = i + 1;
      if (!st || Object.prototype.toString.call(st) !== '[object Object]') {
        errors.push('Bước #' + no + ' phải là object.');
        return;
      }
      var act = String(st.action || '').toLowerCase().trim();
      if (STEP_ACTIONS.indexOf(act) < 0) {
        errors.push('Bước #' + no + ' có action không hợp lệ "' + cut(act, 20) + '". Chấp nhận: ' + STEP_ACTIONS.join(', ') + '.');
        return;
      }
      if (st.selectors !== undefined) {
        if (Object.prototype.toString.call(st.selectors) !== '[object Array]') {
          errors.push('Bước #' + no + ': selectors phải là mảng chuỗi.');
        } else if (st.selectors.length > 10) {
          errors.push('Bước #' + no + ': tối đa 10 selector dự phòng.');
        }
      }
      if (st.delay_min !== undefined || st.delay_max !== undefined) {
        var dmin = st.delay_min !== undefined ? Number(st.delay_min) : 0.2;
        var dmax = st.delay_max !== undefined ? Number(st.delay_max) : dmin;
        if (isNaN(dmin) || isNaN(dmax) || dmin < 0 || dmax > 120 || dmin > dmax) {
          errors.push('Bước #' + no + ': độ trễ phải nằm trong 0–120 giây và delay_min ≤ delay_max.');
        }
      }
      if (act === 'loop') {
        var c = st.count !== undefined ? parseInt(st.count, 10) : 0;
        if (!(c >= 1 && c <= 1000)) errors.push('Bước #' + no + ': loop cần count trong 1–1000.');
      }
      if (['click', 'type', 'select', 'read', 'answer'].indexOf(act) >= 0 &&
          (!st.selectors || !st.selectors.length)) {
        warnings.push('Bước #' + no + ' (' + act + ') chưa có selector dự phòng — nên bổ sung để tăng độ ổn định.');
      }
    });
    return { errors: errors, warnings: warnings };
  }

  /* ---- Phân tích JSON hoặc YAML con (subset) cho ô raw ---- */
  function splitYamlKV(s) {
    var m = String(s).match(/^([^:]+):\s*(.*)$/);
    if (!m) return null;
    var key = m[1].trim().replace(/^['"]|['"]$/g, '');
    var v = m[2].trim();
    return { key: key, value: v === '' ? null : yamlScalar(v) };
  }
  function yamlScalar(v) {
    v = String(v).trim();
    if (/^\[.*\]$/.test(v)) {
      return v.slice(1, -1).split(',').map(function (x) {
        return x.trim().replace(/^['"]|['"]$/g, '');
      }).filter(function (x) { return x !== ''; });
    }
    if (v === 'true') return true;
    if (v === 'false') return false;
    if (v === 'null' || v === '~') return null;
    if (/^-?\d+(\.\d+)?$/.test(v)) return Number(v);
    return v.replace(/^['"]|['"]$/g, '');
  }
  function yamlLite(src) {
    var lines = String(src).replace(/\r\n/g, '\n').split('\n');
    var root = {};
    var stack = [{ indent: -1, container: root, type: 'map' }];
    function nextInfo(idx) {
      for (var j = idx; j < lines.length; j++) {
        var t = lines[j];
        if (!t.trim() || /^\s*#/.test(t)) continue;
        return { indent: t.match(/^ */)[0].length, text: t.trim(), idx: j };
      }
      return null;
    }
    for (var i = 0; i < lines.length; i++) {
      var raw = lines[i];
      if (!raw.trim() || /^\s*#/.test(raw)) continue;
      var indent = raw.match(/^ */)[0].length;
      var line = raw.trim();
      while (stack.length > 1 && indent <= stack[stack.length - 1].indent) stack.pop();
      var top = stack[stack.length - 1];
      if (line.charAt(0) === '-') {
        if (top.type !== 'list') return { ok: false, error: 'dòng ' + (i + 1) + ': dấu "-" không thuộc danh sách.' };
        var item = {};
        var rest = line.slice(1).trim();
        if (rest !== '') {
          var kv = splitYamlKV(rest);
          if (!kv) return { ok: false, error: 'dòng ' + (i + 1) + ': không đọc được "key: value".' };
          if (kv.value === null) {
            var ni0 = nextInfo(i + 1);
            var isList0 = !!(ni0 && ni0.indent > indent && ni0.text.charAt(0) === '-');
            var cont0 = isList0 ? [] : {};
            item[kv.key] = cont0;
            stack.push({ indent: indent + 1, container: item, type: 'map' });
            stack.push({ indent: indent + 1, container: cont0, type: isList0 ? 'list' : 'map' });
          } else {
            item[kv.key] = kv.value;
            stack.push({ indent: indent, container: item, type: 'map' });
          }
        } else {
          stack.push({ indent: indent, container: item, type: 'map' });
        }
        top.container.push(item);
      } else {
        var kv2 = splitYamlKV(line);
        if (!kv2) return { ok: false, error: 'dòng ' + (i + 1) + ': không đọc được "key: value".' };
        if (kv2.value === null) {
          var ni = nextInfo(i + 1);
          var isList = !!(ni && ni.indent > indent && ni.text.charAt(0) === '-');
          var cont = isList ? [] : {};
          top.container[kv2.key] = cont;
          stack.push({ indent: indent, container: cont, type: isList ? 'list' : 'map' });
        } else {
          top.container[kv2.key] = kv2.value;
        }
      }
    }
    return { ok: true, value: root };
  }
  function parseRulesText(txt) {
    txt = String(txt || '').replace(/^\uFEFF/, '').trim();
    if (!txt) return { ok: false, errors: ['Nội dung rỗng.'] };
    try {
      return { ok: true, value: JSON.parse(txt), source: 'json' };
    } catch (e1) {
      var y = yamlLite(txt);
      if (y.ok) return { ok: true, value: y.value, source: 'yaml' };
      return { ok: false, errors: ['JSON không hợp lệ: ' + e1.message, 'YAML: ' + y.error] };
    }
  }

  /* ---- Diff 2 bộ rules (soi gương v4k_rules_diff) ---- */
  function diffRules(oldR, newR) {
    var os = (oldR && Object.prototype.toString.call(oldR.steps) === '[object Array]') ? oldR.steps : [];
    var ns = (newR && Object.prototype.toString.call(newR.steps) === '[object Array]') ? newR.steps : [];
    var added = [], removed = [], changed = [];
    var n = Math.max(os.length, ns.length);
    for (var i = 0; i < n; i++) {
      var o = i < os.length ? os[i] : null;
      var x = i < ns.length ? ns[i] : null;
      if (o === null && x !== null) added.push({ index: i + 1, step: x });
      else if (o !== null && x === null) removed.push({ index: i + 1, step: o });
      else if (JSON.stringify(o) !== JSON.stringify(x)) changed.push({ index: i + 1, from: o, to: x });
    }
    return {
      added: added, removed: removed, changed: changed,
      summary: '+' + added.length + ' \u2212' + removed.length + ' ~' + changed.length
    };
  }
  function stepBrief(st) {
    if (!st || typeof st !== 'object') return '?';
    var s = String(st.action || '?');
    if (st.url) s += ' ' + cut(st.url, 30);
    if (st.selectors && st.selectors.length) s += ' [' + cut(st.selectors[0], 24) + ']';
    if (st.note) s += ' \u2014 ' + cut(st.note, 36);
    return s;
  }
  function diffHtml(diff) {
    if (!diff) return '';
    var out = '<div class="rw-hint">Tóm tắt: <b>' + RW.esc(diff.summary) + '</b></div>';
    function li(arr, mark, cls) {
      return arr.map(function (x) {
        return '<div class="' + cls + '">' + mark + ' #' + x.index + ' ' + RW.esc(stepBrief(x.step || x.to || x.from)) + '</div>';
      }).join('');
    }
    out += li(diff.added, '+', 'rw-log-item');
    out += li(diff.removed, '\u2212', 'rw-log-item');
    out += diff.changed.map(function (x) {
      return '<div class="rw-log-item">~ #' + x.index + ' ' + RW.esc(stepBrief(x.from)) + ' <b>\u2192</b> ' + RW.esc(stepBrief(x.to)) + '</div>';
    }).join('');
    return out;
  }
  function msgList(items, cls) {
    return (items || []).map(function (t) {
      return '<div class="' + cls + '">' + RW.esc(t) + '</div>';
    }).join('');
  }

  /* ============================================================
   * URL state (prefix sk.)
   * ============================================================ */
  function loadUrl() {
    var s = RW.readUrlState('sk');
    state.filters.q = s.q || '';
    state.filters.category = s.cat || '';
    state.filters.status = s.st || '';
    state.filters.difficulty_min = s.dmin || '';
    state.filters.difficulty_max = s.dmax || '';
    state.filters.risk_only = s.risk || '';
    state.page = parseInt(s.page || '1', 10) || 1;
    if (s.sort) state.sort.by = s.sort;
    if (s.dir) state.sort.dir = s.dir === 'asc' ? 'asc' : 'desc';
    state.qfilters.q = s.qq || '';
    state.qfilters.category = s.qcat || '';
    state.qfilters.status = s.qst || '';
    state.qfilters.difficulty_min = s.qdmin || '';
    state.qfilters.difficulty_max = s.qdmax || '';
    state.qpage = parseInt(s.qpage || '1', 10) || 1;
    if (s.qsort) state.qsort.by = s.qsort;
    if (s.qdir) state.qsort.dir = s.qdir === 'asc' ? 'asc' : 'desc';
  }
  function saveUrl() {
    RW.writeUrlState('sk', {
      q: state.filters.q, cat: state.filters.category, st: state.filters.status,
      dmin: state.filters.difficulty_min, dmax: state.filters.difficulty_max,
      risk: state.filters.risk_only, page: state.page,
      sort: state.sort.by, dir: state.sort.dir,
      qq: state.qfilters.q, qcat: state.qfilters.category, qst: state.qfilters.status,
      qdmin: state.qfilters.difficulty_min, qdmax: state.qfilters.difficulty_max,
      qpage: state.qpage, qsort: state.qsort.by, qdir: state.qsort.dir
    });
  }

  /* ============================================================
   * Tải dữ liệu
   * ============================================================ */
  function fetchSummary(silent) {
    if (!silent) { state.sumState = 'loading'; state.sumError = ''; render(); }
    RW.api(SK_API + '?action=summary').then(function (res) {
      if (!res.ok) {
        state.sumState = 'error'; state.sumError = RW.errText(res);
        if (silent) updateSummaryBox(); else render();
        return;
      }
      state.summary = (res.data && res.data.data) || {};
      state.sumState = 'ok';
      if (silent) updateSummaryBox(); else render();
    });
  }
  function updateSummaryBox() {
    if (!container) return;
    var box = container.querySelector('[data-sk-summary]');
    if (box) box.outerHTML = summaryHtml();
  }

  function fetchList(silent) {
    if (!silent) { state.listState = 'loading'; state.listError = ''; render(); }
    var p = new URLSearchParams();
    p.set('action', 'list');
    p.set('page', String(state.page));
    p.set('per_page', String(state.perPage));
    p.set('sort', state.sort.by === 'risk_score' ? 'risk' : state.sort.by);
    p.set('dir', state.sort.dir);
    if (state.filters.q) p.set('q', state.filters.q);
    if (state.filters.category) p.set('category', state.filters.category);
    if (state.filters.status) p.set('status', state.filters.status);
    if (state.filters.difficulty_min !== '' && state.filters.difficulty_min !== null) p.set('difficulty_min', state.filters.difficulty_min);
    if (state.filters.difficulty_max !== '' && state.filters.difficulty_max !== null) p.set('difficulty_max', state.filters.difficulty_max);
    if (state.filters.risk_only) p.set('risk_only', '1');
    RW.api(SK_API + '?' + p.toString()).then(function (res) {
      if (!res.ok) {
        state.listState = 'error'; state.listError = RW.errText(res);
        if (silent) renderListTable(); else render();
        return;
      }
      var d = res.data || {};
      state.rows = d.data || [];
      state.total = d.total || 0;
      state.page = d.page || state.page;
      state.listState = state.rows.length ? 'ok' : 'empty';
      if (silent) renderListTable(); else render();
    });
  }

  function fetchQueue(silent) {
    if (!silent) { state.qState = 'loading'; state.qError = ''; render(); }
    var p = new URLSearchParams();
    p.set('action', 'queue_unmatched');
    p.set('page', String(state.qpage));
    p.set('per_page', String(state.qperPage));
    p.set('sort', state.qsort.by);
    p.set('dir', state.qsort.dir);
    if (state.qfilters.q) p.set('q', state.qfilters.q);
    if (state.qfilters.category) p.set('category', state.qfilters.category);
    if (state.qfilters.status) p.set('status', state.qfilters.status);
    if (state.qfilters.difficulty_min !== '' && state.qfilters.difficulty_min !== null) p.set('difficulty_min', state.qfilters.difficulty_min);
    if (state.qfilters.difficulty_max !== '' && state.qfilters.difficulty_max !== null) p.set('difficulty_max', state.qfilters.difficulty_max);
    RW.api(SK_API + '?' + p.toString()).then(function (res) {
      if (!res.ok) {
        state.qState = 'error'; state.qError = RW.errText(res);
        if (silent) renderQueueTable(); else render();
        return;
      }
      var d = res.data || {};
      state.qrows = d.data || [];
      state.qtotal = d.total || 0;
      state.qpage = d.page || state.qpage;
      if (d.summary && d.summary.by_status) state.qBy = d.summary.by_status;
      state.qState = state.qrows.length ? 'ok' : 'empty';
      if (silent) renderQueueTable(); else render();
    });
  }

  function findQueue(id) {
    for (var i = 0; i < state.qrows.length; i++) {
      if (Number(state.qrows[i].id) === Number(id)) return state.qrows[i];
    }
    return null;
  }

  /* ============================================================
   * Render trang chính
   * ============================================================ */
  function render() {
    if (!container) return;
    container.innerHTML =
      '<div class="rw-head"><div><h2>\u{1F9ED} Nhiệm vụ &amp; Skill</h2>' +
      '<div class="rw-muted">Ngày ' + RW.esc((state.summary && state.summary.date) || '\u2014') +
      ' \u00b7 ' + RW.num(state.total) + ' skill \u00b7 ' + RW.num(state.qtotal) + ' nhiệm vụ trong hàng đợi</div></div>' +
      '<div class="rw-inline">' +
      '<button class="rw-btn primary" data-sk-create>＋ Tạo skill</button>' +
      '<button class="rw-btn" data-sk-import>\u21E9 Nhập gói</button>' +
      '<button class="rw-btn" data-sk-export>\u21E7 Xuất gói (có chữ ký)</button>' +
      '<button class="rw-btn" data-sk-reload>\u21BB Làm mới</button>' +
      '</div></div>' +
      summaryHtml() +
      '<div class="rw-section-title" style="margin-top:1rem">\u{1F4DA} Thư viện Skill</div>' +
      listFiltersHtml() +
      '<div class="rw-panel" data-sk-table></div>' +
      '<div class="rw-section-title" style="margin-top:1.1rem">\u{1F195} Hàng đợi «Nhiệm vụ mới chưa có skill»</div>' +
      queueFiltersHtml() +
      '<div class="rw-panel" data-sk-qtable></div>';
    bindHeader();
    renderListTable();
    renderQueueTable();
  }

  /* ---- Thẻ tổng hợp (mục 1) ---- */
  function summaryHtml() {
    if (state.sumState === 'loading') {
      return '<div class="rw-cards" data-sk-summary><div class="rw-card"><div class="k">Tổng hợp hôm nay</div>' +
        '<div class="v" style="font-size:1rem">Đang tải\u2026</div></div></div>';
    }
    if (state.sumState === 'error') {
      return '<div class="rw-cards" data-sk-summary><div class="rw-card"><div class="k">Lỗi tải tổng hợp</div>' +
        '<div class="v" style="font-size:.95rem;color:var(--rw-err)">' + RW.esc(state.sumError) + '</div>' +
        '<div class="s"><button class="rw-btn" data-sk-sum-retry>\u21BB Thử lại</button></div></div></div>';
    }
    var s = state.summary || {};
    var cov = s.skill_coverage_today || {};
    var na = s.non_automatable || {};
    var risk = s.skills_at_risk || [];
    var riskLines = risk.map(function (r) {
      return '<div class="s"><a href="#" data-act="open-skill" data-id="' + r.id + '">' + RW.esc(cut(r.name, 24)) + '</a> \u00b7 ' +
        RW.esc(r.risk_label || '\u2014') + ' \u00b7 fail ' + RW.num(r.fail_streak) + ' \u00b7 7d ' + pct(r.success_rate_7d) + '</div>';
    }).join('');
    var reasonLines = (na.reasons || []).map(function (x) {
      return '<div class="s">' + RW.esc(cut(x.task_key, 20)) + ': ' + RW.esc(cut(x.reason, 70)) + '</div>';
    }).join('');
    return '<div class="rw-cards" data-sk-summary>' +
      '<div class="rw-card"><div class="k">Tỉ lệ phủ skill hôm nay</div><div class="v">' + pct(cov.rate) + '</div>' +
      '<div class="s">Đã xử lý ' + nf(cov.resolved) + ' \u00b7 skill ' + nf(cov.by_skill) +
      ' \u00b7 AI ' + nf(cov.by_ai) + ' \u00b7 người ' + nf(cov.by_human) + '</div></div>' +
      '<div class="rw-card"><div class="k">Lượt AI tiêu thụ</div><div class="v">' + nf(s.ai_calls_today) + '</div>' +
      '<div class="s">trong ngày ' + RW.esc(s.date || '\u2014') + '</div></div>' +
      '<div class="rw-card"><div class="k">Nhiệm vụ chưa có skill</div><div class="v">' + nf(s.queue_pending) + '</div>' +
      '<div class="s">đang giao AI: ' + nf(s.queue_pending_ai) + '</div></div>' +
      '<div class="rw-card"><div class="k">Skill sắp hỏng</div><div class="v">' + RW.num(risk.length) + '</div>' +
      (riskLines || '<div class="s">Không có skill nào bị cảnh báo.</div>') + '</div>' +
      '<div class="rw-card"><div class="k">Không tự động được</div><div class="v">' + nf(na.count_today) + '</div>' +
      '<div class="s">trong hàng đợi mức khó: ' + nf(na.count_queue_hard) + '</div>' +
      (reasonLines || '<div class="s">Không có lý do cụ thể.</div>') + '</div>' +
      '</div>';
  }

  /* ---- Bộ lọc thư viện skill ---- */
  function listFiltersHtml() {
    var f = state.filters;
    return '<div class="rw-filters" style="margin:.65rem 0;flex-wrap:wrap">' +
      '<input class="rw-input" style="max-width:15rem" placeholder="\u{1F50E} Tìm tên, skill_key, fingerprint\u2026" value="' + RW.esc(f.q) + '" data-sk-search>' +
      '<select class="rw-input" data-sk-filter="category"><option value="">Nhóm: tất cả</option>' +
      CATS.map(function (c) {
        return '<option value="' + c[0] + '"' + (f.category === c[0] ? ' selected' : '') + '>' + RW.esc(c[1]) + '</option>';
      }).join('') + '</select>' +
      '<select class="rw-input" data-sk-filter="status"><option value="">Trạng thái: tất cả</option>' +
      Object.keys(SKILL_STATUS).map(function (k) {
        return '<option value="' + k + '"' + (f.status === k ? ' selected' : '') + '>' + RW.esc(SKILL_STATUS[k]) + '</option>';
      }).join('') + '</select>' +
      '<input class="rw-input" style="max-width:6.5rem" type="number" min="0" max="100" placeholder="Độ khó ≥" value="' + RW.esc(f.difficulty_min) + '" data-sk-filter="difficulty_min">' +
      '<input class="rw-input" style="max-width:6.5rem" type="number" min="0" max="100" placeholder="Độ khó ≤" value="' + RW.esc(f.difficulty_max) + '" data-sk-filter="difficulty_max">' +
      '<button class="rw-chip' + (f.risk_only ? ' active' : '') + '" data-sk-risk>\u26A0 Sắp hỏng (risk ≥ 50)</button>' +
      '<button class="rw-btn" data-sk-clear-filter>\u2715 Xóa bộ lọc</button>' +
      '</div>';
  }

  /* ---- Bộ lọc hàng đợi ---- */
  function queueFiltersHtml() {
    var f = state.qfilters;
    var by = state.qBy || {};
    return '<div class="rw-filters" style="margin:.65rem 0;flex-wrap:wrap">' +
      '<input class="rw-input" style="max-width:15rem" placeholder="\u{1F50E} Tìm title, fingerprint, url\u2026" value="' + RW.esc(f.q) + '" data-sk-qsearch>' +
      '<select class="rw-input" data-sk-qfilter="status"><option value="">Trạng thái: tất cả</option>' +
      Object.keys(QUEUE_STATUS).map(function (k) {
        return '<option value="' + k + '"' + (f.status === k ? ' selected' : '') + '>' + RW.esc(QUEUE_STATUS[k]) +
          ' (' + RW.num(by[k] || 0) + ')</option>';
      }).join('') + '</select>' +
      '<select class="rw-input" data-sk-qfilter="category"><option value="">Nhóm: tất cả</option>' +
      CATS.map(function (c) {
        return '<option value="' + c[0] + '"' + (f.category === c[0] ? ' selected' : '') + '>' + RW.esc(c[1]) + '</option>';
      }).join('') + '</select>' +
      '<input class="rw-input" style="max-width:6.5rem" type="number" min="0" max="100" placeholder="Độ khó ≥" value="' + RW.esc(f.difficulty_min) + '" data-sk-qfilter="difficulty_min">' +
      '<input class="rw-input" style="max-width:6.5rem" type="number" min="0" max="100" placeholder="Độ khó ≤" value="' + RW.esc(f.difficulty_max) + '" data-sk-qfilter="difficulty_max">' +
      '<button class="rw-btn" data-sk-qclear>\u2715 Xóa bộ lọc</button>' +
      '</div>';
  }

  /* ---- Bảng thư viện skill (mục 2) ---- */
  function listColumns() {
    return [
      { key: 'name', label: 'Tên', sortable: true, render: function (r) {
        return '<b>' + RW.esc(r.name) + '</b>' +
          '<div class="rw-muted" style="font-family:var(--font-mono);font-size:.72rem">' + RW.esc(r.skill_key) + '</div>'; } },
      { key: 'category', label: 'Nhóm', sortable: true, render: function (r) {
        return RW.esc(r.category_label || CAT_LABEL[r.category] || r.category); } },
      { key: 'fingerprint', label: 'Fingerprint', render: function (r) {
        var fps = (r.fingerprints && r.fingerprints.length) ? r.fingerprints : [r.fingerprint];
        var first = fps[0] || '';
        return '<span style="font-family:var(--font-mono);font-size:.72rem" title="' + RW.esc(fps.join(' | ')) + '">' +
          RW.esc(cut(first, 26)) + (fps.length > 1 ? ' <span class="rw-muted">+' + (fps.length - 1) + '</span>' : '') + '</span>'; } },
      { key: 'current_version', label: 'Version', sortable: true, render: function (r) {
        return '<span style="font-family:var(--font-mono)">' + RW.esc(r.current_version || '\u2014') + '</span>'; } },
      { key: 'status', label: 'Trạng thái', sortable: true, render: function (r) {
        return skillBadge(r.status, r.status_label); } },
      { key: 'difficulty', label: 'Độ khó', sortable: true, render: function (r) {
        return difficultyBadge(r.difficulty_label, r.difficulty); } },
      { key: 'success', label: 'Thành công / Thất bại', render: function (r) {
        return '\u2713 ' + RW.num(r.success) + ' \u00b7 \u2715 ' + RW.num(r.fail) +
          (r.fail_streak ? ' <span class="rw-badge warn">fail ' + RW.num(r.fail_streak) + ' liên tiếp</span>' : ''); } },
      { key: 'success_rate_7d', label: 'Tỉ lệ 7 ngày', render: function (r) { return '<b>' + pct(r.success_rate_7d) + '</b>'; } },
      { key: 'device_count', label: 'Thiết bị nhận version', render: function (r) { return RW.num(r.device_count); } },
      { key: 'risk_score', label: 'Risk', sortable: true, render: function (r) {
        return riskBadge(r.risk_label, r.risk_score); } },
      { key: '_act', label: 'Thao tác', render: function (r) {
        return '<button class="rw-btn" data-sk-detail-btn="' + r.id + '">Chi tiết</button>'; } }
    ];
  }
  function renderListTable() {
    var box = container.querySelector('[data-sk-table]');
    if (!box) return;
    RW.renderTable(box, {
      state: state.listState, errorMsg: state.listError,
      rows: state.rows, total: state.total, page: state.page, perPage: state.perPage,
      sort: state.sort, columns: listColumns(),
      emptyText: 'Chưa có skill nào trong thư viện.',
      emptyAction: '<button class="rw-btn primary" data-rw-empty-act>＋ Tạo skill đầu tiên</button>',
      onEmptyAction: openCreate,
      onRetry: function () { fetchList(); },
      onSort: function (by, dir) { state.sort = { by: by, dir: dir }; state.page = 1; saveUrl(); fetchList(true); },
      onPage: function (p) { state.page = p; saveUrl(); fetchList(true); },
      rowAttrs: function (r) { return { attrs: 'data-sk-row="' + r.id + '" style="cursor:pointer"' }; }
    });
    RW.qsa('[data-sk-row]', box).forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('button, input, select, a, textarea')) return;
        openSkillDetail(Number(tr.getAttribute('data-sk-row')));
      });
    });
    RW.qsa('[data-sk-detail-btn]', box).forEach(function (b) {
      b.addEventListener('click', function () { openSkillDetail(Number(b.getAttribute('data-sk-detail-btn'))); });
    });
  }

  /* ---- Bảng hàng đợi (mục 3) ---- */
  function queueColumns() {
    return [
      { key: 'title', label: 'Nhiệm vụ', render: function (r) {
        return '<b>' + RW.esc(r.title || '(không tiêu đề)') + '</b>' +
          '<div class="rw-muted" style="font-family:var(--font-mono);font-size:.72rem">' + RW.esc(cut(r.fingerprint, 40)) + '</div>' +
          '<div style="font-size:.72rem">' + safeLink(r.sample_url) + '</div>'; } },
      { key: 'screenshot', label: 'Ảnh chụp', render: function (r) {
        if (!r.screenshot) return '<span class="rw-muted">\u2014</span>';
        var src = String(r.screenshot);
        if (!/^(https?:\/\/|\/|\.\/)/i.test(src)) return '<span class="rw-muted">\u2014</span>';
        return '<a href="' + RW.esc(src) + '" target="_blank" rel="noopener noreferrer">' +
          '<img src="' + RW.esc(src) + '" alt="ảnh chụp nhiệm vụ" style="max-width:76px;max-height:52px;border-radius:6px;border:1px solid var(--color-border)"></a>'; } },
      { key: 'difficulty', label: 'Độ khó', render: function (r) { return difficultyBadge(r.difficulty_label, r.difficulty); } },
      { key: 'occurrences', label: 'Số lần gặp', render: function (r) { return '<b>' + RW.num(r.occurrences) + '</b>'; } },
      { key: 'status', label: 'Trạng thái', render: function (r) { return queueBadge(r.status, r.status_label); } },
      { key: 'first_seen', label: 'Lần đầu', render: function (r) { return RW.fmtTimeShort(r.first_seen); } },
      { key: 'last_seen', label: 'Gần nhất', sortable: true, render: function (r) { return RW.fmtTimeShort(r.last_seen); } },
      { key: '_act', label: 'Thao tác', width: '17rem', render: function (r) {
        return '<button class="rw-btn" data-qact="test" data-qid="' + r.id + '">Giải thử</button> ' +
          '<button class="rw-btn" data-qact="guide" data-qid="' + r.id + '">Viết hướng dẫn</button> ' +
          '<button class="rw-btn" data-qact="ai" data-qid="' + r.id + '">Giao AI giải</button> ' +
          '<button class="rw-btn danger" data-qact="skip" data-qid="' + r.id + '">Bỏ qua</button>'; } }
    ];
  }
  function renderQueueTable() {
    var box = container.querySelector('[data-sk-qtable]');
    if (!box) return;
    RW.renderTable(box, {
      state: state.qState, errorMsg: state.qError,
      rows: state.qrows, total: state.qtotal, page: state.qpage, perPage: state.qperPage,
      sort: state.qsort, columns: queueColumns(),
      emptyText: 'Hàng đợi trống — không có nhiệm vụ mới nào chờ skill.',
      onRetry: function () { fetchQueue(); },
      onSort: function (by, dir) { state.qsort = { by: by, dir: dir }; state.qpage = 1; saveUrl(); fetchQueue(true); },
      onPage: function (p) { state.qpage = p; saveUrl(); fetchQueue(true); }
    });
    RW.qsa('[data-qact]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        queueAction(b.getAttribute('data-qact'), Number(b.getAttribute('data-qid')));
      });
    });
  }

  /* ============================================================
   * Bind đầu trang + bộ lọc
   * ============================================================ */
  function bindHeader() {
    function on(sel, ev, fn) {
      var e = container.querySelector(sel);
      if (e) e.addEventListener(ev, fn);
    }
    on('[data-sk-create]', 'click', openCreate);
    on('[data-sk-import]', 'click', openImport);
    on('[data-sk-export]', 'click', exportBundle);
    on('[data-sk-reload]', 'click', function () { fetchSummary(); fetchList(); fetchQueue(); });

    var search = container.querySelector('[data-sk-search]');
    if (search) {
      var tm = null;
      search.addEventListener('input', function () {
        clearTimeout(tm);
        tm = setTimeout(function () {
          state.filters.q = search.value.trim();
          state.page = 1; saveUrl(); fetchList();
        }, 280);
      });
    }
    RW.qsa('[data-sk-filter]', container).forEach(function (e2) {
      e2.addEventListener('change', function () {
        state.filters[e2.getAttribute('data-sk-filter')] = String(e2.value).trim();
        state.page = 1; saveUrl(); fetchList();
      });
    });
    on('[data-sk-risk]', 'click', function () {
      state.filters.risk_only = state.filters.risk_only ? '' : '1';
      state.page = 1; saveUrl(); fetchList();
    });
    on('[data-sk-clear-filter]', 'click', function () {
      state.filters = { q: '', category: '', status: '', difficulty_min: '', difficulty_max: '', risk_only: '' };
      state.page = 1; saveUrl(); render(); fetchList(true);
    });

    var qsearch = container.querySelector('[data-sk-qsearch]');
    if (qsearch) {
      var tm2 = null;
      qsearch.addEventListener('input', function () {
        clearTimeout(tm2);
        tm2 = setTimeout(function () {
          state.qfilters.q = qsearch.value.trim();
          state.qpage = 1; saveUrl(); fetchQueue();
        }, 280);
      });
    }
    RW.qsa('[data-sk-qfilter]', container).forEach(function (e3) {
      e3.addEventListener('change', function () {
        state.qfilters[e3.getAttribute('data-sk-qfilter')] = String(e3.value).trim();
        state.qpage = 1; saveUrl(); fetchQueue();
      });
    });
    on('[data-sk-qclear]', 'click', function () {
      state.qfilters = { q: '', category: '', status: '', difficulty_min: '', difficulty_max: '' };
      state.qpage = 1; saveUrl(); render(); fetchQueue(true);
    });
  }

  /* ============================================================
   * Hàng đợi: 4 thao tác (mục 3)
   * ============================================================ */
  function queueAction(op, id) {
    if (op === 'test') openQueueTest(id);
    else if (op === 'guide') openQueueGuide(id);
    else if (op === 'ai') openQueueAi(id);
    else if (op === 'skip') queueSkip(id);
  }

  function openQueueTest(id) {
    var item = findQueue(id);
    var panel = RW.slidePanel({
      title: 'Giải thử nhiệm vụ #' + id,
      body: '<div class="rw-warn">Xếp 1 job chạy thử trên account thật (không AI). API yêu cầu account_id.</div>' +
        (item ? '<dl class="rw-kv"><dt>Nhiệm vụ</dt><dd>' + RW.esc(item.title || '\u2014') + '</dd>' +
        '<dt>Fingerprint</dt><dd style="font-family:var(--font-mono)">' + RW.esc(item.fingerprint || '\u2014') + '</dd>' +
        '<dt>URL mẫu</dt><dd>' + safeLink(item.sample_url) + '</dd></dl>' : '') +
        '<div class="rw-field"><label>Account ID <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-qf="account_id" type="number" min="1" placeholder="ví dụ: 12"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-qtest-submit>Xếp job giải thử</button>'
    });
    panel.el.querySelector('[data-qtest-submit]').addEventListener('click', function () {
      var acc = parseInt(fval(panel.el, '[data-qf="account_id"]'), 10);
      if (isNaN(acc) || acc < 1) { RW.toast('Thiếu account_id hợp lệ để giải thử.', 'err'); return; }
      RW.api(SK_API + '?action=queue_unmatched&op=test', {
        method: 'POST', body: { queue_id: id, account_id: acc }
      }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã xếp job giải thử.', 'ok');
        panel.close(); fetchQueue(true);
      });
    });
  }

  function openQueueGuide(id) {
    var item = findQueue(id);
    var panel = RW.slidePanel({
      title: 'Viết hướng dẫn \u2192 tạo skill nháp #' + id,
      body: '<div class="rw-warn">Hệ thống sẽ tạo một skill nháp (0.1.0) từ hướng dẫn này và đánh dấu mục hàng đợi là «Đã có skill».</div>' +
        '<div class="rw-field"><label>Tên skill</label><input class="rw-input" data-qf="name" value="' +
        RW.esc(item && item.title ? item.title : '') + '" placeholder="Để trống sẽ dùng title"></div>' +
        '<div class="rw-field"><label>Nhóm</label><select class="rw-input" data-qf="category">' +
        catOptions(item ? item.category : 'other') + '</select></div>' +
        '<div class="rw-field"><label>Độ khó (0–100)</label><input class="rw-input" data-qf="difficulty" type="number" min="0" max="100" value="' +
        (item ? Number(item.difficulty || 0) : 0) + '"></div>' +
        '<div class="rw-field"><label>Fingerprint</label><input class="rw-input" data-qf="fingerprint" value="' +
        RW.esc(item ? item.fingerprint : '') + '"></div>' +
        '<div class="rw-field"><label>Hướng dẫn giải <span class="rw-req">*</span></label>' +
        '<textarea class="rw-input" data-qf="guide_text" rows="10" placeholder="Mỗi dòng một bước, ví dụ: Mở https://\u2026 / Bấm nút Kiếm điểm / Chọn đáp án A"></textarea>' +
        '<div class="rw-hint">Không được chứa mật khẩu, token, số điện thoại hay đáp án theo ngày (mục 6.6.2).</div></div>' +
        '<div class="rw-field"><label>Rules JSON (tùy chọn)</label>' +
        '<textarea class="rw-input" data-qf="rules_json" rows="4" placeholder="{&quot;steps&quot;:[{&quot;action&quot;:&quot;goto&quot;,...}]}"></textarea></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-qguide-submit>Tạo skill nháp</button>'
    });
    panel.el.querySelector('[data-qguide-submit]').addEventListener('click', function () {
      var guide = fval(panel.el, '[data-qf="guide_text"]');
      if (!guide) { RW.toast('Thiếu hướng dẫn giải (guide_text).', 'err'); return; }
      var rulesRaw = fval(panel.el, '[data-qf="rules_json"]');
      if (rulesRaw) {
        var p = parseRulesText(rulesRaw);
        if (!p.ok) { RW.toast(p.errors[0], 'err'); return; }
        var v = validateRules(p.value);
        if (v.errors.length) { RW.toast(v.errors[0], 'err'); return; }
      }
      var body = { queue_id: id, guide_text: guide };
      var name = fval(panel.el, '[data-qf="name"]');
      if (name) body.name = name;
      var cat = fval(panel.el, '[data-qf="category"]');
      if (cat) body.category = cat;
      var diff = fval(panel.el, '[data-qf="difficulty"]');
      if (diff !== '') body.difficulty = parseInt(diff, 10);
      var fp = fval(panel.el, '[data-qf="fingerprint"]');
      if (fp) body.fingerprint = fp;
      if (rulesRaw) body.rules_json = rulesRaw;
      RW.api(SK_API + '?action=queue_unmatched&op=guide', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        var d = res.data || {};
        RW.toast(d.message || 'Đã tạo skill nháp.', 'ok');
        if (d.warnings && d.warnings.length) RW.toast('Cảnh báo: ' + d.warnings[0], 'info');
        panel.close(); fetchQueue(true); fetchList(true); fetchSummary(true);
      });
    });
  }

  function openQueueAi(id) {
    var panel = RW.slidePanel({
      title: 'Giao AI giải nhiệm vụ #' + id,
      body: '<div class="rw-warn">Tạo job AI giải nhiệm vụ (mục 6.5). Ngân sách token quyết định độ sâu tìm lời giải.</div>' +
        '<div class="rw-field"><label>Ngân sách token (100–200.000)</label>' +
        '<input class="rw-input" data-qf="budget_tokens" type="number" min="100" max="200000" value="4000"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-qai-submit>Giao AI</button>'
    });
    panel.el.querySelector('[data-qai-submit]').addEventListener('click', function () {
      var budget = parseInt(fval(panel.el, '[data-qf="budget_tokens"]'), 10);
      if (isNaN(budget) || budget < 100 || budget > 200000) {
        RW.toast('Ngân sách token phải nằm trong 100–200.000.', 'err'); return;
      }
      RW.api(SK_API + '?action=queue_unmatched&op=ai', {
        method: 'POST', body: { queue_id: id, budget_tokens: budget }
      }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã giao AI giải.', 'ok');
        panel.close(); fetchQueue(true);
      });
    });
  }

  function queueSkip(id) {
    var item = findQueue(id);
    RW.confirmDestructive({
      title: 'Bỏ qua nhiệm vụ trong hàng đợi',
      scope: 'Mục #' + id + (item && item.title ? ' — ' + cut(item.title, 40) : ''),
      dangerText: 'Mục hàng đợi sẽ chuyển sang «Đã bỏ qua» và không còn được xử lý tự động.',
      requireType: '',
      okLabel: 'Bỏ qua'
    }).then(function (ok) {
      if (!ok) return;
      RW.api(SK_API + '?action=queue_unmatched&op=skip', {
        method: 'POST', body: { ids: [id], reason: 'Bỏ qua theo yêu cầu từ giao diện' }
      }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã bỏ qua.', 'ok');
        fetchQueue(true); fetchSummary(true);
      });
    });
  }

  /* ============================================================
   * Xuất / nhập gói skill (có chữ ký)
   * ============================================================ */
  function exportBundle() {
    RW.api(SK_API + '?action=export').then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      var bundle = res.data.bundle;
      var blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'skills_bundle_' + Date.now() + '.json';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () {
        if (a.parentNode) a.parentNode.removeChild(a);
        URL.revokeObjectURL(a.href);
      }, 500);
      RW.toast('Đã xuất gói ' + RW.num(res.data.total || 0) + ' skill (có chữ ký).', 'ok');
    });
  }

  function openImport() {
    var panel = RW.slidePanel({
      title: 'Nhập gói skill',
      body: '<div class="rw-warn">Gói là JSON format=v4k_bundle_v1 (lấy từ «Xuất gói»). Gói sai chữ ký chỉ admin nhập được (cần tick xác nhận).</div>' +
        '<div class="rw-field"><label>Chọn tệp bundle (.json)</label>' +
        '<input class="rw-input" data-im-file type="file" accept=".json,application/json"></div>' +
        '<div class="rw-field"><label>Hoặc dán nội dung bundle</label>' +
        '<textarea class="rw-input" data-im-text rows="8" placeholder="{&quot;format&quot;:&quot;v4k_bundle_v1&quot;, ...}"></textarea></div>' +
        '<div class="rw-field"><label>Chế độ</label><select class="rw-input" data-im-mode>' +
        '<option value="merge">merge \u2014 gộp, giữ skill cũ</option>' +
        '<option value="replace">replace \u2014 thay thế (skill ngoài gói bị Ngừng)</option></select></div>' +
        '<div class="rw-field"><label class="rw-check"><input type="checkbox" class="rw-check" data-im-unsigned> Cho phép gói không có chữ ký hợp lệ (admin)</label></div>' +
        '<div data-im-result></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Đóng</button>' +
        '<button class="rw-btn primary" data-im-submit>Nhập gói</button>'
    });
    var fileInput = panel.el.querySelector('[data-im-file]');
    fileInput.addEventListener('change', function () {
      if (!fileInput.files.length) return;
      var reader = new FileReader();
      reader.onload = function () { panel.el.querySelector('[data-im-text]').value = String(reader.result); };
      reader.readAsText(fileInput.files[0], 'UTF-8');
    });
    panel.el.querySelector('[data-im-submit]').addEventListener('click', function () {
      var raw = fval(panel.el, '[data-im-text]');
      if (!raw) { RW.toast('Chưa có nội dung bundle để nhập.', 'err'); return; }
      var bundle = tryJson(raw);
      if (!bundle) { RW.toast('Bundle không phải JSON hợp lệ.', 'err'); return; }
      var mode = fval(panel.el, '[data-im-mode]') || 'merge';
      var body = { bundle: bundle, mode: mode };
      if (mode === 'replace') body.confirm = true;
      if (fchecked(panel.el, '[data-im-unsigned]')) body.allow_unsigned = true;
      RW.api(SK_API + '?action=import', { method: 'POST', body: body }).then(function (res) {
        var out = panel.el.querySelector('[data-im-result]');
        if (!res.ok) {
          RW.toast(RW.errText(res), 'err');
          if (out) out.innerHTML = '<div class="rw-alert bad">\u26A0 ' + RW.esc(RW.errText(res)) + '</div>';
          return;
        }
        var d = res.data || {};
        RW.toast(d.message || 'Đã nhập gói.', 'ok');
        if (out) {
          out.innerHTML = '<div class="rw-hint">Chữ ký hợp lệ: <b>' + (d.signature_valid ? 'có' : 'không') + '</b><br>' +
            'Thêm ' + RW.num((d.added || []).length) + ' \u00b7 Cập nhật ' + RW.num((d.updated || []).length) +
            ' \u00b7 Không đổi ' + RW.num((d.unchanged || []).length) + ' \u00b7 Lỗi ' + RW.num((d.errors || []).length) + '</div>' +
            msgList((d.errors || []).map(function (e) { return (e.skill_key || '?') + ': ' + e.reason; }), 'rw-log-item');
        }
        fetchList(true); fetchSummary(true);
      });
    });
  }

  /* ============================================================
   * Tạo skill mới (mục 5)
   * ============================================================ */
  function openCreate() {
    var panel = RW.slidePanel({
      title: 'Tạo skill mới (Nháp)',
      body: '<div class="rw-field"><label>skill_key <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-cf="skill_key" placeholder="search_daily_1" autocomplete="off">' +
        '<div class="rw-hint">Chữ thường, số, _ hoặc -, 2–64 ký tự (dùng để nhận diện fingerprint).</div></div>' +
        '<div class="rw-field"><label>Tên skill <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-cf="name" placeholder="Kiếm điểm tìm kiếm hằng ngày"></div>' +
        '<div class="rw-grid2">' +
        '<div class="rw-field"><label>Nhóm</label><select class="rw-input" data-cf="category">' + catOptions('search') + '</select></div>' +
        '<div class="rw-field"><label>Độ khó (0–100)</label><input class="rw-input" data-cf="difficulty" type="number" min="0" max="100" value="30"></div>' +
        '</div>' +
        '<div class="rw-grid2">' +
        '<div class="rw-field"><label>Fingerprint (cách nhau dấu phẩy)</label><input class="rw-input" data-cf="fingerprint" placeholder="url:/rewards, btn#earn"></div>' +
        '<div class="rw-field"><label>semver ban đầu</label><input class="rw-input" data-cf="semver" value="0.1.0" placeholder="0.1.0">' +
        '<div class="rw-hint">Để trống sẽ dùng 0.1.0.</div></div>' +
        '</div>' +
        '<div class="rw-field"><label>Hướng dẫn giải (guide_text)</label>' +
        '<textarea class="rw-input" data-cf="guide_text" rows="5" placeholder="Mỗi dòng một bước\u2026"></textarea></div>' +
        '<div class="rw-field"><label>Rules JSON (tùy chọn)</label>' +
        '<textarea class="rw-input" data-cf="rules_json" rows="5" placeholder="{&quot;steps&quot;:[{&quot;action&quot;:&quot;goto&quot;,&quot;url&quot;:&quot;https://\u2026&quot;}]}"></textarea></div>' +
        '<div class="rw-field"><label>Lý do</label><input class="rw-input" data-cf="reason" value="Tạo skill mới"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-create-submit>Tạo skill</button>'
    });
    panel.el.querySelector('[data-create-submit]').addEventListener('click', function () {
      var key = fval(panel.el, '[data-cf="skill_key"]');
      var name = fval(panel.el, '[data-cf="name"]');
      var cat = fval(panel.el, '[data-cf="category"]');
      var semver = fval(panel.el, '[data-cf="semver"]');
      if (!SKILL_KEY_RE.test(key)) {
        RW.toast('skill_key không hợp lệ (chữ thường, số, _ hoặc -, 2–64 ký tự).', 'err'); return;
      }
      if (!name) { RW.toast('Thiếu tên skill.', 'err'); return; }
      if (!CAT_LABEL[cat]) { RW.toast('Nhóm skill không hợp lệ.', 'err'); return; }
      if (semver && !SEMVER_RE.test(semver)) {
        RW.toast('semver không hợp lệ (cần dạng x.y.z).', 'err'); return;
      }
      var rulesRaw = fval(panel.el, '[data-cf="rules_json"]');
      if (rulesRaw) {
        var p = parseRulesText(rulesRaw);
        if (!p.ok) { RW.toast(p.errors[0], 'err'); return; }
        var v = validateRules(p.value);
        if (v.errors.length) { RW.toast(v.errors[0], 'err'); return; }
      }
      var body = {
        skill_key: key, name: name, category: cat,
        difficulty: parseInt(fval(panel.el, '[data-cf="difficulty"]') || '0', 10) || 0,
        fingerprint: fval(panel.el, '[data-cf="fingerprint"]'),
        guide_text: fval(panel.el, '[data-cf="guide_text"]')
      };
      if (semver) body.semver = semver;
      if (rulesRaw) body.rules_json = rulesRaw;
      var reason = fval(panel.el, '[data-cf="reason"]');
      if (reason) body.reason = reason;
      RW.api(SK_API + '?action=create', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã tạo skill.', 'ok');
        panel.close(); fetchList(); fetchSummary(true);
      });
    });
  }

  /* ============================================================
   * Panel chi tiết skill — 5 tab nội bộ (mục 4)
   * ============================================================ */
  function openSkillDetail(id) {
    RW.api(SK_API + '?action=get&id=' + id).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      showSkillPanel(res.data.data || {});
    });
  }

  function showSkillPanel(d) {
    var isNew = !detailPanel;
    detail = d;
    detailTab = 'rules';
    rulesMode = 'form';
    rulesWorking = normalizeRules(d.rules);
    rawText = JSON.stringify(rulesWorking, null, 2);
    guideText = d.guide_text || '';
    compileResult = null;
    lastTest = null;
    lastGates = null;
    compareResult = null;
    expandedTrace = 0;
    if (!isNew) { renderDetail(); return; }
    detailPanel = RW.slidePanel({
      title: 'Skill #' + d.id + ' \u00b7 ' + (d.name || ''),
      body: '<div data-sk-detail></div>',
      foot: '<button class="rw-btn" data-act="edit-skill">\u270F Sửa thông tin</button>' +
        '<button class="rw-btn" data-act="clone-skill">\u229B Nhân bản</button>' +
        '<button class="rw-btn" data-act="merge-skill">\u2295 Gộp skill trùng</button>' +
        '<button class="rw-btn" data-act="fp-add">＋ Fingerprint</button>' +
        '<button class="rw-btn" data-act="close-panel">Đóng</button>'
    });
    detailPanel.el.addEventListener('click', onDetailClick);
    detailPanel.el.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.hasAttribute) return;
      if (t.hasAttribute('data-raw')) rawText = t.value;
      else if (t.hasAttribute('data-guide')) guideText = t.value;
    });
    renderDetail();
  }

  function reloadDetail() {
    if (!detail) return;
    RW.api(SK_API + '?action=get&id=' + detail.id).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      detail = res.data.data || detail;
      rulesWorking = normalizeRules(detail.rules);
      guideText = detail.guide_text || '';
      renderDetail();
      fetchList(true); fetchSummary(true); fetchQueue(true);
    });
  }

  function renderDetail() {
    if (!detailPanel || !detail) return;
    var box = detailPanel.el.querySelector('[data-sk-detail]');
    if (box) box.innerHTML = detailHtml();
    var t = detailPanel.el.querySelector('.rw-slide-head b');
    if (t) t.textContent = 'Skill #' + detail.id + ' \u00b7 ' + (detail.name || '');
  }

  function detailHtml() {
    var d = detail;
    var tabs = [
      ['rules', '\u2630 Quy tắc'], ['guide', '\u{1F4DD} Hướng dẫn'],
      ['traces', '\u{1F50D} Trace & Mẫu'], ['tests', '\u{1F9EA} Kiểm thử'],
      ['versions', '\u{1F4C6} Lịch sử phiên bản']
    ];
    var head = '<div class="rw-inline" style="flex-wrap:wrap;gap:.35rem;margin-bottom:.55rem">' +
      skillBadge(d.status, d.status_label) +
      badge('running', 'v' + (d.current_version || '?'), 'phiên bản hiện tại') +
      riskBadge(d.risk_label, d.risk_score) +
      badge('muted', d.category_label || CAT_LABEL[d.category] || d.category || '\u2014') +
      difficultyBadge(d.difficulty_label, d.difficulty) +
      (d.has_pending_changes ? badge('warn', '\u23F3 có thay đổi chờ phát hành') : '') +
      '</div>' +
      '<dl class="rw-kv">' +
      '<dt>skill_key</dt><dd style="font-family:var(--font-mono)">' + RW.esc(d.skill_key || '\u2014') + '</dd>' +
      '<dt>Fingerprint</dt><dd style="font-family:var(--font-mono);font-size:.72rem">' +
      RW.esc(((d.fingerprints && d.fingerprints.length) ? d.fingerprints : [d.fingerprint]).filter(Boolean).join(' | ') || '\u2014') + '</dd>' +
      '<dt>Thành công / Thất bại</dt><dd>\u2713 ' + RW.num(d.success) + ' \u00b7 \u2715 ' + RW.num(d.fail) +
      ' \u00b7 fail liên tiếp ' + RW.num(d.fail_streak) + '</dd>' +
      '<dt>Tỉ lệ 7 ngày</dt><dd>' + pct(d.success_rate_7d) + '</dd>' +
      '<dt>Account / thiết bị</dt><dd>' + RW.num(d.account_count) + ' account \u00b7 ' + RW.num(d.device_count) + ' thiết bị nhận version</dd>' +
      '<dt>Lần verify / chạy thật cuối</dt><dd>' + RW.fmtTime(d.last_verified_at) + ' \u00b7 ' + RW.fmtTime(d.last_live_at) + '</dd>' +
      '</dl>' +
      '<div class="rw-subtabs">' + tabs.map(function (t2) {
        return '<button class="rw-chip' + (detailTab === t2[0] ? ' active' : '') + '" data-act="tab" data-tab="' + t2[0] + '">' + t2[1] + '</button>';
      }).join('') + '</div>' +
      '<div data-sk-tabbody>' + tabBodyHtml() + '</div>';
    return head;
  }

  function tabBodyHtml() {
    if (detailTab === 'guide') return guideTabHtml();
    if (detailTab === 'traces') return tracesTabHtml();
    if (detailTab === 'tests') return testsTabHtml();
    if (detailTab === 'versions') return versionsTabHtml();
    return rulesTabHtml();
  }

  /* ---- Tab 1: Quy tắc (biểu mẫu kéo-thả đơn giản + YAML/JSON) ---- */
  function stepHtml(st, i, total) {
    st = st || {};
    var sels = Object.prototype.toString.call(st.selectors) === '[object Array]' ? st.selectors.slice() : [];
    var main = sels.length ? sels[0] : '';
    var alt = sels.slice(1).join(', ');
    function inp(key, val, ph, type) {
      return '<div class="rw-field"><label>' + RW.esc(ph) + '</label>' +
        '<input class="rw-input" data-sf="' + key + '" data-i="' + i + '" type="' + (type || 'text') + '" value="' + RW.esc(val === undefined || val === null ? '' : val) + '"></div>';
    }
    return '<div class="rw-panel-pad" style="border:1px solid var(--color-border);border-radius:8px;margin:.5rem 0" data-step="' + i + '">' +
      '<div class="rw-inline" style="justify-content:space-between;flex-wrap:wrap">' +
      '<b>Bước ' + (i + 1) + ' / ' + total + '</b>' +
      '<div class="rw-inline">' +
      '<button class="rw-btn" data-act="step-up" data-i="' + i + '" title="Chuyển lên"' + (i === 0 ? ' disabled' : '') + '>\u2191</button>' +
      '<button class="rw-btn" data-act="step-down" data-i="' + i + '" title="Chuyển xuống"' + (i === total - 1 ? ' disabled' : '') + '>\u2193</button>' +
      '<button class="rw-btn danger" data-act="step-del" data-i="' + i + '" title="Xóa bước">\u2715</button>' +
      '</div></div>' +
      '<div class="rw-grid2">' +
      '<div class="rw-field"><label>Hành động</label><select class="rw-input" data-sf="action" data-i="' + i + '">' +
      actionOptions(String(st.action || 'click').toLowerCase()) + '</select></div>' +
      inp('selector', main, 'Selector chính (selectors[0])') +
      '</div>' +
      inp('selectors', alt, 'Selector dự phòng (cách nhau dấu phẩy)') +
      '<div class="rw-grid2">' +
      inp('url', st.url, 'URL (dùng cho goto)') +
      inp('value', st.value, 'Giá trị (value: text, đáp án\u2026)') +
      '</div>' +
      '<div class="rw-grid2">' +
      inp('var', st.var, 'Biến gán (var)') +
      inp('count', st.count, 'Số vòng lặp (count, chỉ action=loop)', 'number') +
      '</div>' +
      '<div class="rw-grid2">' +
      inp('delay_min', st.delay_min, 'Độ trễ tối thiểu (giây)', 'number') +
      inp('delay_max', st.delay_max, 'Độ trễ tối đa (giây)', 'number') +
      '</div>' +
      inp('condition', st.condition, 'Điều kiện thực hiện (condition)') +
      inp('note', st.note, 'Ghi chú bước (note)') +
      '</div>';
  }

  function rulesTabHtml() {
    var modeBtns = '<div class="rw-subtabs">' +
      '<button class="rw-chip' + (rulesMode === 'form' ? ' active' : '') + '" data-act="rules-mode" data-mode="form">\u2630 Biểu mẫu</button>' +
      '<button class="rw-chip' + (rulesMode === 'raw' ? ' active' : '') + '" data-act="rules-mode" data-mode="raw">YAML / JSON</button>' +
      '</div>';
    var saveBlock = '<div class="rw-section-title" style="margin-top:.9rem">Lưu quy tắc (tạo version mới)</div>' +
      '<div class="rw-field"><label>Lý do <span class="rw-req">*</span> (mục 6.6.3)</label>' +
      '<input class="rw-input" data-save-reason placeholder="ví dụ: bổ sung selector dự phòng cho nút Kiếm điểm"></div>' +
      '<div class="rw-grid2">' +
      '<div class="rw-field"><label>Changelog</label><input class="rw-input" data-save-changelog placeholder="tóm tắt thay đổi"></div>' +
      '<div class="rw-field"><label>semver mới (tùy chọn)</label><input class="rw-input" data-save-semver placeholder="để trống: tự tăng patch">' +
      '<div class="rw-hint">Phải lớn hơn ' + RW.esc(detail.current_version || '0.0.0') + '.</div></div>' +
      '</div>' +
      '<button class="rw-btn primary" data-act="save-rules">Lưu quy tắc</button>';

    if (rulesMode === 'raw') {
      return modeBtns +
        '<div class="rw-field"><label>Rules (JSON hoặc YAML con)</label>' +
        '<textarea class="rw-input" data-raw rows="16" style="font-family:var(--font-mono);font-size:.75rem">' +
        RW.esc(rawText) + '</textarea>' +
        '<div class="rw-hint">JSON chuẩn: {"steps":[{"action":"goto","url":"https://\u2026"}]}. ' +
        'Hỗ trợ thêm YAML con (danh sách bước dạng "- action: goto").</div></div>' +
        '<div class="rw-inline">' +
        '<button class="rw-btn" data-act="validate-raw">\u2714 Kiểm tra schema</button>' +
        '<button class="rw-btn" data-act="apply-raw">\u2192 Áp dụng vào biểu mẫu</button>' +
        '</div>' +
        (validateMsg ? '<div style="margin-top:.6rem">' +
          msgList(validateMsg.errors, 'rw-log-item' ) .replace('rw-log-item', 'rw-alert bad') +
          msgList(validateMsg.warnings, 'rw-warn') + '</div>' : '') +
        saveBlock;
    }
    var steps = rulesWorking.steps || [];
    var body = steps.map(function (st, i) { return stepHtml(st, i, steps.length); }).join('');
    if (!steps.length) body = '<div class="rw-empty">Chưa có bước nào. Bấm «＋ Thêm bước».</div>';
    return modeBtns +
      '<div class="rw-inline"><button class="rw-btn primary" data-act="step-add">＋ Thêm bước</button></div>' +
      '<div data-steps>' + body + '</div>' +
      '<div class="rw-section-title" style="margin-top:.9rem">Biến &amp; điều kiện xác minh</div>' +
      '<div class="rw-grid2">' +
      '<div class="rw-field"><label>Ô biến (mỗi dòng: key = value)</label>' +
      '<textarea class="rw-input" data-rule-vars rows="4">' + RW.esc(varsToText(rulesWorking.vars)) + '</textarea></div>' +
      '<div>' +
      '<div class="rw-field"><label>Điều kiện xác minh — loại (verify.type)</label>' +
      '<input class="rw-input" data-verify-type value="' + RW.esc((rulesWorking.verify && rulesWorking.verify.type) || '') + '" placeholder="ví dụ: text_contains"></div>' +
      '<div class="rw-field"><label>Kỳ vọng (verify.expect)</label>' +
      '<input class="rw-input" data-verify-expect value="' + RW.esc((rulesWorking.verify && rulesWorking.verify.expect) || '') + '"></div>' +
      '</div></div>' +
      (validateMsg ? '<div>' + msgList(validateMsg.errors, 'rw-alert bad') + msgList(validateMsg.warnings, 'rw-warn') + '</div>' : '') +
      saveBlock;
  }

  function collectRulesFromForm() {
    var root = detailPanel.el;
    var steps = [];
    RW.qsa('[data-step]', root).forEach(function (box) {
      var get = function (k) { return fval(box, '[data-sf="' + k + '"]'); };
      var st = { action: (get('action') || 'click').toLowerCase().trim() };
      var sels = [];
      var main = get('selector');
      if (main) sels.push(main);
      get('selectors').split(',').forEach(function (s) { s = s.trim(); if (s) sels.push(s); });
      if (sels.length) st.selectors = sels;
      if (get('url')) st.url = get('url');
      if (get('value')) st.value = get('value');
      if (get('var')) st.var = get('var');
      if (get('note')) st.note = get('note');
      if (get('condition')) st.condition = get('condition');
      var dmin = get('delay_min'), dmax = get('delay_max');
      if (dmin !== '' || dmax !== '') {
        st.delay_min = dmin !== '' ? Number(dmin) : 0.2;
        st.delay_max = dmax !== '' ? Number(dmax) : st.delay_min;
      }
      var cnt = get('count');
      if (cnt !== '') st.count = parseInt(cnt, 10);
      steps.push(st);
    });
    var rules = { steps: steps };
    var vars = parseVarsText(fval(root, '[data-rule-vars]'));
    if (Object.keys(vars).length) rules.vars = vars;
    var vt = fval(root, '[data-verify-type]');
    var ve = fval(root, '[data-verify-expect]');
    if (vt || ve) rules.verify = { type: vt, expect: ve };
    return rules;
  }

  function showValidate(errors, warnings) {
    validateMsg = { errors: errors || [], warnings: warnings || [] };
    renderDetail();
  }

  function saveRules() {
    var rules;
    if (rulesMode === 'raw') {
      var p = parseRulesText(rawText);
      if (!p.ok) { showValidate(p.errors, []); return; }
      var vr = validateRules(p.value);
      if (vr.errors.length) { showValidate(vr.errors, vr.warnings); return; }
      rules = p.value;
    } else {
      rules = collectRulesFromForm();
      var v2 = validateRules(rules);
      if (v2.errors.length) { showValidate(v2.errors, v2.warnings); RW.toast(v2.errors[0], 'err'); return; }
    }
    var reason = fval(detailPanel.el, '[data-save-reason]');
    if (!reason) {
      RW.toast('Bắt buộc nhập «Lý do» khi sửa quy tắc skill (mục 6.6.3).', 'err'); return;
    }
    var body = { id: detail.id, rules_json: JSON.stringify(rules), reason: reason };
    var ch = fval(detailPanel.el, '[data-save-changelog]');
    if (ch) body.changelog = ch;
    var sv = fval(detailPanel.el, '[data-save-semver]');
    if (sv) {
      if (!SEMVER_RE.test(sv)) { RW.toast('semver không hợp lệ (cần dạng x.y.z).', 'err'); return; }
      body.semver = sv;
    }
    RW.api(SK_API + '?action=update', { method: 'POST', body: body }).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      var d = res.data || {};
      RW.toast(d.message || 'Đã lưu quy tắc.', 'ok');
      if (d.warnings && d.warnings.length) RW.toast('Cảnh báo: ' + d.warnings[0], 'info');
      validateMsg = null;
      rulesWorking = normalizeRules(rules);
      rawText = JSON.stringify(rulesWorking, null, 2);
      reloadDetail();
    });
  }

  /* ---- Tab 2: Hướng dẫn (compile_guide) ---- */
  function guideTabHtml() {
    var out = '<div class="rw-field"><label>Hướng dẫn giải (guide_text)</label>' +
      '<textarea class="rw-input" data-guide rows="12" placeholder="Mỗi dòng một bước, ví dụ: Mở https://\u2026 / Bấm nút Kiếm điểm">' +
      RW.esc(guideText) + '</textarea>' +
      '<div class="rw-hint">Nội dung không được chứa mật khẩu, token, số điện thoại, đáp án theo ngày (mục 6.6.2).</div></div>' +
      '<div class="rw-inline"><button class="rw-btn primary" data-act="compile-guide">\u2699 Biên dịch hướng dẫn thành quy tắc</button></div>';
    if (compileResult) {
      out += '<div class="rw-section-title" style="margin-top:.9rem">Kết quả biên dịch (' +
        RW.esc(compileResult.source === 'ai' ? 'từ AI' : 'xác định') + ')</div>' +
        '<div class="rw-hint">Bước sinh được: <b>' + RW.num(((compileResult.compiled && compileResult.compiled.steps) || []).length) + '</b></div>' +
        diffHtml(compileResult.diff) +
        msgList(compileResult.warnings, 'rw-warn') +
        '<div class="rw-field"><label>Lý do lưu version mới <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-compile-reason placeholder="ví dụ: chấp nhận biên dịch từ hướng dẫn ngày 04/11"></div>' +
        '<button class="rw-btn primary" data-act="save-compiled">Lưu thành version mới</button>';
    }
    return out;
  }

  function compileGuide(save) {
    var body = { id: detail.id, guide_text: guideText };
    if (save) {
      var reason = fval(detailPanel.el, '[data-compile-reason]');
      if (!reason) {
        RW.toast('Bắt buộc nhập «Lý do» khi lưu quy tắc biên dịch (mục 6.6.3).', 'err'); return;
      }
      body.save = true;
      body.reason = reason;
    }
    RW.api(SK_API + '?action=compile_guide', { method: 'POST', body: body }).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      var d = res.data || {};
      compileResult = d;
      RW.toast(d.message || (save ? 'Đã lưu.' : 'Đã biên dịch (chưa lưu).'), 'ok');
      if (save) { reloadDetail(); return; }
      renderDetail();
    });
  }

  /* ---- Tab 3: Trace & Mẫu ---- */
  function tracesTabHtml() {
    var tr = detail.traces || [];
    if (!tr.length) return '<div class="rw-empty">Chưa có trace nào. Trace được ghi khi worker chạy skill.</div>';
    var rows = tr.map(function (t) {
      var open = Number(expandedTrace) === Number(t.id);
      var head = '<tr>' +
        '<td>#' + t.id + (t.is_sample ? ' ' + badge('ok', 'MẪU') : '') + '</td>' +
        '<td>' + (t.result === 'pass' ? badge('ok', 'pass') : badge('error', 'fail')) + '</td>' +
        '<td>' + RW.num(t.steps_count) + '</td>' +
        '<td>' + RW.fmtTime(t.created_at) + '</td>' +
        '<td>' +
        '<button class="rw-btn" data-act="trace-toggle" data-trace="' + t.id + '">' + (open ? 'Ẩn bước' : 'Xem bước') + '</button> ' +
        '<button class="rw-btn" data-act="trace-sample" data-trace="' + t.id + '" data-sample="' + (t.is_sample ? '0' : '1') + '">' +
        (t.is_sample ? 'Bỏ cờ mẫu' : 'Đặt làm mẫu chuẩn') + '</button>' +
        '</td></tr>';
      if (!open) return head;
      var steps = (t.steps || []).map(function (s, i) {
        return '<div class="rw-log-item">' + (i + 1) + '. ' + RW.esc(stepBrief(s)) + '</div>';
      }).join('');
      return head + '<tr><td colspan="5"><div class="rw-log" style="max-height:14rem;overflow:auto">' +
        (steps || '<div class="rw-muted">(không có chi tiết bước)</div>') + '</div></td></tr>';
    }).join('');
    return '<div class="rw-warn">Trace mẫu (is_sample=1) là dữ liệu gốc để chạy hồi quy (mục 6.6.1).</div>' +
      '<div class="rw-panel" style="margin-top:.6rem"><table class="rw-table"><thead><tr>' +
      '<th>Trace</th><th>Kết quả</th><th>Số bước</th><th>Thời gian</th><th>Thao tác</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  function traceSample(traceId, flag) {
    RW.api(SK_API + '?action=trace_get&op=sample', {
      method: 'POST', body: { trace_id: traceId, is_sample: flag }
    }).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      RW.toast((res.data && res.data.message) || 'Đã cập nhật trace mẫu.', 'ok');
      reloadDetail();
    });
  }

  /* ---- Tab 4: Kiểm thử + vòng đời (mục 6.6) ---- */
  function testsTabHtml() {
    var t = detail.tests || {};
    var live = t.live || {};
    var out = '<dl class="rw-kv">' +
      '<dt>Hồi quy đã chạy</dt><dd>' + RW.num(t.regression_total) + '</dd>' +
      '<dt>Chạy thật</dt><dd>\u2713 ' + RW.num(live.success) + ' \u00b7 \u2715 ' + RW.num(live.fail) + ' \u00b7 chờ ' + RW.num(live.pending) + '</dd>' +
      '<dt>Tỉ lệ 7 ngày</dt><dd>' + pct(live.rate_7d) + ' \u00b7 fail liên tiếp ' + RW.num(live.streak_fail) + '</dd>' +
      '<dt>Account đã chạy</dt><dd>' + RW.num(live.account_count) + '</dd>' +
      '<dt>Chạy thật cuối</dt><dd>' + RW.fmtTime(live.last_live_at) + '</dd>' +
      '</dl>';

    out += '<div class="rw-section-title">Kiểm thử</div>' +
      '<div class="rw-inline" style="flex-wrap:wrap">' +
      '<label class="rw-check"><input type="checkbox" class="rw-check" data-reg-all> Dùng toàn bộ trace (không chỉ mẫu)</label>' +
      '<button class="rw-btn primary" data-act="run-regression">\u21BB Chạy hồi quy</button>' +
      '</div>' +
      '<div class="rw-grid2" style="margin-top:.55rem">' +
      '<div class="rw-field"><label>Account IDs (cách nhau dấu phẩy, tối đa 5)</label>' +
      '<input class="rw-input" data-live-accounts placeholder="12, 15"></div>' +
      '<div class="rw-field"><label>Device ID (tùy chọn)</label>' +
      '<input class="rw-input" data-live-device type="number" min="1" placeholder="để trống: job chờ device nhận"></div>' +
      '</div>' +
      '<button class="rw-btn primary" data-act="run-live">\u25B6 Chạy thử thật</button>';

    if (lastTest) {
      if (lastTest.kind === 'regression') {
        out += '<div class="rw-section-title" style="margin-top:.8rem">Kết quả hồi quy (' + RW.esc(lastTest.semver || '') + ')</div>' +
          (lastTest.result === 'pass' ? badge('ok', 'PASS') : badge('error', 'FAIL')) +
          ' <span class="rw-muted">' + RW.num(lastTest.traces_tested) + ' trace</span>' +
          '<table class="rw-table" style="margin-top:.5rem"><thead><tr><th>Trace</th><th>Bước khớp</th><th>Tỉ lệ</th><th>Kết quả</th></tr></thead><tbody>' +
          (lastTest.details || []).map(function (x) {
            return '<tr><td>#' + x.trace_id + '</td><td>' + RW.num(x.steps_matched) + '/' + RW.num(x.steps_total) + '</td>' +
              '<td>' + pct(x.match_ratio) + '</td><td>' + (x.pass ? badge('ok', 'pass') : badge('error', 'fail')) + '</td></tr>';
          }).join('') + '</tbody></table>';
      } else {
        out += '<div class="rw-section-title" style="margin-top:.8rem">Kết quả xếp job chạy thật</div>' +
          msgList([lastTest.message || ''], 'rw-hint') +
          '<table class="rw-table"><thead><tr><th>Job</th><th>Account</th><th>Device</th></tr></thead><tbody>' +
          (lastTest.jobs || []).map(function (j) {
            return '<tr><td style="font-family:var(--font-mono)">' + RW.esc(j.job_key) + '</td><td>#' + j.account_id + '</td><td>' +
              (j.device_id ? '#' + j.device_id : '<span class="rw-muted">chờ nhận</span>') + '</td></tr>';
          }).join('') + '</tbody></table>';
      }
    }

    out += '<div class="rw-section-title" style="margin-top:.9rem">Vòng đời phát hành (mục 6.6)</div>' +
      '<div class="rw-inline" style="flex-wrap:wrap">' +
      '<button class="rw-btn primary" data-act="verify">\u2714 Verify</button>' +
      '<button class="rw-btn" data-act="publish">\u2197 Phát hành</button>' +
      '<button class="rw-btn" data-act="rollback">\u21A9 Rollback</button>' +
      '<button class="rw-btn danger" data-act="deprecate">\u26D4 Ngừng skill</button>' +
      '</div>';

    if (lastGates) {
      out += '<div class="rw-panel-pad" style="margin-top:.6rem;border:1px solid ' +
        (lastGates.ok ? 'var(--rw-ok)' : 'var(--rw-err)') + ';border-radius:8px">' +
        '<b>Điều kiện phát hành (gates) — ' + RW.esc(lastGates.semver || '') + '</b>' +
        '<div class="rw-hint">Hồi quy PASS: ' + RW.num(lastGates.regression_pass) +
        ' \u00b7 chạy thật PASS: ' + RW.num(lastGates.live_passes) +
        ' \u00b7 account khác nhau: ' + RW.num(lastGates.live_accounts) + '</div>' +
        msgList(lastGates.missing, 'rw-alert bad') + '</div>';
    }
    return out;
  }

  /* ---- Tab 5: Lịch sử phiên bản + diff ---- */
  function versionsTabHtml() {
    var vs = detail.versions || [];
    if (!vs.length) return '<div class="rw-empty">Chưa có phiên bản nào.</div>';
    var opts = vs.map(function (v) { return '<option value="' + RW.esc(v.semver) + '">' + RW.esc(v.semver) + '</option>'; }).join('');
    var rows = vs.map(function (v) {
      return '<tr><td style="font-family:var(--font-mono)"><b>' + RW.esc(v.semver) + '</b></td>' +
        '<td>' + versionBadge(v.status, v.status_label) + '</td>' +
        '<td>' + RW.esc(v.changelog || '\u2014') + '</td>' +
        '<td>' + RW.esc(v.reason || '\u2014') + '</td>' +
        '<td>' + RW.esc(v.created_by || '\u2014') + '</td>' +
        '<td>' + RW.fmtTime(v.created_at) + '</td>' +
        '<td>' + RW.fmtTime(v.verified_at) + '</td>' +
        '<td>' + RW.fmtTime(v.published_at) + '</td></tr>';
    }).join('');
    var out = '<div class="rw-panel"><table class="rw-table"><thead><tr>' +
      '<th>semver</th><th>Trạng thái</th><th>Changelog</th><th>Lý do</th><th>Người tạo</th>' +
      '<th>Tạo lúc</th><th>Verify</th><th>Phát hành</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="rw-section-title" style="margin-top:.9rem">So sánh 2 phiên bản (rules_json)</div>' +
      '<div class="rw-inline" style="flex-wrap:wrap">' +
      '<select class="rw-input" data-vcmp-a>' + opts + '</select>' +
      '<span class="rw-muted">\u2194</span>' +
      '<select class="rw-input" data-vcmp-b>' + opts + '</select>' +
      '<button class="rw-btn primary" data-act="compare-versions">So sánh</button>' +
      '</div>' +
      '<div class="rw-hint">API không trả rules_json của version cũ trong action=get — diff lấy qua gói export (có chữ ký).</div>';
    if (compareResult) {
      out += '<div class="rw-panel-pad" style="margin-top:.55rem;border:1px solid var(--color-border);border-radius:8px">' +
        '<b>' + RW.esc(compareResult.a) + ' \u2192 ' + RW.esc(compareResult.b) + '</b>' +
        diffHtml(compareResult.diff) + '</div>';
    }
    return out;
  }

  function compareVersions() {
    var a = fval(detailPanel.el, '[data-vcmp-a]');
    var b = fval(detailPanel.el, '[data-vcmp-b]');
    if (!a || !b || a === b) { RW.toast('Chọn 2 phiên bản khác nhau để so sánh.', 'err'); return; }
    RW.api(SK_API + '?action=export&id=' + detail.id).then(function (res) {
      if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
      var bundle = (res.data && res.data.bundle) || {};
      var skills = bundle.skills || [];
      var s = skills[0];
      if (!s) { RW.toast('Không lấy được rules của các version.', 'err'); return; }
      var va = null, vb = null;
      (s.versions || []).forEach(function (v) {
        if (v.semver === a) va = v;
        if (v.semver === b) vb = v;
      });
      if (!va || !vb) { RW.toast('Không tìm thấy version đã chọn trong gói export.', 'err'); return; }
      compareResult = { a: a, b: b, diff: diffRules(tryJson(va.rules_json), tryJson(vb.rules_json)) };
      renderDetail();
    });
  }

  /* ============================================================
   * Sửa / nhân bản / gộp / fingerprint (mục 5)
   * ============================================================ */
  function openEdit() {
    var d = detail;
    var panel = RW.slidePanel({
      title: 'Sửa thông tin skill #' + d.id,
      body: '<div class="rw-inline"><span class="rw-badge running">Phiên bản hiện tại: v' +
        RW.esc(d.current_version || '?') + '</span>' + skillBadge(d.status, d.status_label) + '</div>' +
        '<div class="rw-field"><label>Tên skill <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-ef="name" value="' + RW.esc(d.name || '') + '"></div>' +
        '<div class="rw-grid2">' +
        '<div class="rw-field"><label>Nhóm</label><select class="rw-input" data-ef="category">' +
        catOptions(d.category) + '</select></div>' +
        '<div class="rw-field"><label>Độ khó (0–100)</label><input class="rw-input" data-ef="difficulty" type="number" min="0" max="100" value="' +
        Number(d.difficulty || 0) + '"></div></div>' +
        '<div class="rw-field"><label>Fingerprint (cách nhau dấu phẩy)</label>' +
        '<input class="rw-input" data-ef="fingerprint" value="' +
        RW.esc(((d.fingerprints && d.fingerprints.length) ? d.fingerprints : [d.fingerprint]).filter(Boolean).join(',')) + '"></div>' +
        '<div class="rw-grid2">' +
        '<div class="rw-field"><label>Changelog</label><input class="rw-input" data-ef="changelog"></div>' +
        '<div class="rw-field"><label>semver mới (chỉ khi đổi rules/hướng dẫn)</label><input class="rw-input" data-ef="semver" placeholder="x.y.z"></div></div>' +
        '<div class="rw-field"><label>Lý do <span class="rw-req">*</span> (mục 6.6.3)</label>' +
        '<input class="rw-input" data-ef="reason" placeholder="ví dụ: sửa tên skill cho đúng nghiệp vụ"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-ef-submit>Lưu thay đổi</button>'
    });
    panel.el.querySelector('[data-ef-submit]').addEventListener('click', function () {
      var name = fval(panel.el, '[data-ef="name"]');
      var cat = fval(panel.el, '[data-ef="category"]');
      var semver = fval(panel.el, '[data-ef="semver"]');
      var reason = fval(panel.el, '[data-ef="reason"]');
      if (!name) { RW.toast('Thiếu tên skill.', 'err'); return; }
      if (!CAT_LABEL[cat]) { RW.toast('Nhóm skill không hợp lệ.', 'err'); return; }
      if (semver && !SEMVER_RE.test(semver)) { RW.toast('semver không hợp lệ (cần dạng x.y.z).', 'err'); return; }
      if (!reason) { RW.toast('Bắt buộc nhập «Lý do» khi sửa skill (mục 6.6.3).', 'err'); return; }
      var body = {
        id: detail.id, name: name, category: cat,
        difficulty: parseInt(fval(panel.el, '[data-ef="difficulty"]') || '0', 10) || 0,
        fingerprint: fval(panel.el, '[data-ef="fingerprint"]'),
        reason: reason
      };
      var ch = fval(panel.el, '[data-ef="changelog"]');
      if (ch) body.changelog = ch;
      if (semver) body.semver = semver;
      RW.api(SK_API + '?action=update', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã cập nhật.', 'ok');
        panel.close(); reloadDetail();
      });
    });
  }

  function openClone() {
    var d = detail;
    var panel = RW.slidePanel({
      title: 'Nhân bản skill #' + d.id,
      body: '<div class="rw-warn">Bản sao luôn ở trạng thái Nháp (0.1.0), kế thừa rules + hướng dẫn hiện tại.</div>' +
        '<div class="rw-field"><label>skill_key mới</label><input class="rw-input" data-cl-key placeholder="để trống: tự sinh"></div>' +
        '<div class="rw-field"><label>Tên bản sao</label><input class="rw-input" data-cl-name value="' + RW.esc((d.name || '') + ' (bản sao)') + '"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-cl-submit>Nhân bản</button>'
    });
    panel.el.querySelector('[data-cl-submit]').addEventListener('click', function () {
      var key = fval(panel.el, '[data-cl-key]');
      if (key && !SKILL_KEY_RE.test(key)) {
        RW.toast('skill_key mới không hợp lệ (chữ thường, số, _ hoặc -, 2–64 ký tự).', 'err'); return;
      }
      var body = { id: detail.id };
      if (key) body.new_key = key;
      var name = fval(panel.el, '[data-cl-name]');
      if (name) body.name = name;
      RW.api(SK_API + '?action=clone', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã nhân bản.', 'ok');
        panel.close(); fetchList();
      });
    });
  }

  function openMerge() {
    var panel = RW.slidePanel({
      title: 'Gộp skill trùng vào #' + detail.id,
      body: '<div class="rw-warn">Các skill nguồn sẽ chuyển sang «Ngừng», trace/test/fingerprint được chuyển vào skill này (mục 6.5).</div>' +
        '<div class="rw-field"><label>ID skill nguồn (cách nhau dấu phẩy) <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-mg-sources placeholder="12, 15"></div>' +
        '<div class="rw-field"><label>Lý do <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-mg-reason placeholder="ví dụ: 2 skill trùng fingerprint"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn danger" data-mg-submit>Gộp</button>'
    });
    panel.el.querySelector('[data-mg-submit]').addEventListener('click', function () {
      var ids = fval(panel.el, '[data-mg-sources]').split(',').map(function (x) {
        return parseInt(String(x).trim(), 10);
      }).filter(function (x) { return !isNaN(x) && x > 0; });
      if (!ids.length) { RW.toast('Thiếu danh sách id skill nguồn.', 'err'); return; }
      if (ids.indexOf(Number(detail.id)) >= 0) { RW.toast('source_ids không được chứa target_id.', 'err'); return; }
      var reason = fval(panel.el, '[data-mg-reason]');
      if (!reason) { RW.toast('Bắt buộc nhập «Lý do» khi gộp skill.', 'err'); return; }
      RW.api(SK_API + '?action=merge', {
        method: 'POST', body: { target_id: detail.id, source_ids: ids, reason: reason }
      }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã gộp skill.', 'ok');
        panel.close(); reloadDetail();
      });
    });
  }

  function openFpAdd() {
    var panel = RW.slidePanel({
      title: 'Gắn thêm fingerprint cho #' + detail.id,
      body: '<div class="rw-field"><label>Fingerprint <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-fp-value placeholder="url:/rewards"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-fp-submit>Gắn</button>'
    });
    panel.el.querySelector('[data-fp-submit]').addEventListener('click', function () {
      var fp = fval(panel.el, '[data-fp-value]');
      if (!fp) { RW.toast('Thiếu fingerprint để gắn.', 'err'); return; }
      RW.api(SK_API + '?action=fingerprint_add', {
        method: 'POST', body: { id: detail.id, fingerprint: fp }
      }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã gắn fingerprint.', 'ok');
        panel.close(); reloadDetail();
      });
    });
  }

  /* ---- Vòng đời: publish / rollback / deprecate ---- */
  function openPublish() {
    var d = detail;
    var panel = RW.slidePanel({
      title: 'Phát hành skill #' + d.id,
      body: '<div class="rw-warn">Canary phát cho 1 thiết bị trước, stable phát cho tất cả (mục 6.6.5). Phiên bản phải qua Verify và đủ gates.</div>' +
        '<div class="rw-grid2">' +
        '<div class="rw-field"><label>Phiên bản (semver)</label><select class="rw-input" data-pb-semver>' +
        (d.versions || []).map(function (v) {
          return '<option value="' + RW.esc(v.semver) + '">' + RW.esc(v.semver) + ' \u00b7 ' + RW.esc(v.status_label || v.status) + '</option>';
        }).join('') + '</select></div>' +
        '<div class="rw-field"><label>Kênh</label><select class="rw-input" data-pb-channel>' +
        '<option value="canary">canary \u2014 1 thiết bị</option>' +
        '<option value="stable">stable \u2014 tất cả thiết bị</option></select></div></div>' +
        '<div class="rw-field"><label>Device ID (chỉ kênh canary, để trống: tự chọn)</label>' +
        '<input class="rw-input" data-pb-device type="number" min="1"></div>' +
        '<div class="rw-field"><label>Lý do (khuyến nghị)</label><input class="rw-input" data-pb-reason></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn primary" data-pb-submit>Phát hành</button>'
    });
    panel.el.querySelector('[data-pb-submit]').addEventListener('click', function () {
      var body = {
        id: detail.id,
        semver: fval(panel.el, '[data-pb-semver]'),
        channel: fval(panel.el, '[data-pb-channel]') || 'stable'
      };
      var dev = fval(panel.el, '[data-pb-device]');
      if (dev) body.device_id = parseInt(dev, 10);
      var reason = fval(panel.el, '[data-pb-reason]');
      if (reason) body.reason = reason;
      RW.api(SK_API + '?action=publish', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) {
          if (res.error && res.error.gates) lastGates = res.error.gates;
          RW.toast(RW.errText(res), 'err');
          panel.close(); renderDetail();
          return;
        }
        var d2 = res.data || {};
        RW.toast(d2.message || 'Đã phát hành.', 'ok');
        panel.close(); reloadDetail();
      });
    });
  }

  function openRollback() {
    var d = detail;
    var panel = RW.slidePanel({
      title: 'Rollback skill #' + d.id,
      body: '<div class="rw-danger">Rollback đưa mọi thiết bị về phiên bản mục tiêu qua config_pull. Hành động cần lý do.</div>' +
        '<div class="rw-field"><label>Phiên bản mục tiêu (để trống: tự lấy bản đã phát hành gần nhất)</label>' +
        '<select class="rw-input" data-rb-target><option value="">\u2014 tự chọn \u2014</option>' +
        (d.versions || []).map(function (v) {
          return '<option value="' + RW.esc(v.semver) + '">' + RW.esc(v.semver) + ' \u00b7 ' + RW.esc(v.status_label || v.status) + '</option>';
        }).join('') + '</select></div>' +
        '<div class="rw-field"><label>Lý do <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-rb-reason placeholder="ví dụ: version mới fail liên tục trên canary"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn danger" data-rb-submit>Rollback</button>'
    });
    panel.el.querySelector('[data-rb-submit]').addEventListener('click', function () {
      var reason = fval(panel.el, '[data-rb-reason]');
      if (!reason) { RW.toast('Bắt buộc nhập «Lý do» khi rollback skill.', 'err'); return; }
      var body = { id: detail.id, reason: reason };
      var target = fval(panel.el, '[data-rb-target]');
      if (target) body.target_semver = target;
      RW.api(SK_API + '?action=rollback', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        RW.toast((res.data && res.data.message) || 'Đã rollback.', 'ok');
        panel.close(); reloadDetail();
      });
    });
  }

  function openDeprecate() {
    var d = detail;
    var panel = RW.slidePanel({
      title: 'Ngừng skill #' + d.id,
      body: '<div class="rw-danger">Sau khi ngừng, worker sẽ không tự chạy skill này nữa. Các rollout đang hoạt động bị dừng.</div>' +
        '<div class="rw-field"><label>Lý do <span class="rw-req">*</span></label>' +
        '<input class="rw-input" data-dp-reason placeholder="ví dụ: nhiệm vụ không còn trên web"></div>',
      foot: '<button class="rw-btn" data-rw-slide-close>Hủy</button>' +
        '<button class="rw-btn danger" data-dp-submit>Tiếp tục</button>'
    });
    panel.el.querySelector('[data-dp-submit]').addEventListener('click', function () {
      var reason = fval(panel.el, '[data-dp-reason]');
      if (!reason) { RW.toast('Bắt buộc nhập «Lý do» khi ngừng skill.', 'err'); return; }
      RW.confirmDestructive({
        title: 'Ngừng skill (deprecated)',
        scope: 'Skill #' + d.id + ' — ' + cut(d.name || '', 40),
        dangerText: 'Skill sẽ bị đánh dấu «Ngừng», mọi rollout đang hoạt động bị dừng. Có thể tạo lại skill mới nếu cần.',
        requireType: 'NGUNG',
        okLabel: 'Ngừng skill'
      }).then(function (ok) {
        if (!ok) return;
        RW.api(SK_API + '?action=deprecate', {
          method: 'POST', body: { id: detail.id, confirm: true, reason: reason }
        }).then(function (res) {
          if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
          RW.toast((res.data && res.data.message) || 'Đã ngừng skill.', 'ok');
          panel.close(); reloadDetail();
        });
      });
    });
  }

  /* ============================================================
   * Điều khiển panel chi tiết (delegated click)
   * ============================================================ */
  function onDetailClick(e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-act]') : null;
    if (!b || !detailPanel.el.contains(b)) return;
    var act = b.getAttribute('data-act');
    var i = Number(b.getAttribute('data-i'));

    if (act === 'close-panel') { detailPanel.close(); detailPanel = null; detail = null; return; }
    if (act === 'tab') { detailTab = b.getAttribute('data-tab'); renderDetail(); return; }
    if (act === 'rules-mode') {
      var mode = b.getAttribute('data-mode');
      if (mode === rulesMode) return;
      if (mode === 'raw') {
        rulesWorking = collectRulesFromForm();
        rawText = JSON.stringify(rulesWorking, null, 2);
        rulesMode = 'raw';
      } else {
        var p = parseRulesText(rawText);
        if (!p.ok) { RW.toast(p.errors[0], 'err'); return; }
        rulesWorking = normalizeRules(p.value);
        rulesMode = 'form';
      }
      validateMsg = null;
      renderDetail();
      return;
    }
    if (act === 'step-add') {
      rulesWorking = collectRulesFromForm();
      rulesWorking.steps.push({ action: 'click', delay_min: 0.3, delay_max: 2.5 });
      renderDetail();
      return;
    }
    if (act === 'step-up' || act === 'step-down' || act === 'step-del') {
      rulesWorking = collectRulesFromForm();
      var steps = rulesWorking.steps;
      if (act === 'step-del') steps.splice(i, 1);
      else {
        var j = act === 'step-up' ? i - 1 : i + 1;
        if (j >= 0 && j < steps.length) {
          var tmp = steps[j];
          steps[j] = steps[i];
          steps[i] = tmp;
        }
      }
      renderDetail();
      return;
    }
    if (act === 'validate-raw') {
      var pr = parseRulesText(rawText);
      if (!pr.ok) { showValidate(pr.errors, []); return; }
      var vr = validateRules(pr.value);
      if (!vr.errors.length && !vr.warnings.length) {
        validateMsg = null;
        RW.toast('Schema hợp lệ (' + pr.source + '): ' + pr.value.steps.length + ' bước.', 'ok');
        renderDetail();
        return;
      }
      showValidate(vr.errors, vr.warnings);
      return;
    }
    if (act === 'apply-raw') {
      var pa = parseRulesText(rawText);
      if (!pa.ok) { showValidate(pa.errors, []); return; }
      var va = validateRules(pa.value);
      if (va.errors.length) { showValidate(va.errors, va.warnings); return; }
      rulesWorking = normalizeRules(pa.value);
      rulesMode = 'form';
      validateMsg = null;
      renderDetail();
      return;
    }
    if (act === 'save-rules') { saveRules(); return; }
    if (act === 'compile-guide') { compileGuide(false); return; }
    if (act === 'save-compiled') { compileGuide(true); return; }
    if (act === 'trace-toggle') {
      var tid = Number(b.getAttribute('data-trace'));
      expandedTrace = Number(expandedTrace) === tid ? 0 : tid;
      renderDetail();
      return;
    }
    if (act === 'trace-sample') {
      traceSample(Number(b.getAttribute('data-trace')), b.getAttribute('data-sample') === '1');
      return;
    }
    if (act === 'run-regression') {
      var body = { id: detail.id };
      if (fchecked(detailPanel.el, '[data-reg-all]')) body.use_all_traces = true;
      RW.api(SK_API + '?action=test_regression', { method: 'POST', body: body }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        var d = res.data || {};
        lastTest = { kind: 'regression', result: d.result, semver: d.semver, traces_tested: d.traces_tested, details: d.details, message: d.message };
        RW.toast(d.message || 'Đã chạy hồi quy.', d.result === 'pass' ? 'ok' : 'err');
        renderDetail();
      });
      return;
    }
    if (act === 'run-live') {
      var accRaw = fval(detailPanel.el, '[data-live-accounts]');
      var ids = accRaw.split(',').map(function (x) { return parseInt(String(x).trim(), 10); }).filter(function (x) { return !isNaN(x); });
      if (!ids.length) { RW.toast('Nhập ít nhất 1 account_id để chạy thử thật.', 'err'); return; }
      if (ids.length > 5) { RW.toast('Chạy thử tối đa 5 account mỗi lần.', 'err'); return; }
      var lb = { id: detail.id, account_ids: ids };
      var devRaw = fval(detailPanel.el, '[data-live-device]');
      if (devRaw) lb.device_id = parseInt(devRaw, 10);
      RW.api(SK_API + '?action=test_live', { method: 'POST', body: lb }).then(function (res) {
        if (!res.ok) { RW.toast(RW.errText(res), 'err'); return; }
        var d2 = res.data || {};
        lastTest = { kind: 'live', message: d2.message, jobs: d2.jobs || [] };
        RW.toast(d2.message || 'Đã xếp job chạy thử thật.', 'ok');
        renderDetail();
      });
      return;
    }
    if (act === 'verify') {
      RW.api(SK_API + '?action=verify', { method: 'POST', body: { id: detail.id } }).then(function (res) {
        if (!res.ok) {
          lastGates = (res.error && res.error.gates) ? res.error.gates : null;
          RW.toast(RW.errText(res), 'err');
          detailTab = 'tests';
          renderDetail();
          return;
        }
        lastGates = res.data.gates || null;
        RW.toast((res.data && res.data.message) || 'Đã verify.', 'ok');
        detailTab = 'tests';
        reloadDetail();
      });
      return;
    }
    if (act === 'publish') { openPublish(); return; }
    if (act === 'rollback') { openRollback(); return; }
    if (act === 'deprecate') { openDeprecate(); return; }
    if (act === 'edit-skill') { openEdit(); return; }
    if (act === 'clone-skill') { openClone(); return; }
    if (act === 'merge-skill') { openMerge(); return; }
    if (act === 'fp-add') { openFpAdd(); return; }
    if (act === 'compare-versions') { compareVersions(); return; }
  }

  /* ============================================================
   * Khởi tạo
   * ============================================================ */
  window.RW_TAB_SKILLS = {
    init: function (el) {
      container = el;
      loadUrl();
      container.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('[data-act="open-skill"]') : null;
        if (a && container.contains(a)) {
          e.preventDefault();
          openSkillDetail(Number(a.getAttribute('data-id')));
          return;
        }
        var r = e.target && e.target.closest ? e.target.closest('[data-sk-sum-retry]') : null;
        if (r) fetchSummary();
      });
      render();
      fetchSummary(true);
      fetchList(true);
      fetchQueue(true);
      if (!poller && RW.startPolling) {
        poller = RW.startPolling(function () {
          fetchSummary(true);
          fetchList(true);
          fetchQueue(true);
        });
      }
    },
    refresh: function () {
      fetchSummary(true);
      fetchList(true);
      fetchQueue(true);
    }
  };
})();
