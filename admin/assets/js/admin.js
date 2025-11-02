(function ($) {
    'use strict';

    function showToast(message) {
        var toast = $('.hr-cm-toast');
        if (!toast.length) {
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

    $(function () {
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

            showToast(hrCmAdmin.toastQueued);
            select.val('');
        });
    });
})(jQuery);
