<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

/**
 * The one thing the SMTP conversation needs from the outside world: a line in, a line out, and
 * a way to raise TLS halfway through.
 *
 * It exists so the conversation itself — the part with all the decisions in it — can be run
 * against a written-down transcript in a unit test. The socket lives behind it, in the Magento
 * side of the module, and a test never opens one.
 */
interface Stream
{
    /** @throws StreamFailure when the line cannot be put on the wire */
    public function write(string $line): void;

    /**
     * One reply line, without its trailing newline.
     *
     * An empty string means the other side has stopped talking — a timeout or a closed
     * connection — and the conversation treats it as such rather than looping.
     *
     * @throws StreamFailure
     */
    public function readLine(): string;

    /** Raise TLS on an open connection, after the server has agreed to STARTTLS. */
    public function enableEncryption(): bool;

    public function close(): void;
}
