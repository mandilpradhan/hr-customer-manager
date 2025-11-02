(function ($) {
    'use strict';

    var state = window.hrcmState || {};
    var tripDeps = window.hrcmTripDeps || {};
    var pendingTrips = {};

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

    function fetchTripDepartures(tripName, selectedValue) {
        if (!tripName) {
            rebuildDepartureOptions('', selectedValue);
            return;
        }

        if (pendingTrips[tripName]) {
            return;
        }

        pendingTrips[tripName] = true;

        var ajaxUrl = (window.hrCmAdmin && hrCmAdmin.ajaxUrl) ? hrCmAdmin.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        var nonce = (window.hrCmAdmin && hrCmAdmin.departuresNonce) ? hrCmAdmin.departuresNonce : '';

        if (!ajaxUrl) {
            pendingTrips[tripName] = false;
            rebuildDepartureOptions(tripName, selectedValue);
            return;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'hrcm_get_departures',
                trip: tripName,
                _ajax_nonce: nonce
            }
        }).done(function (response) {
            var dates = [];

            if (response && response.success && $.isArray(response.data)) {
                dates = response.data;
            } else if (response && $.isArray(response.data)) {
                dates = response.data;
            }

            tripDeps[tripName] = dates;
        }).fail(function () {
            tripDeps[tripName] = tripDeps[tripName] || [];
        }).always(function () {
            pendingTrips[tripName] = false;
            rebuildDepartureOptions(tripName, selectedValue);
        });
    }

    function loadTripDepartures(tripName, selectedValue) {
        if (!tripName) {
            rebuildDepartureOptions('', selectedValue);
            return;
        }

        if (tripDeps[tripName]) {
            rebuildDepartureOptions(tripName, selectedValue);
            return;
        }

        fetchTripDepartures(tripName, selectedValue);
    }

    $(function () {
        var tripSelect = $('#hr-cm-trip');
        var departureSelect = $('#hr-cm-departure');
        var initialDeparture = departureSelect.data('selected') || '';

        if (tripSelect.length && departureSelect.length) {
            loadTripDepartures(tripSelect.val(), initialDeparture);

            tripSelect.on('change', function () {
                loadTripDepartures($(this).val(), '');
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

    });
})(jQuery);
