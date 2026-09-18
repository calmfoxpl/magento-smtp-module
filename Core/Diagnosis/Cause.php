<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Diagnosis;

/**
 * Why sending is not working, in the shopkeeper's terms rather than the protocol's.
 *
 * "535 5.7.8" is the truth but not an answer. Each of these causes maps to one sentence in the
 * panel and, where we can, to one thing to do about it — and they are deliberately coarse: a
 * cause that cannot be acted on differently from its neighbour should not be its own cause.
 *
 * The list is complete on purpose: the panel is tested for having a sentence for every entry,
 * so a new cause cannot reach an administrator as an empty red box.
 */
final class Cause
{
    public const OK = 'ok';

    /** The name does not resolve: a typo in the host, or DNS on the server is broken. */
    public const DNS_FAILURE = 'dns_failure';

    /** Something answered at that address and said no. Usually the wrong port. */
    public const CONNECT_REFUSED = 'connect_refused';

    /** Nothing answered. Nearly always the hosting company blocking the port outbound. */
    public const CONNECT_TIMEOUT = 'connect_timeout';

    public const NETWORK_UNREACHABLE = 'network_unreachable';

    /** TLS could not be agreed: an old server, or one that does not speak TLS on this port. */
    public const TLS_HANDSHAKE_FAILED = 'tls_handshake_failed';

    /** The certificate is not trusted — expired, self-signed, or for a different name. */
    public const CERTIFICATE_REJECTED = 'certificate_rejected';

    /** Something is listening, but it is not a mail server. A proxy, a web server, a firewall. */
    public const NOT_SMTP = 'not_smtp';

    /** The server greeted us with a refusal: a blocked address or a blacklisted IP. */
    public const GREETING_REJECTED = 'greeting_rejected';

    /** A temporary no: too many connections, or a rate limit. Worth retrying, not worth panic. */
    public const SERVER_BUSY = 'server_busy';

    public const EHLO_REJECTED = 'ehlo_rejected';

    /** We are set to use STARTTLS and the server does not offer it. */
    public const STARTTLS_UNSUPPORTED = 'starttls_unsupported';

    public const STARTTLS_REJECTED = 'starttls_rejected';

    /** Credentials are configured and the server offers no way to present them. */
    public const AUTH_UNSUPPORTED = 'auth_unsupported';

    /** The credentials were refused. The single most common real failure. */
    public const AUTH_REJECTED = 'auth_rejected';

    /** Authentication failed for now: throttling, or a provider asking us to slow down. */
    public const AUTH_TEMPORARY = 'auth_temporary';

    /** The conversation stopped mid-sentence. */
    public const TIMEOUT = 'timeout';

    /** The server said something we cannot make sense of. */
    public const PROTOCOL_ERROR = 'protocol_error';

    /** The connection worked but the message itself was refused (sender, recipient, quota). */
    public const MESSAGE_REJECTED = 'message_rejected';

    /** Sending is switched off, or the settings cannot produce a connection attempt. */
    public const NOT_CONFIGURED = 'not_configured';

    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::OK,
        self::DNS_FAILURE,
        self::CONNECT_REFUSED,
        self::CONNECT_TIMEOUT,
        self::NETWORK_UNREACHABLE,
        self::TLS_HANDSHAKE_FAILED,
        self::CERTIFICATE_REJECTED,
        self::NOT_SMTP,
        self::GREETING_REJECTED,
        self::SERVER_BUSY,
        self::EHLO_REJECTED,
        self::STARTTLS_UNSUPPORTED,
        self::STARTTLS_REJECTED,
        self::AUTH_UNSUPPORTED,
        self::AUTH_REJECTED,
        self::AUTH_TEMPORARY,
        self::TIMEOUT,
        self::PROTOCOL_ERROR,
        self::MESSAGE_REJECTED,
        self::NOT_CONFIGURED,
        self::UNKNOWN,
    ];

    /**
     * Causes that pass on their own: a rate limit, a busy server, a temporary refusal.
     *
     * These decide whether the health check shouts. A shop that hit a per-minute limit at noon
     * is not a shop that cannot send, and waking somebody for it teaches them to ignore us.
     */
    public const TRANSIENT = [
        self::SERVER_BUSY,
        self::AUTH_TEMPORARY,
        self::CONNECT_TIMEOUT,
        self::TIMEOUT,
    ];

    /** Causes where somebody has to change a setting; nothing gets better by waiting. */
    public const NEEDS_A_HUMAN = [
        self::DNS_FAILURE,
        self::CONNECT_REFUSED,
        self::CERTIFICATE_REJECTED,
        self::NOT_SMTP,
        self::STARTTLS_UNSUPPORTED,
        self::AUTH_UNSUPPORTED,
        self::AUTH_REJECTED,
        self::NOT_CONFIGURED,
    ];

    public static function isTransient(string $cause): bool
    {
        return \in_array($cause, self::TRANSIENT, true);
    }

    public static function needsAHuman(string $cause): bool
    {
        return \in_array($cause, self::NEEDS_A_HUMAN, true);
    }
}
