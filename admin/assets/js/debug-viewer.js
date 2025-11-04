(function ($) {
    'use strict';

    const $viewer = $('#hrcm-debug-viewer');
    if (!$viewer.length || 'undefined' === typeof hrCmDebugViewer) {
        return;
    }

    const state = {
        sections: hrCmDebugViewer.sections || {},
        sectionData: {},
        sectionModes: {},
        sectionVisibility: {},
        currentBookingId: null,
    };

    const $bookingSelect = $('#hrcm-debug-booking-select');
    const $bookingSearch = $('#hrcm-debug-booking-search');
    const $sectionToggles = $('.hrcm-debug-section-toggle');
    const $sectionsWrap = $('#hrcm-debug-sections');
    const $feedback = $('#hrcm-debug-feedback');
    const $loading = $('#hrcm-debug-loading');

    Object.keys(state.sections).forEach((sectionKey) => {
        state.sectionVisibility[sectionKey] = true;
        state.sectionModes[sectionKey] = 'pretty';
    });

    function setLoading(isLoading) {
        if (isLoading) {
            $loading.removeAttr('hidden');
        } else {
            $loading.attr('hidden', 'hidden');
        }
    }

    function showFeedback(message, type = 'info') {
        if (!message) {
            $feedback.empty();
            return;
        }

        const classes = {
            info: 'notice notice-info',
            success: 'notice notice-success',
            warning: 'notice notice-warning',
            error: 'notice notice-error',
        };

        const noticeClass = classes[type] || classes.info;
        const $notice = $('<div />', { class: noticeClass });
        $notice.append($('<p />').text(message));
        $feedback.empty().append($notice);
    }

    function filterBookings(term) {
        const searchTerm = term.toLowerCase();

        $bookingSelect.find('option').each(function () {
            const $option = $(this);
            const label = ($option.data('label') || '').toString().toLowerCase();
            const match = !searchTerm || label.includes(searchTerm);
            if (match) {
                $option.removeAttr('hidden');
            } else {
                $option.attr('hidden', 'hidden');
            }
        });
    }

    function formatPrettyRows(rows) {
        if (!Array.isArray(rows) || !rows.length) {
            return [{ key: '', value: hrCmDebugViewer.strings.noData, title: '' }];
        }

        return rows;
    }

    function renderSection(sectionKey, sectionData) {
        const $section = $sectionsWrap.find(`.hrcm-debug-section[data-section="${sectionKey}"]`);
        if (!$section.length) {
            return;
        }

        state.sectionData[sectionKey] = sectionData;

        const rows = formatPrettyRows(sectionData.pretty);
        const $tbody = $section.find('tbody');
        $tbody.empty();

        rows.forEach((row, index) => {
            const $tr = $('<tr />').attr('data-row-label', `R${index + 1}`);

            const $keyCell = $('<td />').addClass('hrcm-debug-key').text(row.key || '');
            const $valueCell = $('<td />').addClass('hrcm-debug-value').text(row.value || '');

            if (row.title) {
                $valueCell.attr('title', row.title);
            }

            $tr.append($keyCell, $valueCell);
            $tbody.append($tr);
        });

        const $raw = $section.find('.hrcm-debug-raw');
        const rawContent = sectionData.raw || '';
        if (rawContent) {
            $raw.text(rawContent);
        } else {
            $raw.text('');
        }
    }

    function renderSections(data) {
        Object.keys(state.sections).forEach((sectionKey) => {
            if (data.sections && data.sections[sectionKey]) {
                renderSection(sectionKey, data.sections[sectionKey]);
            } else {
                renderSection(sectionKey, { pretty: [], raw: '' });
            }
        });

        if (Array.isArray(data.warnings) && data.warnings.length) {
            showFeedback(data.warnings.join(' '), 'warning');
        } else {
            showFeedback('');
        }
    }

    function setSectionMode(sectionKey, mode) {
        state.sectionModes[sectionKey] = mode;
        const $section = $sectionsWrap.find(`.hrcm-debug-section[data-section="${sectionKey}"]`);
        const $body = $section.find('.hrcm-debug-section__body');
        $body.attr('data-view', mode);

        $section.find('.hrcm-debug-view-button').each(function () {
            const $button = $(this);
            const buttonMode = $button.data('mode');
            if (buttonMode === mode) {
                $button.addClass('is-active');
            } else {
                $button.removeClass('is-active');
            }
        });
    }

    function toggleSection(sectionKey, forceVisible) {
        const isVisible = 'boolean' === typeof forceVisible ? forceVisible : !state.sectionVisibility[sectionKey];
        state.sectionVisibility[sectionKey] = isVisible;

        const $section = $sectionsWrap.find(`.hrcm-debug-section[data-section="${sectionKey}"]`);
        const $toggle = $sectionToggles.filter(`[data-section="${sectionKey}"]`);

        if (isVisible) {
            $section.removeAttr('hidden');
            $toggle.addClass('is-active');
        } else {
            $section.attr('hidden', 'hidden');
            $toggle.removeClass('is-active');
        }
    }

    function copyText(text) {
        if (!text) {
            return Promise.reject(new Error('empty'));
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        const $temp = $('<textarea>', { css: { position: 'fixed', top: '-9999px' } }).text(text);
        $('body').append($temp);
        $temp[0].select();

        let success = false;
        try {
            success = document.execCommand('copy');
        } catch (err) {
            success = false;
        }

        $temp.remove();

        return success ? Promise.resolve() : Promise.reject(new Error('copy'));
    }

    function buildPrettyText(sectionKey) {
        const sectionData = state.sectionData[sectionKey];
        if (!sectionData || !Array.isArray(sectionData.pretty)) {
            return '';
        }

        return sectionData.pretty.map((row) => `${row.key || ''}\t${row.value || ''}`).join('\n');
    }

    function getSectionCopyPayload(sectionKey) {
        const mode = state.sectionModes[sectionKey] || 'pretty';
        const data = state.sectionData[sectionKey];
        if (!data) {
            return '';
        }

        if ('raw' === mode) {
            return data.raw || '';
        }

        return buildPrettyText(sectionKey);
    }

    function copySection(sectionKey) {
        const text = getSectionCopyPayload(sectionKey);
        if (!text) {
            showFeedback(hrCmDebugViewer.strings.copyFailure, 'warning');
            return;
        }

        copyText(text)
            .then(() => {
                showFeedback(hrCmDebugViewer.strings.copySuccess, 'success');
            })
            .catch(() => {
                showFeedback(hrCmDebugViewer.strings.copyFailure, 'error');
            });
    }

    function copyAllVisible() {
        const parts = [];
        Object.keys(state.sections).forEach((sectionKey) => {
            if (!state.sectionVisibility[sectionKey]) {
                return;
            }

            const payload = getSectionCopyPayload(sectionKey);
            if (payload) {
                parts.push(`${state.sections[sectionKey]}\n${payload}`);
            }
        });

        if (!parts.length) {
            showFeedback(hrCmDebugViewer.strings.copyFailure, 'warning');
            return;
        }

        copyText(parts.join('\n\n'))
            .then(() => {
                showFeedback(hrCmDebugViewer.strings.copySuccess, 'success');
            })
            .catch(() => {
                showFeedback(hrCmDebugViewer.strings.copyFailure, 'error');
            });
    }

    function fetchBookingData(bookingId) {
        if (!bookingId) {
            renderSections({ sections: {}, warnings: [] });
            return;
        }

        setLoading(true);
        showFeedback('');

        $.ajax({
            url: hrCmDebugViewer.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'hrcm_debug_viewer_get_booking',
                nonce: hrCmDebugViewer.nonce,
                bookingId: bookingId,
            },
        })
            .done((response) => {
                if (response && response.success && response.data) {
                    state.currentBookingId = bookingId;
                    renderSections(response.data);
                } else if (response && response.data && response.data.message) {
                    showFeedback(response.data.message, 'error');
                } else {
                    showFeedback(hrCmDebugViewer.strings.errorPrefix + ' ' + hrCmDebugViewer.strings.noData, 'error');
                }
            })
            .fail((xhr) => {
                const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : hrCmDebugViewer.strings.errorPrefix + ' ' + hrCmDebugViewer.strings.noData;
                showFeedback(message, 'error');
            })
            .always(() => {
                setLoading(false);
            });
    }

    $bookingSearch.on('input', function () {
        filterBookings($(this).val() || '');
    });

    $bookingSelect.on('change', function () {
        const bookingId = parseInt($(this).val(), 10);
        fetchBookingData(bookingId);
    });

    $sectionToggles.on('click', function () {
        const sectionKey = $(this).data('section');
        toggleSection(sectionKey);
    });

    $sectionsWrap.on('click', '.hrcm-debug-view-button', function () {
        const $button = $(this);
        const sectionKey = $button.closest('.hrcm-debug-section').data('section');
        const mode = $button.data('mode');
        setSectionMode(sectionKey, mode);
    });

    $sectionsWrap.on('click', '.hrcm-debug-copy-section', function () {
        const sectionKey = $(this).data('section');
        if (!state.sectionVisibility[sectionKey]) {
            showFeedback(hrCmDebugViewer.strings.copyFailure, 'warning');
            return;
        }
        copySection(sectionKey);
    });

    $('#hrcm-debug-copy-all').on('click', function () {
        copyAllVisible();
    });

    // Initialise section modes and visibility.
    Object.keys(state.sections).forEach((sectionKey) => {
        setSectionMode(sectionKey, 'pretty');
        toggleSection(sectionKey, true);
    });

    // Load initial booking if available.
    const initialBookingId = parseInt($bookingSelect.val(), 10);
    if (initialBookingId) {
        fetchBookingData(initialBookingId);
    } else {
        renderSections({ sections: {}, warnings: [] });
    }
})(jQuery);
