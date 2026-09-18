/**
 * Fills in the server fields when a provider is picked, and explains what that provider means by
 * its two credential fields.
 *
 * Two rules, both about not being clever at somebody else's expense:
 *
 *  - on page load nothing is written. The saved settings are the saved settings, even where they
 *    disagree with the preset, because a shop may have a good reason and being quietly corrected
 *    on load would be the module overruling it,
 *  - a field Magento has disabled — one inheriting from a wider scope, with "Use Default"
 *    ticked — is left alone. Writing to it would be writing to a field the administrator cannot
 *    see the effect of.
 */
define(['jquery', 'mage/translate'], function ($) {
    'use strict';

    function fill(field, value) {
        if (!field.length || field.prop('disabled') || value === null || typeof value === 'undefined') {
            return;
        }
        field.val(value).trigger('change');
    }

    function describe(container, preset) {
        var hints = container.find('[data-role="hints"]').empty(),
            hosts = container.find('[data-role="hosts"]').empty(),
            docs = container.find('[data-role="docs"]').empty(),
            empty = container.find('[data-role="empty"]');

        if (!preset) {
            empty.show();

            return;
        }

        empty.hide();
        (preset.hints || []).forEach(function (hint) {
            hints.append($('<li/>').text(hint));
        });

        var variants = Object.keys(preset.hostVariants || {});

        if (variants.length) {
            hosts.append($('<span/>').text($.mage.__('Other addresses for this provider:') + ' '));
            variants.forEach(function (region, index) {
                if (index > 0) {
                    hosts.append(document.createTextNode(', '));
                }
                hosts.append(
                    $('<a/>')
                        .attr('href', '#')
                        .text(region)
                        .on('click', function (event) {
                            event.preventDefault();
                            fill($('#' + container.data('calmfoxSmtpFields').host), preset.hostVariants[region]);
                        })
                );
            });
        }

        if (preset.docsUrl) {
            docs.append(
                $('<a/>')
                    .attr({ href: preset.docsUrl, target: '_blank', rel: 'noopener noreferrer' })
                    .text($.mage.__('The provider\'s own instructions'))
            );
        }
    }

    /** A login the provider insists on is shown, filled in and locked rather than corrected later. */
    function pinUsername(fields, preset) {
        var username = $('#' + fields.username);

        if (!username.length) {
            return;
        }

        if (preset && preset.pinnedUsername) {
            fill(username, preset.pinnedUsername);
            username.prop('readonly', true);

            return;
        }

        username.prop('readonly', false);
    }

    return function (selector) {
        var container = $(selector);

        if (!container.length) {
            return;
        }

        var presets = container.data('calmfoxSmtpPresets') || {},
            fields = container.data('calmfoxSmtpFields') || {},
            provider = $('#' + fields.provider);

        describe(container, presets[provider.val()]);
        pinUsername(fields, presets[provider.val()]);

        provider.on('change', function () {
            var preset = presets[$(this).val()];

            describe(container, preset);
            pinUsername(fields, preset);

            if (!preset || !preset.host) {
                return;
            }

            fill($('#' + fields.host), preset.host);
            fill($('#' + fields.port), preset.port);
            fill($('#' + fields.encryption), preset.encryption);
            fill($('#' + fields.auth), preset.auth);
        });
    };
});
