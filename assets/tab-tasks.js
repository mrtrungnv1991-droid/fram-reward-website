function rwD5(r){var e=r.data;if(e&&e.data!==undefined){return e.data;}return e||{};}
window.RW_TAB_TASKS = (function () {
  'use strict';
  var el = null;
  function render() {
    if (!el) return;
    var h = '<div class="rw-panel"><div class="rw-panel-pad">';
    h += '<h3>📋 Nhiệm vụ &amp; Skill — Vòng đời</h3>';
    h += '<div class="rw-inline"><button class="rw-btn" id="tkRefresh">↻ Làm mới</button><button class="rw-btn primary" id="tkNewTask">＋ Nhiệm vụ</button><button class="rw-btn primary" id="tkNewSkill">＋ Skill version</button></div>';
    h += '<p class="rw-muted">Bản nháp chỉ được phát hành sau khi có kết quả kiểm thử fixture thật và quản trị viên duyệt.</p>';
    h += '<h4 style="margin:1rem 0 .5rem">Danh mục nhiệm vụ</h4>';
    h += '<table class="rw-table"><thead><tr><th>Mã</th><th>Loại</th><th>Thị trường</th><th>Trạng thái</th><th>Nhạy cảm</th><th>Skill</th></tr></thead><tbody id="tkBody"></tbody></table>';
    h += '<h4 style="margin:1rem 0 .5rem">Skill versions</h4>';
    h += '<table class="rw-table"><thead><tr><th>Skill</th><th>Semver</th><th>Trạng thái</th><th>Nguồn</th><th>Tester</th><th>Approver</th></tr></thead><tbody id="skBody"></tbody></table>';
    h += '<div id="tkForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>Mã nhiệm vụ</label><input class="rw-input" id="tkCode"></div>';
    h += '<div class="rw-field"><label>Loại</label><input class="rw-input" id="tkKind"></div>';
    h += '<div class="rw-inline"><button class="rw-btn primary" id="tkSubmit">Lưu</button><button class="rw-btn" id="tkCancel">Hủy</button></div></div>';
    h += '<div id="skForm" style="display:none;margin:1rem 0;padding:1rem;border:1px solid var(--rw-border);border-radius:8px">';
    h += '<div class="rw-field"><label>Mã skill</label><input class="rw-input" id="skCode" maxlength="64"></div>';
    h += '<div class="rw-field"><label>Phiên bản (x.y.z)</label><input class="rw-input" id="skSemver" placeholder="1.0.0"></div>';
    h += '<div class="rw-field"><label>Workflow khai báo</label><textarea class="rw-input" id="skBodyText" rows="8" placeholder="Mô tả các bước và điều kiện kiểm tra"></textarea></div>';
    h += '<div class="rw-inline"><button class="rw-btn primary" id="skSubmit">Lưu bản nháp</button><button class="rw-btn" id="skCancel">Hủy</button></div></div>';
    h += '</div></div>';
    el.innerHTML = h;
    document.getElementById('tkRefresh').onclick = refresh;
    document.getElementById('tkNewTask').onclick = function(){ document.getElementById('tkForm').style.display=''; };
    document.getElementById('tkNewSkill').onclick = function(){ document.getElementById('skForm').style.display=''; };
    document.getElementById('skCancel').onclick = function(){ document.getElementById('skForm').style.display='none'; };
    document.getElementById('skSubmit').onclick = function(){
      var code=document.getElementById('skCode').value.trim(), semver=document.getElementById('skSemver').value.trim(), body=document.getElementById('skBodyText').value.trim();
      if(!/^[A-Za-z0-9_.-]{2,64}$/.test(code) || !/^\d+\.\d+\.\d+$/.test(semver) || !body){ RW.toast('Kiểm tra mã skill, phiên bản và nội dung.','bad'); return; }
      var payload='skill_code='+encodeURIComponent(code)+'&semver='+encodeURIComponent(semver)+'&body='+encodeURIComponent(body)+'&origin=human';
      RW.api('api_v5_tasks.php?action=skill_create',{method:'POST',body:payload,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){
        RW.toast(r.ok?'Đã lưu phiên bản, đang chờ kiểm thử thật.':'Lỗi: '+r.error,r.ok?'ok':'bad');
        if(r.ok){document.getElementById('skForm').style.display='none';refresh();}
      });
    };
    document.getElementById('tkCancel').onclick = function(){ document.getElementById('tkForm').style.display='none'; };
    document.getElementById('tkSubmit').onclick = function(){
      var body = 'code='+encodeURIComponent(document.getElementById('tkCode').value)+'&kind='+encodeURIComponent(document.getElementById('tkKind').value);
      RW.api('api_v5_tasks.php?action=task_save',{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=csrf]').content}}).then(function(r){ RW.toast(r.ok?'Đã lưu.':'Lỗi: '+r.error,r.ok?'ok':'bad'); document.getElementById('tkForm').style.display='none'; refresh(); });
    };
    refresh();
  }
  function refresh() {
    if (!el) return;
    RW.api('api_v5_tasks.php?action=task_list').then(function(r){
      if(!r.ok) return;
      var body=document.getElementById('tkBody'); if(!body) return;
      body.innerHTML=(rwD5(r).items||[]).map(function(x){ return '<tr><td>'+RW.esc(x.code)+'</td><td>'+RW.esc(x.kind)+'</td><td>'+RW.esc(x.market||'—')+'</td><td>'+RW.esc(x.status)+'</td><td>'+RW.esc(x.sensitivity||'normal')+'</td><td>'+RW.esc(x.skill_code||'—')+'</td></tr>'; }).join('');
    });
    RW.api('api_v5_tasks.php?action=skill_list').then(function(r){
      if(!r.ok) return;
      var body=document.getElementById('skBody'); if(!body) return;
      var skills=rwD5(r).items||[];
      if(!skills.length){body.innerHTML='<tr><td colspan="6">Chưa có skill.</td></tr>';return;}
      Promise.all(skills.map(function(x){return RW.api('api_v5_tasks.php?action=skill_versions&skill_code='+encodeURIComponent(x.skill_code));})).then(function(rows){
        if(!document.getElementById('skBody'))return;
        var versions=[];rows.forEach(function(result){if(result.ok)versions=versions.concat(rwD5(result).items||[]);});
        body.innerHTML=versions.length?versions.map(function(x){return '<tr><td>'+RW.esc(x.skill_code)+'</td><td>'+RW.esc(x.semver)+'</td><td>'+RW.esc(x.status)+'</td><td>'+RW.esc(x.origin||'—')+'</td><td>'+RW.esc(x.tester||'—')+'</td><td>'+RW.esc(x.approver||'—')+'</td></tr>';}).join(''):'<tr><td colspan="6">Chưa có phiên bản.</td></tr>';
      });
    });
  }
  return { init: function(p){el=p;render();}, refresh: refresh };
})();
