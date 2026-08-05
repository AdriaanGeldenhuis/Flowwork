/*
 * QIDnD — drag & drop reordering for quote/invoice editor rows.
 *
 * Rows are dragged by their handle only (the row is made draggable on
 * handle mousedown and disarmed afterwards, so text selection inside the
 * form inputs keeps working). Dragging a section heading moves the heading
 * together with the item rows below it up to the next heading — the same
 * way dragging a group moves its items on a project board.
 */
(function () {
    'use strict';

    window.QIDnD = {
        attach: function (opts) {
            const container = typeof opts.container === 'string'
                ? document.querySelector(opts.container)
                : opts.container;
            if (!container || container.dataset.qiDndAttached === '1') return;
            container.dataset.qiDndAttached = '1';

            const rowSel = opts.rowSelector;
            const headSel = opts.headingSelector;
            const allSel = rowSel + ', ' + headSel;
            const handleSel = opts.handleSelector || '.fw-qi__drag-handle';
            const onReorder = typeof opts.onReorder === 'function' ? opts.onReorder : function () {};

            let block = null;  // the row(s) being moved as one unit
            let armed = null;  // row whose handle was pressed

            function rows() {
                return Array.from(container.querySelectorAll(allSel));
            }

            function isHeading(el) {
                return el.matches(headSel);
            }

            // A heading moves with everything below it until the next heading
            function sectionOf(heading) {
                const out = [heading];
                let n = heading.nextElementSibling;
                while (n && !isHeading(n)) {
                    if (n.matches(rowSel)) out.push(n);
                    n = n.nextElementSibling;
                }
                return out;
            }

            function clearIndicators() {
                rows().forEach(function (r) {
                    r.classList.remove('fw-qi__row-drop-before', 'fw-qi__row-drop-after');
                });
            }

            function findTarget(clientY) {
                const candidates = rows().filter(function (r) { return block.indexOf(r) === -1; });
                for (let i = 0; i < candidates.length; i++) {
                    const rect = candidates[i].getBoundingClientRect();
                    if (clientY < rect.top + rect.height / 2) {
                        return { target: candidates[i], before: true };
                    }
                }
                return { target: candidates[candidates.length - 1] || null, before: false };
            }

            function finishDrag() {
                clearIndicators();
                if (block) block.forEach(function (r) { r.classList.remove('fw-qi__row-dragging'); });
                if (armed) armed.removeAttribute('draggable');
                block = null;
                armed = null;
            }

            container.addEventListener('mousedown', function (e) {
                const handle = e.target.closest(handleSel);
                if (!handle) return;
                const row = handle.closest(allSel);
                if (!row) return;
                armed = row;
                row.setAttribute('draggable', 'true');
            });

            document.addEventListener('mouseup', function () {
                if (armed && !block) {
                    armed.removeAttribute('draggable');
                    armed = null;
                }
            });

            container.addEventListener('dragstart', function (e) {
                const row = e.target && e.target.closest ? e.target.closest(allSel) : null;
                if (!row || row !== armed) {
                    e.preventDefault();
                    return;
                }
                block = isHeading(row) ? sectionOf(row) : [row];
                block.forEach(function (r) { r.classList.add('fw-qi__row-dragging'); });
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', 'qi-row'); } catch (err) { /* IE */ }
            });

            container.addEventListener('dragover', function (e) {
                if (!block) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                clearIndicators();
                const t = findTarget(e.clientY);
                if (t.target) {
                    t.target.classList.add(t.before ? 'fw-qi__row-drop-before' : 'fw-qi__row-drop-after');
                }
            });

            container.addEventListener('drop', function (e) {
                if (!block) return;
                e.preventDefault();
                const t = findTarget(e.clientY);
                if (t.target) {
                    const frag = document.createDocumentFragment();
                    block.forEach(function (r) { frag.appendChild(r); });
                    if (t.before) {
                        container.insertBefore(frag, t.target);
                    } else {
                        container.insertBefore(frag, t.target.nextSibling);
                    }
                    onReorder();
                }
                finishDrag();
            });

            container.addEventListener('dragend', finishDrag);
        },

        // Shared drag-handle markup (six-dot grip)
        handleHtml: function (title) {
            return '<span class="fw-qi__drag-handle" title="' + (title || 'Drag to reorder') + '">' +
                '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                '<circle cx="9" cy="5" r="1.7"/><circle cx="15" cy="5" r="1.7"/>' +
                '<circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/>' +
                '<circle cx="9" cy="19" r="1.7"/><circle cx="15" cy="19" r="1.7"/>' +
                '</svg></span>';
        }
    };
})();
