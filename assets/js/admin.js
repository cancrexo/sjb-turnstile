(function () {
    let toastTimer = 0;

    /**
     * Aviso abajo a la derecha.
     *
     * @param {string} level
     * @param {string} message
     */
    function show(level, message) {
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
    }

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

                if (!data.silent) {
                    show(level, message);
                }

                if (task === 'list_log' && payload.success) {
                    paintLog(data);
                }

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
                    paintLog({
                        body: '',
                        pager: '',
                        total: 0,
                        total_label: '',
                    });
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

    const testButton = document.getElementById('sjb-turnstile-test');
    if (testButton) {
        let widgetId = null;
        let verifying = false;

        testButton.addEventListener('click', function () {
            const siteInput = document.getElementById('sjb-turnstile-site-key');
            const secretInput = document.getElementById('sjb-turnstile-secret-key');
            const box = document.getElementById('sjb-turnstile-test-box');
            const mount = document.getElementById('sjb-turnstile-test-widget');
            const siteKey = siteInput ? siteInput.value.trim() : '';
            const secretKey = secretInput ? secretInput.value.trim() : '';

            if (!siteKey || !secretKey) {
                show('warning', window.sjbTurnstile.i18n.testKeys);
                return;
            }

            if (box) {
                box.hidden = false;
            }

            testButton.disabled = true;

            const paint = function () {
                try {
                    if (widgetId !== null && window.turnstile) {
                        window.turnstile.remove(widgetId);
                        widgetId = null;
                    }

                    if (mount) {
                        mount.textContent = '';
                    }

                    widgetId = window.turnstile.render(mount, {
                        sitekey: siteKey,
                        theme: 'auto',
                        size: 'normal',
                        callback: function (token) {
                            verifying = true;
                            testButton.disabled = true;
                            request('test_keys', {
                                site_key: siteKey,
                                secret_key: secretKey,
                                'cf-turnstile-response': token,
                            }).finally(function () {
                                verifying = false;
                                testButton.disabled = false;
                            });
                        },
                        'error-callback': function () {
                            show('error', window.sjbTurnstile.i18n.testWidget);
                            testButton.disabled = false;
                        },
                    });
                    if (!verifying) {
                        testButton.disabled = false;
                    }
                } catch (error) {
                    show('error', window.sjbTurnstile.i18n.testWidget);
                    testButton.disabled = false;
                }
            };

            if (window.turnstile) {
                paint();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.onload = paint;
            script.onerror = function () {
                show('error', window.sjbTurnstile.i18n.network);
                testButton.disabled = false;
            };
            document.head.appendChild(script);
        });
    }

    const exportButton = document.getElementById('sjb-turnstile-export');
    if (exportButton) {
        exportButton.addEventListener('click', function () {
            exportButton.disabled = true;
            request('export_log', {}).finally(function () {
                exportButton.disabled = false;
            });
        });
    }

    /**
     * Sustituye filas, paginador y total. Sin registros oculta la tabla.
     *
     * @param {{body?: string, pager?: string, total?: number, total_label?: string}} data
     */
    function paintLog(data) {
        const table = document.getElementById('sjb-turnstile-log-table');
        const empty = document.getElementById('sjb-turnstile-log-empty');
        const tbody = document.getElementById('sjb-turnstile-log-body');
        const pager = document.getElementById('sjb-turnstile-pager');
        const total = document.getElementById('sjb-turnstile-log-total');
        const has = (data.total || 0) > 0;

        if (tbody) {
            tbody.innerHTML = data.body || '';
        }
        if (pager) {
            pager.innerHTML = data.pager || '';
            pager.hidden = !has;
        }
        if (total) {
            total.textContent = data.total_label || '';
            total.hidden = !has;
        }
        if (table) {
            table.hidden = !has;
        }
        if (empty) {
            empty.hidden = has;
        }
    }

    let logLoading = false;

    /**
     * Muestra u oculta el aviso de espera sobre la tabla.
     *
     * @param {boolean} on
     */
    function setLogLoading(on) {
        const loading = document.getElementById('sjb-turnstile-log-loading');
        logLoading = on;
        if (loading) {
            loading.hidden = !on;
        }
    }

    /**
     * Pide una página del registro por AJAX. No recarga la pantalla ni muestra toast.
     *
     * @param {string|number} page
     */
    function loadLog(page) {
        if (logLoading) {
            return;
        }

        const perPage = document.getElementById('sjb-turnstile-per-page');
        setLogLoading(true);

        request('list_log', {
            page: String(page),
            per_page: perPage ? perPage.value : '20',
        }).finally(function () {
            setLogLoading(false);
        });
    }

    const logBox = document.querySelector('.sjb-turnstile-log');
    if (logBox) {
        logBox.addEventListener('click', function (event) {
            const button = event.target.closest('[data-page]');
            if (!button || button.disabled || button.getAttribute('data-page') === '0' || logLoading) {
                return;
            }

            loadLog(button.getAttribute('data-page'));
        });

        logBox.addEventListener('change', function (event) {
            if (event.target.id === 'sjb-turnstile-per-page' && !logLoading) {
                loadLog(1);
            }
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
