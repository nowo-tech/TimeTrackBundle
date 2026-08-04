# Installation

```bash
composer require nowo-tech/time-track-bundle
```

The Flex recipe copies `config/packages/nowo_time_track.yaml` and `config/routes/nowo_time_track.yaml`.

## Database

Create tables via Doctrine migrations. See demo migration `demo/symfony8/migrations/Version20250706120000.php` for reference SQL.

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
