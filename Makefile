.PHONY: help install up down restart logs shell test test-unit test-integration test-functional benchmark migrate db-create db-drop db-reset stats clean

# Default target
.DEFAULT_GOAL := help

# Docker compose command
DOCKER_COMPOSE = docker-compose -f docker/docker-compose.yml

## —— Scheduler Makefile ————————————————————————————————————————————————————
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## —— Docker ————————————————————————————————————————————————————————————————
up: ## Start containers
	$(DOCKER_COMPOSE) up -d

down: ## Stop containers
	$(DOCKER_COMPOSE) down

restart: ## Restart containers
	$(DOCKER_COMPOSE) restart

logs: ## Show logs (use service=php for specific service)
	@if [ -z "$(service)" ]; then \
		$(DOCKER_COMPOSE) logs -f; \
	else \
		$(DOCKER_COMPOSE) logs -f $(service); \
	fi

shell: ## Access PHP container shell
	$(DOCKER_COMPOSE) exec php sh

## —— Installation & Setup ——————————————————————————————————————————————————
install: up ## Full installation (containers + dependencies + database)
	@echo "Installing dependencies..."
	$(DOCKER_COMPOSE) exec php composer install
	@echo "Creating database..."
	$(MAKE) db-create
	@echo "Running migrations..."
	$(MAKE) migrate
	@echo "✓ Installation complete!"

## —— Database ——————————————————————————————————————————————————————————————
db-create: ## Create database
	$(DOCKER_COMPOSE) exec php bin/console doctrine:database:create --if-not-exists

db-drop: ## Drop database
	$(DOCKER_COMPOSE) exec php bin/console doctrine:database:drop --force --if-exists

migrate: ## Run migrations
	$(DOCKER_COMPOSE) exec php bin/console doctrine:migrations:migrate --no-interaction

db-reset: db-drop db-create migrate ## Reset database (drop + create + migrate)
	@echo "✓ Database reset complete!"

## —— Testing ———————————————————————————————————————————————————————————————
test: ## Run all tests (non-interactive, for CI/CD)
	$(DOCKER_COMPOSE) exec php vendor/bin/phpunit

test-unit: ## Run unit tests only
	$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Unit

test-integration: ## Run integration tests only
	$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Integration

test-functional: ## Run functional tests only
	$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Functional

test-coverage: ## Run tests with coverage (requires Xdebug)
	$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --coverage-html coverage/

test-interactive: ## Run all test suites with interactive benchmark prompt (developer-friendly)
	@echo "======================================"
	@echo "  Scheduler Test Suite"
	@echo "======================================"
	@echo ""
	@if [ ! -d "vendor" ]; then \
		echo "\033[1;33mInstalling dependencies...\033[0m"; \
		$(DOCKER_COMPOSE) exec php composer install; \
		echo ""; \
	fi
	@echo "\033[0;34mRunning Unit Tests...\033[0m"
	@$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Unit --colors=always
	@echo ""
	@echo "\033[0;34mRunning Integration Tests...\033[0m"
	@$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Integration --colors=always
	@echo ""
	@echo "\033[0;34mRunning Functional Tests...\033[0m"
	@$(DOCKER_COMPOSE) exec php vendor/bin/phpunit --testsuite=Functional --colors=always
	@echo ""
	@echo "\033[0;32m======================================"
	@echo "  All Tests Completed Successfully!"
	@echo "======================================\033[0m"
	@echo ""
	@read -p "Do you want to run the performance benchmark with 1000 tasks? (y/n) " answer; \
	if [ "$$answer" = "y" ] || [ "$$answer" = "Y" ]; then \
		echo ""; \
		echo "\033[0;34mRunning Performance Benchmark...\033[0m"; \
		echo ""; \
		$(DOCKER_COMPOSE) exec php bin/console app:benchmark-scheduler --tasks=1000 --clean; \
	fi

## —— Scheduler Commands ————————————————————————————————————————————————————
benchmark: ## Run performance benchmark with 1000 tasks
	$(DOCKER_COMPOSE) exec php bin/console app:benchmark-scheduler --tasks=1000 --clean

