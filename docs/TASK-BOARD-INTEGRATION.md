# TaskBoard integration

`TimeTrackBundle` stores **references** to tasks (`task_id` + snapshots). Task definitions live in `nowo-tech/task-board-bundle`.

## Table of contents

- [Interfaces (implemented by TaskBoard)](#interfaces-implemented-by-taskboard)
- [Wiring](#wiring)
- [Recommended TaskBoard features](#recommended-taskboard-features)
- [Imported time (1.11)](#imported-time-111)
- [Entries without a timer (1.14)](#entries-without-a-timer-114)
- [A task moved to another board (Unreleased)](#a-task-moved-to-another-board-unreleased)

## Interfaces (implemented by TaskBoard)

| Interface | Purpose |
|-----------|---------|
| `TaskProviderInterface` | List/find tasks, `canUserTrack()`; since 1.10 the returned `TaskReference` may carry `projectId`, `projectTitle` and the task page `url` for the clients |
| `BatchTaskProviderInterface` (optional, since 1.12) | `filterTrackable(user, taskIds)`: the subset the user may track, so masking imported titles makes one call per entry owner. Masking asks what the entry **owner** may track, not the viewer |
| `TeamContextProviderInterface` | Teams, `isManagerOf()`, `getManagedUserIds()` |

## Wiring

```yaml
nowo_time_track:
    task_provider: Nowo\TaskBoardBundle\Bridge\TaskBoardTaskProvider
    team_context_provider: Nowo\TaskBoardBundle\Bridge\TaskBoardTeamContextProvider
```

## Recommended TaskBoard features

- Stable task UUID
- Team membership with `manager` role
- Optional listener on `TimerStopEvent` to update `task.total_time_seconds`
- UI widget on task cards showing accumulated time

Both bundles should declare reciprocal `suggest` entries in `composer.json`.

## Imported time (1.11)

TaskBoardBundle (1.25+) keeps `total_time_seconds` for imported entries through `TIME_ENTRY_IMPORTED` (adds the
change in duration) and `TIME_ENTRIES_REMOVED` (subtracts `secondsByTask`). Its listener registers only when these
event classes exist. Deleting tasks or boards never deletes TimeTrack entries (live or imported); imported time is
deleted only by an explicit manager action.

Since 1.15 the hours of a person with no user are stored too:

```php
$person = $candidate->person;
$user   = $candidate->user();
$owner  = $user instanceof UserInterface
    ? new ImportedTimeEntryClaim($user, $person->getId(), $scopeKey)
    : new ImportedTimeEntryPerson($person->getId(), $person->isAnonymised() ? null : $person->getName(), $scopeKey);
```

Rows go through `ImportedTimeEntryWriter::upsertMany([[$owner, $data], ...])`. When a person is unmapped or remapped, move what the source holds with `moveToPerson()`, `moveToUser()` or `moveBetweenUsers()` (since 1.16): remove-then-upsert deletes time. See [Entries with no user](USAGE.md#entries-with-no-user-115).

## Entries without a timer (1.14)

`POST entries` (sources `manual` and `calendar`) and accepted calendar suggestions create entries without a timer, so no
`TIMER_STOP` fires for them. 1.14 dispatches `TimeTrackEvents::TIME_ENTRY_CREATED` (`TimeEntryCreatedEvent`: the
entry, `origin` `manual` / `calendar`) after the flush. TaskBoard keeps `total_time_seconds` right with a listener on it
(guarded by `class_exists(TimeEntryCreatedEvent::class)`), released as a TaskBoard patch next to TimeTrack 1.14
(contract §9.3).

## A task moved to another board (Unreleased)

TaskBoard 2.3 dispatches `TASK_BOARD_CHANGED` (`TaskBoardChangedEvent::$taskIds`, `$targetBoardId`) after a move's commit. The host
listens to it and calls `TaskTimeMover::moveTasks()` with the target board and its tenant scope, so the time follows the task. Entries in
locked, approved or submitted periods stay and are reported. Listener, rules and retry: [USAGE.md](USAGE.md#task-time-follows-a-board-move-unreleased).
