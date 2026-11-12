<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see StubTaskKeyResolver} (same state). */
final class TwinTaskKeyLookup implements TaskKeyLookupInterface
{
    public int $calls = 0;

    public function __construct(private readonly StubTaskKeyResolver $inner)
    {
    }

    public function extractKeys(string $title): array
    {
        ++$this->calls;

        return $this->inner->extractKeys($title);
    }

    public function resolveFor(TimeTrackUserRef $user, string $scopeKey, array $keys): array
    {
        ++$this->calls;

        return $this->inner->resolve(TwinPorts::user($user), $scopeKey, $keys);
    }
}
