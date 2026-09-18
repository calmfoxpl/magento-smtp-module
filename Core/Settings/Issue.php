<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * Something wrong with the settings, named by a code rather than a sentence.
 *
 * The core decides what is wrong; the panel decides how to say it, in the language the
 * administrator reads. That split is why there is a code here and not a message: a sentence in
 * this file could never be translated, and a shopkeeper reading an English warning about their
 * Polish shop's mail settings is a warning half ignored.
 *
 * An error means we would not even get as far as a connection, so there is nothing to test yet.
 * A warning means the settings are usable but very probably not what was meant.
 */
final class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /** @param array<int, string|int> $params values the sentence needs, in order */
    private function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly array $params = [],
    ) {
    }

    /** @param array<int, string|int> $params */
    public static function error(string $code, array $params = []): self
    {
        return new self($code, self::ERROR, $params);
    }

    /** @param array<int, string|int> $params */
    public static function warning(string $code, array $params = []): self
    {
        return new self($code, self::WARNING, $params);
    }

    public function isError(): bool
    {
        return self::ERROR === $this->severity;
    }
}
