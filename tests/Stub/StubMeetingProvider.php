<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Meeting\MeetingView;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Test meeting provider (fake host read model): returns `$meetings[userId]`, records who asked and with which query,
 * and can fail or say `disabled`.
 */
final class StubMeetingProvider implements MeetingProviderInterface
{
    /** @var array<int|string, list<MeetingView>> */
    public array $meetings = [];

    /** @var list<array{string, MeetingQuery}> */
    public array $calls = [];

    public MeetingSyncState $state;

    public ?string $failWith = null;

    public bool $failState = false;

    /** State of the page (null = `$state`). */
    public ?MeetingSyncState $pageState = null;

    public function __construct()
    {
        $this->state = new MeetingSyncState(MeetingSyncStatus::Connected);
    }

    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage
    {
        $this->calls[] = [UserIdResolver::getId($user), $query];
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return new MeetingPage($this->meetings[UserIdResolver::getId($user)] ?? [], $this->pageState ?? $this->state);
    }

    public function stateFor(UserInterface $user): MeetingSyncState
    {
        if ($this->failState) {
            throw new RuntimeException('state store down ' . ($this->failWith ?? ''));
        }

        return $this->state;
    }
}
