<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Settings;

/**
 * What the shop will send with, together with everything questionable about it.
 *
 * Errors and warnings travel with the settings rather than being thrown, because the panel has
 * to show them all at once: an administrator who fixes one field and is then told about the
 * next one has a worse afternoon than one who is shown the whole list.
 */
final class ResolvedSettings
{
    /** @param list<Issue> $issues */
    public function __construct(
        public readonly MailSettings $settings,
        public readonly array $issues = [],
    ) {
    }

    /** Can we get as far as a connection attempt? */
    public function isUsable(): bool
    {
        return $this->settings->enabled && [] === $this->errors();
    }

    /** @return list<Issue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => $issue->isError()));
    }

    /** @return list<Issue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => !$issue->isError()));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (Issue $issue): string => $issue->code, $this->issues);
    }

    public function has(string $code): bool
    {
        return \in_array($code, $this->codes(), true);
    }
}