benchmark-10k: ## Run benchmark with 10,000 tasks
	$(DOCKER_COMPOSE) exec php bin/console app:benchmark-scheduler --tasks=10000 --clean

stats: ## Show scheduler statistics
	$(DOCKER_COMPOSE) exec php bin/console app:scheduler:stats

cleanup: ## Clean old completed/failed tasks (30 days)
	$(DOCKER_COMPOSE) exec php bin/console app:scheduler:cleanup

process: ## Process scheduled tasks (single run, worker 0)
	$(DOCKER_COMPOSE) exec php bin/console app:process-scheduled-tasks --worker-id=0 --total-workers=5

process-daemon: ## Process tasks in daemon mode (worker 0)
	$(DOCKER_COMPOSE) exec php bin/console app:process-scheduled-tasks --daemon --worker-id=0 --total-workers=5

## —— Development ———————————————————————————————————————————————————————————
create-task: ## Create a test task (usage: make create-task case=send_email)
	@if [ -z "$(case)" ]; then \
		echo "Error: Please specify case (e.g., make create-task case=send_email)"; \
		exit 1; \
	fi; \
	curl -X POST http://localhost:8180/api/scheduler/tasks \
		-H "Content-Type: application/json" \
		-d '{"use_case":"$(case)","payload":{"test":"data"},"scheduled_at":"'$$(date -u +"%Y-%m-%dT%H:%M:%S")'"}'

api-stats: ## Get stats via API
	@curl -s http://localhost:8180/api/scheduler/stats | json_pp || curl http://localhost:8180/api/scheduler/stats

health: ## Check API health
	@curl -s http://localhost:8180/health | json_pp || curl http://localhost:8180/health

## —— Code Quality ——————————————————————————————————————————————————————————
cs-fix: ## Fix coding standards (if PHP-CS-Fixer installed)
	$(DOCKER_COMPOSE) exec php vendor/bin/php-cs-fixer fix src/

phpstan: ## Run PHPStan static analysis (if installed)
	$(DOCKER_COMPOSE) exec php vendor/bin/phpstan analyse src/

## —— Utilities —————————————————————————————————————————————————————————————
clean: ## Clean cache and logs
	$(DOCKER_COMPOSE) exec php rm -rf var/cache/* var/log/*
	@echo "✓ Cache and logs cleaned!"

composer-install: ## Install Composer dependencies
	$(DOCKER_COMPOSE) exec php composer install

composer-update: ## Update Composer dependencies
	$(DOCKER_COMPOSE) exec php composer update

cache-clear: ## Clear Symfony cache
	$(DOCKER_COMPOSE) exec php bin/console cache:clear

## —— Supervisor ————————————————————————————————————————————————————————————
supervisor-status: ## Show supervisor workers status
	$(DOCKER_COMPOSE) exec supervisor supervisorctl -c /etc/supervisor/conf.d/supervisor.conf status

supervisor-restart: ## Restart all supervisor workers
	$(DOCKER_COMPOSE) exec supervisor supervisorctl -c /etc/supervisor/conf.d/supervisor.conf restart scheduler-worker:*

supervisor-stop: ## Stop all supervisor workers
	$(DOCKER_COMPOSE) exec supervisor supervisorctl -c /etc/supervisor/conf.d/supervisor.conf stop scheduler-worker:*

supervisor-start: ## Start all supervisor workers
	$(DOCKER_COMPOSE) exec supervisor supervisorctl -c /etc/supervisor/conf.d/supervisor.conf start scheduler-worker:*

supervisor-logs: ## Show supervisor logs
	$(DOCKER_COMPOSE) logs -f supervisor

## —— Quick Actions ——————————————————————————————————————————————————————————
quick-test: up ## Quick test: create tasks and process them
	@echo "Creating 100 test tasks..."
	$(DOCKER_COMPOSE) exec php bin/console app:benchmark-scheduler --tasks=100 --clean
	@echo "\nProcessing tasks with worker 0..."
	$(DOCKER_COMPOSE) exec php bin/console app:process-scheduled-tasks --worker-id=0 --total-workers=5
	@echo "\nShowing stats..."
	$(MAKE) stats

demo: install quick-test ## Full demo: install + create tasks + process
	@echo "\n✓ Demo complete! Check stats above."
