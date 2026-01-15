// inventory/js/inventory.js
// Front-end functionality for the inventory index page.
// Handles searching and new item navigation.

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('itemSearch');
    const table = document.getElementById('itemsTable');
    const newBtn = document.getElementById('btnNewItem');

    if (newBtn) {
        newBtn.addEventListener('click', function () {
            window.location.href = '/inventory/item_new.php';
        });
    }

    if (searchInput && table) {
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase();
            const rows = table.tBodies[0].rows;
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            }
        });
    }
});