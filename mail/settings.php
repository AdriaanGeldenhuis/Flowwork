<script>
/* Inline Settings Accounts script – identical logic as mail.settings.js */
(function () {
  'use strict';
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function showMessage(elId, message, isError) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.textContent = message;
    el.className = 'fw-mail__form-message ' + (isError ? 'fw-mail__form-message--error' : 'fw-mail__form-message--success');
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
  }
  async function fetchJson(url, opts) {
    const res = await fetch(url, opts);
    let data = null; try { data = await res.json(); } catch(_){}
    return { ok: res.ok && data && (data.ok !== false), data };
  }
  function openModal(){ const m = document.getElementById('accountModal'); if (m) m.style.display='block'; }
  function closeModal(){ const m = document.getElementById('accountModal'); if (m) m.style.display='none'; }
  function resetForm(){ const f=$('#accountForm'); if(!f) return; f.reset(); if(f.elements['account_id']) f.elements['account_id'].value=''; const msg=$('#accountMessage'); if(msg){ msg.textContent=''; msg.style.display='none'; } }
  function fillForm(acc){
    const f=$('#accountForm'); if(!f) return; const get=(k,d='')=>acc&&acc[k]!=null?acc[k]:d;
    f.elements['account_id'].value=get('account_id','');
    f.elements['account_name'].value=get('account_name');
    f.elements['email_address'].value=get('email_address');
    f.elements['imap_server'].value=get('imap_server');
    f.elements['imap_port'].value=get('imap_port',993);
    f.elements['imap_encryption'].value=get('imap_encryption','ssl');
    f.elements['smtp_server'].value=get('smtp_server');
    f.elements['smtp_port'].value=get('smtp_port',587);
    f.elements['smtp_encryption'].value=get('smtp_encryption','tls');
    f.elements['username'].value=get('username');
    const active=document.getElementById('accountActive'); if(active) active.checked=!!get('is_active',1);
  }
  function getPayload(){
    const f=$('#accountForm');
    return {
      account_id:Number(f.account_id.value||0),
      account_name:f.account_name.value.trim(),
      email_address:f.email_address.value.trim(),
      imap_server:f.imap_server.value.trim(),
      imap_port:Number(f.imap_port.value||993),
      imap_encryption:f.imap_encryption.value,
      smtp_server:f.smtp_server.value.trim(),
      smtp_port:Number(f.smtp_port.value||587),
      smtp_encryption:f.smtp_encryption.value,
      username:f.username.value.trim(),
      password:f.password.value,
      is_active:document.getElementById('accountActive')?.checked?1:0
    };
  }
  async function onEditAccount(id){
    const t=document.getElementById('accountModalTitle'); if(t) t.textContent='Edit Email Account';
    resetForm();
    const {ok,data}=await fetchJson('/mail/ajax/account_get.php?id='+encodeURIComponent(id));
    if(!ok||!data||!data.account){ showMessage('accountMessage',(data&&data.error)?data.error:'Failed to load account',true); return; }
    fillForm(data.account); openModal();
  }
  function onNewAccount(){ const t=document.getElementById('accountModalTitle'); if(t) t.textContent='New Email Account'; resetForm(); openModal(); }
  async function onSaveAccount(e){
    e.preventDefault();
    const payload=getPayload();
    if(!payload.account_name||!payload.email_address){ showMessage('accountMessage','Account name and email address are required',true); return; }
    const {ok,data}=await fetchJson('/mail/ajax/account_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    if(!ok){ showMessage('accountMessage',(data&&data.error)?data.error:'Failed to save',true); return; }
    showMessage('accountMessage','Saved'); setTimeout(()=>{ window.location.reload(); },600);
  }
  async function onTestAccount(id){
    const {ok,data}=await fetchJson('/mail/ajax/account_test.php?id='+encodeURIComponent(id));
    if(!ok){ showMessage('accountMessage',(data&&data.error)?data.error:'Test failed',true); return; }
    showMessage('accountMessage',(data&&data.message)?data.message:'Test completed',false);
  }
  async function onDeleteAccount(id){
    if(!confirm('Delete this email account? This cannot be undone.')) return;
    const {ok,data}=await fetchJson('/mail/ajax/account_delete.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({account_id:Number(id)})});
    if(!ok){ showMessage('accountMessage',(data&&data.error)?data.error:'Delete failed',true); return; }
    showMessage('accountMessage','Account deleted'); setTimeout(()=>{ window.location.reload(); },600);
  }
  function init(){
    if(!document.getElementById('accountList')) return;
    document.addEventListener('click',(e)=>{
      const btn=e.target.closest('[data-action]'); if(!btn) return;
      const action=btn.getAttribute('data-action');
      if(action==='edit-account'){ const id=btn.getAttribute('data-id'); if(id) onEditAccount(id); }
      else if(action==='new-account'){ onNewAccount(); }
      else if(action==='test-account'){ const id=btn.getAttribute('data-id'); if(id) onTestAccount(id); }
      else if(action==='delete-account'){ const id=btn.getAttribute('data-id'); if(id) onDeleteAccount(id); }
      else if(action==='close-modal'){ const m=document.getElementById('accountModal'); if(m) m.style.display='none'; }
    });
    const form=document.getElementById('accountForm'); if(form) form.addEventListener('submit', onSaveAccount);
  }
  document.addEventListener('DOMContentLoaded', init);
})();
</script>
