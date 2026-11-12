<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;

/**
 * Since 1.21: answers a configured default and records each question (scope key, task).
 */
final class StubBillableDefaultProvider implements TimeEntryBillableDefaultProviderInterface
{
    /** @var list<array{0: string, 1: string|null}> */
    public array $calls = [];

    public function __construct(
        public ?bool $answer = true,
    ) {
    }

    public function defaultFor(string $scopeKey, ?string $taskRef): ?bool
    {
        $this->calls[] = [$scopeKey, $taskRef];

        return $this->answer;
    }
}
