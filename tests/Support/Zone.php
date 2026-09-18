<?php

declare(strict_types=1);

namespace Calmfox\Smtp\Tests\Support;

use Calmfox\Smtp\Core\Dns\LookupFailed;
use Calmfox\Smtp\Core\Dns\Resolver;

/**
 * A zone written down: the records a domain publishes, as a test can state them.
 *
 * Real DNS is the worst possible fixture for this. It is slow, it changes under you, and the
 * cases worth testing cannot be arranged at all — nobody can ask a domain to publish two SPF
 * records for a minute, or to have eleven lookups in one, or to stop answering.
 */
final class Zone implements Resolver
{
    /** @var array<string, list<string>> */
    private array $txt;

    /** @var array<string, list<array{priority: int, weight: int, port: int, target: string}>> */
    private array $srv;

    /** @var array<string, list<array{priority: int, host: string}>> */
    private array $mx;

    /** @var list<string> */
    private array $unreachable;

    /**
     * @param array<string, list<string>>                                                        $txt
     * @param array<string, list<array{priority: int, weight: int, port: int, target: string}>>  $srv
     * @param array<string, list<array{priority: int, host: string}>>                            $mx
     * @param list<string>                                                                       $unreachable names DNS cannot answer for
     */
    public function __construct(array $txt = [], array $srv = [], array $mx = [], array $unreachable = [])
    {
        $this->txt = array_change_key_case($txt);
        $this->srv = array_change_key_case($srv);
        $this->mx = array_change_key_case($mx);
        $this->unreachable = array_map('mb_strtolower', $unreachable);
    }

    public function txt(string $name): array
    {
        return $this->answer($this->txt, $name);
    }

    public function mx(string $name): array
    {
        return $this->answer($this->mx, $name);
    }

    public function srv(string $name): array
    {
        return $this->answer($this->srv, $name);
    }

    /**
     * @param array<string, list<mixed>> $records
     *
     * @return list<mixed>
     */
    private function answer(array $records, string $name): array
    {
        $name = mb_strtolower(rtrim($name, '.'));
        if (\in_array($name, $this->unreachable, true)) {
            throw new LookupFailed(sprintf('no answer for %s', $name));
        }

        return $records[$name] ?? [];
    }
}
