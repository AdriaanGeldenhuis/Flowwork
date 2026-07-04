/**
 * Touch Drag & Drop
 * The mouse drag systems use HTML5 dragstart/drop, which never fires from
 * touch. This module adds a long-press (350ms) pointer-events fallback for
 * table rows and kanban cards, reusing the same drop indicators and
 * persistence paths as the mouse implementations.
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  const LONG_PRESS_MS = 350;
  const MOVE_CANCEL_PX = 10;

  let pressTimer = null;
  let startX = 0;
  let startY = 0;
  let dragEl = null;      // the source .fw-item-row or .fw-kanban-card
  let dragKind = null;    // 'row' | 'card'
  let ghost = null;
  let sourceGroupId = null;
  let sourceStatus = null;

  function clearPress() {
    if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
  }

  function makeGhost(el, x, y) {
    const rect = el.getBoundingClientRect();
    const g = document.createElement('div');
    g.className = 'fw-touch-ghost';
    g.textContent = dragKind === 'row'
      ? (el.querySelector('.fw-item-title')?.value || 'Item')
      : (el.querySelector('.fw-kanban-card-title')?.textContent || 'Card');
    g.style.cssText = 'position:fixed;z-index:10005;pointer-events:none;padding:8px 14px;' +
      'background:var(--modal-bg, #1e1e28);color:var(--modal-text, #fff);border:1px solid var(--accent-primary, #8b5cf6);' +
      'border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,0.4);' +
      `max-width:${Math.min(rect.width, 260)}px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;`;
    document.body.appendChild(g);
    positionGhost(g, x, y);
    return g;
  }

  function positionGhost(g, x, y) {
    g.style.left = (x + 12) + 'px';
    g.style.top = (y - 20) + 'px';
  }

  function clearIndicators() {
    document.querySelectorAll('.fw-drag-over-top, .fw-drag-over-bottom').forEach(el =>
      el.classList.remove('fw-drag-over-top', 'fw-drag-over-bottom'));
    document.querySelectorAll('.fw-group-drop-target').forEach(el =>
      el.classList.remove('fw-group-drop-target'));
    document.querySelectorAll('.fw-kanban-column-body.fw-drag-over').forEach(el =>
      el.classList.remove('fw-drag-over'));
  }

  function startDrag(e) {
    pressTimer = null;
    if (!dragEl) return;

    if (dragKind === 'row') {
      sourceGroupId = dragEl.dataset.groupId;
    } else {
      sourceStatus = dragEl.dataset.status;
    }

    ghost = makeGhost(dragEl, e.clientX, e.clientY);
    dragEl.classList.add('fw-dragging');
    dragEl.style.opacity = '0.5';

    // Block scrolling for the duration of the drag
    document.addEventListener('touchmove', preventScroll, { passive: false });

    if (window.fwAnnounce) window.fwAnnounce('Dragging — release over the destination');
    if (navigator.vibrate) navigator.vibrate(30);
  }

  function preventScroll(e) {
    e.preventDefault();
  }

  function targetUnder(x, y) {
    if (ghost) ghost.style.display = 'none';
    const el = document.elementFromPoint(x, y);
    if (ghost) ghost.style.display = '';
    return el;
  }

  function onPointerDown(e) {
    if (e.pointerType !== 'touch') return;
    if (e.target.closest('input, textarea, select, button, a')) return;

    const row = e.target.closest('tr.fw-item-row');
    const card = e.target.closest('.fw-kanban-card');
    if (!row && !card) return;

    dragEl = row || card;
    dragKind = row ? 'row' : 'card';
    startX = e.clientX;
    startY = e.clientY;

    clearPress();
    pressTimer = setTimeout(() => startDrag(e), LONG_PRESS_MS);
  }

  function onPointerMove(e) {
    if (e.pointerType !== 'touch') return;

    // Finger moved before the long-press fired — this is a scroll, not a drag
    if (pressTimer && (Math.abs(e.clientX - startX) > MOVE_CANCEL_PX || Math.abs(e.clientY - startY) > MOVE_CANCEL_PX)) {
      clearPress();
      dragEl = null;
      return;
    }

    if (!ghost || !dragEl) return;

    positionGhost(ghost, e.clientX, e.clientY);
    clearIndicators();

    const under = targetUnder(e.clientX, e.clientY);
    if (!under) return;

    if (dragKind === 'row') {
      const overRow = under.closest && under.closest('tr.fw-item-row');
      const overGroup = under.closest && under.closest('.fw-group:not(.fw-board-totals-group)');
      if (overRow && overRow !== dragEl) {
        const rect = overRow.getBoundingClientRect();
        overRow.classList.add(e.clientY < rect.top + rect.height / 2 ? 'fw-drag-over-top' : 'fw-drag-over-bottom');
      } else if (overGroup) {
        overGroup.classList.add('fw-group-drop-target');
      }
    } else {
      const overCol = under.closest && under.closest('.fw-kanban-column-body');
      if (overCol) overCol.classList.add('fw-drag-over');
    }
  }

  function onPointerEnd(e) {
    if (e.pointerType !== 'touch') return;

    clearPress();
    if (!ghost || !dragEl) { dragEl = null; return; }

    const under = targetUnder(e.clientX, e.clientY);
    clearIndicators();

    if (dragKind === 'row') {
      dropRow(under, e.clientY);
    } else {
      dropCard(under);
    }

    // Cleanup
    ghost.remove();
    ghost = null;
    document.removeEventListener('touchmove', preventScroll);
    dragEl.classList.remove('fw-dragging');
    dragEl.style.opacity = '';
    dragEl = null;
  }

  function dropRow(under, clientY) {
    if (!under) return;

    const overRow = under.closest && under.closest('tr.fw-item-row');
    const overGroup = under.closest && under.closest('.fw-group:not(.fw-board-totals-group)');
    const itemId = parseInt(dragEl.dataset.itemId, 10);

    if (overRow && overRow !== dragEl) {
      const rect = overRow.getBoundingClientRect();
      const isAbove = clientY < rect.top + rect.height / 2;
      const tbody = overRow.closest('tbody');
      tbody.insertBefore(dragEl, isAbove ? overRow : overRow.nextSibling);

      const targetGroupId = overRow.dataset.groupId;
      dragEl.dataset.groupId = String(targetGroupId);
      window.BoardApp.dragDrop?.persistDrop(itemId, targetGroupId, sourceGroupId);
      if (window.fwAnnounce) window.fwAnnounce('Item moved');
    } else if (overGroup) {
      const tbody = overGroup.querySelector('tbody');
      const targetGroupId = overGroup.dataset.groupId;
      if (tbody && targetGroupId) {
        tbody.querySelectorAll('.fw-empty-state').forEach(td => td.closest('tr')?.remove());
        tbody.insertBefore(dragEl, tbody.querySelector('.fw-agg-row') || tbody.querySelector('.fw-add-row') || null);
        dragEl.dataset.groupId = String(targetGroupId);
        window.BoardApp.dragDrop?.persistDrop(itemId, targetGroupId, sourceGroupId);
        if (window.fwAnnounce) window.fwAnnounce('Item moved');
      }
    }
  }

  function dropCard(under) {
    if (!under) return;

    const overCol = under.closest && under.closest('.fw-kanban-column-body');
    if (!overCol) return;

    const newKey = overCol.dataset.status;
    if (!newKey || newKey === sourceStatus) return;

    const itemId = parseInt(dragEl.dataset.itemId, 10);
    const source = dragEl.parentElement;

    const addBtn = overCol.querySelector('.fw-kanban-add-card');
    if (addBtn) overCol.insertBefore(dragEl, addBtn);
    else overCol.appendChild(dragEl);
    dragEl.dataset.status = newKey;

    const el = dragEl; // capture for the revert closure — dragEl nulls after cleanup
    const revert = () => {
      el.dataset.status = sourceStatus;
      if (source) source.appendChild(el);
    };

    if (window.BoardApp.kanbanPersistMove) {
      window.BoardApp.kanbanPersistMove(itemId, newKey, revert);
    } else if (window.BoardApp.updateItemStatus) {
      window.BoardApp.updateItemStatus(itemId, newKey, revert);
    }
  }

  document.addEventListener('pointerdown', onPointerDown, { passive: true });
  document.addEventListener('pointermove', onPointerMove, { passive: true });
  document.addEventListener('pointerup', onPointerEnd, { passive: true });
  document.addEventListener('pointercancel', onPointerEnd, { passive: true });

  console.log('✅ Touch drag & drop module loaded');
})();
