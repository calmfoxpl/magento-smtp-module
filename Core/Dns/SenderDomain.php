<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

use Calmfox\Smtp\Core\Settings\MailSettings;

/**
 * Which domain the records are about.
 *
 * Not the mail server's domain — the sender's. A shop sending as `zamowienia@sklep.pl` through
 * `smtp-relay.brevo.com` is judged by what `sklep.pl` publishes, because that is the domain a
 * receiving server checks the message against. Getting this backwards would mean checking
 * Brevo's own records, which are always fine and tell nobody anything.
 *
 * The forced sender comes first because it is the address every message will actually carry;
 * the envelope sender next, because that is what receivers see in `MAIL FROM`; and the login
 * last, since at most providers it happens to be an address at the shop's own domain.
 */
final class SenderDomain
{
    public static function of(MailSettings $settings): ?string
    {
        foreach ([$settings->fromEmail, $settings->returnPath, $settings->username] as $candidate) {
            $domain = self::domainOf((string) $candidate);
            if (null !== $domain) {
                return $domain;
            }
        }

        return null;
    }

    public static function domainOf(string $address): ?string
    {
        $at = strrpos($address, '@');
        if (false === $at) {
            return null;
        }

        // A login is sometimes pasted with the display name still around it, and a trailing dot
        // is a perfectly valid way to write a fully qualified name.
        $domain = mb_strtolower(trim(substr($address, $at + 1), " \t.\r\n<>"));

        // A bare host name with no dot cannot have published anything, and a wildcard of a
        // domain is not one either: better to say we do not know than to look up nonsense.
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) ? $domain : null;
    }
}
