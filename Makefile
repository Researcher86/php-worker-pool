.PHONY: up down shell build install test analyse bench \
        run-server run-server-debug run-client run-client-debug

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec php bash

install: up
	docker compose exec php composer install

test: up
	docker compose exec php composer test

analyse: up
	docker compose exec php composer analyse

run-server: up
	docker compose exec php php bin/server.php

# The image ships xdebug with start_with_request=trigger, so nothing reaches
# for a debugger unless asked - which matters here, where one run is a master
# plus N forked workers. These targets are the ask; point your IDE at port
# 9003 first, or the connection attempt just times out and the run continues.

run-server-debug: up
	docker compose exec php bash -c "XDEBUG_TRIGGER=1 php bin/server.php"

run-client: up
	docker compose exec php php bin/client.php

run-client-debug: up
	docker compose exec php bash -c "XDEBUG_TRIGGER=1 php bin/client.php"

bench: up
	docker compose exec php php bin/bench.php $(ARGS)
