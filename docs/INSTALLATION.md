# Installation

```bash
composer require nowo-tech/time-track-bundle
```

The Flex recipe copies `config/packages/nowo_time_track.yaml` and `config/routes/nowo_time_track.yaml`.

## Table of contents

- [Database](#database)
- [Security](#security)
- [TaskBoardBundle](#taskboardbundle)
- [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)

## Database

Create tables via Doctrine migrations. See demo migration `demo/symfony8/migrations/Version20250706120000.php` for reference SQL, and `Version20261105000000.php` for the 1.11 imported-entry columns and the `time_track_entries_ended_idx` `(ended_at, id)` index.

### Schema 1.13

1.13 adds `time_track_entries.scope_key` and the always-mapped tables `time_track_timesheets`,
`time_track_timesheet_events` and `time_track_balance_ledger`: run `doctrine:migrations:diff`, or use the reference SQL
for [MySQL / MariaDB](sql/1.13.0/mysql-up.sql), [PostgreSQL](sql/1.13.0/postgresql-up.sql) and
[SQLite](sql/1.13.0/sqlite-up.sql) (rollbacks next to them; details and optional append-only triggers in
[UPGRADING](UPGRADING.md#to-1130)). Then, with timesheets or working time on, run
`nowo:time-track:entries:backfill-scope` and schedule `nowo:time-track:timesheets:purge` (and `…:auto-lock` when used).

## Security

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/api/time-track, roles: PUBLIC_ACCESS }
        - { path: ^/tools/time-track, roles: ROLE_USER }
```

Bearer tokens authenticate client API requests; session CSRF does not apply to `/api/time-track/*`.

## TaskBoardBundle

When `nowo-tech/task-board-bundle` is installed, register its bridge services:

```yaml
nowo_time_track:
    task_provider: Nowo\TaskBoardBundle\Bridge\TaskBoardTaskProvider
    team_context_provider: Nowo\TaskBoardBundle\Bridge\TaskBoardTeamContextProvider
```

Until then, the demo uses `StubTaskProvider` with sample tasks.

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
