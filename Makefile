.PHONY: up down shell build install test analyse run bench

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

run: up
	docker compose exec php php bin/server.php

bench: up
	docker compose exec php php bin/bench.php $(ARGS)
