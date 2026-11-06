(()=>{'use strict';
let offset=0,query='';
const esc=v=>String(v??'—').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const time=v=>v?new Date(v).toLocaleString('vi-VN',{timeZone:'Asia/Bangkok',hour12:false}):'—';
async function render(root,api,principal=window.RW_PRINCIPAL){
 const data=await api(`banned?limit=50&offset=${offset}&q=${encodeURIComponent(query)}`);
 root.innerHTML=`<div class="notice">Chỉ tài khoản đã xác minh bị khóa hoặc cấm mới nằm ở đây. Tài khoản Banned bị tắt, không nhận việc mới; phiên đang chạy nhận yêu cầu dừng. Lỗi đăng nhập hay lỗi worker không tự chuyển vào mục này.</div>
 <div class="toolbar"><input id="banned-search" aria-label="Tìm tài khoản Banned" placeholder="Tìm email…" value="${esc(query)}"><button id="banned-find">Tìm kiếm</button><span class="subtle">${Number(data.total)||0} tài khoản Banned</span></div>
 <div class="table-wrap"><table><thead><tr><th>Tài khoản</th><th>Lý do</th><th>Bằng chứng</th><th>Người xác nhận</th><th>Thời điểm</th><th>Thao tác</th></tr></thead><tbody>${data.rows.length?data.rows.map((row,i)=>`<tr><td>${esc(row.name)}</td><td>${esc(row.reason)}</td><td>${esc(row.evidence)}</td><td>${esc(row.banned_by)}</td><td>${esc(time(row.banned_at))}</td><td>${principal?.role==='admin'?`<button data-unban="${i}">Rà soát mở lại</button>`:'Chỉ xem'}</td></tr>`).join(''):'<tr><td colspan="6" class="empty">Chưa có tài khoản được xác minh là Banned.</td></tr>'}</tbody></table></div>
 <div class="pagination"><span>${data.total?offset+1:0}–${Math.min(offset+50,data.total)} / ${data.total}</span><div><button id="banned-prev" ${!offset?'disabled':''}>Trang trước</button><button id="banned-next" ${offset+50>=data.total?'disabled':''}>Trang sau</button></div></div><p id="banned-feedback" role="status"></p>`;
 root.querySelector('#banned-find').onclick=()=>{query=root.querySelector('#banned-search').value;offset=0;void render(root,api,principal);};
 root.querySelector('#banned-search').onkeydown=e=>{if(e.key==='Enter')root.querySelector('#banned-find').click();};
 root.querySelector('#banned-prev').onclick=()=>{offset=Math.max(0,offset-50);void render(root,api,principal);};
 root.querySelector('#banned-next').onclick=()=>{offset+=50;void render(root,api,principal);};
 root.querySelectorAll('[data-unban]').forEach(button=>button.onclick=async()=>{
  const row=data.rows[Number(button.dataset.unban)],reason=window.prompt('Lý do rà soát và mở khỏi Banned:');if(!reason)return;
  button.disabled=true;try{await api('accounts/'+encodeURIComponent(row.id)+'/unban',{reason:reason.trim()});await render(root,api,principal);root.querySelector('#banned-feedback').textContent='Đã chuyển về Cần xử lý và vẫn tắt tài khoản. Cần kiểm tra trước khi bật lại.';}catch(e){root.querySelector('#banned-feedback').textContent=e.message;button.disabled=false;}
 });
}
window.RW_BANNED={render};
})();
