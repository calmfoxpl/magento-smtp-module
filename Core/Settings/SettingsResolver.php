<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

use Calmfox\Smtp\Core\Provider\Provider;
use Calmfox\Smtp\Core\Provider\ProviderCatalog;

/**
 * Turns what is in the configuration into what we will dial, and says what looks wrong.
 *
 * Two jobs that belong together. Forgiving where forgiveness is harmless — a host pasted as
 * `smtp://host:587` is a host and a port, an App password copied with the spaces Google shows
 * is still the password, a blank port means the preset's port — and blunt where it is not: the
 * combinations that cannot work (465 without encryption, credentials with nowhere to send them)
 * are reported before anything is dialled, because a timeout tells an administrator nothing and
 * "port 465 needs TLS from the first byte" tells them everything.
 *
 * Nothing here talks to a network or to Magento, so every rule is a test that runs in a
 * millisecond.
 */
final class SettingsResolver
{
    /**
     * @param array<string, mixed> $raw   the module's own configuration group
     * @param array<string, mixed> $magentoSmtp Magento's `system/smtp` group, which decides
     *                                          whether sending is switched off at all and may
     *                                          hold a second, contradicting server
     */
    public function resolve(array $raw, array $magentoSmtp = []): ResolvedSettings
    {
        $provider = ProviderCatalog::get(trim((string) ($raw['provider'] ?? ProviderCatalog::CUSTOM)));
        $enabled = self::flag($raw['enabled'] ?? false);

        [$host, $portFromHost] = self::splitHost((string) ($raw['host'] ?? ''), $provider);
        $port = self::port($raw['port'] ?? null, $portFromHost, $provider);
        $encryption = \array_key_exists('encryption', $raw)
            ? Encryption::normalize($raw['encryption'])
            : $provider->encryption;
        $authMethod = self::authMethod($raw['auth'] ?? null, $provider);
        $password = self::password((string) ($raw['password'] ?? ''), $provider);
        $username = trim($provider->username(trim((string) ($raw['username'] ?? '')), $password));

        $settings = new MailSettings(
            enabled: $enabled,
            providerId: $provider->id,
            host: $host,
            port: $port,
            encryption: $encryption,
            authMethod: $authMethod,
            username: $username,
            password: $password,
            timeout: self::timeout($raw['timeout'] ?? null),
            fromName: self::text($raw['from_name'] ?? null),
            fromEmail: self::text($raw['from_email'] ?? null),
            returnPath: self::text($raw['return_path'] ?? null),
            verifyCertificate: self::flag($raw['verify_certificate'] ?? true),
        );

        return new ResolvedSettings($settings, $enabled ? $this->inspect($settings, $raw, $magentoSmtp) : []);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $magentoSmtp
     *
     * @return list<Issue>
     */
    private function inspect(MailSettings $settings, array $raw, array $magentoSmtp): array
    {
        $issues = [];

        if (self::flag($magentoSmtp['disable'] ?? false)) {
            // Nothing below matters while this is on: Magento drops every message before a
            // transport is asked for it, so even a perfect configuration sends nothing.
            $issues[] = Issue::error(IssueCode::EMAIL_DISABLED_IN_MAGENTO);
        }

        if ('' === $settings->host) {
            $issues[] = Issue::error(IssueCode::HOST_MISSING);
        }

        $rawPort = (int) ($raw['port'] ?? 0);
        if (0 !== $rawPort && ($rawPort < 1 || $rawPort > 65535)) {
            $issues[] = Issue::error(IssueCode::PORT_INVALID, [$rawPort]);
        }

        if (AuthMethod::NONE !== $settings->authMethod) {
            if ('' === $settings->username) {
                $issues[] = Issue::error(IssueCode::USERNAME_MISSING);
            }
            if ('' === $settings->password) {
                $issues[] = Issue::error(IssueCode::PASSWORD_MISSING);
            }
        }

        $implicitPort = \in_array($settings->port, Encryption::IMPLICIT_TLS_PORTS, true);

        if ($implicitPort && Encryption::NONE === $settings->encryption) {
            $issues[] = Issue::error(IssueCode::ENCRYPTION_MISSING_ON_IMPLICIT_PORT, [$settings->port]);
        }
        if ($implicitPort && Encryption::TLS === $settings->encryption) {
            $issues[] = Issue::warning(IssueCode::STARTTLS_ON_IMPLICIT_PORT, [$settings->port]);
        }
        if (!$implicitPort && Encryption::SSL === $settings->encryption) {
            $issues[] = Issue::warning(IssueCode::IMPLICIT_TLS_ON_STARTTLS_PORT, [$settings->port]);
        }
        if (Encryption::NONE === $settings->encryption && '' !== $settings->password) {
            $issues[] = Issue::warning(IssueCode::CREDENTIALS_WITHOUT_ENCRYPTION);
        }
        if (25 === $settings->port) {
            $issues[] = Issue::warning(IssueCode::PORT_25);
        }
        if (!$settings->verifyCertificate) {
            $issues[] = Issue::warning(IssueCode::CERTIFICATE_CHECK_OFF);
        }
        if (null !== $settings->fromEmail && !self::isAddress($settings->fromEmail)) {
            $issues[] = Issue::warning(IssueCode::FROM_EMAIL_INVALID, [$settings->fromEmail]);
        }
        if (null !== $settings->returnPath && !self::isAddress($settings->returnPath)) {
            $issues[] = Issue::warning(IssueCode::RETURN_PATH_INVALID, [$settings->returnPath]);
        }

        $magentoHost = trim((string) ($magentoSmtp['host'] ?? ''));
        if ('smtp' === mb_strtolower(trim((string) ($magentoSmtp['transport'] ?? ''))) && '' !== $magentoHost && $magentoHost !== $settings->host) {
            // Harmless — we are in front of Magento's transport — but the next person to read
            // the configuration will find two different servers and believe the wrong one.
            $issues[] = Issue::warning(IssueCode::MAGENTO_SMTP_ALSO_CONFIGURED, [$magentoHost]);
        }

        return $issues;
    }

    /**
     * A host as typed, which is not always a host.
     *
     * People paste what their provider's page shows them, and that page often shows
     * `smtp://mail.example.com:587` or a trailing slash. Reading the port out of it is better
     * than refusing the line.
     *
     * @return array{0: string, 1: int} the host, and the port found in it or zero
     */
    private static function splitHost(string $typed, Provider $provider): array
    {
        $host = trim($typed);
        if ('' === $host) {
            return [$provider->host, 0];
        }

        $host = (string) preg_replace('#^[a-z0-9+.-]+://#i', '', $host);
        $host = rtrim($host, '/');
        $port = 0;

        if (preg_match('/^(?<host>\[[0-9a-f:]+\]|[^:]+):(?<port>\d{1,5})$/i', $host, $match)) {
            $host = $match['host'];
            $port = (int) $match['port'];
        }

        return [mb_strtolower(trim($host, '[]')), $port];
    }

    private static function port(mixed $configured, int $fromHost, Provider $provider): int
    {
        $port = (int) $configured;
        if ($port < 1 || $port > 65535) {
            $port = $fromHost;
        }
        if ($port < 1 || $port > 65535) {
            $port = $provider->port;
        }

        return $port;
    }

    private static function authMethod(mixed $configured, Provider $provider): string
    {
        if (null === $configured || '' === trim((string) $configured)) {
            return $provider->authMethod;
        }

        $method = AuthMethod::normalize($configured);

        // A preset that names a method knows something we do not: Gmail and Microsoft both
        // advertise mechanisms they will not complete. Let the preset win over "auto".
        return AuthMethod::AUTO === $method ? $provider->authMethod : $method;
    }

    /**
     * Google shows an App password as four groups of four, and half the people who copy it keep
     * the spaces. Google itself ignores them, so a password that differs from the working one
     * only by spaces is the working one.
     */
    private static function password(string $typed, Provider $provider): string
    {
        if ('gmail' === $provider->id) {
            return (string) preg_replace('/\s+/', '', $typed);
        }

        return $typed;
    }

    private static function timeout(mixed $configured): int
    {
        $timeout = (int) $configured;
        if ($timeout < MailSettings::MIN_TIMEOUT) {
            return MailSettings::DEFAULT_TIMEOUT;
        }

        return min($timeout, MailSettings::MAX_TIMEOUT);
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private static function isAddress(string $value): bool
    {
        return false !== filter_var($value, \FILTER_VALIDATE_EMAIL);
    }

    private static function flag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
