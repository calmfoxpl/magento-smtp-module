<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Provider;

use Calmfox\Smtp\Core\Settings\AuthMethod;
use Calmfox\Smtp\Core\Settings\Encryption;

/**
 * One e-mail provider, described well enough that nobody has to open a second browser tab.
 *
 * A preset is not only a host and a port. Most support tickets about SMTP are about the two
 * credential fields, because every provider means something different by them: SendGrid wants
 * the literal word `apikey` as the login, Postmark wants the same token twice, Amazon wants
 * credentials that look like AWS keys but are not. So the hints travel with the preset and are
 * shown next to the fields they describe, and where a provider has a habit of biting people —
 * a daily limit, a switch that has to be turned on first — that is written down as a caveat
 * rather than left for the shopkeeper to discover on the day of a sale.
 *
 * Hosts stay editable. A preset that guesses the wrong region is a starting point, not a cage.
 */
final class Provider
{
    /**
     * @param array<string, string> $hostVariants region or edition name => host, offered next to
     *                                            the default one because picking the wrong region
     *                                            is the most common reason a correct password is
     *                                            refused
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption = Encryption::TLS,
        public readonly string $authMethod = AuthMethod::AUTO,
        public readonly string $usernameHint = '',
        public readonly string $passwordHint = '',
        public readonly string $caveat = '',
        public readonly ?string $pinnedUsername = null,
        public readonly bool $tokenInBothFields = false,
        public readonly array $hostVariants = [],
        public readonly ?string $docsUrl = null,
    ) {
    }

    /** True for the preset that stands for "a server of my own", which has nothing to prefill. */
    public function isCustom(): bool
    {
        return ProviderCatalog::CUSTOM === $this->id;
    }

    /**
     * The login this provider insists on, whatever was typed.
     *
     * SendGrid and Resend accept exactly one login and treat the key as the password; Postmark
     * wants its server token in both fields. Correcting that here, rather than in a red message
     * under the field, is the difference between a shop that sends and a ticket.
     */
    public function username(string $typed, string $password): string
    {
        if (null !== $this->pinnedUsername) {
            return $this->pinnedUsername;
        }
        if ($this->tokenInBothFields && '' === trim($typed)) {
            return $password;
        }

        return $typed;
    }

    /** @return list<string> every host this preset knows, the default first */
    public function hosts(): array
    {
        return array_values(array_unique(array_merge([$this->host], array_values($this->hostVariants))));
    }

    /** @return list<string> the sentences shown as help, in the order they should be read */
    public function hints(): array
    {
        return array_values(array_filter([$this->usernameHint, $this->passwordHint, $this->caveat]));
    }
}
