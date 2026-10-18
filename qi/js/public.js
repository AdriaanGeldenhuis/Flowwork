// /qi/js/public.js — the customer pages: fit the paper to the screen, show the
// decline form, copy bank details. Everything also works without it.
(function () {
  'use strict';
  const paper = document.getElementById('pubPaper');
  const inner = document.getElementById('pubInner');
  function fit() {
    if (!paper || !inner) return;
    const doc = inner.firstElementChild;
    if (!doc) return;
    inner.style.transform = '';
    const w = doc.offsetWidth;
    const avail = paper.clientWidth;
    const z = Math.min(1, avail / w);
    inner.style.transform = 'scale(' + z + ')';
    inner.style.width = w + 'px';
    paper.style.height = Math.ceil(doc.offsetHeight * z) + 'px';
  }
  window.addEventListener('resize', fit);
  window.addEventListener('load', fit);
  fit();

  const accept = document.getElementById('acceptForm');
  const decline = document.getElementById('declineForm');
  const toggle = document.getElementById('declineToggle');
  const back = document.getElementById('declineCancel');
  if (toggle && accept && decline) {
    toggle.addEventListener('click', () => { accept.hidden = true; decline.hidden = false; decline.querySelector('textarea').focus(); });
    back.addEventListener('click', () => { decline.hidden = true; accept.hidden = false; });
  }
  [accept, decline].forEach((f) => {
    if (!f) return;
    f.addEventListener('submit', () => { const b = f.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.style.opacity = '0.7'; } });
  });

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    try { await navigator.clipboard.writeText(b.dataset.copy); b.classList.add('is-done'); setTimeout(() => b.classList.remove('is-done'), 1400); } catch (x) { /* no clipboard */ }
  });
})();
