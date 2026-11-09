// Drag a site on the standards board by its handle to put it where Rob wants
// it: inside its block, into Pinned, or from Pinned back to its own host's
// block. Never into another host's block, whose heading says where a site is
// served from; the server refuses that too (Inventory\Standards\SiteOrder).
// Each drop saves the blocks it touched. The CSRF header is added to the
// fetch by templates/partials/csrf_head.tpl.php.
document.querySelectorAll('.StdBlocks').forEach(function (board) {
    var slug = board.dataset.standard;
    var note = board.querySelector('.StdOrderNote');

    function order(block) {
        return Array.prototype.map.call(block.querySelectorAll(':scope > .StdItem'), function (item) {
            return item.dataset.shortcode;
        });
    }

    function say(text) {
        note.textContent = text;
    }

    function save(from, to) {
        var blocks = {};
        blocks[from.dataset.block] = order(from);
        blocks[to.dataset.block] = order(to);
        fetch('/admin/dh/standards_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ standard: slug, blocks: blocks })
        }).then(function (response) {
            return response.json().catch(function () {
                return { error: 'HTTP ' + response.status };
            }).then(function (body) {
                if (!response.ok || body.error) {
                    throw new Error(body.error || 'HTTP ' + response.status);
                }
            });
        }).then(function () {
            say('Order saved.');
        }).catch(function (error) {
            say('The order was not saved (' + error.message + '). Reload to see the stored order.');
        });
    }

    board.querySelectorAll('.StdBlock').forEach(function (block) {
        Sortable.create(block, {
            group: {
                name: 'std-' + slug,
                put: function (to, from, dragged) {
                    var target = to.el.dataset.block;
                    return target === 'pinned' || target === dragged.dataset.host;
                }
            },
            draggable: '.StdItem',
            handle: '.StdHandle',
            animation: 150,
            onEnd: function (evt) {
                if (evt.from === evt.to && evt.oldIndex === evt.newIndex) {
                    return;
                }
                save(evt.from, evt.to);
            }
        });
    });
});
