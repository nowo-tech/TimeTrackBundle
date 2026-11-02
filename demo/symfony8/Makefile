# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
DOCKER_COMPOSE = $(COMPOSE)
SERVICE_PHP = php

.PHONY: help ensure-env ensure-up up down restart build install setup database shell test test-coverage verify release-check update-bundle update-deps time-track-purge-tokens test-e2e demo-screenshots

help:
	@echo "TimeTrack Bundle — Symfony 8.1 Demo"
	@echo ""
	@echo "  up             Start demo (FrankenPHP, port from .env), DB + fixtures"
	@echo "  down           Stop containers"
	@echo "  restart        Restart containers"
	@echo "  build          Build images"
	@echo "  ensure-up      Start the demo only when it is not running"
	@echo "  install        Install Composer dependencies"
	@echo "  setup          Create the database, run migrations and load fixtures"
	@echo "  database       Create DB and run migrations"
	@echo "  shell          Open shell in PHP container"
	@echo "  test           Run demo PHPUnit tests"
	@echo "  test-coverage  Run demo PHPUnit tests with coverage"
	@echo "  verify         Require HTTP 200 on the timer page (REQ-TEST-011)"
	@echo "  release-check  update-bundle, then verify (REQ-DEMO-007)"
	@echo "  update-bundle  Sync bundle autoload and clear cache"
	@echo "  update-deps    composer update inside the demo (REQ-MAKE-008)"
	@echo "  test-e2e       Playwright e2e against this demo (REQ-DEMO-013)"
	@echo "  demo-screenshots  Refresh docs/images/demo/*.webp via Playwright (REQ-DEMO-013)"
	@echo "  time-track-purge-tokens  Purge expired client Bearer tokens"

ensure-env:
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	@rm -f .env.dev

# REQ-DEMO-005: canonical up — the last line is "Demo started at: http://localhost:<PORT>" (PORT from .env, else
# .env.example); the timer page URL is printed on the line before it.
up: ensure-env
	@if [ ! -f .env ]; then cp .env.example .env; fi
	@rm -f .env.dev
	@# Fresh clone: worker mode cannot boot without vendor/, so install it in a one-off container first.
	@if [ ! -f vendor/autoload_runtime.php ]; then \
		$(COMPOSE) run --rm --no-deps --entrypoint composer -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) install --no-interaction --no-scripts; \
	fi
	@$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction --no-scripts
	@$(COMPOSE) exec -T $(SERVICE_PHP) rm -rf var/cache/* 2>/dev/null || true
	@rm -f .env.dev
	@$(COMPOSE) exec -T $(SERVICE_PHP) test -f bin/console && $(COMPOSE) exec -T $(SERVICE_PHP) composer run-script post-install-cmd || true
	@$(MAKE) setup
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Timer UI: http://localhost:$$PORT/tools/time-track"; \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(COMPOSE) down

restart: ensure-up
	@$(COMPOSE) restart

build:
	@$(COMPOSE) build

# REQ-MAKE-003: start the demo (full `up`) only when the PHP container is not running.
ensure-up: ensure-env
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true >/dev/null 2>&1; then $(MAKE) up; fi

install: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

# REQ-MAKE-003: schema + demo data (idempotent: --if-not-exists, migrations, fixtures appended).
setup:
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction --append || true

database: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer database

shell: ensure-up
	@$(COMPOSE) exec $(SERVICE_PHP) sh

# REQ-DEMO-007: tests run against the current bundle code.
test: ensure-up update-bundle
	@$(COMPOSE) exec -T -e APP_ENV=test $(SERVICE_PHP) vendor/bin/phpunit

test-coverage: ensure-up update-bundle
	@$(COMPOSE) exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage $(SERVICE_PHP) vendor/bin/phpunit --coverage-text

# REQ-MAKE-003 / REQ-TEST-011: final check — HTTP 200 on the timer page (retries while FrankenPHP warms up).
verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	code=000; \
	for i in $$(seq 1 30); do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT/tools/time-track 2>/dev/null || echo "000"); \
		[ "$$code" = "200" ] && { echo "Demo healthcheck OK (HTTP 200 on http://127.0.0.1:$$PORT/tools/time-track)"; exit 0; }; \
		sleep 2; \
	done; \
	echo "Demo healthcheck failed (HTTP $$code on http://127.0.0.1:$$PORT/tools/time-track)"; exit 1

# REQ-DEMO-007: sync the mounted bundle (autoload + cache) before the final verification.
release-check: ensure-up update-bundle verify

update-bundle: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

# REQ-DEMO-013 — Playwright e2e via the official image (host Chromium deps are often missing on WSL); the image tag
# matches @playwright/test in e2e/package.json.
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8024}; \
	docker run --rm --cpus 2 --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium --workers=1'

# Captures PNGs with the screenshots project, converts them to WebP (sharp, quality 90) and deletes the PNGs.
demo-screenshots: ensure-up
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8024}; \
	docker run --rm --cpus 2 --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots --workers=1 && node scripts/to-webp.mjs /screenshots 90'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

time-track-purge-tokens: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:time-track:client-tokens:purge --no-interaction

# REQ-MAKE-008: update-deps — optional monorepo helper (REQ-MAKE-009: soft include only). A standalone clone gets the
# in-repo fallback below (ensure-up + composer update in the container).
DEMO_UPDATE_DEPS_MK := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
ifneq (,$(wildcard $(DEMO_UPDATE_DEPS_MK)))
-include $(DEMO_UPDATE_DEPS_MK)
else
update-deps: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer update --no-interaction
endif
