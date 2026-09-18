<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Provider;

use Calmfox\Smtp\Core\Provider\ProviderCatalog;

/**
 * A provider's name as a person reads it.
 *
 * Brand names are not translated — Brevo is Brevo in every language — and the one entry that is
 * a phrase rather than a name is the custom preset. Having this in one place keeps the three
 * screens that name a provider from disagreeing about it.
 */
final class ProviderLabel
{
    public static function of(string $providerId): string
    {
        $provider = ProviderCatalog::get($providerId);

        return $provider->isCustom() ? (string) __('A server of my own') : $provider->label;
    }
}
