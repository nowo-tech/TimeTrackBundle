# Makefile to manage TimeTrackBundle demos
.PHONY: help release-check release-verify up-symfony8 down-symfony8 update-bundle-symfony8 verify-symfony8

DEMOS := symfony8

help:
	@echo "TimeTrackBundle - Demos"
	@echo "  up-symfony8 / down-symfony8 / update-bundle-symfony8 / verify-symfony8"
	@echo "  release-check / release-verify"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

verify-symfony8:
	@$(MAKE) -C symfony8 up
	@sleep 3
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example | cut -d= -f2 | tr -d '\r'); \
	curl -sf "http://localhost:$$PORT/tools/time-track" >/dev/null && echo "Demo healthcheck OK: http://localhost:$$PORT/tools/time-track"

release-check: release-verify

release-verify:
	@for d in $(DEMOS); do $(MAKE) verify-$$d; $(MAKE) down-$$d; done
