function rwD5(r){var e=r.data;if(e&&e.data!==undefined){return e.data;}return e||{};}
window.RW_TAB_ACCEPT = (function () {
  'use strict';
  var el = null, runs = [];
  function render() {
    if (!el) return;
    var h = '<div class="rw-panel"><div class="rw-panel-pad">';
    h += '<h3>✅ Nghiệm thu VPS (10 bước)</h3>';
    h += '<div class="rw-inline"><button class="rw-btn" id="acRefresh">↻ Làm mới</button><button class="rw-btn primary" id="acNew">＋ Tạo phiên nghiệm thu</button></div>';
    h += '<div id="acForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>VPS đích</label><input class="rw-input" id="acTarget" placeholder="vps-hcm-1"></div>';
    h += '<div class="rw-field"><label>Phiên bản nguồn</label><input class="rw-input" id="acVersion" placeholder="v5.0.1"></div>';
    h += '<div class="rw-inline"><button class="rw-btn primary" id="acSubmit">Tạo</button><button class="rw-btn" id="acCancel">Hủy</button></div></div>';
    h += '<h4 style="margin:1rem 0 .5rem">Các phiên nghiệm thu</h4>';
    h += '<table class="rw-table"><thead><tr><th>ID</th><th>VPS đích</th><th>Phiên bản</th><th>Kết luận</th><th>Bắt đầu</th><th>Thao tác</th></tr></thead><tbody id="acRunBody"></tbody></table>';
    h += '<div id="acDetail" style="display:none;margin:1rem 0"></div>';
    h += '</div></div>';
    el.innerHTML = h;
    document.getElementById('acRefresh').onclick = refresh;
    document.getElementById('acNew').onclick = function(){ document.getElementById('acForm').style.display=''; };
    document.getElementById('acCancel').onclick = function(){ document.getElementById('acForm').style.display='none'; };
    document.getElementById('acSubmit').onclick = function(){
      var body='target_vps='+encodeURIComponent(document.getElementById('acTarget').value)+'&source_version='+encodeURIComponent(document.getElementById('acVersion').value);
      RW.api('api_v5_accept.php?action=run_create',{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã tạo phiên (10 bước).':'Lỗi: '+r.error,r.ok?'ok':'bad'); document.getElementById('acForm').style.display='none'; refresh(); });
    };
    refresh();
  }
  function refresh() {
    if (!el) return;
    RW.api('api_v5_accept.php?action=run_list').then(function(r){
      if(!r.ok) return;
      runs=rwD5(r).items||[];
      var b=document.getElementById('acRunBody'); if(!b) return;
      b.innerHTML=runs.map(function(x){
        var v={pass:'✅ pass',fail:'❌ fail',partial:'⚠ partial',pending:'⏳ đang làm'}[x.verdict]||x.verdict;
        return '<tr><td>'+RW.esc(x.id.slice(-10))+'</td><td>'+RW.esc(x.target_vps)+'</td><td>'+RW.esc(x.source_version||'—')+'</td><td>'+v+'</td><td>'+RW.esc(x.started_at||'—')+'</td><td><button class="rw-btn" onclick="RW_TAB_ACCEPT.detail(\''+x.id+'\')">Chi tiết</button> <button class="rw-btn" onclick="RW_TAB_ACCEPT.report(\''+x.id+'\')">Biên bản</button></td></tr>';
      }).join('');
    });
  }
  return { init: function(p){el=p;render();}, refresh: refresh,
    detail: function(id){
      RW.api('api_v5_accept.php?action=run_get&id='+encodeURIComponent(id)).then(function(r){
        if(!r.ok){ RW.toast('Lỗi: '+r.error,'bad'); return; }
        var d=r.data, box=document.getElementById('acDetail'); box.style.display='';
        var h='<h4>Phiên '+(d.id)+' — VPS '+(d.target_vps)+'</h4>';
        h+='<table class="rw-table"><thead><tr><th>#</th><th>Bước</th><th>KQ</th><th>Bằng chứng</th><th>Executor</th><th>Thao tác</th></tr></thead><tbody>';
        (d.checks||[]).forEach(function(c,i){
          var sym={pass:'✅',fail:'❌',skip:'⏭',pending:'⏳'}[c.result]||'⏳';
          h+='<tr><td>'+(i+1)+'</td><td>'+RW.esc(c.step.slice(0,50))+'</td><td>'+sym+' '+RW.esc(c.result)+'</td><td>'+RW.esc((c.evidence||'').slice(0,25))+'</td><td>'+RW.esc(c.executor||'—')+'</td><td>';
          h+='<button class="rw-btn" onclick="RW_TAB_ACCEPT.mark(\''+c.id+'\',\'pass\',\'vps\')">✓ VPS</button> ';
          h+='<button class="rw-btn" onclick="RW_TAB_ACCEPT.mark(\''+c.id+'\',\'pass\',\'local\')">✓ Local</button> ';
          h+='<button class="rw-btn" onclick="RW_TAB_ACCEPT.mark(\''+c.id+'\',\'fail\',\'\')">✗</button></td></tr>';
        });
        h+='</tbody></table>';
        if(d.verdict==='pending'){
          h+='<div class="rw-inline" style="margin-top:.5rem"><button class="rw-btn primary" onclick="RW_TAB_ACCEPT.conclude(\''+d.id+'\',\'pass\')">Kết luận PASS</button><button class="rw-btn" onclick="RW_TAB_ACCEPT.conclude(\''+d.id+'\',\'partial\')">Partial</button><button class="rw-btn" onclick="RW_TAB_ACCEPT.conclude(\''+d.id+'\',\'fail\')">FAIL</button></div>';
        }
        box.innerHTML=h;
      });
    },
    mark: function(id,result,executor){
      var ev=prompt('Bằng chứng (bắt buộc khi executor=vps):')||'';
      if(executor==='vps' && !ev){ RW.toast('Cần bằng chứng khi chấm trên VPS.','bad'); return; }
      RW.api('api_v5_accept.php?action=check_update',{method:'POST',body:'id='+encodeURIComponent(id)+'&result='+result+'&evidence='+encodeURIComponent(ev)+'&executor='+executor,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã cập nhật.':'Lỗi: '+r.error,r.ok?'ok':'bad'); if(r.ok) refresh(); });
    },
    conclude: function(id,verdict){
      RW.api('api_v5_accept.php?action=run_conclude',{method:'POST',body:'id='+encodeURIComponent(id)+'&verdict='+verdict,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã kết luận '+verdict:'Lỗi: '+r.error,r.ok?'ok':'bad'); if(r.ok) refresh(); });
    },
    report: function(id){ window.open('api_v5_accept.php?action=report&id='+encodeURIComponent(id),'_blank'); }
  };
})();
