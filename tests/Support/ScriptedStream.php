<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Support;

use Calmfox\Smtp\Core\Probe\Stream;
use Calmfox\Smtp\Core\Probe\StreamFailure;

/**
 * A mail server written down: the lines it would say, in order.
 *
 * Real servers are the worst possible test fixture — they are slow, they rate limit, they have
 * good days — and the interesting cases cannot be arranged at all. Nobody can ask Amazon to
 * refuse a password on demand, or a provider to hang up halfway through a handshake. Written
 * transcripts can, and they are the actual transcripts, taken from what these servers say.
 */
final class ScriptedStream implements Stream
{
    /** A line that makes the connection die instead of answering. */
    public const HANGS_UP = "\0hangs-up";

    /** @var list<string> */
    public array $written = [];

    public bool $closed = false;

    public bool $encrypted = false;

    /** @param list<string> $lines what the server says; running out of lines is silence */
    public function __construct(
        private array $lines,
        private readonly bool $encryptionSucceeds = true,
    ) {
    }

    public function write(string $line): void
    {
        $this->written[] = $line;
    }

    public function readLine(): string
    {
        $line = array_shift($this->lines);
        if (self::HANGS_UP === $line) {
            throw new StreamFailure('the connection was closed by the server');
        }

        return $line ?? '';
    }

    public function enableEncryption(): bool
    {
        return $this->encrypted = $this->encryptionSucceeds;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** Did we send this line, exactly? */
    public function wrote(string $line): bool
    {
        return \in_array($line, $this->written, true);
    }

    /** Did we send anything starting like this? */
    public function wroteSomethingLike(string $prefix): bool
    {
        foreach ($this->written as $line) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
