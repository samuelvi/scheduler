# AI Agent Style Guide

This document defines coding standards and conventions for AI agents working on this project.

## Language Standards

### Code and Comments
- **All code, comments, and documentation MUST be in English**
- Use clear, descriptive variable and function names
- Write comments that explain "why", not "what"

### Examples
```php
// ✅ Good
/**
 * Validates concurrency and processing order
 */
private function validateConcurrency(array $results): void

// ❌ Bad
/**
 * Valida la concurrencia y el orden de procesamiento
 */
private function validarConcurrencia(array $resultados): void
```

## Command Documentation

### PHPDoc Comments
All Symfony Console commands MUST include a docblock with usage examples:

```php
/**
 * Process scheduled tasks using FOR UPDATE SKIP LOCKED for concurrency safety.
 *
 * Examples:
 * - Single run (100 tasks): php bin/console app:process-scheduled-tasks
 * - Single run (custom limit): php bin/console app:process-scheduled-tasks --limit=1000
 * - Daemon mode: php bin/console app:process-scheduled-tasks --daemon --sleep=10 --limit=100
 * - Quick exit (testing): php bin/console app:process-scheduled-tasks --limit=10 --max-execution-time=0
 */
#[AsCommand(
    name: 'app:process-scheduled-tasks',
    description: 'Process scheduled tasks that are due for execution',
)]
class ProcessScheduledTasksCommand extends Command
```

### Key Principles
1. Show common use cases
2. Include command-line flags
3. Be concise (3-5 examples maximum)
4. Use realistic parameters

## Test Standards

### Test Output Messages
- Use English for all echo/printf statements in tests
- Use emojis consistently for visual clarity

```php
// ✅ Good
echo "🔸 Worker 1: Starting processing...\n";
echo "✅ No race conditions: 15000 unique tasks processed\n";

// ❌ Bad
echo "🔸 Worker 1: Iniciando procesamiento...\n";
echo "✅ Sin race conditions: 15000 tareas únicas procesadas\n";
```

### Test Documentation
Use clear, descriptive docblocks:

```php
/**
 * Real concurrency test with multiple workers processing simultaneously
 *
 * Scenario:
 * - 20,000 pending tasks (15,000 with same date, 5,000 with different dates)
 * - 3 workers processing in parallel with staggered delays
 * - Insertion of 10 tasks between workers
 * - Validation of correct distribution and absence of race conditions
 */
class ConcurrentWorkersTest extends DatabaseTestCase
```

## Code Organization

### Comment Sections
Use clear separators for major code sections:

```php
// =====================================
// PHASE 1: Create 20,000 initial tasks
// =====================================

// =====================================
// HELPER METHODS
// =====================================
```

### Method Ordering
1. Public methods first
2. Protected methods
3. Private methods
4. Group related methods together

## Database Standards

### Multi-Database Support
- Always consider both PostgreSQL and MariaDB compatibility
- Test changes against both databases
- Use platform-agnostic SQL when possible

```php
// ✅ Good - Platform detection
$platform = $this->connection->getDatabasePlatform()->getName();
if ($platform === 'postgresql') {
    // PostgreSQL-specific code
} else {
    // MariaDB-specific code
}

// ❌ Bad - Hardcoded PostgreSQL
$sql = "SELECT * FROM tasks WHERE payload::jsonb @> '{\"status\":\"pending\"}'";
```

### SQL Queries
- Use parameterized queries to prevent SQL injection
- For LIMIT/OFFSET with MariaDB, use sprintf with (int) cast:

```php
// ✅ Good
$sql = sprintf(
    "SELECT * FROM tasks LIMIT %d OFFSET %d",
    (int) $limit,
    (int) $offset
);

// ❌ Bad - MariaDB doesn't support placeholders for LIMIT
$sql = "SELECT * FROM tasks LIMIT :limit OFFSET :offset";
```

## Concurrency Best Practices

