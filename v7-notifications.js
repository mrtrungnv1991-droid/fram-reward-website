(()=>{'use strict';
const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);if(cls)n.className=cls;return n;};
function button(text,fn){const b=el('button',text);b.type='button';b.onclick=fn;return b;}
async function render(root,api,principal){
 if(principal?.role!=='admin')return;
 const panel=el('section',undefined,'panel'),feedback=el('p');feedback.setAttribute('role','status');panel.append(el('h2','Kênh nhận cảnh báo'),feedback);root.append(panel);
 const data=await api('notifications/channels');if(!root.contains(panel))return;
 panel.append(el('p','Chỉ gửi ra ngoài khi bạn cấu hình và bật một kênh. Nội dung cảnh báo không kèm mật khẩu, cookie hoặc địa chỉ email tài khoản. Có chống gửi lặp và giới hạn số lần thử lại.'));
 panel.append(el('p',data.allowed_hosts.length?'Máy chủ được phép: '+data.allowed_hosts.join(', '):'Chưa cấu hình máy chủ nhận cảnh báo được phép. Quản trị máy điều phối cần cấu hình ALERT_ALLOWED_HOSTS trước.','notice'));
 const list=el('div');panel.append(list);
 for(const channel of data.rows){
  const row=el('div',undefined,'panel'),status=el('p',channel.host+' · '+(channel.enabled?'Đang bật':'Đang tắt'));row.append(el('h3',channel.name),status);
  const confirm=el('input');confirm.type='checkbox';const label=el('label',' Tôi xác nhận đúng kênh nhận và cho phép thao tác bên dưới.');label.prepend(confirm);row.append(label);
  let busy=false;async function action(path,body){if(busy)return false;if(!confirm.checked){feedback.textContent='Đánh dấu xác nhận đúng kênh trước khi thực hiện.';return false;}busy=true;try{await api(path,body);feedback.textContent='Đã thực hiện yêu cầu.';confirm.checked=false;return true;}catch(e){feedback.textContent=e.message;return false;}finally{busy=false;}}
  const toggle=button(channel.enabled?'Tắt kênh':'Bật kênh',async()=>{if(await action('notifications/channels/'+encodeURIComponent(channel.id)+'/control',{enabled:!channel.enabled})){channel.enabled=!channel.enabled;toggle.textContent=channel.enabled?'Tắt kênh':'Bật kênh';status.textContent=channel.host+' · '+(channel.enabled?'Đang bật':'Đang tắt');}});
  row.append(toggle,button('Gửi bản tin thử',async()=>{await action('notifications/channels/'+encodeURIComponent(channel.id)+'/test',{confirm:true});}));list.append(row);
 }
 const form=el('form');
 for(const [name,text,type]of [['name','Tên kênh','text'],['url','Địa chỉ webhook HTTPS','url'],['token','Bearer token (nếu có)','password']]){const label=el('label',text),input=el('input');input.name=name;input.type=type;input.required=name!=='token';input.autocomplete='off';label.append(input);form.append(label);}
 const enabled=el('input');enabled.type='checkbox';enabled.name='enabled';const enabledLabel=el('label',' Bật gửi cảnh báo đến kênh này sau khi lưu');enabledLabel.prepend(enabled);form.append(enabledLabel);
 const save=el('button','Lưu kênh','primary');save.disabled=!data.allowed_hosts.length;form.append(save);form.onsubmit=async e=>{e.preventDefault();save.disabled=true;try{const f=new FormData(form),b={name:f.get('name'),url:f.get('url'),enabled:f.has('enabled')};if(f.get('token'))b.token=f.get('token');await api('notifications/channels',b);form.reset();feedback.textContent='Đã lưu kênh. Làm mới trang để xem danh sách cập nhật.';}catch(e){feedback.textContent=e.message;}finally{save.disabled=!data.allowed_hosts.length;}};panel.append(form);
}
window.RW_NOTIFICATIONS={render};
})();
