<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\AllocationGuard;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Since 2.1.0 (Beacon spec 256, client API v0.4): the `subject` and `activityType` of the entries and timers the client API
 * returns, labelled per page with the token's user as viewer (one `describe()` per tenant and type present). A subject the
 * viewer may not read is `{type, hidden: true, label}` without its id; a subject URL is published only under the task URL
 * rule (absolute https on `client_api.task_url.origin`, else the request origin). Stateless.
 *
 * @internal
 */
final readonly class ClientAllocationPresenter
{
    /**
     * Receives the labeler, the timer service (a running task timer's scope) and the task presenter (the URL rule).
     */
    public function __construct(
        private AllocationLabeler $labeler,
        private TimerService $timers,
        private ClientTaskPresenter $taskPresenter,
    ) {
    }

    /**
     * The subject and activity type checks (the timer service's).
     */
    public function guard(): AllocationGuard
    {
        return $this->timers->allocationGuard();
    }

    /**
     * Whether time with neither task nor subject may be started (`entries.allow_without_task` on and
     * `entries.require_allocation` off).
     */
    public function allowsUnallocated(): bool
    {
        return $this->timers->allowsEntriesWithoutTask() && !$this->guard()->requiresAllocation();
    }

    /**
     * The 2.1 features of `GET me`: `subjects` (`[{type, label, allowsTask}]`, empty without a provider), `activityTypes`,
     * `requireAllocation`, `requireActivityType`.
     *
     * @return array{subjects: list<array{type: string, label: string, allowsTask: bool}>, activityTypes: bool, requireAllocation: bool, requireActivityType: bool}
     */
    public function features(?string $locale = null): array
    {
        $guard    = $this->guard();
        $subjects = [];
        foreach ($guard->subjects()->types() as $type) {
            $subjects[] = ['type' => $type, 'label' => $this->labeler->typeLabel($type, $locale), 'allowsTask' => $guard->subjects()->allowsTask($type)];
        }

        return [
            'subjects'            => $subjects,
            'activityTypes'       => $guard->activityTypes()->isEnabled(),
            'requireAllocation'   => $guard->requiresAllocation(),
            'requireActivityType' => $guard->requiresActivityType(),
        ];
    }

    /**
     * The labels of a page of entries (the viewer's own).
     *
     * @param list<TimeEntry> $entries
     */
    public function labelsFor(UserInterface $viewer, array $entries): AllocationLabels
    {
        return $this->labeler->label(TimeTrackUserRefs::fromUser($viewer), array_map(static fn (TimeEntry $entry): array => [$entry->getScopeKey(), $entry->subject(), $entry->getActivityTypeKey()], $entries));
    }

    /**
     * The allocation fields of one entry of the page.
     *
     * @return array{subject: array<string, mixed>|null, activityType: array{key: string, label: string}|null}
     */
    public function entry(TimeEntry $entry, AllocationLabels $labels, ?Request $request): array
    {
        return [
            'subject'      => $labels->subject($entry->getScopeKey(), $entry->subject(), $request instanceof Request ? fn (?string $url): ?string => $this->taskPresenter->safeUrl($url, $request) : null),
            'activityType' => $labels->activityType($entry->getScopeKey(), $entry->getActivityTypeKey()),
        ];
    }

    /**
     * One entry with its allocation fields (POST / PATCH entries).
     *
     * @return array{subject: array<string, mixed>|null, activityType: array{key: string, label: string}|null}
     */
    public function single(UserInterface $viewer, TimeEntry $entry, ?Request $request): array
    {
        return $this->entry($entry, $this->labelsFor($viewer, [$entry]), $request);
    }

    /**
     * The allocation fields of the viewer's running timer (its subject labelled in the scope its entry will have).
     *
     * @return array{subject: array<string, mixed>|null, activityType: array{key: string, label: string}|null}
     */
    public function timer(UserInterface $viewer, ActiveTimer $timer, Request $request): array
    {
        if (!$timer->subject() instanceof TimeSubjectRef && $timer->getActivityTypeKey() === null) {
            return ['subject' => null, 'activityType' => null];
        }
        $scope  = $this->timers->scopeKeyOfTimer($viewer, $timer);
        $labels = $this->labeler->label(TimeTrackUserRefs::fromUser($viewer), [[$scope, $timer->subject(), $timer->getActivityTypeKey()]]);

        return [
            'subject'      => $labels->subject($scope, $timer->subject(), fn (?string $url): ?string => $this->taskPresenter->safeUrl($url, $request)),
            'activityType' => $labels->activityType($scope, $timer->getActivityTypeKey()),
        ];
    }
}
