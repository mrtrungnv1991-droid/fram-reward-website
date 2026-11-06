function rwD5(r){var e=r.data;if(e&&e.data!==undefined){return e.data;}return e||{};}
window.RW_TAB_AI = (function () {
  'use strict';
  var el = null, items = [];
  function render() {
    if (!el) return;
    var h = '<div class="rw-panel"><div class="rw-panel-pad">';
    h += '<h3>🤖 AI hỗ trợ — Hàng chờ</h3>';
    h += '<div class="rw-inline"><button type="button" class="rw-btn primary" id="aiNew">＋ Tạo yêu cầu</button><button type="button" class="rw-btn" id="aiRefresh">↻ Làm mới</button></div>';
    h += '<div id="aiForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>Loại</label><select class="rw-input" id="aiKind"><option value="suggest">Gợi ý</option><option value="analyze">Phân tích</option><option value="distill">Rút gọn</option><option value="review">Kiểm duyệt</option></select></div>';
    h += '<div class="rw-field"><label>Lý do (bắt buộc)</label><input class="rw-input" id="aiReason" placeholder="Mô tả yêu cầu..."></div>';
    h += '<div class="rw-field"><label>Kết quả mong muốn</label><input class="rw-input" id="aiExpected"></div>';
    h += '<div class="rw-inline"><button type="button" class="rw-btn primary" id="aiSubmit">Gửi</button><button type="button" class="rw-btn" id="aiCancel">Hủy</button></div></div>';
    h += '<div id="aiBudget" class="rw-muted" style="margin:.5rem 0"></div>';
    h += '<table class="rw-table"><thead><tr><th>ID</th><th>Loại</th><th>Lý do</th><th>Trạng thái</th><th>Model</th><th>Token</th><th>Chi phí</th><th>Lỗi</th><th>Thao tác</th></tr></thead><tbody id="aiBody"></tbody></table>';
    h += '</div></div>';
    el.innerHTML = h;
    document.getElementById('aiNew').onclick = function(){ document.getElementById('aiForm').style.display=''; };
    document.getElementById('aiCancel').onclick = function(){ document.getElementById('aiForm').style.display='none'; };
    document.getElementById('aiRefresh').onclick = refresh;
    document.getElementById('aiSubmit').onclick = function(){
      var body = 'action=create&kind='+encodeURIComponent(document.getElementById('aiKind').value)+'&reason='+encodeURIComponent(document.getElementById('aiReason').value)+'&expected_result='+encodeURIComponent(document.getElementById('aiExpected').value);
      RW.api('api_v5_ai.php?action=create', {method:'POST', body: body, headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){
        if(r.ok){ RW.toast('Đã tạo yêu cầu AI.','ok'); document.getElementById('aiForm').style.display='none'; refresh(); } else { RW.toast('Lỗi: '+r.error,'bad'); }
      });
    };
    refresh();
  }
  function refresh() {
    if (!el) return;
    RW.api('api_v5_ai.php?action=list').then(function(r){
      if (!r.ok) return;
      items = rwD5(r).items || [];
      var body = document.getElementById('aiBody');
      if (!body) return;
      body.innerHTML = items.map(function(x){
        var st = x.status==='proposed'?'✅':x.status==='error'?'❌':x.status==='pending'?'⏳':x.status==='processing'?'🔄':x.status==='canceled'?'🚫':'⚠';
        return '<tr><td>'+RW.esc(x.id.slice(-8))+'</td><td>'+RW.esc(x.kind)+'</td><td>'+RW.esc((x.reason||'').slice(0,40))+'</td><td>'+st+' '+RW.esc(x.status)+'</td><td>'+RW.esc(x.model||'—')+'</td><td>'+(x.tokens_in||0)+'/'+(x.tokens_out||0)+'</td><td>'+(x.budget_actual||0)+'</td><td>'+RW.esc(x.error_class||'—')+'</td><td><button class="rw-btn" onclick="RW_TAB_AI.process(\''+x.id+'\')">Xử lý</button> <button class="rw-btn" onclick="RW_TAB_AI.cancel(\''+x.id+'\')">Hủy</button></td></tr>';
      }).join('');
    });
    RW.api('api_v5_ai.php?action=budget').then(function(r){
      if(r.ok && rwD5(r).items && rwD5(r).items[0]){
        var b=rwD5(r).items[0]; var el2=document.getElementById('aiBudget');
        if(el2) el2.textContent='Ngân sách: dự trù $'+b.planned+' · đang giữ $'+b.held+' · thực tế $'+b.actual;
      }
    });
  }
  return { init: function(p){el=p;render();}, refresh: refresh,
    process: function(id){ RW.api('api_v5_ai.php?action=process',{method:'POST',body:'id='+id,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã xử lý.':'Lỗi: '+r.error, r.ok?'ok':'bad'); refresh(); }); },
    cancel: function(id){ RW.api('api_v5_ai.php?action=cancel',{method:'POST',body:'id='+id,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã hủy.':'Lỗi: '+r.error, r.ok?'ok':'bad'); refresh(); }); }
  };
})();
