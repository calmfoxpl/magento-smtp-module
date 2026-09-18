<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

use Calmfox\Smtp\Core\Diagnosis\Cause;

/**
 * What one check of the connection found.
 *
 * Deliberately more than a yes or a no: the stage, the cause, the server's own words and how
 * long it took. The panel shows all four, because "we could not log in" plus the server's
 * `535 5.7.8 Username and Password not accepted` is a report a hosting company can act on,
 * while a red light is a second ticket.
 *
 * It says nothing about delivery. A server that accepts a connection and a password can still
 * drop the message, and a check that claimed otherwise would be the kind of green light that
 * costs somebody a day of orders.
 */
final class ProbeResult
{
    /**
     * @param list<string> $capabilities what the server advertised in its EHLO reply
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $stage,
        public readonly string $cause,
        public readonly ?int $replyCode = null,
        public readonly string $reply = '',
        public readonly array $capabilities = [],
        public readonly ?string $mechanism = null,
        public readonly int $milliseconds = 0,
        public readonly bool $authenticated = false,
        public readonly bool $encrypted = false,
    ) {
    }

    /** @param list<string> $capabilities */
    public static function succeeded(
        array $capabilities = [],
        ?string $mechanism = null,
        int $milliseconds = 0,
        bool $authenticated = false,
        bool $encrypted = false,
    ): self {
        return new self(
            ok: true,
            stage: Stage::DONE,
            cause: Cause::OK,
            capabilities: $capabilities,
            mechanism: $mechanism,
            milliseconds: $milliseconds,
            authenticated: $authenticated,
            encrypted: $encrypted,
        );
    }

    /** @param list<string> $capabilities */
    public static function failed(
        string $stage,
        string $cause,
        ?int $replyCode = null,
        string $reply = '',
        array $capabilities = [],
        int $milliseconds = 0,
        bool $encrypted = false,
    ): self {
        return new self(
            ok: false,
            stage: $stage,
            cause: $cause,
            replyCode: $replyCode,
            reply: $reply,
            capabilities: $capabilities,
            milliseconds: $milliseconds,
            encrypted: $encrypted,
        );
    }

    public function withTiming(int $milliseconds): self
    {
        return new self(
            $this->ok,
            $this->stage,
            $this->cause,
            $this->replyCode,
            $this->reply,
            $this->capabilities,
            $this->mechanism,
            $milliseconds,
            $this->authenticated,
            $this->encrypted,
        );
    }

    /**
     * The same finding, named more precisely.
     *
     * The conversation cannot tell a refused certificate from a refused protocol version — both
     * are a handshake that did not happen — but the socket underneath knows, and the difference
     * decides who has to do something about it. So the dialogue names what it can see and the
     * layer with the socket in it corrects the name.
     */
    public function withCause(string $cause, string $reply = ''): self
    {
        return new self(
            $this->ok,
            $this->stage,
            $cause,
            $this->replyCode,
            '' !== $reply ? $reply : $this->reply,
            $this->capabilities,
            $this->mechanism,
            $this->milliseconds,
            $this->authenticated,
            $this->encrypted,
        );
    }

    public function supports(string $capability): bool
    {
        return \in_array(mb_strtoupper($capability), array_map('mb_strtoupper', $this->capabilities), true);
    }
}
