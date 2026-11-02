# Makefile to manage TimeTrackBundle demos
.PHONY: help release-check release-verify up-symfony8 down-symfony8 update-bundle-symfony8 verify-symfony8

DEMOS := symfony8

help:
	@echo "TimeTrackBundle - Demos"
	@echo "  up-symfony8 / down-symfony8 / update-bundle-symfony8 / verify-symfony8"
	@echo "  release-check  Per demo: up, update-bundle, HTTP 200 check, down (REQ-DEMO-007)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

verify-symfony8:
	@$(MAKE) -C symfony8 verify

# REQ-DEMO-007: each demo's release-check runs update-bundle before verify; the stack is stopped afterwards.
release-check: release-verify

release-verify:
	@for d in $(DEMOS); do $(MAKE) -C $$d release-check || { $(MAKE) down-$$d; exit 1; }; $(MAKE) down-$$d; done
