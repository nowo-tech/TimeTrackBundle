# TimeTrack Bundle - Development
.PHONY: help up down build shell install test test-coverage coverage-php-percent cs-check cs-fix qa clean ensure-up rector rector-dry phpstan release-check release-check-demos composer-sync update validate time-track-purge-tokens

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= /usr/bin/docker compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

help:
	@echo "TimeTrack Bundle - Development Commands"
	@echo ""
	@echo "  up / down / build / shell / install"
	@echo "  test / test-coverage / cs-check / cs-fix / phpstan / qa"
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
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --coverage-html coverage --coverage-clover coverage.xml --coverage-text
	@$(MAKE) coverage-php-percent

coverage-php-percent:
	@php scripts/check-coverage.php coverage.xml --min-percent=0 2>/dev/null || true

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

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

qa: cs-check test

release-check: ensure-up composer-sync cs-check rector-dry phpstan test-coverage-100 release-check-demos

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

include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
