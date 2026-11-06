function rwD5(r){var e=r.data;if(e&&e.data!==undefined){return e.data;}return e||{};}
window.RW_TAB_VPS = (function () {
  'use strict';
  var el = null;
  function render() {
    if (!el) return;
    var h = '<div class="rw-panel"><div class="rw-panel-pad">';
    h += '<h3>🖥 VPS &amp; Worker</h3>';
    h += '<div class="rw-inline"><button class="rw-btn" id="vpRefresh">↻ Làm mới</button><button class="rw-btn primary" id="vpEnroll">＋ Mã ghép nối</button><button class="rw-btn primary" id="vpNewCmd">＋ Gửi lệnh</button></div>';
    h += '<div id="vpCmdForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>Thiết bị</label><select class="rw-input" id="vpTarget"></select></div>';
    h += '<div class="rw-field"><label>Lệnh</label><select class="rw-input" id="vpAction"><option value="check_now">Kiểm tra ngay</option><option value="start">Bắt đầu</option><option value="pause">Tạm dừng</option><option value="stop">Dừng</option><option value="maintenance">Bảo trì</option></select></div>';
    h += '<div class="rw-field"><label>Lý do</label><input class="rw-input" id="vpReason" maxlength="300" placeholder="Lý do gửi lệnh"></div>';
    h += '<div class="rw-inline"><button class="rw-btn primary" id="vpSendCmd">Gửi lệnh</button><button class="rw-btn" id="vpCloseCmd">Hủy</button></div></div>';
    h += '<h4 style="margin:1rem 0 .5rem">Thiết bị (device tokens)</h4>';
    h += '<table class="rw-table"><thead><tr><th>Device ID</th><th>Nhãn</th><th>Phiên bản</th><th>API</th><th>Đồng bộ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody id="vpDevBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Lệnh thiết bị</h4>';
    h += '<table class="rw-table"><thead><tr><th>ID</th><th>Device</th><th>Lệnh</th><th>Lý do</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody id="vpCmdBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Chuyển hồ sơ</h4>';
    h += '<table class="rw-table"><thead><tr><th>Account</th><th>Từ</th><th>Đến</th><th>Trạng thái</th></tr></thead><tbody id="vpTrfBody"></tbody></table>';
    h += '</div></div>';
    el.innerHTML = h;
    document.getElementById('vpRefresh').onclick = refresh;
    document.getElementById('vpNewCmd').onclick = function(){document.getElementById('vpCmdForm').style.display='';};
    document.getElementById('vpCloseCmd').onclick = function(){document.getElementById('vpCmdForm').style.display='none';};
    document.getElementById('vpSendCmd').onclick = function(){
      var dev=document.getElementById('vpTarget').value, cmd=document.getElementById('vpAction').value, reason=document.getElementById('vpReason').value.trim();
      if(!dev || reason.length<3){RW.toast('Chọn thiết bị và nhập lý do ít nhất 3 ký tự.','bad');return;}
      var body='device_id='+encodeURIComponent(dev)+'&cmd='+encodeURIComponent(cmd)+'&reason='+encodeURIComponent(reason);
      RW.api('api_v5_vps.php?action=command_create',{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){
        RW.toast(r.ok?'Đã lưu lệnh, chờ worker nhận.':'Lỗi: '+r.error,r.ok?'ok':'bad');
        if(r.ok){document.getElementById('vpCmdForm').style.display='none';refresh();}
      });
    };
    document.getElementById('vpEnroll').onclick = function(){
      RW.api('api_v5_vps.php?action=enroll_create',{method:'POST',body:'ttl_min=30',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){
        if(r.ok){ RW.toast('Mã ghép nối: '+rwD5(r).code+' (hết hạn: '+rwD5(r).expires_at+')','ok'); refresh(); } else { RW.toast('Lỗi: '+r.error,'bad'); }
      });
    };
    refresh();
  }
  function refresh() {
    if (!el) return;
    RW.api('api_v5_vps.php?action=token_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('vpDevBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        var st=x.status==='active'?'✅':x.status==='revoked'?'🚫':'⚠';
        return '<tr><td>'+RW.esc(x.device_id)+'</td><td>'+RW.esc(x.label||'—')+'</td><td>'+RW.esc(x.app_version||'—')+'</td><td>'+RW.esc(x.api_compat||'—')+'</td><td>'+RW.esc(x.last_sync_at||'—')+'</td><td>'+st+' '+RW.esc(x.status)+'</td><td>'+(x.status==='active'?'<button class="rw-btn vpRevoke" data-id="'+RW.esc(x.device_id)+'">Thu hồi</button>':'—')+'</td></tr>';
      }).join('');
      var select=document.getElementById('vpTarget');if(select){select.innerHTML=(rwD5(r).items||[]).filter(function(x){return x.status==='active';}).map(function(x){return '<option value="'+RW.esc(x.device_id)+'">'+RW.esc(x.label||x.device_id)+'</option>';}).join('');}
    });
    RW.api('api_v5_vps.php?action=command_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('vpCmdBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        return '<tr><td>'+RW.esc(x.id.slice(-8))+'</td><td>'+RW.esc(x.device_id)+'</td><td>'+RW.esc(x.cmd)+'</td><td>'+RW.esc((x.reason||'').slice(0,30))+'</td><td>'+RW.esc(x.status)+'</td><td>'+(x.status==='queued'?'<button class="rw-btn vpCancel" data-id="'+RW.esc(x.id)+'">Hủy</button>':'—')+'</td></tr>';
      }).join('');
    });
    RW.api('api_v5_vps.php?action=transfer_list').then(function(r){
      if(!r.ok) return;
      var b=document.getElementById('vpTrfBody'); if(!b) return;
      b.innerHTML=(rwD5(r).items||[]).map(function(x){
        return '<tr><td>'+RW.esc(x.account_id)+'</td><td>'+RW.esc(x.from_device)+'</td><td>'+RW.esc(x.to_device)+'</td><td>'+RW.esc(x.status)+'</td></tr>';
      }).join('');
    });
  }
  document.addEventListener('click',function(e){
    if(!el || !el.contains(e.target))return;
    var b=e.target.closest('.vpRevoke,.vpCancel');if(!b)return;
    if(b.classList.contains('vpRevoke'))window.RW_TAB_VPS.revoke(b.dataset.id);
    else window.RW_TAB_VPS.cancelCmd(b.dataset.id);
  });
  return { init: function(p){el=p;render();}, refresh: refresh,
    revoke: function(d){ var r=prompt('Lý do thu hồi token:'); if(!r) return; RW.api('api_v5_vps.php?action=token_revoke',{method:'POST',body:'device_id='+encodeURIComponent(d)+'&reason='+encodeURIComponent(r),headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(x){ RW.toast(x.ok?'Đã thu hồi.':'Lỗi: '+x.error,x.ok?'ok':'bad'); refresh(); }); },
    cancelCmd: function(id){ var r=prompt('Lý do hủy lệnh:'); if(!r) return; RW.api('api_v5_vps.php?action=command_cancel',{method:'POST',body:'id='+encodeURIComponent(id)+'&reason='+encodeURIComponent(r),headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(x){ RW.toast(x.ok?'Đã hủy.':'Lỗi: '+x.error,x.ok?'ok':'bad'); refresh(); }); }
  };
})();
