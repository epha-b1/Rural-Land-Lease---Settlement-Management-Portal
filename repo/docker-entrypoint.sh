#!/bin/bash
set -e

echo "=== Rural Lease Portal - Startup ==="

# Run database migrations
echo "[1/2] Running migrations..."
php database/migrate.php

# Start the PHP server
echo "[2/2] Starting API server on port 8000..."
exec php -S 0.0.0.0:8000 -t public public/router.php
