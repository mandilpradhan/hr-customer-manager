(function ($) {
    'use strict';

    var state = window.hrcmState || {};
    var tripDeps = window.hrcmTripDeps || {};

    function showToast(message) {
        var toast = $('.hr-cm-toast');
        if (!toast.length || !message) {
            return;
        }

        toast.text(message);
        toast.attr('aria-hidden', 'false');
        toast.addClass('is-visible');

        window.setTimeout(function () {
            toast.removeClass('is-visible');
            toast.attr('aria-hidden', 'true');
        }, 2500);
    }

    function showNotice(message) {
        if (!message) {
            return;
        }

        var container = $('.hr-cm-admin');
        if (!container.length) {
            return;
        }

        var notice = $('<div class="notice notice-info is-dismissible hrcm-inline-notice"><p></p></div>');
        notice.find('p').text(message);

        var dismissText = (window.hrCmAdmin && hrCmAdmin.dismissText) ? hrCmAdmin.dismissText : 'Dismiss this notice.';
        var dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>');
        dismissButton.find('span').text(dismissText);
        notice.append(dismissButton);

        container.find('.hrcm-inline-notice').remove();
        container.prepend(notice);

        if (window.wp && window.wp.a11y && window.wp.a11y.speak) {
            window.wp.a11y.speak(message);
        }
    }

    function rebuildDepartureOptions(tripName, selectedValue) {
        var departure = $('#hr-cm-departure');
        if (!departure.length) {
            return;
        }

        var dates = tripDeps[tripName] || [];
        var allLabel = departure.attr('data-all-label') || 'All Departures';
        var options = ['<option value="">' + allLabel + '</option>'];

        dates.forEach(function (date) {
            var selectedAttr = date === selectedValue ? ' selected="selected"' : '';
            options.push('<option value="' + date + '"' + selectedAttr + '>' + date + '</option>');
        });

        departure.html(options.join(''));

        departure.val('');

        if (selectedValue && dates.indexOf(selectedValue) !== -1) {
            departure.val(selectedValue);
        }
    }

    $(function () {
        var tripSelect = $('#hr-cm-trip');
        var departureSelect = $('#hr-cm-departure');
        var initialDeparture = departureSelect.data('selected') || '';

        if (tripSelect.length && departureSelect.length) {
            rebuildDepartureOptions(tripSelect.val(), initialDeparture);

            tripSelect.on('change', function () {
                rebuildDepartureOptions($(this).val(), '');
            });
        }

        $('.hrcm-table').on('click', '.hrcm-resend-btn', function () {
            var button = $(this);

            if (button.is(':disabled')) {
                return;
            }

            var container = button.closest('.hrcm-resend');
            var select = container.find('.hrcm-resend-select');
            var selected = select.val();

            if (!selected) {
                select.trigger('focus');
                return;
            }

            var message = (window.hrCmAdmin && hrCmAdmin.toastQueued) ? hrCmAdmin.toastQueued : '';
            showToast(message);
            showNotice((window.hrCmAdmin && hrCmAdmin.noticeQueued) ? hrCmAdmin.noticeQueued : message);
            select.val('');
        });

        $('.hr-cm-admin').on('click', '.hrcm-inline-notice .notice-dismiss', function (event) {
            event.preventDefault();
            $(this).closest('.hrcm-inline-notice').remove();
        });

        $('th.hrcm-th').on('click', function () {
            var sortKey = $(this).data('sort');
            if (!sortKey) {
                return;
            }

            var url = new URL(window.location.href);
            var currentSort = url.searchParams.get('sort') || state.sort || '';
            var currentDir = (url.searchParams.get('dir') || state.dir || 'asc').toLowerCase();
            var nextDir = 'asc';

            if (currentSort === sortKey) {
                nextDir = currentDir === 'asc' ? 'desc' : 'asc';
            }

            url.searchParams.set('sort', sortKey);
            url.searchParams.set('dir', nextDir);
            url.searchParams.set('paged', '1');

            window.location.href = url.toString();
        });

        $('#hrcm-per-page').on('change', function () {
            var perPage = $(this).val();
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', perPage);
            url.searchParams.set('paged', '1');
            window.location.href = url.toString();
        });
    });
})(jQuery);
