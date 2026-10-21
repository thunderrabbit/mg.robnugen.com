<?php
// Included in the <head> of every layout whose pages run prepend.php.
?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <script>
    // prepend.php refuses a POST (or PUT, PATCH, DELETE) without the session's
    // CSRF token. Forms carry it as a hidden field; this adds it as the
    // X-CSRF-Token header to every same-origin fetch() and XMLHttpRequest, so
    // dashboard.js, meisogambare.js, jQuery and inline page scripts all send it
    // without each call site having to remember.
    (function () {
        var token = document.querySelector('meta[name="csrf-token"]').content;
        var safe = /^(GET|HEAD|OPTIONS)$/i;
        function sameOrigin(url) {
            try {
                return new URL(url, location.href).origin === location.origin;
            } catch (e) {
                return false;
            }
        }

        var originalFetch = window.fetch;
        if (originalFetch) {
            window.fetch = function (input, init) {
                init = init || {};
                var isRequest = typeof Request !== 'undefined' && input instanceof Request;
                var method = init.method || (isRequest ? input.method : 'GET');
                var url = isRequest ? input.url : String(input);
                if (!safe.test(method) && sameOrigin(url)) {
                    var headers = new Headers(init.headers || (isRequest ? input.headers : undefined));
                    headers.set('X-CSRF-Token', token);
                    init = Object.assign({}, init, { headers: headers });
                }
                return originalFetch.call(this, input, init);
            };
        }

        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.mgNeedsCsrf = !safe.test(method) && sameOrigin(url);
            return originalOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this.mgNeedsCsrf) {
                this.setRequestHeader('X-CSRF-Token', token);
            }
            return originalSend.apply(this, arguments);
        };
    })();
    </script>
