<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Closure;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * 1.27.0 (Beacon spec 239): a clock that plays a concurrent request: the hook registered for the n-th call of now()
 * runs before that call answers (counted from the last {@see self::arm()}).
 */
final class HookClock implements ClockInterface
{
    private int $calls = 0;

    /** @var array<int, Closure(): mixed> */
    private array $hooks = [];

    public function __construct(private readonly ClockInterface $inner)
    {
    }

    /**
     * @param array<int, Closure(): mixed> $hooks call number (1 = the next call) => hook
     */
    public function arm(array $hooks): void
    {
        $this->calls = 0;
        $this->hooks = $hooks;
    }

    public function now(): DateTimeImmutable
    {
        ++$this->calls;
        $hook = $this->hooks[$this->calls] ?? null;
        if ($hook instanceof Closure) {
            unset($this->hooks[$this->calls]);
            $hook();
        }

        return $this->inner->now();
    }
}
