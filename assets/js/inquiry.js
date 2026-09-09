/**
 * Portal Cloud 9 - Product Inquiry Shortcode JS
 * Uses event delegation on .portalcloud9-inquiry containers
 */
jQuery(function ($) {
    'use strict';

    // Initialize each inquiry widget on the page
    $('.portalcloud9-inquiry').each(function () {
        var $widget  = $(this);
        var $box     = $widget.find('.portalcloud9-inquiry-box');
        var $trigger = $widget.find('.portalcloud9-inquiry-trigger');
        var $close   = $widget.find('.portalcloud9-inquiry-close');
        var $form    = $widget.find('.portalcloud9-inquiry-form');
        var $toast   = $widget.find('.portalcloud9-inquiry-toast');

        /* open / close */
        $trigger.on('click', function () {
            $box.toggleClass('portalcloud9-inquiry-open');
        });
        $close.on('click', function () {
            $box.removeClass('portalcloud9-inquiry-open');
        });

        /* toast helper */
        function showToast(msg, type) {
            $toast.removeClass('success error').addClass(type);
            $toast.find('.portalcloud9-toast-text').text(msg);
            $toast.addClass('show');
            setTimeout(function () {
                $toast.removeClass('show');
            }, 3000);
        }

        /* send message */
        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn         = $form.find('button[type="submit"]');
            var originalText = $btn.text();
            var nonce = (typeof portalcloud9_inquiry_data !== 'undefined') ? portalcloud9_inquiry_data.nonce : '';
            var ajaxUrl = (typeof portalcloud9_inquiry_data !== 'undefined') ? portalcloud9_inquiry_data.ajax_url : '';

            $btn.prop('disabled', true).text('Sending\u2026');

            var fd = new FormData();
            fd.append('action',     'portalcloud9_send_product_inquiry');
            fd.append('nonce',      nonce);
            fd.append('product_id', $form.data('product-id'));
            fd.append('message',    $form.find('textarea[name="message"]').val());

            var $guestName  = $form.find('input[name="guest_name"]');
            var $guestEmail = $form.find('input[name="guest_email"]');
            fd.append('guest_name',  $guestName.length  ? $guestName.val()  : '');
            fd.append('guest_email', $guestEmail.length ? $guestEmail.val() : '');

            $.ajax({
                url:         ajaxUrl,
                method:      'POST',
                data:        fd,
                processData: false,
                contentType: false,
                dataType:    'json',
                timeout:     30000
            }).done(function (res) {
                if (res.success) {
                    $form[0].reset();
                    showToast('\u2705 Message sent!', 'success');
                    setTimeout(function () {
                        $box.removeClass('portalcloud9-inquiry-open');
                    }, 1200);
                } else {
                    showToast(res.data || '\u274c Failed \u2013 please try again', 'error');
                }
            }).fail(function () {
                showToast('\u274c Network error \u2013 please try again', 'error');
            }).always(function () {
                $btn.prop('disabled', false).text(originalText);
            });
        });
    });
});
