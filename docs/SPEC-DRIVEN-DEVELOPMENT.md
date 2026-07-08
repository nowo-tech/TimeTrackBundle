# Spec-driven development — TimeTrackBundle

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **TimeTrackBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** user, **I want** to start/stop a timer on a trackable task **so that** work time is recorded automatically. |
| US-02 | **As a** user, **I want** personal time reports **so that** I review my logged hours. |
| US-03 | **As a** manager, **I want** team member entries **so that** I oversee squad capacity. |
| US-04 | **As a** client (browser extension / Tauri desktop), **I want** Bearer JSON APIs **so that** I sync timers outside the browser UI. |
| US-05 | **As an** integrator, **I want** `TaskProviderInterface` / `TeamContextProviderInterface` **so that** TaskBoardBundle supplies tasks and teams. |

**Out of scope for these stories:** billing/invoicing, offline conflict resolution beyond the single-active-timer rule, and built-in task CRUD (delegated to task providers).

---

## Bundle functional scope

**Goal:** Personal and team time tracking for Symfony apps: web UI, one active timer per user, manager reports, and client APIs for browser extension and desktop agent.

**In scope**

- Web manage UI (`/tools/time-track`) and configurable routes via `TimeTrackRouteLoader`.
- Client authentication (`ClientToken`), rate-limited login, and JSON timer API when `clients.enabled=true`.
- Doctrine entities (`ActiveTimer`, `TimeEntry`, `ClientToken`) with configurable `table_prefix`.
- Symfony events for timer start/stop and entry ACL/list filtering.
- Optional TaskBoard bridge (`StubTaskProvider` / TaskBoard providers) documented in [`TASK-BOARD-INTEGRATION.md`](TASK-BOARD-INTEGRATION.md).

**Explicit non-goals**

- Invoicing or payroll export.
- Multiple concurrent active timers per user.
- Behavior not documented in `docs/` or the baseline spec under `specs/001-baseline/`.
- **`demo/`** trees unless explicitly published as stable API.

---

## Validating the functional spec

- Run **`composer qa`** and/or **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Run **PHPUnit** and **PHPStan** locally and in CI.
- Manual demo flow: start timer on TaskBoard task → stop → entry visible in reports; client API returns 409 on double-start.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-TEST-001 | `make test`, `composer test` | PHPUnit test targets |
| REQ-DEMO-005 | `demo/symfony8/Makefile` | Demo port **8024** |
| REQ-DEMO-007 | `demo/symfony8` target `update-bundle` | Refresh path dependency in demo |

When you change scripted behavior, **update the existing `REQ-*` comment** if the ID still matches the rule, or **add a new `REQ-*`** and document it here and in the PR description.

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

