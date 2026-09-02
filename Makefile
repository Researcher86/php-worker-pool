.PHONY: up down shell build install test analyse

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
