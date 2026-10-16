/* =============================================================================
   Flowwork Mail — Settings & Templates pages (uses window.FWMail from mail.js)
   ============================================================================= */
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, fmt, avatar, openModal, closeModal, dialog, Editor } = window.FWMail;
  const LABEL_COLORS = ['#7b1e2b', '#b42318', '#c08a2b', '#6b7d2b', '#2f7d5b', '#1f7a7a', '#2c5f8a', '#4b4fa8', '#7a5ab8', '#b4577a', '#5b6670', '#8a5a3c'];

  // Close the drawer on narrow screens after picking a section.
  $$('#side a').forEach((a) => a.addEventListener('click', () => {
    document.body.classList.remove('drawer-open');
    const s = $('.fwm-scrim'); if (s) s.remove();
  }));

  if ($('#settingsApp')) initSettings();
  if ($('#templatesApp')) initTemplates();

  // ===========================================================================
  // Settings
  // ===========================================================================
  function initSettings() {
    const isAdmin = $('#settingsApp').dataset.admin === '1';
    const S = { accounts: [], signatures: [], labels: [], rules: [], prefs: {} };

    // ---------------------------------------------------------- tabs
    const TABS = ['accounts', 'signatures', 'labels', 'rules', 'preferences'];
    function showTab() {
      const h = location.hash.replace(/^#/, '');
      const [tab, sub] = h.split('/');
      const t = TABS.includes(tab) ? tab : 'accounts';
      $$('.fwm-panel').forEach((p) => p.classList.toggle('is-active', p.dataset.panel === t));
      $$('#settingsNav [data-tab]').forEach((a) => a.classList.toggle('is-active', a.dataset.tab === t));
      document.title = $(`#settingsNav [data-tab="${t}"] .fwm-nav__label`).textContent + ' · Mail settings';
      if (t === 'accounts' && sub === 'new' && S.loaded) { history.replaceState(null, '', '#accounts'); accountDialog(null); }
    }
    window.addEventListener('hashchange', showTab);

    // ---------------------------------------------------------- load
    async function load() {
      try {
        const [b, r] = await Promise.all([api.get('bootstrap.php'), api.get('rule.php')]);
        Object.assign(S, { accounts: b.accounts, signatures: b.signatures, labels: b.labels, prefs: b.prefs, rules: r.rules, loaded: true });
        renderAccounts(); renderSignatures(); renderLabels(); renderRules(); renderPrefs();
        showTab();
      } catch (e) { toastError(e); }
    }
    showTab();
    load();

    // ---------------------------------------------------------- accounts
    const PRESETS = {
      gmail: { label: 'Gmail / Google Workspace', imap: ['imap.gmail.com', 993, 'ssl'], smtp: ['smtp.gmail.com', 587, 'tls'], sent: false,
        hint: 'Google requires an <b>app password</b> (Google Account → Security → 2-Step Verification → App passwords). Your normal password will be rejected.' },
      microsoft: { label: 'Microsoft 365 / Outlook', imap: ['outlook.office365.com', 993, 'ssl'], smtp: ['smtp.office365.com', 587, 'tls'], sent: false,
        hint: 'Your Microsoft 365 admin must allow IMAP and "Authenticated SMTP" for this mailbox. Accounts with MFA need an app password.' },
      yahoo: { label: 'Yahoo', imap: ['imap.mail.yahoo.com', 993, 'ssl'], smtp: ['smtp.mail.yahoo.com', 465, 'ssl'], sent: true,
        hint: 'Yahoo requires an app password (Account security → Generate app password).' },
      icloud: { label: 'iCloud', imap: ['imap.mail.me.com', 993, 'ssl'], smtp: ['smtp.mail.me.com', 587, 'tls'], sent: true,
        hint: 'iCloud requires an app-specific password (appleid.apple.com → Sign-In and Security).' },
      other: { label: 'Company domain (cPanel, Xneelo, other)', imap: null, smtp: null, sent: true,
        hint: 'Most hosts use <b>mail.yourdomain</b> for both servers with SSL on 993 (IMAP) and STARTTLS on 587 (SMTP). Check your host\'s mail client settings if the test fails.' },
    };

    function renderAccounts() {
      const host = $('#accountsList');
      if (!S.accounts.length) {
        host.innerHTML = `<div class="fwm-card"><div class="fwm-empty" style="min-height:220px"><div class="fwm-empty__art">${icon('at')}</div>
          <h3>No accounts yet</h3><p>Connect your work mailbox to read and send email from Flowwork.</p>
          <button class="btn btn--primary" data-add-account>${icon('plus', 'i--sm')}Connect account</button></div></div>`;
        return;
      }
      host.innerHTML = S.accounts.map((a) => {
        const status = !a.is_active ? '<span class="fwm-status fwm-status--off">Inactive</span>'
          : a.last_error ? '<span class="fwm-status fwm-status--err">Needs attention</span>'
          : a.last_sync_at ? `<span class="fwm-status fwm-status--ok">Synced ${esc(fmt.ago(a.last_sync_at))}</span>` : '<span class="fwm-status">Not synced yet</span>';
        const sig = S.signatures.find((s) => s.signature_id === a.default_signature_id);
        return `<div class="fwm-card" data-account="${a.account_id}">
          <div class="fwm-card__head">${avatar(a.display_name || a.account_name, a.email_address, 'fwm-avatar--lg')}
            <div class="fwm-card__title"><h3>${esc(a.account_name)} ${status}</h3><p>${esc(a.display_name ? a.display_name + ' · ' : '')}${esc(a.email_address)}</p></div>
            <div class="fwm-card__actions">
              <button class="btn btn--sm" data-sync="${a.account_id}" ${a.is_active && a.imap_server ? '' : 'disabled'}>${icon('refresh', 'i--sm')}Sync now</button>
              <button class="icon-btn" data-edit-account="${a.account_id}" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>
              <button class="icon-btn" data-del-account="${a.account_id}" aria-label="Remove" data-tip="Remove">${icon('trash')}</button>
            </div></div>
          ${a.last_error ? `<div class="fwm-banner fwm-banner--danger" style="margin:14px 0 0">${icon('alert')}<span class="grow">${esc(a.last_error)}</span><button class="btn btn--sm" data-edit-account="${a.account_id}">Fix settings</button></div>` : ''}
          <div class="fwm-card__body hint" style="display:flex;gap:22px;flex-wrap:wrap">
            <span>${icon('inbox', 'i--xs')} ${esc(a.imap_server || '—')}${a.imap_server ? ':' + a.imap_port : ''}</span>
            <span>${icon('send', 'i--xs')} ${esc(a.smtp_server || '—')}${a.smtp_server ? ':' + a.smtp_port : ''}</span>
            <span>${icon('signature', 'i--xs')} ${sig ? esc(sig.name) : 'Default signature'}</span>
          </div></div>`;
      }).join('');
    }

    $('#addAccount').addEventListener('click', () => accountDialog(null));
    $('#accountsList').addEventListener('click', async (e) => {
      if (e.target.closest('[data-add-account]')) return accountDialog(null);
      const ed = e.target.closest('[data-edit-account]');
      if (ed) return accountDialog(S.accounts.find((a) => a.account_id === Number(ed.dataset.editAccount)));
      const del = e.target.closest('[data-del-account]');
      if (del) {
        const a = S.accounts.find((x) => x.account_id === Number(del.dataset.delAccount));
        const ok = await dialog({ title: `Remove ${a.email_address}?`, body: 'Its synced mail is removed from Flowwork. Nothing is deleted from your mail server.', confirm: 'Remove account', danger: true });
        if (!ok) return;
        try { await api.post('account.php', { op: 'delete', account_id: a.account_id }); S.accounts = S.accounts.filter((x) => x !== a); renderAccounts(); toast('Account removed'); }
        catch (err) { toastError(err); }
        return;
      }
      const sy = e.target.closest('[data-sync]');
      if (sy) {
        sy.classList.add('is-busy');
        try {
          const r = await api.post('sync.php', { force: true, account_id: Number(sy.dataset.sync) });
          r.accounts.forEach((x) => { const a = S.accounts.find((y) => y.account_id === x.account_id); if (a) Object.assign(a, x); });
          renderAccounts();
          if (r.errors.length) toast(r.errors[0].error, { type: 'error' });
          else toast(r.new ? `${r.new} new message${r.new === 1 ? '' : 's'}` : 'Up to date');
        } catch (err) { toastError(err); sy.classList.remove('is-busy'); }
      }
    });

    function accountDialog(acc) {
      const f = $('#accountForm');
      f.reset();
      $('#testResult').innerHTML = '';
      $('#accountTitle').textContent = acc ? 'Edit account' : 'Connect an account';
      $('#acSig').innerHTML = '<option value="">Use my default signature</option>' + S.signatures.map((s) => `<option value="${s.signature_id}">${esc(s.name)}</option>`).join('');
      $('#presets').innerHTML = Object.entries(PRESETS).map(([k, p]) => `<button type="button" class="fwm-preset" data-preset="${k}">${esc(p.label)}</button>`).join('');
      $('#presetHint').innerHTML = '';
      $('#presets').hidden = !!acc;
      $('#serverDetails').open = !!acc;
      if (acc) {
        f.account_id.value = acc.account_id;
        ['account_name', 'display_name', 'email_address', 'imap_server', 'imap_port', 'imap_encryption', 'smtp_server', 'smtp_port', 'smtp_encryption', 'username'].forEach((k) => { if (f[k]) f[k].value = acc[k] == null ? '' : acc[k]; });
        f.default_signature_id.value = acc.default_signature_id || '';
        f.tls_verify.checked = acc.tls_verify; f.save_sent_copy.checked = acc.save_sent_copy; f.is_active.checked = acc.is_active;
        f.password.placeholder = acc.has_password ? '•••••••• (unchanged)' : 'Mailbox or app password';
      } else {
        f.account_id.value = '';
        f.password.placeholder = 'Mailbox or app password';
        f.imap_port.value = 993; f.smtp_port.value = 587;
        f.display_name.value = '';
      }
      openModal('accountModal');
    }
    $('#presets').addEventListener('click', (e) => {
      const b = e.target.closest('[data-preset]');
      if (!b) return;
      const f = $('#accountForm');
      const p = PRESETS[b.dataset.preset];
      $$('.fwm-preset').forEach((x) => x.classList.toggle('is-on', x === b));
      $('#presetHint').innerHTML = p.hint;
      if (p.imap) {
        [f.imap_server.value, f.imap_port.value, f.imap_encryption.value] = p.imap;
        [f.smtp_server.value, f.smtp_port.value, f.smtp_encryption.value] = p.smtp;
      } else {
        const dom = (f.email_address.value.split('@')[1] || '').trim();
        f.imap_server.value = dom ? 'mail.' + dom : ''; f.imap_port.value = 993; f.imap_encryption.value = 'ssl';
        f.smtp_server.value = dom ? 'mail.' + dom : ''; f.smtp_port.value = 587; f.smtp_encryption.value = 'tls';
        $('#serverDetails').open = true;
      }
      f.save_sent_copy.checked = p.sent;
      f.email_address.focus();
    });
    // Fill "mail.<domain>" when the user types the address after choosing "Other".
    $('#acEmail').addEventListener('change', () => {
      const f = $('#accountForm');
      const dom = (f.email_address.value.split('@')[1] || '').trim();
      if (!dom) return;
      if (!f.imap_server.value) f.imap_server.value = 'mail.' + dom;
      if (!f.smtp_server.value) f.smtp_server.value = 'mail.' + dom;
      if (!f.account_name.value) f.account_name.value = dom;
    });
    // Ports follow the security choice.
    $('#acImapEnc').addEventListener('change', (e) => { const f = $('#accountForm'); f.imap_port.value = e.target.value === 'ssl' ? 993 : 143; });
    $('#acSmtpEnc').addEventListener('change', (e) => { const f = $('#accountForm'); f.smtp_port.value = e.target.value === 'ssl' ? 465 : e.target.value === 'tls' ? 587 : 25; });

    function formData() {
      const f = $('#accountForm');
      const d = {};
      ['account_id', 'account_name', 'display_name', 'email_address', 'password', 'imap_server', 'imap_port', 'imap_encryption', 'smtp_server', 'smtp_port', 'smtp_encryption', 'username', 'default_signature_id']
        .forEach((k) => { d[k] = f[k].value.trim(); });
      d.tls_verify = f.tls_verify.checked; d.save_sent_copy = f.save_sent_copy.checked; d.is_active = f.is_active.checked;
      return d;
    }
    function showTest(r) {
      const line = (label, x) => !x ? '' : `<div class="fwm-test-line ${x.ok ? 'is-ok' : 'is-err'}">${icon(x.ok ? 'check' : 'alert')}<div><b>${label}</b> — ${x.ok ? 'connected' + (x.folders != null ? ` (${x.folders} folders)` : '') : esc(x.error)}</div></div>`;
      $('#testResult').innerHTML = line('Incoming (IMAP)', r.imap) + line('Outgoing (SMTP)', r.smtp);
    }
    $('#testAccount').addEventListener('click', async (e) => {
      const b = e.currentTarget;
      const d = formData();
      if (!d.email_address) { toast('Enter the email address first', { type: 'error' }); return; }
      b.classList.add('is-busy');
      $('#testResult').innerHTML = `<div class="fwm-test-line">${icon('refresh')}<div>Connecting…</div></div>`;
      try { showTest(await api.post('account.php', Object.assign({ op: 'test' }, d))); }
      catch (err) { $('#testResult').innerHTML = ''; toastError(err); }
      finally { b.classList.remove('is-busy'); }
    });
    $('#accountForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = formData();
      const isNew = !d.account_id;
      const btn = $('#saveAccount');
      btn.classList.add('is-busy');
      try {
        const r = await api.post('account.php', Object.assign({ op: 'save' }, d));
        const i = S.accounts.findIndex((a) => a.account_id === r.account.account_id);
        if (i >= 0) S.accounts[i] = r.account; else S.accounts.push(r.account);
        renderAccounts();
        closeModal('accountModal');
        if (isNew && r.account.imap_server) {
          const t = toast('Connected. Importing your recent mail…', { timeout: 0 });
          api.post('sync.php', { force: true, account_id: r.account.account_id })
            .then((s) => {
              t.close();
              s.accounts.forEach((x) => { const a = S.accounts.find((y) => y.account_id === x.account_id); if (a) Object.assign(a, x); });
              renderAccounts();
              if (s.errors.length) toast(s.errors[0].error, { type: 'error' });
              else toast(`Imported ${s.new} message${s.new === 1 ? '' : 's'}`, { action: { label: 'Open inbox', run: () => { location.href = '/mail/'; } } });
            }).catch((err) => { t.close(); toastError(err); });
        } else toast('Account saved');
      } catch (err) { toastError(err); }
      finally { btn.classList.remove('is-busy'); }
    });

    // ---------------------------------------------------------- signatures
    let sigEditor = null;
    let sigEditing = null;
    function renderSignatures() {
      const host = $('#signaturesList');
      if (!S.signatures.length) {
        host.innerHTML = `<div class="fwm-card"><div class="fwm-empty" style="min-height:200px"><div class="fwm-empty__art">${icon('signature')}</div><h3>No signatures</h3><p>Create a signature with your name, title and contact details.</p></div></div>`;
        return;
      }
      host.innerHTML = S.signatures.map((s) => `<div class="fwm-card">
        <div class="fwm-card__head"><div class="fwm-card__title"><h3>${esc(s.name)} ${s.is_default ? '<span class="fwm-badge fwm-badge--accent">Default</span>' : ''} ${s.shared ? '<span class="fwm-badge">Company</span>' : ''}</h3></div>
          <div class="fwm-card__actions">${s.shared && !isAdmin ? '' : `<button class="icon-btn" data-edit-sig="${s.signature_id}" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>
          <button class="icon-btn" data-del-sig="${s.signature_id}" aria-label="Delete" data-tip="Delete">${icon('trash')}</button>`}</div></div>
        <div class="fwm-sig-preview">${s.content_html}</div></div>`).join('');
    }
    function signatureDialog(sig) {
      sigEditing = sig;
      $('#signatureTitle').textContent = sig ? 'Edit signature' : 'New signature';
      $('#sigName').value = sig ? sig.name : '';
      $('#sigDefault').checked = sig ? sig.is_default : !S.signatures.length;
      $('#sigSharedWrap').hidden = !isAdmin;
      $('#sigShared').checked = sig ? sig.shared : false;
      sigEditor = Editor.create($('#sigEditor'), { boxed: true, html: sig ? sig.content_html : '<p><b>' + esc('Your Name') + '</b><br>Title · Company<br>+27 00 000 0000</p>', placeholder: 'Your name, title, phone…' });
      openModal('signatureModal');
    }
    $('#addSignature').addEventListener('click', () => signatureDialog(null));
    $('#signaturesList').addEventListener('click', async (e) => {
      const ed = e.target.closest('[data-edit-sig]');
      if (ed) return signatureDialog(S.signatures.find((s) => s.signature_id === Number(ed.dataset.editSig)));
      const del = e.target.closest('[data-del-sig]');
      if (del && await dialog({ title: 'Delete this signature?', confirm: 'Delete', danger: true })) {
        try { const r = await api.post('signature.php', { op: 'delete', signature_id: Number(del.dataset.delSig) }); S.signatures = r.signatures; renderSignatures(); renderAccounts(); toast('Signature deleted'); }
        catch (err) { toastError(err); }
      }
    });
    $('#saveSignature').addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.classList.add('is-busy');
      try {
        const r = await api.post('signature.php', {
          op: 'save', signature_id: sigEditing ? sigEditing.signature_id : null, name: $('#sigName').value.trim(),
          content_html: sigEditor.getHTML(), is_default: $('#sigDefault').checked, shared: $('#sigShared').checked,
        });
        S.signatures = r.signatures; renderSignatures(); closeModal('signatureModal'); toast('Signature saved');
      } catch (err) { toastError(err); }
      finally { b.classList.remove('is-busy'); }
    });

    // ---------------------------------------------------------- labels
    let labelEditing = null;
    let labelColor = LABEL_COLORS[0];
    function renderLabels() {
      const host = $('#labelsList');
      if (!S.labels.length) { host.innerHTML = `<div class="fwm-card"><p class="hint">No labels yet.</p></div>`; return; }
      host.innerHTML = `<div class="fwm-card" style="padding:6px 8px">` + S.labels.map((l) => `<div class="fwm-card__head" style="padding:8px 8px;border-bottom:1px solid var(--line)">
          <span class="fwm-nav__dot" style="background:${esc(l.color)}"></span>
          <div class="fwm-card__title"><h3 style="font-weight:500">${esc(l.name)} ${l.global ? '<span class="fwm-badge">Built-in</span>' : ''}</h3></div>
          <div class="fwm-card__actions">${l.global ? '' : `<button class="icon-btn" data-edit-label="${l.tag_id}" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>
          <button class="icon-btn" data-del-label="${l.tag_id}" aria-label="Delete" data-tip="Delete">${icon('trash')}</button>`}</div></div>`).join('') + '</div>';
    }
    function labelDialog(l) {
      labelEditing = l;
      labelColor = l ? l.color : LABEL_COLORS[S.labels.length % LABEL_COLORS.length];
      $('#labelTitle').textContent = l ? 'Edit label' : 'New label';
      $('#labelName').value = l ? l.name : '';
      paintSwatches();
      openModal('labelModal');
    }
    function paintSwatches() {
      $('#labelSwatches').innerHTML = LABEL_COLORS.map((c) => `<button type="button" class="fwm-swatch ${c.toLowerCase() === String(labelColor).toLowerCase() ? 'is-on' : ''}" style="background:${c}" data-c="${c}" aria-label="Colour ${c}"></button>`).join('');
    }
    $('#labelSwatches').addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (b) { labelColor = b.dataset.c; paintSwatches(); } });
    $('#addLabel').addEventListener('click', () => labelDialog(null));
    $('#labelsList').addEventListener('click', async (e) => {
      const ed = e.target.closest('[data-edit-label]');
      if (ed) return labelDialog(S.labels.find((l) => l.tag_id === Number(ed.dataset.editLabel)));
      const del = e.target.closest('[data-del-label]');
      if (del && await dialog({ title: 'Delete this label?', body: 'It is removed from every conversation. The conversations themselves are not deleted.', confirm: 'Delete label', danger: true })) {
        try { const r = await api.post('label.php', { op: 'delete', tag_id: Number(del.dataset.delLabel) }); S.labels = r.labels; renderLabels(); toast('Label deleted'); }
        catch (err) { toastError(err); }
      }
    });
    $('#labelSave').addEventListener('click', async () => {
      const name = $('#labelName').value.trim();
      if (!name) { $('#labelName').focus(); return; }
      try {
        const r = await api.post('label.php', labelEditing ? { op: 'update', tag_id: labelEditing.tag_id, name, color: labelColor } : { op: 'create', name, color: labelColor });
        S.labels = r.labels; renderLabels(); closeModal('labelModal'); toast('Label saved');
      } catch (err) { toastError(err); }
    });
    $('#labelName').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#labelSave').click(); } });

    // ---------------------------------------------------------- rules
    const FIELDS = [['from', 'From'], ['to', 'To / Cc'], ['subject', 'Subject'], ['body', 'Message text'], ['any', 'Anywhere']];
    const OPS = [['contains', 'contains'], ['not_contains', 'doesn\'t contain'], ['equals', 'is exactly'], ['starts_with', 'starts with'], ['ends_with', 'ends with']];
    const ACTIONS = [['label', 'Apply label'], ['star', 'Star it'], ['mark_read', 'Mark as read'], ['move', 'Move to']];
    let ruleEditing = null;
    let ruleMatch = 'all';
    const opt = (list, v) => list.map(([k, l]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${esc(l)}</option>`).join('');

    function ruleSummary(r) {
      const f = Object.fromEntries(FIELDS); const o = Object.fromEntries(OPS);
      const cond = r.items.map((i) => `<b>${esc(f[i.field] || i.field)}</b> ${esc(o[i.operator] || i.operator)} “${esc(i.value)}”`).join(r.match === 'any' ? ' or ' : ' and ');
      const acts = r.actions.map((a) => {
        if (a.type === 'label') { const l = S.labels.find((x) => String(x.tag_id) === a.value || x.name === a.value); return 'label <b>' + esc(l ? l.name : a.value) + '</b>'; }
        if (a.type === 'move') return 'move to <b>' + esc(a.value) + '</b>';
        return a.type === 'star' ? 'star' : 'mark read';
      }).join(', ');
      return `When ${cond || '…'} → ${acts || '…'}`;
    }
    function renderRules() {
      const host = $('#rulesList');
      if (!S.rules.length) {
        host.innerHTML = `<div class="fwm-card"><div class="fwm-empty" style="min-height:200px"><div class="fwm-empty__art">${icon('filter')}</div><h3>No rules yet</h3><p>For example: label everything from <b>@supplier.co.za</b> as “Suppliers”, or archive newsletters automatically.</p></div></div>`;
        return;
      }
      host.innerHTML = S.rules.map((r) => `<div class="fwm-card">
        <div class="fwm-card__head">
          <label class="switch" style="padding:0" title="${r.is_active ? 'Active' : 'Paused'}"><input type="checkbox" data-toggle-rule="${r.rule_id}" ${r.is_active ? 'checked' : ''} ${r.shared && !isAdmin ? 'disabled' : ''}><span class="switch__track"></span></label>
          <div class="fwm-card__title"><h3>${esc(r.name)} ${r.shared ? '<span class="fwm-badge">Company</span>' : ''} ${r.stop_processing ? '<span class="fwm-badge">Stops others</span>' : ''}</h3>
            <div class="fwm-rule-summary">${ruleSummary(r)}</div></div>
          <div class="fwm-card__actions">
            <button class="icon-btn" data-run-rule="${r.rule_id}" aria-label="Run on inbox" data-tip="Run on inbox">${icon('play')}</button>
            ${r.shared && !isAdmin ? '' : `<button class="icon-btn" data-edit-rule="${r.rule_id}" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>
            <button class="icon-btn" data-del-rule="${r.rule_id}" aria-label="Delete" data-tip="Delete">${icon('trash')}</button>`}</div></div></div>`).join('');
    }
    function condLine(c = {}) {
      return `<div class="fwm-rule-line" data-cond>
        <select class="select" data-f>${opt(FIELDS, c.field || 'from')}</select>
        <select class="select" data-o>${opt(OPS, c.operator || 'contains')}</select>
        <input class="input" data-v placeholder="e.g. @supplier.co.za" value="${esc(c.value || '')}">
        <button class="icon-btn" type="button" data-rm aria-label="Remove condition">${icon('x')}</button></div>`;
    }
    function actionValue(type, value) {
      if (type === 'label') return `<select class="select" data-v><option value="">Choose a label…</option>${S.labels.map((l) => `<option value="${l.tag_id}" ${String(l.tag_id) === String(value) || l.name === value ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>`;
      if (type === 'move') return `<select class="select" data-v>${['Archive', 'Spam', 'Trash'].map((f) => `<option ${f === value ? 'selected' : ''}>${f}</option>`).join('')}</select>`;
      return '<span class="hint">—</span>';
    }
    function actionLine(a = {}) {
      const type = a.type || 'label';
      return `<div class="fwm-rule-line fwm-rule-line--action" data-act>
        <select class="select" data-t>${opt(ACTIONS, type)}</select>
        <span data-vwrap>${actionValue(type, a.value)}</span>
        <button class="icon-btn" type="button" data-rm aria-label="Remove action">${icon('x')}</button></div>`;
    }
    function ruleDialog(r) {
      ruleEditing = r;
      ruleMatch = r ? r.match : 'all';
      $('#ruleTitle').textContent = r ? 'Edit rule' : 'New rule';
      $('#ruleName').value = r ? r.name : '';
      $('#rulePriority').value = r ? r.priority : 100;
      $('#ruleStop').checked = r ? r.stop_processing : false;
      $('#ruleActive').checked = r ? r.is_active : true;
      $$('#ruleMatch button').forEach((b) => b.classList.toggle('is-on', b.dataset.m === ruleMatch));
      $('#ruleConditions').innerHTML = (r && r.items.length ? r.items : [{}]).map(condLine).join('');
      $('#ruleActions').innerHTML = (r && r.actions.length ? r.actions : [{}]).map(actionLine).join('');
      openModal('ruleModal');
    }
    $('#ruleMatch').addEventListener('click', (e) => { const b = e.target.closest('[data-m]'); if (!b) return; ruleMatch = b.dataset.m; $$('#ruleMatch button').forEach((x) => x.classList.toggle('is-on', x === b)); });
    $('#addCondition').addEventListener('click', () => $('#ruleConditions').insertAdjacentHTML('beforeend', condLine()));
    $('#addAction').addEventListener('click', () => $('#ruleActions').insertAdjacentHTML('beforeend', actionLine({ type: 'star' })));
    $('#ruleModal').addEventListener('click', (e) => { const rm = e.target.closest('[data-rm]'); if (rm) rm.parentElement.remove(); });
    $('#ruleActions').addEventListener('change', (e) => {
      if (!e.target.matches('[data-t]')) return;
      $('[data-vwrap]', e.target.parentElement).innerHTML = actionValue(e.target.value, '');
    });
    $('#addRule').addEventListener('click', () => ruleDialog(null));
    $('#saveRule').addEventListener('click', async (e) => {
      const b = e.currentTarget;
      const items = $$('#ruleConditions [data-cond]').map((l) => ({ field: $('[data-f]', l).value, operator: $('[data-o]', l).value, value: $('[data-v]', l).value.trim() })).filter((i) => i.value);
      const actions = $$('#ruleActions [data-act]').map((l) => ({ type: $('[data-t]', l).value, value: ($('[data-v]', l) || {}).value || '' }));
      b.classList.add('is-busy');
      try {
        const r = await api.post('rule.php', {
          op: 'save', rule_id: ruleEditing ? ruleEditing.rule_id : null, name: $('#ruleName').value.trim(), priority: Number($('#rulePriority').value) || 100,
          match: ruleMatch, items, actions, stop_processing: $('#ruleStop').checked, is_active: $('#ruleActive').checked,
        });
        S.rules = r.rules; renderRules(); closeModal('ruleModal'); toast('Rule saved — it applies to new mail', { action: { label: 'Run on inbox now', run: () => runRules(null) } });
      } catch (err) { toastError(err); }
      finally { b.classList.remove('is-busy'); }
    });
    async function runRules(ruleId) {
      const t = toast('Applying rules to the last 30 days of your inbox…', { timeout: 0 });
      try { const r = await api.post('rule.php', { op: 'run', rule_id: ruleId }); t.close(); toast(r.matched ? `Applied to ${r.matched} message${r.matched === 1 ? '' : 's'}` : 'No messages matched'); }
      catch (err) { t.close(); toastError(err); }
    }
    $('#runRules').addEventListener('click', () => runRules(null));
    $('#rulesList').addEventListener('click', async (e) => {
      const ed = e.target.closest('[data-edit-rule]');
      if (ed) return ruleDialog(S.rules.find((r) => r.rule_id === Number(ed.dataset.editRule)));
      const run = e.target.closest('[data-run-rule]');
      if (run) return runRules(Number(run.dataset.runRule));
      const del = e.target.closest('[data-del-rule]');
      if (del && await dialog({ title: 'Delete this rule?', confirm: 'Delete', danger: true })) {
        try { const r = await api.post('rule.php', { op: 'delete', rule_id: Number(del.dataset.delRule) }); S.rules = r.rules; renderRules(); toast('Rule deleted'); }
        catch (err) { toastError(err); }
      }
    });
    $('#rulesList').addEventListener('change', async (e) => {
      const t = e.target.closest('[data-toggle-rule]');
      if (!t) return;
      try { const r = await api.post('rule.php', { op: 'toggle', rule_id: Number(t.dataset.toggleRule), is_active: t.checked }); S.rules = r.rules; renderRules(); toast(t.checked ? 'Rule on' : 'Rule paused'); }
      catch (err) { toastError(err); t.checked = !t.checked; }
    });

    // ---------------------------------------------------------- preferences
    function renderPrefs() {
      const p = S.prefs;
      const seg = (name, opts, val) => `<span class="seg" data-pref="${name}">${opts.map(([v, l]) => `<button type="button" data-val="${v}" class="${String(v) === String(val) ? 'is-on' : ''}">${esc(l)}</button>`).join('')}</span>`;
      const sw = (name, title, hint, on) => `<label class="switch"><input type="checkbox" data-pref="${name}" ${on ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${title}</span>${hint ? `<span class="hint">${hint}</span>` : ''}</span></label>`;
      const row = (title, hint, control) => `<div class="fwm-card__head" style="padding:12px 0;border-bottom:1px solid var(--line)"><div class="fwm-card__title"><h3 style="font-weight:500;font-size:14px">${title}</h3>${hint ? `<p>${hint}</p>` : ''}</div>${control}</div>`;
      $('#prefsForm').innerHTML = `
        <div class="fwm-card"><h3 class="fwm-section-title" style="margin-top:0">Reading</h3>
          ${row('Reading pane', 'Show conversations beside the list, or full width.', seg('reading_pane', [['right', 'Beside list'], ['off', 'Full width']], p.reading_pane))}
          ${row('List density', '', seg('density', [['comfortable', 'Comfortable'], ['compact', 'Compact']], p.density))}
          ${sw('show_snippets', 'Show message previews in the list', '', p.show_snippets !== false)}
          ${sw('block_external_images', 'Ask before showing remote images', 'Stops senders from tracking when you open their email. You can always show images per message.', p.block_external_images)}
        </div>
        <div class="fwm-card"><h3 class="fwm-section-title" style="margin-top:0">Sending</h3>
          ${row('Undo send', 'A short window to take a message back after pressing Send.', seg('undo_send_seconds', [[0, 'Off'], [5, '5s'], [10, '10s'], [20, '20s'], [30, '30s']], p.undo_send_seconds))}
          ${row('Default reply', 'Used by the reply button on each message.', seg('default_reply', [['reply', 'Reply'], ['reply_all', 'Reply all']], p.default_reply))}
        </div>
        <div class="fwm-card"><h3 class="fwm-section-title" style="margin-top:0">New mail</h3>
          ${row('Check for new mail', 'While Mail is open. A background job can also sync every few minutes.', `<select class="select" data-pref="sync_minutes" style="width:auto">${[1, 3, 5, 10, 15, 30].map((m) => `<option value="${m}" ${Number(p.sync_minutes) === m ? 'selected' : ''}>Every ${m} min</option>`).join('')}</select>`)}
          ${sw('notification_enabled', 'Flowwork notifications for new mail', 'Shows in the Flowwork notification bell and on your phone (Flowwork app).', p.notification_enabled)}
          ${sw('smart_labels', 'Smart labels', 'Automatically label RFQs, invoice queries and proofs of payment.', p.smart_labels)}
        </div>
        <div class="fwm-card"><h3 class="fwm-section-title" style="margin-top:0">Reply reminders</h3>
          ${sw('sla_enabled', 'Remind me about unanswered mail', 'Conversations waiting on your reply get a “Reply by” tag, and an overdue reminder.', p.sla_enabled)}
          ${row('Reply within', '', `<span style="display:flex;align-items:center;gap:8px"><input class="input" type="number" min="1" max="168" data-pref="sla_default_hours" value="${p.sla_default_hours}" style="width:90px"><span class="hint">hours</span></span>`)}
        </div>`;
    }
    const savePref = window.FWMail.debounce(async (patch) => {
      try { const r = await api.post('prefs.php', patch); S.prefs = r.prefs; toast('Preferences saved', { timeout: 1800 }); }
      catch (err) { toastError(err); }
    }, 300);
    const pending = {};
    const queue = (k, v) => { pending[k] = v; savePref(Object.assign({}, pending)); };
    $('#prefsForm').addEventListener('click', (e) => {
      const b = e.target.closest('.seg [data-val]');
      if (!b) return;
      const seg = b.closest('[data-pref]');
      $$('button', seg).forEach((x) => x.classList.toggle('is-on', x === b));
      const v = /^\d+$/.test(b.dataset.val) ? Number(b.dataset.val) : b.dataset.val;
      queue(seg.dataset.pref, v);
    });
    $('#prefsForm').addEventListener('change', (e) => {
      const t = e.target.closest('[data-pref]');
      if (!t || t.classList.contains('seg')) return;
      queue(t.dataset.pref, t.type === 'checkbox' ? t.checked : Number(t.value));
    });
  }

  // ===========================================================================
  // Templates
  // ===========================================================================
  function initTemplates() {
    let templates = [];
    try { templates = JSON.parse($('#tplData').textContent || '[]'); } catch (e) { templates = []; }
    let editing = null;
    let editor = null;
    let category = '';
    const PLACEHOLDERS = ['{first_name}', '{last_name}', '{full_name}', '{company_name}', '{email}', '{my_name}', '{my_first_name}', '{my_company}', '{my_email}', '{date}'];

    function renderCats() {
      const cats = Array.from(new Set(templates.map((t) => t.category).filter(Boolean))).sort();
      $('#tplCategories').innerHTML = `<a class="fwm-nav__item ${!category ? 'is-active' : ''}" href="#" data-cat="">${icon('template')}<span class="fwm-nav__label">All templates</span><span class="fwm-nav__count">${templates.length}</span></a>`
        + cats.map((c) => `<a class="fwm-nav__item ${category === c ? 'is-active' : ''}" href="#" data-cat="${esc(c)}">${icon('tag')}<span class="fwm-nav__label">${esc(c)}</span><span class="fwm-nav__count">${templates.filter((t) => t.category === c).length}</span></a>`).join('');
      $('#tplCategoryList').innerHTML = cats.map((c) => `<option value="${esc(c)}">`).join('');
    }
    function render() {
      renderCats();
      const list = templates.filter((t) => !category || t.category === category);
      $('#tplGrid').innerHTML = list.length ? list.map((t) => `<article class="fwm-tpl">
          <div>${t.category ? `<span class="fwm-badge">${esc(t.category)}</span>` : '<span class="fwm-badge" style="opacity:.6">General</span>'}</div>
          <h3>${esc(t.name)}</h3>
          <div class="fwm-tpl__subject">${t.subject ? esc(t.subject) : '<i>No subject</i>'}</div>
          <div class="fwm-tpl__preview">${esc(t.preview)}</div>
          <div class="fwm-tpl__foot">
            <a class="btn btn--sm btn--primary" href="/mail/?compose=new&template_id=${t.template_id}">${icon('send', 'i--sm')}Use</a>
            <span class="grow"></span>
            <button class="icon-btn" data-edit="${t.template_id}" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>
            <button class="icon-btn" data-del="${t.template_id}" aria-label="Delete" data-tip="Delete">${icon('trash')}</button>
          </div></article>`).join('')
        : `<div class="fwm-card" style="grid-column:1/-1"><div class="fwm-empty" style="min-height:260px"><div class="fwm-empty__art">${icon('template')}</div>
            <h3>No templates yet</h3><p>Save replies you send often — quotes, follow-ups, statements — and insert them in one click.</p>
            <button class="btn btn--primary" data-new>${icon('plus', 'i--sm')}New template</button></div></div>`;
    }
    async function openEditor(id) {
      editing = id ? templates.find((t) => t.template_id === id) : null;
      $('#tplTitle').textContent = editing ? 'Edit template' : 'New template';
      $('#tplName').value = editing ? editing.name : '';
      $('#tplCategory').value = editing ? editing.category : category;
      $('#tplSubject').value = editing ? editing.subject : '';
      let html = '';
      if (editing) {
        try { const r = await api.get('template.php', { id }); html = r.template.body_html; } catch (e) { toastError(e); return; }
      }
      editor = Editor.create($('#tplEditor'), { boxed: true, html, placeholder: 'Hi {first_name},' });
      $('#tplPlaceholders').innerHTML = PLACEHOLDERS.map((p) => `<button type="button" data-ph="${p}">${p}</button>`).join('');
      openModal('templateModal');
    }
    $('#tplPlaceholders').addEventListener('mousedown', (e) => { if (e.target.closest('[data-ph]')) e.preventDefault(); });
    $('#tplPlaceholders').addEventListener('click', (e) => {
      const b = e.target.closest('[data-ph]');
      if (!b) return;
      if (document.activeElement === $('#tplSubject')) {
        const inp = $('#tplSubject'); const s = inp.selectionStart || inp.value.length;
        inp.value = inp.value.slice(0, s) + b.dataset.ph + inp.value.slice(inp.selectionEnd || s);
      } else editor.insertHTML(esc(b.dataset.ph));
    });
    $('#addTemplate').addEventListener('click', () => openEditor(null));
    $('#tplCategories').addEventListener('click', (e) => { const a = e.target.closest('[data-cat]'); if (a) { e.preventDefault(); category = a.dataset.cat; render(); } });
    $('#tplGrid').addEventListener('click', async (e) => {
      if (e.target.closest('[data-new]')) return openEditor(null);
      const ed = e.target.closest('[data-edit]');
      if (ed) return openEditor(Number(ed.dataset.edit));
      const del = e.target.closest('[data-del]');
      if (del) {
        const t = templates.find((x) => x.template_id === Number(del.dataset.del));
        if (!await dialog({ title: `Delete “${t.name}”?`, body: 'Everyone in your company loses access to this template.', confirm: 'Delete', danger: true })) return;
        try { await api.post('template.php', { op: 'delete', template_id: t.template_id }); templates = templates.filter((x) => x !== t); render(); toast('Template deleted'); }
        catch (err) { toastError(err); }
      }
    });
    $('#saveTemplate').addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.classList.add('is-busy');
      const payload = { op: 'save', template_id: editing ? editing.template_id : null, name: $('#tplName').value.trim(), category: $('#tplCategory').value.trim(), subject: $('#tplSubject').value.trim(), body_html: editor.getHTML() };
      try {
        const r = await api.post('template.php', payload);
        const preview = (editor.area.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 220);
        const row = { template_id: r.template_id, name: payload.name, category: payload.category, subject: payload.subject, preview };
        const i = templates.findIndex((t) => t.template_id === r.template_id);
        if (i >= 0) templates[i] = row; else templates.push(row);
        templates.sort((a, c) => (a.category || '').localeCompare(c.category || '') || a.name.localeCompare(c.name));
        render(); closeModal('templateModal'); toast('Template saved');
      } catch (err) { toastError(err); }
      finally { b.classList.remove('is-busy'); }
    });
    render();
  }
})();
