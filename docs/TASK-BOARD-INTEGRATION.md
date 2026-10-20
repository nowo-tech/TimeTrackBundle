# TaskBoard integration

`TimeTrackBundle` stores **references** to tasks (`task_id` + snapshots). Task definitions live in `nowo-tech/task-board-bundle`.

## Table of contents

- [Interfaces (implemented by TaskBoard)](#interfaces-implemented-by-taskboard)
- [Wiring](#wiring)
- [Recommended TaskBoard features](#recommended-taskboard-features)

## Interfaces (implemented by TaskBoard)

| Interface | Purpose |
|-----------|---------|
| `TaskProviderInterface` | List/find tasks, `canUserTrack()` |
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
