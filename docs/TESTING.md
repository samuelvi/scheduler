# Testing Documentation

## Test Suite Overview

The scheduler project includes comprehensive test coverage:

### 1. **Unit Tests** (`tests/Unit/`)
Tests individual components in isolation with mocked dependencies.

- `Entity/ScheduledTaskTest.php` - Entity behavior and state transitions
- `Service/TaskProcessorTest.php` - Task processing logic

**Run:** `docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Unit`

### 2. **Integration Tests** (`tests/Integration/`)
Tests components interacting with real database.

- `Repository/ScheduledTaskRepositoryTest.php` - Database operations and fair distribution

**Run:** `docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Integration`

### 3. **Functional Tests** (`tests/Functional/`)
Tests complete workflows through commands and APIs.

- `Command/ProcessScheduledTasksCommandTest.php` - Command execution and worker coordination

**Run:** `docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Functional`

---

## Running Tests

### Quick Start

```bash
# Interactive (developer-friendly with colors and benchmark prompt)
make test-interactive

# Non-interactive (for CI/CD pipelines)
make test

# Or manually with docker-compose
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit
```

### Individual Test Suites

```bash
# Unit tests only
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Unit

# Integration tests only
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Integration

# Functional tests only
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --testsuite=Functional
```

### Specific Test File

```bash
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit tests/Unit/Entity/ScheduledTaskTest.php
```

### With Coverage (requires Xdebug)

```bash
docker-compose -f docker/docker-compose.yml exec php vendor/bin/phpunit --coverage-html coverage/
```

---

## Performance Benchmark

### Overview

The benchmark command creates 1000 tasks (200 of each use case) and measures:

1. **Task Creation Rate** - How fast tasks can be inserted into DB
2. **Assignment Performance** - How fast fair distribution works
3. **Distribution Quality** - Standard deviation of task assignment
4. **Theoretical vs Actual** - Expected processing times

### Use Cases & Durations

| Use Case | Avg Duration | Description |
|----------|--------------|-------------|
| `send_notification` | 30ms | Push notifications (fastest) |
| `send_email` | 50ms | SMTP email sending |
| `cleanup_data` | 100ms | Database cleanup operations |
| `process_payment` | 150ms | Payment gateway API calls |
| `generate_report` | 200ms | Complex data aggregation (slowest) |

### Running Benchmark

```bash
# Create and analyze 1000 tasks
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=1000 --clean

# Custom task count
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=5000
```

### Expected Results (1000 tasks)

**Task Creation:**
- Rate: ~5,000-10,000 tasks/sec
- Time: 0.1-0.2 seconds

**Assignment (5 workers):**
- Time: 0.01-0.05 seconds
- Distribution: ±1 task variance (excellent)

**Theoretical Processing Time:**
- Sequential: ~106 seconds (all use cases × average duration)
- Parallel (5 workers): ~21 seconds (106 / 5)
- Parallel (10 workers): ~11 seconds (106 / 10)

**Actual Processing Time:**
To measure actual processing, run workers after benchmark:

```bash
# In separate terminals (or use & for background)
docker-compose -f docker/docker-compose.yml exec php bin/console app:process-scheduled-tasks --worker-id=0 --total-workers=5 &
docker-compose -f docker/docker-compose.yml exec php bin/console app:process-scheduled-tasks --worker-id=1 --total-workers=5 &
docker-compose -f docker/docker-compose.yml exec php bin/console app:process-scheduled-tasks --worker-id=2 --total-workers=5 &
docker-compose -f docker/docker-compose.yml exec php bin/console app:process-scheduled-tasks --worker-id=3 --total-workers=5 &
docker-compose -f docker/docker-compose.yml exec php bin/console app:process-scheduled-tasks --worker-id=4 --total-workers=5 &

# Wait for completion
wait

# Alternative: Use supervisor (automatically manages 5 workers)
make supervisor-status
```

Then check stats:
```bash
docker-compose -f docker/docker-compose.yml exec php bin/console app:scheduler:stats
```

---

## Edge Cases Tested

### ScheduledTask Entity
- ✅ Multiple state transitions
- ✅ Empty and complex nested payloads
- ✅ Null worker_id handling
- ✅ Retry logic with max attempts
- ✅ Timestamp consistency

### Repository
- ✅ Even distribution (100 tasks, 5 workers = 20 each)
- ✅ Uneven distribution (537 tasks, 5 workers = 108, 108, 107, 107, 107)
- ✅ No tasks available
- ✅ Only pending tasks processed
- ✅ Only due tasks processed
- ✅ Single task scenarios
- ✅ More workers than tasks
- ✅ Max attempts exclusion
- ✅ Stuck task recovery

### Command
- ✅ Invalid worker IDs
- ✅ Worker ID exceeding total
- ✅ Empty task queue
- ✅ Fair distribution verification
- ✅ Future tasks ignored

### TaskProcessor
- ✅ All 5 use cases execute correctly
- ✅ Missing payload fields
- ✅ Invalid use cases
- ✅ Max attempts handling
- ✅ Empty payloads
- ✅ Random payment failures (5% rate)

---

## Test Database

Tests use a separate test database configured via `APP_ENV=test`.

### Setup

Tests automatically:
1. Boot Symfony kernel in test mode
2. Clean database before each test
3. Close connections after tests

### Manual Database Reset

```bash
docker-compose -f docker/docker-compose.yml exec php bin/console doctrine:database:drop --force --env=test
docker-compose -f docker/docker-compose.yml exec php bin/console doctrine:database:create --env=test
docker-compose -f docker/docker-compose.yml exec php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

---

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_DB: scheduler_test
          POSTGRES_USER: symfony
          POSTGRES_PASSWORD: symfony
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
    steps:
      - uses: actions/checkout@v3
      - uses: php-actions/composer@v6
      - name: Run tests
        run: vendor/bin/phpunit
```

---

## Performance Metrics

### Key Metrics to Monitor

1. **Assignment Rate**: Tasks assigned per second
2. **Processing Rate**: Tasks completed per second
3. **Distribution Fairness**: Standard deviation < 2
4. **Overdue Count**: Should stay at 0 during normal operation
5. **Stuck Task Count**: Should be 0 or very low

### Benchmarking Different Loads

```bash
# Light load (100 tasks)
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=100

# Medium load (1,000 tasks)
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=1000

# Heavy load (10,000 tasks)
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=10000

# Extreme load (100,000 tasks)
docker-compose -f docker/docker-compose.yml exec php bin/console app:benchmark-scheduler --tasks=100000
```

### Expected Throughput

| Workers | Tasks/sec | 1000 tasks | 10000 tasks |
|---------|-----------|------------|-------------|
| 1       | ~50-100   | 10-20s     | 100-200s    |
| 5       | ~250-500  | 2-4s       | 20-40s      |
| 10      | ~500-1000 | 1-2s       | 10-20s      |

*Note: Actual throughput depends on hardware and use case distribution*

---

## Troubleshooting Tests

### Tests failing with "Cannot find phpunit"

```bash
docker-compose -f docker/docker-compose.yml exec php composer install
```

### Database connection errors

```bash
# Check postgres is running
docker-compose ps

# Verify database exists
docker-compose -f docker/docker-compose.yml exec postgres psql -U symfony -l
```

### Tests timeout

Increase PHPUnit timeout in `phpunit.xml.dist`:

```xml
<phpunit processTimeout="300">
```

### Memory issues with large benchmarks

```bash
# Increase PHP memory limit
docker-compose -f docker/docker-compose.yml exec php php -d memory_limit=512M bin/console app:benchmark-scheduler --tasks=50000
```
