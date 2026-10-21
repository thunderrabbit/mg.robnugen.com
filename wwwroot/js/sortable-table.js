// Click a header button in a .sortable-table to sort by that column; click it
// again to reverse. Each cell's data-sort holds the key, and the button's
// data-type says whether to compare as numbers ("num") or text. The header
// carrying aria-sort is the one the server rendered the rows in.
document.querySelectorAll('.sortable-table').forEach(function (table) {
    var body = table.tBodies[0];
    var headers = table.querySelectorAll('th');
    table.querySelectorAll('th button[data-col]').forEach(function (button) {
        button.addEventListener('click', function () {
            var th = button.parentElement;
            var col = Number(button.dataset.col);
            var numeric = button.dataset.type === 'num';
            var current = th.getAttribute('aria-sort');
            var dir = current === 'ascending' ? -1
                : current === 'descending' ? 1
                : (button.dataset.first === 'desc' ? -1 : 1);
            var rows = Array.prototype.slice.call(body.rows);
            rows.sort(function (a, b) {
                var x = a.cells[col].dataset.sort;
                var y = b.cells[col].dataset.sort;
                return dir * (numeric ? Number(x) - Number(y) : x.localeCompare(y));
            });
            rows.forEach(function (row) { body.appendChild(row); });
            headers.forEach(function (h) { h.removeAttribute('aria-sort'); });
            th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
        });
    });
});
