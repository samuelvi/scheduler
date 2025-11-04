#!/bin/bash

# Script to run tests against all supported databases
# Usage: ./scripts/test-all-db.sh

set -e

echo "======================================"
echo "  Multi-Database Test Suite"
echo "======================================"
echo ""

FAILED=0
PASSED=0

# Function to run tests for a specific database
run_tests_for_db() {
    local db_name=$1
    local phpunit_config=$2

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "  Testing with $db_name"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""

    # Run tests
    if docker-compose -f docker/docker-compose.yml exec -T php vendor/bin/phpunit --configuration "$phpunit_config" --colors=always; then
        echo ""
        echo "✅ $db_name tests PASSED"
        ((PASSED++))
    else
        echo ""
        echo "❌ $db_name tests FAILED"
        ((FAILED++))
    fi

    echo ""
}

# Test PostgreSQL
run_tests_for_db "PostgreSQL" "phpunit.xml.dist"

# Test MariaDB
run_tests_for_db "MariaDB" "phpunit-mariadb.xml.dist"

# Summary
echo "======================================"
echo "  Test Summary"
echo "======================================"
echo ""
echo "✅ Passed: $PASSED database(s)"
echo "❌ Failed: $FAILED database(s)"
echo ""

if [ $FAILED -eq 0 ]; then
    echo "🎉 All tests passed on all databases!"
    exit 0
else
    echo "⚠️  Some tests failed. Check the output above."
    exit 1
fi
