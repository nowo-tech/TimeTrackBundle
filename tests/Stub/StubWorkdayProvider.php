<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayConflictException;
use Nowo\TimeTrackBundle\Workday\WorkdayForbiddenException;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

use const DATE_ATOM;

/**
 * Test register: idle → working → on_break → working → closed; users listed in `$off` have no register, users in
 * `$forbidden` may not act.
 */
final class StubWorkdayProvider implements WorkdayProviderInterface
{
    public string $status = 'idle';

    /** Since 1.22: the register's reminder (the bundle drops it on a leave day). */
    public ?string $reminder = null;

    /** @var list<array{string, string|null, string, string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $off = [];

    /** @var list<string> */
    public array $forbidden = [];

    public function today(UserInterface $user): ?WorkdayState
    {
        return in_array($user->getUserIdentifier(), $this->off, true) ? null : $this->state();
    }

    public function act(UserInterface $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        if (in_array($user->getUserIdentifier(), $this->forbidden, true)) {
            throw new WorkdayForbiddenException();
        }
        $next = match ([$this->status, $action]) {
            ['idle', WorkdayAction::ClockIn], ['on_break', WorkdayAction::BreakEnd] => 'working',
            ['working', WorkdayAction::BreakStart]                                  => 'on_break',
            ['working', WorkdayAction::ClockOut]                                    => 'closed',
            default                                                                 => null,
        };
        if ($next === null) {
            throw new WorkdayConflictException($this->state());
        }
        $this->calls[] = [$action->value, $occurredAt?->format(DATE_ATOM), $idempotencyKey, $client->value];
        $this->status  = $next;

        return $this->state();
    }

    private function state(): WorkdayState
    {
        return new WorkdayState($this->status, '2026-11-05', 'Europe/Madrid', [], 0, 0, [], $this->reminder, $this->status === 'idle' ? ['clock-in'] : []);
    }
}
