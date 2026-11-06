(()=>{'use strict';
const roles={admin:'Quản trị viên',operator:'Vận hành',viewer:'Chỉ xem'};
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);if(cls)n.className=cls;return n;}
function button(text,fn){const b=el('button',text);b.type='button';b.addEventListener('click',fn);return b;}
async function render(root,api,principal){
 root.replaceChildren();
 if(principal?.role!=='admin'){root.append(el('p','Chỉ quản trị viên được quản lý người dùng và khóa kết nối máy.','notice'));return;}
 const feedback=el('p','');feedback.setAttribute('role','status');root.append(feedback);
 const [users,workers]=await Promise.all([api('access/users'),allWorkers(api)]);
 // Navigation can replace the view while requests are in flight.
 if(!root.contains(feedback))return;
 const names=new Map(workers.map(w=>[w.id,w.name]));
 const issue=el('section',undefined,'panel');issue.hidden=true;root.append(issue);
 function showToken(data){
  issue.replaceChildren(el('h2','Khóa mới — chỉ hiển thị tại đây một lần'),el('p','Lưu vào nơi bảo mật trước khi đóng. Danh sách và nhật ký không chứa khóa này.'));
  const label=el('label','Khóa truy cập'),input=el('input');input.type='password';input.readOnly=true;input.autocomplete='off';input.value=data.token;input.setAttribute('aria-label','Khóa mới');label.append(input);issue.append(label);
  issue.append(button('Hiện / ẩn khóa',()=>{input.type=input.type==='password'?'text':'password';}),button('Sao chép khóa',async()=>{try{await navigator.clipboard.writeText(input.value);feedback.textContent='Đã sao chép khóa.';}catch{input.type='text';input.focus();input.select();feedback.textContent='Chọn và sao chép khóa thủ công.';}}),button('Đã lưu — đóng khóa',()=>{input.value='';issue.replaceChildren();issue.hidden=true;}));issue.hidden=false;
 }
 async function perform(path,payload,done){feedback.textContent='Đang lưu…';try{const result=await api(path,payload);if(!root.contains(feedback))return;if(result.token)showToken(result);done?.(result);feedback.textContent='Đã lưu thay đổi.';}catch(e){feedback.textContent=e.message||'Không thể lưu thay đổi.';throw e;}}
 function confirmAction(container,title,warning,path,onDone){
  container.replaceChildren(el('strong',title),el('p',warning));
  const check=el('input');check.type='checkbox';const label=el('label',' Tôi đã kiểm tra đúng người dùng / máy và hiểu tác động.');label.prepend(check);container.append(label);
  const confirm=button('Xác nhận '+title.toLowerCase(),async()=>{if(!check.checked){feedback.textContent='Đánh dấu xác nhận trước khi tiếp tục.';return;}confirm.disabled=true;try{await perform(path,{},()=>{container.replaceChildren();onDone?.();});}catch{confirm.disabled=false;}});container.append(confirm,button('Hủy',()=>container.replaceChildren()));
 }
 const panel=el('section',undefined,'panel');panel.append(el('h2','Cấp quyền người dùng'));
 const form=el('form');const nameLabel=el('label','Tên người dùng'),name=el('input');name.name='name';name.required=true;name.maxLength=200;nameLabel.append(name);
 const roleLabel=el('label','Vai trò'),role=el('select');role.name='role';for(const [value,text] of Object.entries(roles)){const o=el('option',text);o.value=value;role.append(o);}role.value='viewer';roleLabel.append(role);
 const modeLabel=el('label','Phạm vi máy'),mode=el('select');mode.name='scope';for(const [value,text] of [['selected','Chỉ các máy được chọn'],['all','Tất cả máy (kể cả máy thêm sau)'],['none','Không có máy nào']]){const o=el('option',text);o.value=value;mode.append(o);}modeLabel.append(mode);
 const picks=el('fieldset');picks.append(el('legend','Chọn máy được phép truy cập'));const checks=[];
 for(const w of workers){const l=el('label',`${w.name} (${w.id})`),c=el('input');c.type='checkbox';c.value=w.id;c.name='worker_ids';l.prepend(c);picks.append(l);checks.push(c);}if(!workers.length)picks.append(el('p','Chưa có máy. Phạm vi chọn sẽ không cấp quyền cho máy nào.'));
 const sync=()=>{const admin=role.value==='admin';mode.disabled=admin;picks.disabled=admin||mode.value!=='selected';picks.hidden=admin||mode.value!=='selected';note.textContent=admin?'Quản trị viên có quyền trên toàn bộ hệ thống.':mode.value==='all'?'Cấp quyền cho toàn bộ máy hiện tại và tương lai.':mode.value==='none'?'Không cấp quyền truy cập máy nào.':'Chỉ cấp quyền cho các máy được đánh dấu; không chọn máy nào nghĩa là không có quyền.';};
 const note=el('p',undefined,'subtle');role.addEventListener('change',sync);mode.addEventListener('change',sync);sync();
 const create=el('button','Tạo người dùng');create.type='submit';form.append(nameLabel,roleLabel,modeLabel,note,picks,create);panel.append(form);root.append(panel);
 form.addEventListener('submit',async e=>{e.preventDefault();if(!form.reportValidity())return;create.disabled=true;try{await perform('access/users',{name:name.value.trim(),role:role.value,worker_ids:role.value==='admin'||mode.value==='all'?null:mode.value==='none'?[]:checks.filter(c=>c.checked).map(c=>c.value)},r=>{addUser({id:r.id,name:name.value.trim(),role:role.value,worker_ids:role.value==='admin'||mode.value==='all'?null:mode.value==='none'?[]:checks.filter(c=>c.checked).map(c=>c.value),enabled:true});form.reset();role.value='viewer';sync();});}catch{}finally{create.disabled=false;}});
 const userPanel=el('section',undefined,'panel');userPanel.append(el('h2','Người dùng và phạm vi'));const list=el('div');userPanel.append(list);root.append(userPanel);
 function scopeText(ids){return ids===null?'Tất cả máy':ids?.length?ids.map(id=>`${names.get(id)||id} (${id})`).join(', '):'Không có máy nào';}
 function addUser(u){list.querySelector(':scope > p')?.remove();const card=el('article');card.append(el('h3',u.name),el('p',`${roles[u.role]||u.role} · ${scopeText(u.worker_ids)}`));const state=el('p',u.enabled?'Đang bật':'Đã thu hồi');card.append(state);const actions=el('div',undefined,'toolbar'),confirmation=el('div');
  actions.append(button('Đổi khóa người dùng',()=>confirmAction(confirmation,'Đổi khóa người dùng',`Người dùng ${u.name}: khóa cũ và mọi phiên đăng nhập hiện tại sẽ hết hiệu lực. Người dùng được bật lại khi đổi khóa.`,`access/users/${encodeURIComponent(u.id)}/rotate`,()=>{state.textContent='Đang bật';})),button('Thu hồi người dùng',()=>confirmAction(confirmation,'Thu hồi người dùng',`Thu hồi quyền của ${u.name}; khóa và mọi phiên đăng nhập hiện tại sẽ hết hiệu lực.`,`access/users/${encodeURIComponent(u.id)}/revoke`,()=>{state.textContent='Đã thu hồi';})));card.append(actions,confirmation);list.append(card);}
 for(const u of users.rows)addUser(u);if(!users.rows.length)list.append(el('p','Chưa có người dùng bổ sung. Khóa quản trị gốc vẫn được cấu hình trên máy chủ.'));
 const machinePanel=el('section',undefined,'panel');machinePanel.append(el('h2','Khóa kết nối máy'),el('p','Đổi hoặc thu hồi khóa yêu cầu các phiên đang chạy dừng an toàn. Khóa cũ chỉ còn quyền báo cáo, gia hạn và nhận lệnh dừng trong 24 giờ; không được nhận việc hay đọc tài khoản. Thay khóa trong cấu hình máy trước khi kết nối lại.'));
 for(const w of workers){const card=el('article'),confirmation=el('div');card.append(el('h3',`${w.name} (${w.id})`),el('p',w.enabled?'Máy đang bật':'Máy đã tắt'));const actions=el('div',undefined,'toolbar');for(const [action,label] of [['rotate','Đổi khóa máy'],['revoke','Thu hồi khóa máy']])actions.append(button(label,()=>confirmAction(confirmation,label,`${w.name} (${w.id}): ${action==='revoke'?'tắt máy và thu hồi quyền kết nối':'khóa hiện tại sẽ ngừng cấp quyền nhận việc'}. Các phiên đang hoạt động được yêu cầu dừng và đóng hồ sơ an toàn. Thao tác này không khởi động agent.`,`access/workers/${encodeURIComponent(w.id)}/${action}`)));card.append(actions,confirmation);machinePanel.append(card);}if(!workers.length)machinePanel.append(el('p','Chưa có máy để quản lý khóa.'));root.append(machinePanel);
}
async function allWorkers(api){const rows=[];let offset=0;while(true){const page=await api(`workers?limit=200&offset=${offset}`);rows.push(...page.rows);offset+=page.rows.length;if(!page.rows.length||offset>=page.total)return rows;}}
window.RW_ACCESS={render};
})();

