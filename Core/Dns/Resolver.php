<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Core\Dns;

/**
 * The little the domain checks need from DNS.
 *
 * It exists so the interesting part — deciding whether a domain's records actually authorise the
 * provider the shop is configured to send through — is tested against written-down zones rather
 * than against the internet. Real lookups are slow, they fail in ways nobody can arrange on
 * demand, and the answers change under you.
 *
 * An empty array means "no such record", which is a finding. A lookup that could not be made at
 * all throws, because "we do not know" and "there is nothing there" are different answers and
 * telling a shopkeeper the second when the first is true would be a lie.
 */
interface Resolver
{
    /**
     * @return list<string> each TXT record as one string, with its parts already joined
     *
     * @throws LookupFailed
     */
    public function txt(string $name): array;

    /**
     * @return list<array{priority: int, host: string}>
     *
     * @throws LookupFailed
     */
    public function mx(string $name): array;

    /**
     * @return list<array{priority: int, weight: int, port: int, target: string}>
     *
     * @throws LookupFailed
     */
    public function srv(string $name): array;
}
