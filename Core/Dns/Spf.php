<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

/**
 * One SPF record, read the way a receiving server reads it.
 *
 * Only as much of RFC 7208 as the question needs: which senders the domain authorises, what it
 * says about everybody else, and how many DNS lookups evaluating it would cost. The last one is
 * not trivia — a record over the limit of ten is a permanent error at the receiver, which means
 * the whole record is ignored and every message is treated as unauthorised. A shop can sit on
 * that for months with a record that looks perfectly reasonable.
 */
final class Spf
{
    /** RFC 7208 §4.6.4: more than this many lookups and receivers give up on the record. */
    public const LOOKUP_LIMIT = 10;

    /** Mechanisms that cost a DNS lookup when a receiver evaluates the record. */
    private const COSTLY = ['include', 'a', 'mx', 'ptr', 'exists'];

    /** @param list<array{qualifier: string, name: string, value: string}> $mechanisms */
    private function __construct(
        public readonly string $raw,
        public readonly array $mechanisms,
    ) {
    }

    public static function looksLikeSpf(string $txt): bool
    {
        return 1 === preg_match('/^\s*v=spf1(\s|$)/i', $txt);
    }

    /** @param list<string> $records @return list<self> every SPF record in a set of TXT records */
    public static function fromTxtRecords(array $records): array
    {
        $found = [];
        foreach ($records as $record) {
            if (self::looksLikeSpf($record)) {
                $found[] = self::parse($record);
            }
        }

        return $found;
    }

    public static function parse(string $txt): self
    {
        $mechanisms = [];

        foreach (preg_split('/\s+/', trim($txt)) ?: [] as $term) {
            if ('' === $term || 1 === preg_match('/^v=spf1$/i', $term)) {
                continue;
            }

            $qualifier = '';
            if (\in_array($term[0], ['+', '-', '~', '?'], true)) {
                $qualifier = $term[0];
                $term = substr($term, 1);
            }

            [$name, $value] = array_pad(preg_split('/[:=]/', $term, 2) ?: [], 2, '');
            $mechanisms[] = [
                'qualifier' => $qualifier,
                'name' => mb_strtolower((string) $name),
                'value' => mb_strtolower(trim((string) $value)),
            ];
        }

        return new self($txt, $mechanisms);
    }

    /** @return list<string> the domains this record defers to */
    public function includes(): array
    {
        $includes = [];
        foreach ($this->mechanisms as $mechanism) {
            if ('include' === $mechanism['name'] && '' !== $mechanism['value']) {
                $includes[] = $mechanism['value'];
            }
        }

        return array_values(array_unique($includes));
    }

    /** A record that hands the whole answer to another domain. */
    public function redirect(): ?string
    {
        foreach ($this->mechanisms as $mechanism) {
            if ('redirect' === $mechanism['name'] && '' !== $mechanism['value']) {
                return $mechanism['value'];
            }
        }

        return null;
    }

    /**
     * What the domain says about senders it has not listed: `-` reject, `~` treat as suspicious,
     * `?` no opinion, `+` everybody is welcome — which is the same as having no SPF at all, only
     * harder to notice.
     */
    public function allQualifier(): ?string
    {
        foreach ($this->mechanisms as $mechanism) {
            if ('all' === $mechanism['name']) {
                return '' === $mechanism['qualifier'] ? '+' : $mechanism['qualifier'];
            }
        }

        return null;
    }

    public function lookupCost(): int
    {
        $cost = 0;
        foreach ($this->mechanisms as $mechanism) {
            if (\in_array($mechanism['name'], self::COSTLY, true) || 'redirect' === $mechanism['name']) {
                ++$cost;
            }
        }

        return $cost;
    }
}
