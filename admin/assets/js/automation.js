(function ($) {
    'use strict';

    function getConfig() {
        return window.hrcmAutomation || {};
    }

    function parseJSON(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            if (typeof value === 'string') {
                return JSON.parse(value);
            }
            return value;
        } catch (e) {
            return fallback;
        }
    }

    function createOption(value, label, selected) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        if (selected) {
            option.selected = true;
        }
        return option;
    }

    function normalizeOperatorOptions(options) {
        if (!Array.isArray(options)) {
            return [];
        }

        return options.reduce(function (list, option) {
            if (typeof option === 'string') {
                list.push({ value: option, label: option });
                return list;
            }

            if (option && typeof option === 'object') {
                var value = option.value !== undefined ? option.value : option.key;
                if (value) {
                    list.push({
                        value: value,
                        label: option.label || value
                    });
                }
            }

            return list;
        }, []);
    }

    function translate(key, fallback) {
        var config = getConfig();
        if (config.i18n && config.i18n[key]) {
            return config.i18n[key];
        }

        if (window.wp && wp.i18n && wp.i18n.__) {
            return wp.i18n.__(fallback || key, 'hr-customer-manager');
        }

        return fallback || key;
    }

    function getFieldOptions(fieldKey) {
        var config = getConfig();
        if (config.fieldOptions && config.fieldOptions[fieldKey]) {
            return config.fieldOptions[fieldKey];
        }
        return [];
    }

    function buildConditionRow(index, condition, fieldConfig, operatorConfig) {
        condition = condition || {};
        var row = document.createElement('div');
        row.className = 'hrcm-condition-row';

        var field = condition.field || 'days_to_trip';
        var operator = condition.op || '=';
        var join = condition.join || 'AND';
        var value = condition.value;

        var fieldWrap = document.createElement('div');
        fieldWrap.className = 'hrcm-condition-field';
        var fieldSelect = document.createElement('select');
        fieldSelect.name = 'rule[conditions][' + index + '][field]';
        fieldSelect.className = 'hrcm-condition-select';
        Object.keys(fieldConfig).forEach(function (key) {
            var config = fieldConfig[key];
            fieldSelect.appendChild(createOption(key, config.label || key, key === field));
        });
        fieldWrap.appendChild(fieldSelect);
        row.appendChild(fieldWrap);

        var operatorWrap = document.createElement('div');
        operatorWrap.className = 'hrcm-condition-operator';
        var operatorSelect = document.createElement('select');
        operatorSelect.name = 'rule[conditions][' + index + '][op]';
        operatorSelect.className = 'hrcm-condition-select';
        operatorWrap.appendChild(operatorSelect);
        row.appendChild(operatorWrap);

        var valueWrap = document.createElement('div');
        valueWrap.className = 'hrcm-condition-value';
        row.appendChild(valueWrap);

        var joinWrap = document.createElement('div');
        joinWrap.className = 'hrcm-condition-join';
        var joinSelect = document.createElement('select');
        joinSelect.name = 'rule[conditions][' + index + '][join]';
        joinSelect.className = 'hrcm-condition-select';
        joinSelect.appendChild(createOption('AND', 'AND', join === 'AND'));
        joinSelect.appendChild(createOption('OR', 'OR', join === 'OR'));
        joinWrap.appendChild(joinSelect);
        row.appendChild(joinWrap);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-link delete hrcm-remove-condition';
        remove.textContent = translate('remove', 'Remove');
        remove.addEventListener('click', function () {
            row.parentNode.removeChild(row);
        });
        row.appendChild(remove);

        function getFieldType(key) {
            return (fieldConfig[key] && fieldConfig[key].type) || 'string';
        }

        function collectCurrentValue() {
            if (!valueWrap.firstChild) {
                return value;
            }

            if (operatorSelect.value === 'between') {
                var inputs = valueWrap.querySelectorAll('input');
                if (inputs.length >= 2) {
                    return {
                        min: inputs[0].value,
                        max: inputs[1].value
                    };
                }
                return value;
            }

            if (getFieldType(fieldSelect.value) === 'enum') {
                var select = valueWrap.querySelector('select');
                if (select) {
                    return $(select).val() || [];
                }
            }

            var input = valueWrap.querySelector('input');
            if (input) {
                return input.value;
            }

            return value;
        }

        function populateOperators(fieldKey, preserve) {
            var type = getFieldType(fieldKey);
            var options = normalizeOperatorOptions(operatorConfig[type] || []);
            operatorSelect.innerHTML = '';
            if (!options.length) {
                var fallback = preserve ? (operatorSelect.value || operator) : operator;
                fallback = fallback || '=';
                operatorSelect.appendChild(createOption(fallback, fallback, true));
                operator = fallback;
                return;
            }
            var chosen = preserve ? operatorSelect.value : operator;
            var values = options.map(function (item) { return item.value; });
            if (values.indexOf(chosen) === -1) {
                chosen = values[0] || '=';
            }
            options.forEach(function (op) {
                operatorSelect.appendChild(createOption(op.value, op.label || op.value, op.value === chosen));
            });
            operator = chosen;
        }

        function renderValue(fieldKey, op, preserve) {
            var current = preserve ? collectCurrentValue() : value;
            valueWrap.innerHTML = '';
            var type = getFieldType(fieldKey);
            var options = getFieldOptions(fieldKey);

            if (op === 'between') {
                var min = current && current.min !== undefined ? current.min : '';
                var max = current && current.max !== undefined ? current.max : '';
                var minInput = document.createElement('input');
                minInput.type = 'number';
                minInput.name = 'rule[conditions][' + index + '][value][min]';
                minInput.className = 'small-text';
                minInput.value = min;
                var maxInput = document.createElement('input');
                maxInput.type = 'number';
                maxInput.name = 'rule[conditions][' + index + '][value][max]';
                maxInput.className = 'small-text';
                maxInput.value = max;
                valueWrap.appendChild(minInput);
                valueWrap.appendChild(document.createTextNode(' – '));
                valueWrap.appendChild(maxInput);
                value = { min: min, max: max };
                return;
            }

            if (op === 'is empty' || op === 'is not empty') {
                value = '';
                return;
            }

            if (type === 'enum') {
                var multi = op === 'in' || op === 'not in';
                var select = document.createElement('select');
                select.name = 'rule[conditions][' + index + '][value]' + (multi ? '[]' : '');
                select.className = 'hrcm-condition-select';
                if (multi) {
                    select.multiple = true;
                }
                var currentValues = [];
                if (Array.isArray(current)) {
                    currentValues = current;
                } else if (typeof current === 'string' && current.length) {
                    currentValues = current.split(',').map(function (item) { return item.trim(); });
                }
                options.forEach(function (optionValue) {
                    select.appendChild(createOption(optionValue, optionValue, currentValues.indexOf(optionValue) !== -1));
                });
                valueWrap.appendChild(select);
                value = currentValues;
                return;
            }

            if (current && typeof current === 'object') {
                current = '';
            }

            var input = document.createElement('input');
            input.type = type === 'number' ? 'number' : 'text';
            input.name = 'rule[conditions][' + index + '][value]';
            input.className = 'regular-text';
            input.value = (current !== undefined && current !== null) ? current : '';
            valueWrap.appendChild(input);
            value = input.value;
        }

        populateOperators(field, false);
        renderValue(field, operator, false);

        fieldSelect.addEventListener('change', function () {
            var selectedField = fieldSelect.value;
            populateOperators(selectedField, false);
            renderValue(selectedField, operatorSelect.value, false);
        });

        operatorSelect.addEventListener('change', function () {
            renderValue(fieldSelect.value, operatorSelect.value, true);
        });

        return row;
    }

    function initConditions() {
        var metabox = document.getElementById('hrcm-conditions-metabox');
        if (!metabox) {
            return;
        }

        var list = document.getElementById('hrcm-conditions-container');
        var fieldConfig = parseJSON(metabox.dataset.fields, {});
        var operatorConfig = parseJSON(metabox.dataset.operators, {});
        var conditions = parseJSON(metabox.dataset.conditions, []);

        function appendCondition(condition) {
            var index = list.children.length;
            var row = buildConditionRow(index, condition, fieldConfig, operatorConfig);
            list.appendChild(row);
        }

        if (!conditions.length) {
            conditions.push({ field: 'days_to_trip', op: '=', value: '' });
        }

        conditions.forEach(function (condition) {
            appendCondition(condition);
        });

        $('#hrcm-add-condition').on('click', function () {
            appendCondition({ field: 'days_to_trip', op: '=', value: '' });
        });
    }

    function buildHeaderRow(index, header) {
        header = header || { k: '', v: '' };
        var row = document.createElement('div');
        row.className = 'hrcm-header-row';

        var keyInput = document.createElement('input');
        keyInput.type = 'text';
        keyInput.className = 'regular-text';
        keyInput.name = 'rule[action][headers][' + index + '][k]';
        keyInput.value = header.k || '';
        row.appendChild(keyInput);

        var valueInput = document.createElement('input');
        valueInput.type = 'password';
        valueInput.className = 'regular-text';
        valueInput.name = 'rule[action][headers][' + index + '][v]';
        valueInput.value = header.v || '';
        row.appendChild(valueInput);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'button-link';
        toggle.textContent = translate('show', 'Show');
        toggle.addEventListener('click', function () {
            var isPassword = valueInput.type === 'password';
            valueInput.type = isPassword ? 'text' : 'password';
            toggle.textContent = isPassword ? translate('hide', 'Hide') : translate('show', 'Show');
        });
        row.appendChild(toggle);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-link delete';
        remove.textContent = translate('remove', 'Remove');
        remove.addEventListener('click', function () {
            row.parentNode.removeChild(row);
        });
        row.appendChild(remove);

        return row;
    }

    function initHeaders() {
        var container = document.getElementById('hrcm-headers-container');
        if (!container) {
            return;
        }

        var headers = parseJSON(container.dataset.headers, []);

        function render() {
            container.innerHTML = '';
            headers.forEach(function (header, index) {
                container.appendChild(buildHeaderRow(index, header));
            });
        }

        render();

        $('#hrcm-add-header').on('click', function () {
            headers.push({ k: '', v: '' });
            render();
        });
    }

    function initTestSend() {
        var button = document.getElementById('hrcm-test-webhook');
        if (!button) {
            return;
        }

        var form = button.closest('form');
        var result = document.getElementById('hrcm-test-result');
        var spinner = $(button).siblings('.spinner');
        var config = getConfig();

        button.addEventListener('click', function () {
            if (!config.ajaxUrl) {
                return;
            }

            spinner.addClass('is-active');
            result.textContent = config.i18n.testRunning || 'Testing…';

            var payload = $(form).serialize();

            $.post(config.ajaxUrl, {
                action: 'hrcm_automation_test_send',
                nonce: config.testNonce,
                form: payload
            })
                .done(function (response) {
                    if (!response || !response.success) {
                        result.textContent = (response && response.data && response.data.message) ? response.data.message : config.i18n.testFailed;
                        return;
                    }

                    var data = response.data;
                    var snippet = data.body || '';
                    if (snippet.length > 1000) {
                        snippet = snippet.substring(0, 1000) + '…';
                    }

                    result.innerHTML = '<strong>' + translate('statusLabel', 'Status:') + '</strong> ' + data.status + '<br />' +
                        '<strong>' + translate('latencyLabel', 'Latency:') + '</strong> ' + (data.latency ? data.latency.toFixed(2) : '0.00') + 's' +
                        '<pre class="hrcm-test-body"></pre>';
                    var pre = result.querySelector('pre');
                    if (pre) {
                        pre.textContent = snippet;
                    }
                })
                .fail(function () {
                    result.textContent = config.i18n.testFailed;
                })
                .always(function () {
                    spinner.removeClass('is-active');
                });
        });
    }

    $(function () {
        initConditions();
        initHeaders();
        initTestSend();
    });
})(jQuery);
