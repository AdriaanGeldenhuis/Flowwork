// /crm/opps/js/kanban.js
// Client‑side script for the Sales Pipeline Kanban board. Handles drag
// and drop of opportunity cards between stages and sends updates to
// the server. Also recalculates counts and totals per column on the fly.

document.addEventListener('DOMContentLoaded', function () {
    const board = document.getElementById('kanbanBoard');
    if (!board) return;

    // Add dragstart and dragend listeners to cards
    function initCardDragHandlers() {
        document.querySelectorAll('.fw-opps__card').forEach(card => {
            card.setAttribute('draggable', 'true');
            card.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', this.dataset.id);
                this.classList.add('dragging');
            });
            card.addEventListener('dragend', function () {
                this.classList.remove('dragging');
            });
        });
    }

    // Recalculate counts and totals for each stage from data-amount
    // attributes (parsing the rendered "R1 234,56" text broke on formats)
    function recalcStageTotals() {
        document.querySelectorAll('.fw-opps__column').forEach(col => {
            const stage = col.getAttribute('data-stage');
            const itemsContainer = col.querySelector('.fw-opps__items');
            const cards = itemsContainer.querySelectorAll('.fw-opps__card');
            let total = 0;
            cards.forEach(card => {
                const num = parseFloat(card.dataset.amount);
                if (!isNaN(num)) total += num;
            });
            const countSpan = document.getElementById('count-' + stage);
            const totalSpan = document.getElementById('total-' + stage);
            if (countSpan) countSpan.textContent = cards.length;
            if (totalSpan) totalSpan.textContent = 'R' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        });
    }

    function notify(msg, type) {
        if (window.CRM && CRM.toast) { CRM.toast(msg, type); } else { alert(msg); }
    }

    // ===== Loss reason capture =====
    // When a deal is dropped into Lost, ask for an optional reason before
    // sending the stage update. Cancelling the modal reverts the move.
    const lossModal = document.getElementById('lossReasonModal');
    const lossInput = document.getElementById('lossReasonInput');
    let lossResolver = null;

    // Ask for a loss reason. Resolves with {proceed, reason}.
    function askLossReason() {
        return new Promise(resolve => {
            if (!lossModal) { resolve({ proceed: true, reason: '' }); return; }
            lossResolver = resolve;
            lossInput.value = '';
            CRMModal.open('lossReasonModal');
            lossInput.focus();
        });
    }

    function settleLossModal(result) {
        if (!lossResolver) return;
        const resolve = lossResolver;
        lossResolver = null;
        CRMModal.close('lossReasonModal');
        resolve(result);
    }

    if (lossModal) {
        // Overlay-click / Escape close the modal without going through our
        // buttons (crm.js handles those) — treat that as a cancel
        new MutationObserver(() => {
            if (lossResolver && lossModal.getAttribute('aria-hidden') !== 'false') {
                const resolve = lossResolver;
                lossResolver = null;
                resolve({ proceed: false, reason: '' });
            }
        }).observe(lossModal, { attributes: true, attributeFilter: ['class', 'aria-hidden'] });

        document.getElementById('lossReasonSave').addEventListener('click', () => {
            settleLossModal({ proceed: true, reason: lossInput.value.trim() });
        });
        document.getElementById('lossReasonSkip').addEventListener('click', () => {
            settleLossModal({ proceed: true, reason: '' });
        });
        document.getElementById('lossReasonClose').addEventListener('click', () => {
            settleLossModal({ proceed: false, reason: '' });
        });
        lossInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                settleLossModal({ proceed: true, reason: lossInput.value.trim() });
            }
        });
    }

    // Send a stage update; revert() restores the card position on failure
    function sendStageUpdate(oppId, newStage, lossReason, revert) {
        const params = new URLSearchParams();
        params.append('id', oppId);
        params.append('stage', newStage);
        if (newStage === 'lost' && lossReason) {
            params.append('loss_reason', lossReason);
        }
        fetch('/crm/ajax/opportunity_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(resp => resp.json())
        .then(data => {
            if (!data.ok) {
                notify(data.error || 'Failed to update stage', 'error');
                revert();
            }
            initCardDragHandlers();
            recalcStageTotals();
        }).catch(() => {
            notify('Error communicating with server', 'error');
            revert();
            initCardDragHandlers();
            recalcStageTotals();
        });
    }

    // Drop handler for columns
    function initColumnDropHandlers() {
        document.querySelectorAll('.fw-opps__items').forEach(itemsContainer => {
            itemsContainer.addEventListener('dragover', function (e) {
                e.preventDefault();
            });
            itemsContainer.addEventListener('drop', function (e) {
                e.preventDefault();
                const oppId = e.dataTransfer.getData('text/plain');
                const draggedCard = document.querySelector('.fw-opps__card[data-id="' + oppId + '"]');
                if (!draggedCard) return;
                // If dropping into same column, ignore
                if (this.contains(draggedCard)) return;
                // Save original position for rollback on failure
                const originalContainer = draggedCard.parentElement;
                const originalNextSibling = draggedCard.nextElementSibling;
                const revert = () => {
                    if (originalNextSibling) {
                        originalContainer.insertBefore(draggedCard, originalNextSibling);
                    } else {
                        originalContainer.appendChild(draggedCard);
                    }
                };
                // Append card to new column (optimistic)
                this.appendChild(draggedCard);
                recalcStageTotals();
                const newStage = this.parentElement.getAttribute('data-stage');

                if (newStage === 'lost') {
                    askLossReason().then(({ proceed, reason }) => {
                        if (!proceed) {
                            revert();
                            recalcStageTotals();
                            return;
                        }
                        sendStageUpdate(oppId, newStage, reason, revert);
                    });
                } else {
                    sendStageUpdate(oppId, newStage, '', revert);
                }
            });
        });
    }

    // Mobile stage selector
    function initMobileStageSelect() {
        const select = document.getElementById('mobileStageSelect');
        if (!select) return;

        select.addEventListener('change', function() {
            const stage = this.value;
            document.querySelectorAll('.fw-opps__column').forEach(col => {
                col.classList.toggle('fw-opps__column--active', col.getAttribute('data-stage') === stage);
            });
        });
    }

    // Touch-based card moving for mobile (select target stage via prompt)
    function initTouchMoveHandlers() {
        if (!('ontouchstart' in window)) return;

        document.querySelectorAll('.fw-opps__card').forEach(card => {
            card.addEventListener('long-press', handleCardMove);
        });

        // Use a simpler approach: add a "Move" button to each card on mobile
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.fw-opps__card').forEach(card => {
                if (card.querySelector('.fw-opps__card-move')) return;
                const moveBtn = document.createElement('button');
                moveBtn.className = 'fw-opps__card-move';
                moveBtn.textContent = 'Move';
                moveBtn.style.cssText = 'margin-top:6px;padding:4px 10px;font-size:0.75rem;border:1px solid #06b6d4;background:transparent;color:#06b6d4;border-radius:4px;cursor:pointer;';
                moveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    handleCardMove(card);
                });
                card.appendChild(moveBtn);
            });
        }
    }

    function handleCardMove(card) {
        const oppId = card.dataset.id;
        const currentStage = card.closest('.fw-opps__column').getAttribute('data-stage');
        const stages = [];
        document.querySelectorAll('.fw-opps__column').forEach(col => {
            const s = col.getAttribute('data-stage');
            if (s !== currentStage) stages.push(s);
        });

        const choice = prompt('Move to stage:\n' + stages.map((s, i) => (i + 1) + '. ' + s.charAt(0).toUpperCase() + s.slice(1)).join('\n') + '\n\nEnter number:');
        if (!choice) return;
        const idx = parseInt(choice, 10) - 1;
        if (isNaN(idx) || idx < 0 || idx >= stages.length) return;

        const newStage = stages[idx];
        const targetCol = document.querySelector('.fw-opps__column[data-stage="' + newStage + '"] .fw-opps__items');
        if (!targetCol) return;

        const originalContainer = card.parentElement;
        const originalNextSibling = card.nextElementSibling;
        const focusStage = (stage) => {
            const select = document.getElementById('mobileStageSelect');
            if (select) select.value = stage;
            document.querySelectorAll('.fw-opps__column').forEach(col => {
                col.classList.toggle('fw-opps__column--active', col.getAttribute('data-stage') === stage);
            });
        };
        const revert = () => {
            if (originalNextSibling) {
                originalContainer.insertBefore(card, originalNextSibling);
            } else {
                originalContainer.appendChild(card);
            }
            // Bring the board back to the column the card returned to
            focusStage(currentStage);
        };
        targetCol.appendChild(card);

        // Follow the moved card on mobile
        focusStage(newStage);
        recalcStageTotals();

        if (newStage === 'lost') {
            askLossReason().then(({ proceed, reason }) => {
                if (!proceed) {
                    revert();
                    recalcStageTotals();
                    return;
                }
                sendStageUpdate(oppId, newStage, reason, revert);
            });
        } else {
            sendStageUpdate(oppId, newStage, '', revert);
        }
    }

    // Initialize handlers on page load
    initCardDragHandlers();
    initColumnDropHandlers();
    initMobileStageSelect();
    initTouchMoveHandlers();
    recalcStageTotals();
});