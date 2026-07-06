# Spec-driven development — TimeTrackBundle

## Product vision

Reusable Symfony time tracking with one timer per user, team/manager reports, and client APIs for browser + desktop.

## User stories

| ID | Story |
|----|-------|
| US-01 | As a user, I start/stop a timer on a trackable task. |
| US-02 | As a user, I see my time entries in reports. |
| US-03 | As a manager, I view team member entries. |
| US-04 | As a client (extension/desktop), I sync via Bearer API. |
| US-05 | As an integrator, I connect TaskBoardBundle via provider interfaces. |

## REQ traceability

| REQ | Makefile / demo |
|-----|-----------------|
| REQ-TEST-001 | `make test` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` → port **8024** |
| REQ-DEMO-007 | `demo/symfony8` target `update-bundle` |
