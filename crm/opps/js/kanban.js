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
                    }
                    // Regardless, re‑init cards and recalc totals
                    initCardDragHandlers();
                    recalcStageTotals();
                }).catch(() => {
                    alert('Error communicating with server');
                    initCardDragHandlers();
                    recalcStageTotals();
                });
            });
        });
    }

    // Initialize handlers on page load
    initCardDragHandlers();
    initColumnDropHandlers();
    recalcStageTotals();
});