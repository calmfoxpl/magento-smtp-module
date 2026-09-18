<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Probe;

/**
 * One reply from the server, which may have arrived as several lines.
 *
 * A reply with no three-digit code at the front is not an SMTP reply at all, and saying that
 * apart from "the code was not 220" matters: something answering on the mail port that is not a
 * mail server is a different problem from a mail server saying no.
 */
final class Reply
{
    /** @param list<string> $lines every line of the reply, codes stripped */
    private function __construct(
        public readonly ?int $code,
        public readonly array $lines,
        public readonly string $raw,
    ) {
    }

    /** @param list<string> $lines */
    public static function of(?int $code, array $lines, string $raw): self
    {
        return new self($code, $lines, $raw);
    }

    public static function silence(): self
    {
        return new self(null, [], '');
    }

    public function isSilence(): bool
    {
        return '' === $this->raw;
    }

    public function isSmtp(): bool
    {
        return null !== $this->code;
    }

    public function is(int ...$codes): bool
    {
        return null !== $this->code && \in_array($this->code, $codes, true);
    }

    /** The first line, which is the one worth quoting to a human. */
    public function firstLine(): string
    {
        return $this->lines[0] ?? '';
    }

    /** The whole reply on one line, short enough for a log column. */
    public function summary(int $limit = 250): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->raw) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }
}
