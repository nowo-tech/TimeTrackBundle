# TimeTrack Bundle - Development
.PHONY: help up down down-dev build shell install test test-coverage test-coverage-100 coverage-check coverage-php-percent cs-check cs-fix qa clean ensure-up rector rector-dry phpstan release-check release-check-demos demo-smoke composer-sync update validate validate-translations time-track-purge-tokens check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks check-twig-extra

COMPOSE_FILE ?= docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     ?= $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

help:
	@echo "TimeTrack Bundle - Development Commands"
	@echo ""
	@echo "  up / down / down-dev / build / shell / install"
	@echo "  test / test-coverage / coverage-check / cs-check / cs-fix / phpstan / qa"
	@echo "  validate-translations / check-open-prs / demo-smoke"
	@echo "  release-check / release-check-demos"
	@echo "  time-track-purge-tokens"
	@echo ""
	@echo "Demo: make -C demo up-symfony8"

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
release-check: check-no-cursor-coauthor check-open-prs check-twig-extra ensure-up composer-sync cs-check rector-dry phpstan validate-translations coverage-check release-check-demos

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
