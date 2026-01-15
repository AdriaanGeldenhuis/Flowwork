// Automatically add a calendar badge link on quote/invoice view pages.
// It looks for an element with data-qi-entity and data-qi-id attributes and
// inserts a small badge linking to the calendar event if one exists.
(function(){
  // Execute when the DOM is ready
  function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  onReady(function(){
    var node = document.querySelector('[data-qi-entity]');
    if (!node) return;
    var t  = node.getAttribute('data-qi-entity');
    var id = node.getAttribute('data-qi-id');
    if (!t || !id) return;
    fetch('/qi/ajax/get_calendar_link.php?type=' + encodeURIComponent(t) + '&id=' + encodeURIComponent(id), {
      headers: { 'Accept': 'application/json' }
    })
    .then(function(r){ return r.ok ? r.json() : Promise.reject(r.statusText); })
    .then(function(data){
      if (!data || !data.event) return;
      // Determine insertion point: prefer an element marked with data-qi-actionbar,
      // else fall back to the first heading element.
      var target = document.querySelector('[data-qi-actionbar]') || document.querySelector('h1,h2');
      if (!target) return;
      var a = document.createElement('a');
      a.href = '/calendar/event_view.php?id=' + data.event.id;
      a.textContent = '📅 In Calendar';
      // Style as a small badge
      a.style.marginLeft = '8px';
      a.style.fontSize = '12px';
      a.style.padding = '4px 8px';
      a.style.borderRadius = '6px';
      a.style.background = '#06b6d4';
      a.style.color = '#fff';
      a.style.textDecoration = 'none';
      target.parentNode.insertBefore(a, target.nextSibling);
    })
    .catch(function(){ /* ignore errors silently */ });
  });
})();