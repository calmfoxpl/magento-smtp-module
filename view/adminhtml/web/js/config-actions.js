/**
 * The two buttons on the settings screen: check the connection, send a test message.
 *
 * Both post to the module's own controllers and render whatever sentences come back, in order.
 * The answer is written into the page rather than into a modal because it is several sentences
 * long — what happened, the one thing worth checking, and the server's own words — and because
 * an administrator will want to read it while editing the field it is about.
 */
define(['jquery', 'mage/translate'], function ($) {
    'use strict';

    function render(target, payload) {
        var list = $('<ul/>').addClass('calmfox-smtp-action__lines');

        (payload.lines || []).forEach(function (line) {
            list.append($('<li/>').text(line));
        });

        target
            .empty()
            .removeClass('calmfox-smtp-action__result--ok calmfox-smtp-action__result--bad')
            .addClass(payload.ok ? 'calmfox-smtp-action__result--ok' : 'calmfox-smtp-action__result--bad')
            .append($('<strong/>').text(payload.headline || ''))
            .append(list);
    }

    return function (selector) {
        var container = $(selector);

        if (!container.length) {
            return;
        }

        container.find('button').on('click', function (event) {
            var button = $(this),
                target = container.find('[data-role="result"]');

            event.preventDefault();
            button.prop('disabled', true);
            target.empty().text($.mage.__('Working on it…'));

            var payload = { form_key: window.FORM_KEY },
                recipient = container.find('[data-role="recipient"]');

            if (recipient.length && recipient.val()) {
                // The report page lets an address be typed next to the button, so that trying a
                // second mailbox does not mean editing the settings and saving them.
                payload.recipient = recipient.val();
            }

            $.ajax({
                url: container.data('url'),
                type: 'POST',
                dataType: 'json',
                data: payload,
                showLoader: false
            })
                .done(function (payload) {
                    render(target, payload || {});
                })
                .fail(function () {
                    render(target, {
                        ok: false,
                        headline: $.mage.__('The panel could not ask the server.'),
                        lines: [$.mage.__('This is usually a session that has expired. Reload the page and try again.')]
                    });
                })
                .always(function () {
                    button.prop('disabled', false);
                });
        });
    };
});
