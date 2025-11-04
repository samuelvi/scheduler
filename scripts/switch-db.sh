#!/bin/bash

# Script to switch between database drivers
# Usage: ./scripts/switch-db.sh [postgresql|mariadb]

set -e

DB_DRIVER=${1:-postgresql}

if [ "$DB_DRIVER" != "postgresql" ] && [ "$DB_DRIVER" != "mariadb" ]; then
    echo "❌ Invalid database driver. Use: postgresql or mariadb"
    exit 1
fi

echo "🔄 Switching to $DB_DRIVER..."

# Update .env file
if [ -f .env ]; then
    # Check if DB_DRIVER line exists
    if grep -q "^DB_DRIVER=" .env; then
        # Replace existing line
        sed -i.bak "s/^DB_DRIVER=.*/DB_DRIVER=$DB_DRIVER/" .env
        rm -f .env.bak
    else
        # Add line if not exists
        echo "DB_DRIVER=$DB_DRIVER" >> .env
    fi
fi

# Update DATABASE_URL in .env based on driver
if [ "$DB_DRIVER" = "postgresql" ]; then
    DATABASE_URL="postgresql://symfony:symfony@postgres:5432/scheduler?serverVersion=15\&charset=utf8"
elif [ "$DB_DRIVER" = "mariadb" ]; then
    DATABASE_URL="mysql://symfony:symfony@mariadb:3306/scheduler?serverVersion=mariadb-10.11.6\&charset=utf8mb4"
fi

# Update DATABASE_URL in .env
# Remove any existing DATABASE_URL line under the doctrine section
if [ -f .env ]; then
    # Find the line number of the "Active database" comment
    LINE_NUM=$(grep -n "# Active database" .env | cut -d: -f1)
    if [ -n "$LINE_NUM" ]; then
        # Remove the DATABASE_URL line after the comment
        NEXT_LINE=$((LINE_NUM + 1))
        sed -i.bak "${NEXT_LINE}d" .env
        rm -f .env.bak
        # Insert the new DATABASE_URL
        sed -i.bak "${LINE_NUM}a\\
DATABASE_URL=\"$DATABASE_URL\"
" .env
        rm -f .env.bak
    fi
fi

echo "✅ Switched to $DB_DRIVER"
echo ""
echo "📝 Current configuration:"
echo "   DB_DRIVER=$DB_DRIVER"
echo "   DATABASE_URL=$DATABASE_URL"
echo ""
echo "Next steps:"
echo "  1. make db-reset    # Reset database"
echo "  2. make test        # Run tests"
