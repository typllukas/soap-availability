HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
PHP = $(DC) exec -T --user $(HOST_UID):$(HOST_GID) -e COMPOSER_MEMORY_LIMIT=-1 php
CONSOLE = $(PHP) php bin/console

.DEFAULT_GOAL := help
.PHONY: help setup up down shell check phpcs phpcs-fix phpstan rector rector-fix test demo

help: ## List the available targets
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

## --- environment ---

setup:
	@$(MAKE) --no-print-directory up
	$(PHP) composer install --no-interaction

up:
	$(DC) up -d --wait
	@$(DC) ps --format '{{.Service}}\t{{.Status}}'

down:
	$(DC) down

shell: ## Shell in the php container
	$(DC) exec --user $(HOST_UID):$(HOST_GID) php bash

## --- quality ---

check: phpcs phpstan rector test

phpcs:
	$(PHP) vendor/bin/phpcs

phpcs-fix:
	$(PHP) vendor/bin/phpcbf

phpstan:
	$(CONSOLE) cache:warmup --env=dev
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=256M

rector: ## Rector, dry run
	$(PHP) vendor/bin/rector process --dry-run --no-progress-bar

rector-fix: ## Rector fix
	$(PHP) vendor/bin/rector process --no-progress-bar

test:
	$(PHP) vendor/bin/phpunit

## --- demo ---

demo: ## Call both operations, trigger a fault, and call the public NumberConversion service
	$(CONSOLE) soap-availability:dev:demo
