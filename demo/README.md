# Time Track Bundle — Demos

Demo applications for the bundle. They are not part of the Composer package (`archive.exclude`); use a clone of the repository to run them.

| Demo | Symfony | Directory | Default URL |
| --- | --- | --- | --- |
| Symfony 8 | 8.1 | `symfony8/` | <http://localhost:8024> |

The demos run on **FrankenPHP** (Caddy + PHP) with MySQL. Development vs production setup, `FRANKENPHP_MODE` (`worker` by default, `classic` for per-request PHP) and troubleshooting are documented in [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).

From the bundle root:

```bash
make -C demo up-symfony8
```

Pages: the timer (`/tools/time-track`), reports (`/tools/time-track/reports`), the TaskBoard and,
since 1.13, **Timesheet** (`/timesheet`): the bundle's `TimeTrack:TimesheetWeekGrid` and `TimeTrack:BalanceCard`
components configured in a host page (`symfony8/src/Controller/TimesheetController.php`), with submit / withdraw posted
through `TimesheetFormHandler` (one tenant scope, `working_time` from the 1.12 working hours, task time — not a legal
register). Nobody approves their own timesheet, so the demo user can submit and withdraw but not approve.

Copy `symfony8/.env.example` to `symfony8/.env` on first run (the demo `make up` does it for you); `.env`, `.env.dev` and `.env.*.local` are not committed.
