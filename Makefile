CLI_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
$(eval $(sort $(subst :,\:,$(CLI_ARGS))):;@:)

.DEFAULT_GOAL := help
.PHONY: up down migrate test check composer shell help

up: ## Build and start the environment, install dependencies and apply migrations.
	docker compose up -d --build && docker compose exec app composer install --no-interaction && docker compose exec app ./yii migrate:up --no-interaction

down: ## Stop the environment.
	docker compose down

migrate: ## Apply pending migrations.
	docker compose exec app ./yii migrate:up --no-interaction

test: ## Run the unit and integration tests.
	docker compose exec app composer test

check: ## Run code style, static analysis and all tests.
	docker compose exec app composer check

composer: ## Run Composer in the app container, e.g. make composer require vendor/package.
	docker compose exec app composer $(CLI_ARGS)

shell: ## Open a shell in the app container.
	docker compose exec app bash

help: ## Show this help.
	@awk 'BEGIN { FS = ":.*## "; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\n" } /^[a-zA-Z_-]+:.*## / { printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
