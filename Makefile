CLI_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
$(eval $(sort $(subst :,\:,$(CLI_ARGS))):;@:)

.DEFAULT_GOAL := help
.PHONY: up down migrate test check composer shell help api-key\:create api-key\:list api-key\:revoke

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

api-key\:create: ## Create an API key, e.g. make api-key:create CLIENT="North Gate" [EXPIRES=YYYY-MM-DD].
	docker compose exec app ./yii api-key:create "$(CLIENT)" $(if $(EXPIRES),--expires=$(EXPIRES))

api-key\:list: ## List the API keys and their status.
	docker compose exec app ./yii api-key:list

api-key\:revoke: ## Revoke an API key, e.g. make api-key:revoke ID=78a3ac4e.
	docker compose exec app ./yii api-key:revoke "$(ID)"

help: ## Show this help.
	@awk 'BEGIN { printf "\nUsage:\n  make \033[36m<target>\033[0m\n\n" } /^[a-zA-Z_\\:-]+:.*## / { split($$0, parts, " ## "); target = parts[1]; sub(/: *$$/, "", target); gsub(/\\/, "", target); printf "  \033[36m%-16s\033[0m %s\n", target, parts[2] }' $(MAKEFILE_LIST)
