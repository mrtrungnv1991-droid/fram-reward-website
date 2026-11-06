(()=>{'use strict';
const esc=v=>String(v??'—').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const stateLabel={running:'Đang bật',paused:'Tạm dừng',stopped:'Đã dừng',scanning:'Đang kiểm tra',idle:'Chờ lượt tiếp theo',disconnected:'Mất kết nối',pausing:'Đang tạm dừng',stopping:'Đang dừng'};
const commandLabel={queued:'Đã lưu',sent:'Đã gửi',acked:'Agent đã nhận',working:'Đang thực hiện',done:'Hoàn tất',error:'Lỗi',expired:'Quá hạn'};
const time=v=>v?new Date(v).toLocaleString('vi-VN',{timeZone:'Asia/Bangkok',hour12:false}):'Chưa có';
async function render(root,api,principal=window.RW_PRINCIPAL){
 const canControl=principal?.role==='admin'||principal?.role==='operator';
 const data=await api('observers');
 root.innerHTML='<div class="notice">Máy vật lý chạy agent/Observer và chủ động kết nối ra Controller. Điều khiển Observer chỉ đọc; lệnh có thời hạn 5 phút. Trạng thái offline không có nghĩa là lệnh đã hoàn tất.</div><p id="observer-feedback" role="status"></p>'+
  data.rows.sort((a,b)=>Number(b.enabled)-Number(a.enabled)).map(w=>{
   const status=w.status||{},command=w.command,control=w.control||{desired:'stopped'},online=w.online;
   const commandText=command?`${esc(commandLabel[command.status]||command.status)} · ${esc(command.action)} · ${esc(command.id)} · ${esc(command.reason)}`:'Chưa có lệnh';
   const resource=status.resources;
   return `<section class="panel" data-observer="${esc(w.id)}"><h2>${esc(w.name)}</h2>
    <div class="list-row"><span>Kết nối agent</span><strong>${online?'Online':'Offline / chưa cài'}</strong></div>
    <div class="list-row"><span>Hồ sơ được gán</span><strong>${esc(w.assigned_profiles??0)} / ${esc(w.profile_capacity??'chưa đo')}</strong></div>
    <div class="list-row"><span>Giới hạn phiên</span><strong>Quản trị ${esc(w.cap)} · Đã đo ${esc(w.benchmark_cap??'chưa có')} · Hiệu lực ${esc(w.effective_cap??0)}</strong></div>
    <div class="list-row"><span>Lệnh gần nhất</span><strong>${commandText}</strong></div>
    <div class="list-row"><span>Hạn lệnh</span><span>${command?esc(time(command.deadline)):'—'}</span></div>
    <div class="list-row"><span>Trạng thái thực tế</span><strong>${online?esc(stateLabel[status.state]||status.state):'Chưa xác nhận — agent offline'}</strong></div>
    <div class="list-row"><span>Chế độ bảo trì</span><span>${control.maintenance?'Đang bật':'Tắt'}</span></div>
    <div class="list-row"><span>Kiểm tra xong gần nhất</span><span>${esc(time(status.last_scan_at))}</span></div>
    ${resource?`<p class="subtle">CPU: ${esc(resource.cpuPercent==null?'Chưa có':resource.cpuPercent.toFixed(1)+'%')} · RAM trống: ${esc((resource.freeRamBytes/1024**3).toFixed(2))} GiB · Đĩa trống: ${esc((resource.freeDiskBytes/1024**3).toFixed(2))} GiB · Trần hiệu lực: ${esc(resource.effective_cap)}</p>${resource.blocked?'<p class="notice">Tạm dừng do thiếu tài nguyên.</p>':''}`:''}
    ${status.last_error?'<p class="notice">Lượt kiểm tra lỗi; xem log agent tại máy.</p>':''}
    ${canControl?`<label>Lý do điều khiển<input class="observer-reason" maxlength="300" placeholder="Nhập lý do (ít nhất 3 ký tự)"></label><div class="toolbar">${[['start','Bắt đầu'],['pause','Tạm dừng'],['stop','Dừng'],['check_now','Kiểm tra ngay'],['maintenance','Bảo trì']].map(([a,l])=>`<button data-action="${a}" ${!w.enabled?'disabled':''}>${l}</button>`).join('')}<a href="#pool">Xem kết quả tài khoản →</a></div>`:'<a href="#pool">Xem kết quả tài khoản →</a>'}
    <p class="subtle">${!online?'Lệnh chờ agent kết nối lại trong thời hạn. ':''}Chu kỳ 15 phút · Chỉ đọc · ${esc(status.version||'Chưa có phiên bản')}</p></section>`;
  }).join('')||'<p>Chưa có worker. Kết nối worker ở mục VPS & Worker trước.</p>';
 root.querySelectorAll('[data-action]').forEach(button=>button.onclick=async()=>{
  const section=button.closest('[data-observer]'),reason=section.querySelector('.observer-reason').value.trim();
  const feedback=root.querySelector('#observer-feedback');
  if(reason.length<3){feedback.textContent='Nhập lý do ít nhất 3 ký tự.';return;}
  button.disabled=true;
  try{const result=await api('observers/'+encodeURIComponent(section.dataset.observer)+'/control',{action:button.dataset.action,reason});await render(root,api,principal);root.querySelector('#observer-feedback').textContent='Đã lưu lệnh '+result.id+'. Theo dõi trạng thái xác nhận trên thẻ thiết bị.';}
  catch(e){feedback.textContent=e.message;button.disabled=false;}
 });
}
window.RW_OBSERVERS={render};
})();