### FOR UPDATE SKIP LOCKED
Always use `FOR UPDATE SKIP LOCKED` for concurrent task processing:

```php
// ✅ Good
$stmt = $this->db->prepare(
    sprintf(
        "SELECT * FROM scheduled_tasks
         WHERE status = :status
         ORDER BY scheduled_at ASC, id ASC
         LIMIT %d
         FOR UPDATE SKIP LOCKED",
        (int) $limit
    )
);
```

### Avoid Race Conditions
- Never use COUNT + OFFSET for task distribution
- Always lock rows atomically
- Test concurrency scenarios thoroughly

## Performance Optimization

### Bulk Operations
Use direct SQL for large batch inserts (>1000 records):

```php
// ✅ Good - Direct SQL for 20,000 tasks
$batchSize = 500;
for ($i = 0; $i < 20000; $i++) {
    $values[] = sprintf("(%s, %s, %s)", ...);
    if (count($values) >= $batchSize) {
        $sql = "INSERT INTO tasks (...) VALUES " . implode(', ', $values);
        $connection->executeStatement($sql);
        $values = [];
    }
}

// ❌ Bad - ORM for bulk operations (memory exhaustion)
for ($i = 0; $i < 20000; $i++) {
    $task = new Task();
    $entityManager->persist($task);
}
$entityManager->flush();
```

## Test Execution Time

### Balance Between Coverage and Speed
- Concurrency tests with delays should use 1-2 seconds (not 5+)
- Full test suite should complete in under 10 seconds
- Use `sleep(2)` for temporal validation (sufficient for order checking)

```php
// ✅ Good - Fast enough for CI
sleep(2); // 2-second delay (sufficient to validate temporal order)

// ❌ Bad - Too slow for regular testing
sleep(5); // 5-second delay (unnecessarily long)
```

## Error Messages

### Assertion Messages
Use clear, actionable error messages in English:

```php
// ✅ Good
$this->assertEquals(
    5000,
    $count,
    'Worker 1 should process 5,000 tasks'
);

// ❌ Bad
$this->assertEquals(5000, $count, 'Worker 1 debe procesar 5,000 tareas');
```

## Configuration Management

### Batch Size Configuration
Document where batch sizes are configured:

1. **Production (Supervisor)**: `docker/supervisor/supervisor.conf` - Default: 100
2. **Command Default**: `src/Command/ProcessScheduledTasksCommand.php` - Default: 100
3. **Makefile**: Various targets - Default: 100
4. **Tests**: Adjustable per test - Range: 10-1000

## Git Commit Messages

### Format
```
Type: Brief description (max 50 chars)

Detailed explanation of changes (if needed).

- Bullet points for specific changes
- Include reasoning for complex changes

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

### Types
- **Feature**: New functionality
- **Fix**: Bug fixes
- **Refactor**: Code restructuring
- **Test**: Test additions/modifications
- **Docs**: Documentation updates
- **Perf**: Performance improvements

## Tooling

### Development Commands
```bash
# Run all tests (PostgreSQL + MariaDB)
make test

# Run specific test suite
make test-unit
make test-integration
make test-functional

# Run single database tests
make test-postgres
make test-mariadb

# Benchmark performance
make benchmark        # 1,000 tasks
make benchmark-10k    # 10,000 tasks

# Database operations
make db-reset         # Drop, create, migrate
make switch-postgres  # Switch to PostgreSQL
make switch-mariadb   # Switch to MariaDB
```

## Summary

When working on this project:
1. ✅ Use English for all code, comments, and documentation
2. ✅ Include usage examples in command docblocks
3. ✅ Support both PostgreSQL and MariaDB
4. ✅ Use FOR UPDATE SKIP LOCKED for concurrency
5. ✅ Optimize bulk operations with direct SQL
6. ✅ Keep tests fast (<10 seconds for full suite)
7. ✅ Write clear, actionable error messages
8. ✅ Document configuration locations
