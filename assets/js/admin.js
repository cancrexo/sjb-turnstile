(function () {
    let toastTimer = 0;

    /**
     * Envía un task a la rutina AJAX única y muestra el toast de la respuesta.
     *
     * @param {string} task
     * @param {Object<string, string>} fields
     * @returns {Promise}
     */
    function request(task, fields) {
        const body = new FormData();
        body.append('action', window.sjbTurnstile.action);
        body.append('nonce', window.sjbTurnstile.nonce);
        body.append('task', task);

        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });

        const show = function (level, message) {
            const allowed = ['success', 'error', 'warning'];
            const safeLevel = allowed.indexOf(level) === -1 ? 'error' : level;
            let wrap = document.getElementById('sjb-turnstile-toasts');

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'sjb-turnstile-toasts';
                wrap.className = 'sjb-toast-wrap';
                document.body.appendChild(wrap);
            }

            wrap.textContent = '';

            const item = document.createElement('div');
            item.className = 'sjb-toast sjb-toast-' + safeLevel;
            item.setAttribute('role', 'status');
            item.textContent = message;
            wrap.appendChild(item);

            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(function () {
                item.remove();
            }, 4000);
        };

        return fetch(window.sjbTurnstile.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                const data = payload && payload.data ? payload.data : {};
                const level = data.level || (payload.success ? 'success' : 'error');
                const message = data.message || window.sjbTurnstile.i18n.network;

                show(level, message);

                if (task === 'save_cloudflare') {
                    const notice = document.getElementById('sjb-turnstile-keys-notice');
                    if (notice) {
                        notice.hidden = level === 'success';
                    }
                }

                if (task === 'export_log' && level === 'success' && Array.isArray(data.records)) {
                    const blob = new Blob([JSON.stringify(data.records, null, 2)], { type: 'application/json' });
                    const url = window.URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = data.filename || 'sjb-turnstile.json';
                    link.click();
                    window.URL.revokeObjectURL(url);
                }

                if (task === 'empty_log' && level === 'success') {
                    const table = document.getElementById('sjb-turnstile-log-table');
                    const empty = document.getElementById('sjb-turnstile-log-empty');
                    const count = document.getElementById('sjb-turnstile-log-count');
                    const tbody = document.getElementById('sjb-turnstile-log-body');

                    if (tbody) {
                        tbody.textContent = '';
                    }
                    if (table) {
                        table.hidden = true;
                    }
                    if (count) {
                        count.hidden = true;
                    }
                    if (empty) {
                        empty.hidden = false;
                    }
                }

                return payload;
            })
            .catch(function () {
                show('error', window.sjbTurnstile.i18n.network);
            });
    }

    document.querySelectorAll('.sjb-turnstile-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const fields = {};
            form.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
                fields[input.name] = input.checked ? '1' : '0';
            });

            new FormData(form).forEach(function (value, key) {
                if (!Object.prototype.hasOwnProperty.call(fields, key)) {
                    fields[key] = String(value);
                }
            });

            const button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
            }

            request(form.getAttribute('data-task') || '', fields).finally(function () {
                if (button) {
                    button.disabled = false;
                }
            });
        });
    });

    const exportButton = document.getElementById('sjb-turnstile-export');
    if (exportButton) {
        exportButton.addEventListener('click', function () {
            exportButton.disabled = true;
            request('export_log', {}).finally(function () {
                exportButton.disabled = false;
            });
        });
    }

    const emptyButton = document.getElementById('sjb-turnstile-empty');
    if (emptyButton) {
        emptyButton.addEventListener('click', function () {
            if (!window.confirm(window.sjbTurnstile.i18n.confirmEmpty)) {
                return;
            }

            emptyButton.disabled = true;
            request('empty_log', {}).finally(function () {
                emptyButton.disabled = false;
            });
        });
    }
})();
