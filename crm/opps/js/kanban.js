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

    // Recalculate counts and totals for each stage
    function recalcStageTotals() {
        document.querySelectorAll('.fw-opps__column').forEach(col => {
            const stage = col.getAttribute('data-stage');
            const itemsContainer = col.querySelector('.fw-opps__items');
            const cards = itemsContainer.querySelectorAll('.fw-opps__card');
            let total = 0;
            cards.forEach(card => {
                const infoEl = card.querySelector('.fw-opps__card-info');
                if (!infoEl) return;
                const text = infoEl.textContent || '';
                // Extract number portion from "R1234.56"
                const match = text.match(/R\s*([0-9.,]+)/);
                if (match) {
                    // Remove commas for thousand separators
                    const num = parseFloat(match[1].replace(/,/g, ''));
                    if (!isNaN(num)) total += num;
                }
            });
            const countSpan = document.getElementById('count-' + stage);
            const totalSpan = document.getElementById('total-' + stage);
            if (countSpan) countSpan.textContent = cards.length;
            if (totalSpan) totalSpan.textContent = 'R' + total.toFixed(2);
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
                // Append card to new column
                this.appendChild(draggedCard);
                const newStage = this.parentElement.getAttribute('data-stage');
                // Prepare form data
                const params = new URLSearchParams();
                params.append('id', oppId);
                params.append('stage', newStage);
                fetch('/crm/ajax/opportunity_update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                }).then(resp => resp.json())
                .then(data => {
                    if (!data.ok) {
                        alert(data.error || 'Failed to update stage');
                        // Revert card to original column
                        if (originalNextSibling) {
                            originalContainer.insertBefore(draggedCard, originalNextSibling);
                        } else {
                            originalContainer.appendChild(draggedCard);
                        }
                    }
                    initCardDragHandlers();
                    recalcStageTotals();
                }).catch(() => {
                    alert('Error communicating with server');
                    // Revert card to original column
                    if (originalNextSibling) {
                        originalContainer.insertBefore(draggedCard, originalNextSibling);
                    } else {
                        originalContainer.appendChild(draggedCard);
                    }
                    initCardDragHandlers();
                    recalcStageTotals();
                });
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
        targetCol.appendChild(card);

        const params = new URLSearchParams();
        params.append('id', oppId);
        params.append('stage', newStage);

        fetch('/crm/ajax/opportunity_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(resp => resp.json())
        .then(data => {
            if (!data.ok) {
                alert(data.error || 'Failed to update stage');
                if (originalNextSibling) {
                    originalContainer.insertBefore(card, originalNextSibling);
                } else {
                    originalContainer.appendChild(card);
                }
            }
            // Update mobile select options
            const select = document.getElementById('mobileStageSelect');
            if (select) select.value = newStage;
            document.querySelectorAll('.fw-opps__column').forEach(col => {
                col.classList.toggle('fw-opps__column--active', col.getAttribute('data-stage') === newStage);
            });
            recalcStageTotals();
        }).catch(() => {
            alert('Error communicating with server');
            if (originalNextSibling) {
                originalContainer.insertBefore(card, originalNextSibling);
            } else {
                originalContainer.appendChild(card);
            }
            recalcStageTotals();
        });
    }

    // Initialize handlers on page load
    initCardDragHandlers();
    initColumnDropHandlers();
    initMobileStageSelect();
    initTouchMoveHandlers();
    recalcStageTotals();
});