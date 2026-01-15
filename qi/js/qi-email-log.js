// Minimal, framework-free email log viewer
(function(){
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function el(tag, attrs={}, children=[]) {
    const e = document.createElement(tag);
    Object.keys(attrs).forEach(k => e.setAttribute(k, attrs[k]));
    children.forEach(c => e.appendChild(typeof c==='string'?document.createTextNode(c):c));
    return e;
  }

  document.addEventListener('click', function(ev){
    const trg = ev.target.closest('[data-email-log]');
    if (!trg) return;
    const type = trg.getAttribute('data-entity-type');
    const id   = trg.getAttribute('data-entity-id');
    const url  = `/qi/ajax/get_email_log.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;

    fetch(url, {headers: {'Accept':'application/json'}})
      .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
      .then(data => {
        const wrap = $('#emailLogBody');
        wrap.innerHTML = '';
        const rows = Array.isArray(data?.rows) ? data.rows : [];
        if (!rows.length) {
          wrap.appendChild(el('p',{},['Geen e-posse gestuur nie.']));
        } else {
          rows.forEach(rw => {
            const card = el('div', {style:'border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin:8px 0; background:#f9fafb;'});
            card.appendChild(el('div', {style:'font-weight:600; margin-bottom:6px;'}, [`${rw.subject||'No subject'}`]));
            card.appendChild(el('div', {style:'font-size:12px; color:#6b7280; margin-bottom:6px;'}, [`Aan: ${rw.to_email||'-'} • ${rw.sent_at||''} • ${rw.status||''}`]));
            if (rw.body_preview) card.appendChild(el('div', {}, [rw.body_preview]));
            wrap.appendChild(card);
          });
        }
        $('#emailLogModal').style.display = 'flex';
      })
      .catch(err => {
        const wrap = $('#emailLogBody');
        wrap.innerHTML = `<p style="color:#ef4444;">Kon nie e-pos log laai nie: ${String(err)}</p>`;
        $('#emailLogModal').style.display = 'flex';
      });
  });

  document.addEventListener('click', function(ev) {
    if (ev.target.id === 'emailLogClose' || ev.target.id === 'emailLogModal') {
      $('#emailLogModal').style.display = 'none';
    }
  });
})();
