# TimeTrack Bundle - Development
.PHONY: help up down down-dev build shell install assets validate-phpdoc twig-lint test test-coverage test-coverage-100 coverage-check coverage-php-percent cs-check cs-fix qa clean ensure-up rector rector-dry phpstan igor release-check release-check-demos demo-smoke composer-sync update validate validate-translations time-track-purge-tokens check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks check-twig-extra check-no-raw-html-form

COMPOSE_FILE ?= docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     ?= $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

help:
	@echo "TimeTrack Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up             Start Docker container (builds the image, installs Composer deps)"
	@echo "  down           Stop Docker container"
	@echo "  build          Rebuild Docker image (no cache)"
	@echo "  shell          Open shell in container"
	@echo ""
	@echo "Dependencies:"
	@echo "  install        Install Composer dependencies (starts container if needed)"
	@echo ""
	@echo "Assets:"
	@echo "  assets         No-op: the bundle ships no frontend build (UiKit CSS comes from nowo_ui_kit)"
	@echo ""
	@echo "Tests:"
	@echo "  test           Run PHPUnit tests"
	@echo "  test-coverage  Run tests with code coverage (HTML + Clover + text)"
	@echo ""
	@echo "Quality:"
	@echo "  cs-check       Check code style"
	@echo "  cs-fix         Fix code style"
	@echo "  rector         Apply Rector refactoring"
	@echo "  rector-dry     Run Rector in dry-run mode"
	@echo "  phpstan        Run PHPStan static analysis"
	@echo "  igor           Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa             Run cs-check, twig-lint and test"
	@echo ""
	@echo "Release:"
	@echo "  release-check  Pre-release chain (REQ-MAKE-002): hygiene, CS, Rector, PHPStan, Igor,"
	@echo "                 translations, coverage gate, demos"
	@echo "  composer-sync  Validate composer.json and install from composer.lock"
	@echo ""
	@echo "Cleanup:"
	@echo "  clean          Remove vendor, caches and coverage reports"
	@echo ""
	@echo "Composer:"
	@echo "  update         Update composer.lock (composer update, bundle only)"
	@echo "  update-deps    Update bundle and demo dependencies (REQ-MAKE-008, monorepo helper)"
	@echo "  validate       Run composer validate --strict"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  down-dev       Stop root container and remove orphans"
	@echo "  ensure-up      Start the container if needed (+ composer install)"
	@echo "  coverage-check Enforce 100% PHP line coverage (alias of test-coverage-100)"
	@echo "  test-coverage-100  Enforce 100% PHP line coverage (REQ-TEST-006)"
	@echo "  twig-lint      Lint bundle Twig templates (twig-cs-fixer)"
	@echo "  validate-translations  Check translation key parity (7 locales) + YAML parse"
	@echo "  validate-phpdoc        Check that every class and public method in src/ has PHPDoc (REQ-CS-001)"
	@echo "  check-no-cursor-coauthor  Fail on AI co-author trailers (REQ-GIT-001)"
	@echo "  check-open-prs Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  check-twig-extra        Twig Extra installed/configured (REQ-TWIG-004)"
	@echo "  check-no-raw-html-form  Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  setup-hooks    Install git hooks from .githooks/ (REQ-MAKE-006)"
	@echo "  time-track-purge-tokens  Purge expired client Bearer tokens (inside a host app)"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke     Demo release-check: up, update-bundle, tests, HTTP 200, down (REQ-TEST-011)"
	@echo "  release-check-demos  Same as demo-smoke (called by release-check)"
	@echo "  (or: make -C demo up-symfony8 | down-symfony8 | release-check"
	@echo "   or: make -C demo/symfony8 up | down | restart | verify | test | test-coverage | update-bundle)"
	@echo ""

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

# REQ-MAKE-001: the bundle has no frontend build; UiKit ships its own CSS (asset package nowo_ui_kit).
assets:
	@echo "No frontend assets in this bundle."

down-dev:
	$(COMPOSE) down --remove-orphans

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --coverage-html coverage --coverage-clover coverage.xml --coverage-text | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

coverage-check: test-coverage-100

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/php-cs-fixer fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/rector process

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/rector process --dry-run --no-progress-bar

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpstan analyse --memory-limit=512M

qa: cs-check twig-lint test

# REQ-CS-001: read-only check that every class-like and public method in src/ has a PHPDoc block.
validate-phpdoc: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/complete-public-phpdoc.php

validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@GH_REPO=nowo-tech/TimeTrackBundle ./.scripts/check-open-prs.sh

demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip demo-smoke"; fi


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# REQ-TWIG-005 — no raw <form>/<input>/<textarea>/<select> in bundle views or demo templates.
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor

# REQ-MAKE-002 order.
release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations coverage-check release-check-demos

release-check-demos:
	$(MAKE) -C demo release-check

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: composer-sync

clean:
	rm -rf vendor coverage .phpunit.cache .php-cs-fixer.cache

time-track-purge-tokens: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:time-track:client-tokens:purge --no-interaction 2>/dev/null || echo "Run inside a Symfony app with the bundle installed."

# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
