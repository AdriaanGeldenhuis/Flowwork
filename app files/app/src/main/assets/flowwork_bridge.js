// Injected by the FlowWork Android app into FlowWork / FlowDrive pages, before
// the page's own scripts run (WebShellActivity). It talks to the app through
// window.FlowWorkNative, which Android only exposes to those origins.
//
//  - Saves files the page builds itself (blob: / data: links such as CSV and
//    JSON exports). The WebView cannot hand those to Android's downloader.
//  - Makes window.print() open Android's print dialog (Save as PDF / printers).
//  - Reports the colour at the top and bottom of the page so the status and
//    navigation bars match it, including after a light/dark theme switch.
//  - window.FlowWorkApp.request(type) lets pages ask the app for its
//    notification status ("status"), to show the permission prompt
//    ("askNotifications") or to open notification settings
//    ("openNotificationSettings"). Replies arrive as a "flowwork-native"
//    window event whose detail is the reply object.
(function () {
  'use strict';
  if (window.__fwNative || window.top !== window) return;
  var bridge = window.FlowWorkNative;
  if (!bridge || typeof bridge.postMessage !== 'function') return;

  function post(msg) {
    try { bridge.postMessage(JSON.stringify(msg)); } catch (e) { /* app gone */ }
  }

  bridge.onmessage = function (event) {
    var detail = null;
    try { detail = JSON.parse(event.data); } catch (e) { return; }
    try { window.dispatchEvent(new CustomEvent('flowwork-native', { detail: detail })); } catch (e) { /* old engine */ }
  };

  window.FlowWorkApp = Object.freeze({
    request: function (type) { post({ type: String(type || '') }); },
  });

  // ------------------------------------------------------------ downloads
  // Keep the Blob behind each object URL: pages often revoke the URL right
  // after clicking it, before the file could be read.
  var blobs = new Map();
  var createObjectURL = URL.createObjectURL;
  URL.createObjectURL = function (obj) {
    var url = createObjectURL.apply(URL, arguments);
    try {
      if (obj instanceof Blob) {
        blobs.set(url, obj);
        if (blobs.size > 20) blobs.delete(blobs.keys().next().value);
      }
    } catch (e) { /* keep the page working */ }
    return url;
  };

  function save(href, name) {
    var known = blobs.get(href);
    var got = known ? Promise.resolve(known) : fetch(href).then(function (r) { return r.blob(); });
    got.then(function (blob) {
      var reader = new FileReader();
      reader.onload = function () {
        var s = String(reader.result || '');
        post({ type: 'download', name: String(name || ''), mime: blob.type || '', data: s.slice(s.indexOf(',') + 1) });
      };
      reader.onerror = function () { post({ type: 'downloadFailed' }); };
      reader.readAsDataURL(blob);
    }).catch(function () { post({ type: 'downloadFailed' }); });
  }

  function takeOver(a) {
    var href = a && a.href;
    if (!href || !/^(blob|data):/i.test(href) || !a.hasAttribute('download')) return false;
    save(href, a.getAttribute('download'));
    return true;
  }

  // a.click() on a link that is not in the page fires no event we could catch.
  var anchorClick = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function () {
    if (takeOver(this)) return;
    return anchorClick.apply(this, arguments);
  };
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[download]') : null;
    if (a && !e.defaultPrevented && takeOver(a)) e.preventDefault();
  }, true);

  window.__fwNative = Object.freeze({ download: save });

  // ------------------------------------------------------------ printing
  window.print = function () { post({ type: 'print', title: document.title || '' }); };

  // ------------------------------------------------------------ bar colours
  function colourAt(x, y) {
    var el = document.elementFromPoint(x, y);
    while (el && el.nodeType === 1) {
      var c = getComputedStyle(el).backgroundColor;
      if (c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) return c;
      el = el.parentElement;
    }
    var root = document.body || document.documentElement;
    return root ? getComputedStyle(root).backgroundColor : '';
  }

  var last = '';
  var timer = 0;
  function report() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (!document.documentElement) return;
      var meta = document.querySelector('meta[name="theme-color"]');
      var w = window.innerWidth || 1;
      var h = window.innerHeight || 1;
      var top = (meta && meta.content) || colourAt(w / 2, 1);
      var bottom = colourAt(w / 2, h - 2);
      var sig = top + '|' + bottom;
      if (sig === last) return;
      last = sig;
      post({ type: 'bars', top: top, bottom: bottom });
    }, 120);
  }

  function watch() {
    report();
    var mo = new MutationObserver(report);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'style', 'content'] };
    mo.observe(document.documentElement, opts);
    if (document.body) mo.observe(document.body, opts);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) mo.observe(meta, opts);
    window.addEventListener('resize', report);
    window.addEventListener('pageshow', report);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch);
  else watch();
  window.addEventListener('load', report);
})();
