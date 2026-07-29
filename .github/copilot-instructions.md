## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for this repository.

### Scope

- Symfony bundle for personal/team time tracking (`nowo-tech/time-track-bundle`).
- Respect PHP/Symfony ranges in `composer.json`.
- Prefer PHP 8 attributes; do not introduce `doctrine/annotations`.

### Code

- Follow PSR-12, PHP-CS-Fixer, and PHPStan (`ignoreErrors: []`, level ≥ 8).
- Manage UI mutations must keep CSRF tokens; client API tokens stay hashed.
- Document breaking changes in `docs/UPGRADING.md` and `docs/CHANGELOG.md`.

### Documentation

- English under `docs/`. README includes FrankenPHP Friendly banner when CS-005 is met.

### Tests

- Preserve PHPUnit coverage gate on includable `src/` (100% of non-excluded paths).

### Git

- Never add `Co-authored-by: Cursor` trailers (REQ-GIT-001).
