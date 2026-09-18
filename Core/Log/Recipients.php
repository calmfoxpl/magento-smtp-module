<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Log;

/**
 * Addresses, flattened into something a grid column can show and a search can match.
 *
 * A message to nine people is one row in the log, and the row has to be honest about that
 * without being unreadable: the first few addresses, then how many more there are.
 */
final class Recipients
{
    public const COLUMN_LIMIT = 255;

    private const SHOWN = 3;

    /** @param list<string> $addresses */
    public static function format(array $addresses): string
    {
        $addresses = array_values(array_filter(array_map('trim', $addresses)));
        if ([] === $addresses) {
            return '';
        }

        $shown = \array_slice($addresses, 0, self::SHOWN);
        $remaining = \count($addresses) - \count($shown);
        $text = implode(', ', $shown);
        if ($remaining > 0) {
            $text .= sprintf(' (+%d)', $remaining);
        }

        return mb_strlen($text) > self::COLUMN_LIMIT ? mb_substr($text, 0, self::COLUMN_LIMIT - 1) . '…' : $text;
    }

    /** The one address a failure is most usefully attributed to. */
    public static function first(array $addresses): ?string
    {
        foreach ($addresses as $address) {
            $address = trim((string) $address);
            if ('' !== $address) {
                return $address;
            }
        }

        return null;
    }
}
