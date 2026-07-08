# Release process

1. Update [CHANGELOG.md](CHANGELOG.md): move entries from `[Unreleased]` to a new `[X.Y.Z] - YYYY-MM-DD` section and add the version link at the bottom. (This project does not store version in `composer.json`; Packagist uses the git tag.)
2. Update [UPGRADING.md](UPGRADING.md) if the release introduces breaking changes or new migration steps.
3. Run `make release-check` (CS, Rector dry-run, PHPStan, 100% coverage, demo healthcheck).
4. Commit, tag (e.g. `v1.0.0`), and push. The [release workflow](../.github/workflows/release.yml) creates the GitHub Release with the changelog entry.
5. Publish or auto-update on [Packagist](https://packagist.org/packages/nowo-tech/time-track-bundle) if applicable.

### Tag message

Use an annotated tag with a short summary; the release workflow merges it with the CHANGELOG section:

```bash
git tag -a v1.0.3 -m "Release 1.0.3: GitHub Spec Kit scaffolding and docs"
git push origin v1.0.3
```
