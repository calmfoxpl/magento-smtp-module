<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Log;

/**
 * The few facts about a message that belong in a log row, read from the message itself.
 *
 * Magento hands a transport a finished RFC 822 message, so this reads headers rather than
 * asking Magento what it was sending — which means it works the same for an order confirmation,
 * a newsletter and whatever a third-party module is sending, with no list of special cases.
 *
 * Subjects are the fiddly part: anything with a Polish letter in it arrives as
 * `=?UTF-8?B?...?=`, and a log full of that is a log nobody reads. So encoded words are decoded
 * here, and only the header block is looked at — the body can be megabytes and is none of this
 * class's business.
 */
final class MessageSummary
{
    private const HEADER_LIMIT = 65536;

    /**
     * @param list<string> $recipients every address the message is addressed to
     */
    private function __construct(
        public readonly string $from,
        public readonly array $recipients,
        public readonly string $subject,
        public readonly int $bytes,
    ) {
    }

    public static function fromRaw(string $raw): self
    {
        $headers = self::headers($raw);

        return new self(
            self::addresses($headers['from'] ?? '')[0] ?? '',
            // One person on both To and Cc is one recipient, not two.
            array_values(array_unique(array_merge(
                self::addresses($headers['to'] ?? ''),
                self::addresses($headers['cc'] ?? ''),
                self::addresses($headers['bcc'] ?? ''),
            ))),
            self::decode($headers['subject'] ?? ''),
            \strlen($raw),
        );
    }

    public function recipientsForColumn(): string
    {
        return Recipients::format($this->recipients);
    }

    public function subjectForColumn(int $limit = 255): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', $this->subject) ?? '');

        return mb_strlen($subject) > $limit ? mb_substr($subject, 0, $limit - 1) . '…' : $subject;
    }

    /**
     * @return array<string, string> header name in lower case => value, folded lines joined
     */
    private static function headers(string $raw): array
    {
        $block = substr($raw, 0, self::HEADER_LIMIT);
        $end = strpos($block, "\r\n\r\n");
        if (false === $end) {
            $end = strpos($block, "\n\n");
        }
        if (false !== $end) {
            $block = substr($block, 0, $end);
        }

        $headers = [];
        $name = null;
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            // A header value continued on the next line begins with a space or a tab.
            if (null !== $name && '' !== $line && (' ' === $line[0] || "\t" === $line[0])) {
                $headers[$name] .= ' ' . trim($line);
                continue;
            }
            if (!preg_match('/^(?<name>[A-Za-z0-9-]+):\s*(?<value>.*)$/', $line, $match)) {
                continue;
            }
            $name = mb_strtolower($match['name']);
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($match['value']) : trim($match['value']);
        }

        return $headers;
    }

    /** @return list<string> */
    private static function addresses(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        $addresses = [];
        // Split on commas that are not inside a quoted display name.
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [] as $part) {
            $part = trim($part);
            if (preg_match('/<(?<address>[^>]+)>/', $part, $match)) {
                $part = trim($match['address']);
            }
            $part = trim($part, '"\' ');
            if ('' !== $part && str_contains($part, '@')) {
                $addresses[] = $part;
            }
        }

        return array_values(array_unique($addresses));
    }

    /** A header value as a person would read it, whatever it was encoded as. */
    private static function decode(string $value): string
    {
        if ('' === $value || !str_contains($value, '=?')) {
            return $value;
        }

        if (\function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, \ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (\is_string($decoded) && '' !== $decoded) {
                return $decoded;
            }
        }

        // No iconv, or a header it refused: decode the encoded words by hand rather than
        // showing a shopkeeper the raw base64 of their own subject line.
        return (string) preg_replace_callback(
            '/=\?(?<charset>[^?]+)\?(?<encoding>[BbQq])\?(?<text>[^?]*)\?=/',
            static function (array $match): string {
                $text = 'B' === mb_strtoupper($match['encoding'])
                    ? (string) base64_decode($match['text'], false)
                    : quoted_printable_decode(str_replace('_', ' ', $match['text']));
                $charset = mb_strtoupper($match['charset']);

                if ('UTF-8' === $charset || !\function_exists('mb_convert_encoding')) {
                    return $text;
                }

                return (string) @mb_convert_encoding($text, 'UTF-8', $charset);
            },
            $value,
        );
    }
}
