.PHONY: up-symfony8 down-symfony8 release-check

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

release-check:
	@$(MAKE) -C symfony8 up
	@sleep 3
	@PORT=$$(grep "^PORT=" symfony8/.env.example | cut -d= -f2 | tr -d '\r'); \
	curl -sf "http://localhost:$$PORT/tools/time-track" >/dev/null && echo "Demo healthcheck OK: http://localhost:$$PORT/tools/time-track"
