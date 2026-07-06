# Usage

- **Web timer:** `/tools/time-track` — start/stop timer on trackable tasks
- **Reports:** `/tools/time-track/reports` — last 7 days of entries
- **Override Twig:** `templates/bundles/NowoTimeTrackBundle/`

## API (clients)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/time-track/login` | Issue Bearer token |
| GET | `/api/time-track/me` | Current user |
| GET | `/api/time-track/tasks` | Trackable tasks |
| GET | `/api/time-track/timer` | Active timer (204 if none) |
| POST | `/api/time-track/timer/start` | `{ "taskId", "clientType" }` |
| POST | `/api/time-track/timer/stop` | Close active timer |
| POST | `/api/time-track/heartbeat` | `{ "isIdle": true/false }` |
| GET | `/api/time-track/entries` | `?from=&to=&userId=` |

## Events

- `nowo_time_track.timer.start` — `TimerStartEvent`
- `nowo_time_track.timer.stop` — `TimerStopEvent` (TaskBoard can aggregate `time_spent`)
- `nowo_time_track.time_entry.list_query` — filter report user ids
- `nowo_time_track.time_entry.access_check` — fine-grained ACL
