function rwD5(r){var e=r.data;if(e&&e.data!==undefined){return e.data;}return e||{};}
window.RW_TAB_SECURITY = (function () {
  'use strict';
  var el = null;
  function render() {
    if (!el) return;
    var h = '<div class="rw-panel"><div class="rw-panel-pad">';
    h += '<h3>🛡 Bảo mật · Sự cố · Sao lưu</h3>';
    h += '<div class="rw-inline"><button class="rw-btn" id="scRefresh">↻ Làm mới</button><button class="rw-btn primary" id="scNewInc">＋ Sự cố</button><button class="rw-btn" id="scReport">📊 Báo cáo</button></div>';
    h += '<div id="scForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>Tiêu đề</label><input class="rw-input" id="scTitle"></div>';
    h += '<div class="rw-field"><label>Ưu tiên</label><select class="rw-input" id="scPrio"><option value="low">Thấp</option><option value="normal" selected>Thường</option><option value="high">Cao</option><option value="urgent">Khẩn</option></select></div>';
    h += '<div class="rw-field"><label>Hướng xử lý</label><input class="rw-input" id="scGuide"></div>';
    h += '<div class="rw-inline"><button class="rw-btn primary" id="scSubmit">Lưu</button><button class="rw-btn" id="scCancel">Hủy</button></div></div>';
    h += '<h4 style="margin:1rem 0 .5rem">Hộp "Cần xử lý"</h4>';
    h += '<table class="rw-table"><thead><tr><th>ID</th><th>Tiêu đề</th><th>Ưu tiên</th><th>Chủ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody id="scIncBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Retry policy (tách ngưỡng liên tiếp vs tổng)</h4>';
    h += '<table class="rw-table"><thead><tr><th>Đối tượng</th><th>Liên tiếp</th><th>Tổng</th><th>Thử lại sau</th><th>Cần người</th><th>Thao tác</th></tr></thead><tbody id="scRtyBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Backup &amp; retention</h4>';
    h += '<table class="rw-table"><thead><tr><th>Đích</th><th>Lưu trữ</th><th>RPO/RTO</th><th>Lần OK</th><th>Test restore</th><th>Thao tác</th></tr></thead><tbody id="scBakBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Đăng nhập gần đây</h4>';
    h += '<table class="rw-table"><thead><tr><th>User</th><th>IP</th><th>KQ</th><th>Thời gian</th></tr></thead><tbody id="scLogBody"></tbody></table>';
    h += '<div id="scReportBox" style="display:none;margin:1rem 0;padding:1rem;background:var(--rw-bg);border-radius:8px;white-space:pre-wrap;font-family:monospace"></div>';
    h += '</div></div>';
    el.innerHTML = h;
    document.getElementById('scRefresh').onclick = refresh;
    document.getElementById('scNewInc').onclick = function(){ document.getElementById('scForm').style.display=''; };
    document.getElementById('scCancel').onclick = function(){ document.getElementById('scForm').style.display='none'; };
    document.getElementById('scSubmit').onclick = function(){
      var body = 'title='+encodeURIComponent(document.getElementById('scTitle').value)+'&priority='+encodeURIComponent(document.getElementById('scPrio').value)+'&guidance='+encodeURIComponent(document.getElementById('scGuide').value);
      RW.api('api_v5_security.php?action=incident_save',{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã lưu sự cố.':'Lỗi: '+r.error,r.ok?'ok':'bad'); document.getElementById('scForm').style.display='none'; refresh(); });
    };
    document.getElementById('scReport').onclick = function(){
      RW.api('api_v5_security.php?action=report').then(function(r){
        if(!r.ok) return;
        var box=document.getElementById('scReportBox'); box.style.display='';
        var i=rwD5(r).incidents||{}, rp=rwD5(r).retry||{};
        box.textContent='BÁO CÁO BẢO MẬT\nSự cố: tổng '+(i.total||0)+' · đã đóng '+(i.closed||0)+' · đang mở '+(i.open_now||0)+' · TB mở lại '+(i.avg_reopen||0).toFixed(2)+'\nRetry: cần người '+(rp.needs_human||0)+' · TB lần thử '+(rp.avg_tries||0).toFixed(2);
      });
    };
    refresh();
  }
  function refresh() {
    if (!el) return;
    RW.api('api_v5_security.php?action=incident_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('scIncBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        var sy=x.status==='closed'?'✅':x.status==='reopened'?'🔴':x.status==='in_progress'?'🔄':'⚠';
        var prio={low:'🟢',normal:'🔵',high:'🟠',urgent:'🔴'}[x.priority]||'🔵';
        return '<tr><td>'+RW.esc(x.id.slice(-8))+'</td><td>'+RW.esc((x.title||'').slice(0,40))+'</td><td>'+prio+' '+RW.esc(x.priority)+'</td><td>'+RW.esc(x.owner||'—')+'</td><td>'+sy+' '+RW.esc(x.status)+'</td><td>'+(x.status!=='closed'?'<button class="rw-btn" onclick="RW_TAB_SECURITY.close(\''+x.id+'\')">Đóng</button>':'—')+'</td></tr>';
      }).join('');
    });
    RW.api('api_v5_security.php?action=retry_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('scRtyBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        return '<tr><td>'+RW.esc(x.target)+'</td><td>'+(x.consecutive_fail||0)+'/'+(x.max_consecutive||3)+'</td><td>'+(x.total_retries||0)+'/'+(x.max_total||10)+'</td><td>'+RW.esc(x.next_attempt_at||'—')+'</td><td>'+(x.needs_human?'⚠ cần người':'—')+'</td><td><button class="rw-btn" onclick="RW_TAB_SECURITY.resetRetry(\''+RW.esc(x.target)+'\')">Reset</button></td></tr>';
      }).join('');
    });
    RW.api('api_v5_security.php?action=backup_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('scBakBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        return '<tr><td>'+RW.esc(x.target)+'</td><td>'+RW.esc(x.storage)+'</td><td>'+(x.rpo_minutes||0)+'/'+(x.rto_minutes||0)+' phút</td><td>'+RW.esc(x.last_ok_at||'—')+'</td><td>'+RW.esc(x.last_test_restore_at||'chưa')+'</td><td><button class="rw-btn" onclick="RW_TAB_SECURITY.restoreTest(\''+x.id+'\')">Test restore</button></td></tr>';
      }).join('');
    });
    RW.api('api_v5_security.php?action=login_log').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('scLogBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).slice(0,10).map(function(x){
        return '<tr><td>'+RW.esc(x.username_masked)+'</td><td>'+RW.esc(x.ip||'—')+'</td><td>'+(x.ok?'✅':'❌')+'</td><td>'+RW.esc(x.created_at)+'</td></tr>';
      }).join('');
    });
  }
  return { init: function(p){el=p;render();}, refresh: refresh,
    close: function(id){ var e=prompt('Bằng chứng phục hồi (bắt buộc để đóng):'); if(!e) return; RW.api('api_v5_security.php?action=incident_transition',{method:'POST',body:'id='+encodeURIComponent(id)+'&status=closed&close_evidence='+encodeURIComponent(e),headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã đóng sự cố.':'Lỗi: '+r.error,r.ok?'ok':'bad'); refresh(); }); },
    resetRetry: function(t){ var r=prompt('Lý do reset retry (bắt buộc):'); if(!r) return; RW.api('api_v5_security.php?action=retry_reset',{method:'POST',body:'target='+encodeURIComponent(t)+'&reason='+encodeURIComponent(r),headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(x){ RW.toast(x.ok?'Đã reset.':'Lỗi: '+x.error,x.ok?'ok':'bad'); refresh(); }); },
    restoreTest: function(id){ RW.api('api_v5_security.php?action=backup_restore_test',{method:'POST',body:'id='+encodeURIComponent(id),headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã chạy test restore (môi trường tách biệt).':'Lỗi: '+r.error,r.ok?'ok':'bad'); refresh(); }); }
  };
})();
