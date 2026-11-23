// The Images card on issue and project pages (templates/issues/image_strip.tpl.php):
// copy a picture's markdown line, confirm a remove, and stop an upload the
// server would turn away before it is sent.
(function () {
    'use strict';

    document.querySelectorAll('.image-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var line = button.dataset.markdown || '';
            var done = function () {
                var was = button.textContent;
                button.textContent = 'copied';
                setTimeout(function () { button.textContent = was; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(line).then(done, function () {
                    window.prompt('Copy this line:', line);
                });
            } else {
                window.prompt('Copy this line:', line);
            }
        });
    });

    document.querySelectorAll('.image-remove-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm('Remove this picture from the issue? The file stays on the server.')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.image-add-form').forEach(function (form) {
        var input = form.querySelector('input[type="file"]');
        var problem = form.querySelector('.image-add-problem');
        var maxFiles = parseInt(form.dataset.maxFiles, 10);
        var maxBytes = parseInt(form.dataset.maxBytes, 10);

        var check = function () {
            var files = Array.prototype.slice.call(input.files || []);
            var message = '';
            if (files.length > maxFiles) {
                message = files.length + ' pictures chosen; at most ' + maxFiles + ' at a time.';
            } else {
                var big = files.filter(function (file) { return file.size > maxBytes; });
                if (big.length > 0) {
                    message = big.map(function (file) { return file.name; }).join(', ')
                        + (big.length === 1 ? ' is' : ' are') + ' larger than '
                        + Math.round(maxBytes / 1048576) + ' MB.';
                }
            }
            problem.textContent = message;
            problem.hidden = message === '';
            return message === '';
        };

        input.addEventListener('change', check);
        form.addEventListener('submit', function (event) {
            if (!check()) {
                event.preventDefault();
            }
        });
    });
}());
