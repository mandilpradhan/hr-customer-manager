(function (window, document, $, config) {
    'use strict';

    if (!config || !config.viewMap) {
        return;
    }

    var root = document.getElementById('hrcm-view-root');
    if (!root) {
        return;
    }

    var tabBar = document.querySelector('.hrcm-tab-bar');
    var activeView = null;
    var activeController = null;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setActiveTab(view) {
        if (!tabBar) {
            return;
        }

        var buttons = tabBar.querySelectorAll('[data-view]');
        buttons.forEach(function (button) {
            var isActive = button.getAttribute('data-view') === view;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    function renderLoading() {
        root.innerHTML = '<p class="description">' + escapeHtml(config.strings && config.strings.loading ? config.strings.loading : 'Loading…') + '</p>';
    }

    function renderNotice(type, message) {
        root.innerHTML = '<div class="notice notice-' + type + '"><p>' + escapeHtml(message) + '</p></div>';
    }

    function renderTable(columns, rows) {
        if (!Array.isArray(columns) || columns.length === 0) {
            renderNotice('info', config.strings && config.strings.empty ? config.strings.empty : 'No data available.');
            return;
        }

        var table = document.createElement('table');
        table.className = 'wp-list-table widefat fixed striped';

        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        columns.forEach(function (label) {
            var th = document.createElement('th');
            th.scope = 'col';
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        if (!Array.isArray(rows) || rows.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = columns.length;
            emptyCell.innerHTML = '<em>' + escapeHtml(config.strings && config.strings.empty ? config.strings.empty : 'No trips found.') + '</em>';
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
        } else {
            rows.forEach(function (row) {
                var tr = document.createElement('tr');
                columns.forEach(function (_, index) {
                    var cell = document.createElement('td');
                    var value = row && row[index] !== undefined ? row[index] : '';
                    cell.textContent = value;
                    tr.appendChild(cell);
                });
                tbody.appendChild(tr);
            });
        }

        table.appendChild(tbody);
        root.innerHTML = '';
        root.appendChild(table);
    }

    function loadView(view) {
        if (!config.viewMap || !config.viewMap[view]) {
            return;
        }

        if (activeController) {
            activeController.abort();
        }

        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        activeController = controller;
        activeView = view;

        setActiveTab(view);
        renderLoading();

        var form = new window.FormData();
        form.append('action', config.viewMap[view]);
        form.append('nonce', config.nonce);

        var fetchOptions = {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        };

        if (controller) {
            fetchOptions.signal = controller.signal;
        }

        window.fetch(config.ajaxUrl, fetchOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                if (controller && controller.signal.aborted) {
                    return;
                }

                if (!payload || !payload.success || !payload.data) {
                    throw new Error('Invalid response');
                }

                renderTable(payload.data.columns, payload.data.rows);
            })
            .catch(function (error) {
                if (controller && controller.signal.aborted) {
                    return;
                }
                console.error('HR_CM overall trip overview request failed:', error);
                renderNotice('error', config.strings && config.strings.error ? config.strings.error : 'Unable to load data.');
            })
            .finally(function () {
                if (controller === activeController) {
                    activeController = null;
                }
            });
    }

    if (tabBar) {
        tabBar.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || target.disabled) {
                return;
            }

            var button = target.closest('[data-view]');
            if (!button) {
                return;
            }

            var view = button.getAttribute('data-view');
            if (!view || view === activeView) {
                return;
            }

            event.preventDefault();
            loadView(view);
        });
    }

    loadView('overall');
})(window, document, window.jQuery, window.hrCmOverallTrip || {});
