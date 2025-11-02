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
        $('.hr-cm-email-form').on('click', '.hr-cm-email-send', function () {
            var form = $(this).closest('.hr-cm-email-form');
            var select = form.find('.hr-cm-email-select');
            var selected = select.val();

            if (!selected) {
                select.focus();
                return;
            }

            showToast(hrCmAdmin.toastQueued);
            select.val('');
        });
    });
})(jQuery);
