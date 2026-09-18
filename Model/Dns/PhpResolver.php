<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Model\Dns;

use Calmfox\Smtp\Core\Dns\LookupFailed;
use Calmfox\Smtp\Core\Dns\Resolver;

/**
 * DNS through the system resolver, which is the only one a shop is guaranteed to have.
 *
 * `dns_get_record()` takes no timeout: it inherits whatever `/etc/resolv.conf` says, which is
 * commonly five seconds and two attempts. That is the reason this class is never called while a
 * page is being rendered — the button, the cron job and the console command call it, and
 * everything else reads the answer they stored. A resolver with a proper timeout would need a
 * dependency and a socket of its own, which is a poor trade for a check that runs four times a
 * day.
 *
 * A record that does not exist and a nameserver that will not answer come back differently on
 * purpose: the first is an empty array and a finding, the second is an exception and an admission.
 */
class PhpResolver implements Resolver
{
    public function txt(string $name): array
    {
        $records = $this->query($name, \DNS_TXT);
        $values = [];

        foreach ($records as $record) {
            // `txt` is the record's strings already joined, which is what a long DKIM key or a
            // split SPF record arrives as; `entries` holds the pieces and we do not need them.
            $value = trim((string) ($record['txt'] ?? ''));
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function mx(string $name): array
    {
        $records = [];
        foreach ($this->query($name, \DNS_MX) as $record) {
            $host = rtrim((string) ($record['target'] ?? ''), '.');
            if ('' !== $host) {
                $records[] = ['priority' => (int) ($record['pri'] ?? 0), 'host' => $host];
            }
        }

        return $records;
    }

    public function srv(string $name): array
    {
        $records = [];
        foreach ($this->query($name, \DNS_SRV) as $record) {
            $records[] = [
                'priority' => (int) ($record['pri'] ?? 0),
                'weight' => (int) ($record['weight'] ?? 0),
                'port' => (int) ($record['port'] ?? 0),
                'target' => (string) ($record['target'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws LookupFailed
     */
    private function query(string $name, int $type): array
    {
        $name = trim($name, " \t.");
        if ('' === $name) {
            throw new LookupFailed('there is no name to look up');
        }

        $records = @dns_get_record($name, $type);
        if (false === $records) {
            // The distinction that matters: the question could not be asked at all.
            throw new LookupFailed(sprintf('the nameserver would not answer for %s', $name));
        }

        return array_values(array_filter($records, 'is_array'));
    }
}
