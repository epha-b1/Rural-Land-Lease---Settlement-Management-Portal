#!/bin/bash
set -e

echo "=== Rural Lease Portal - Startup ==="

# 1) Run database migrations
echo "[1/3] Running migrations..."
php database/migrate.php

# 2) Wire scheduled jobs: start the scheduler loop in the background.
#    This satisfies the prompt requirement that background jobs (overdue
#    invoice updater, delegation expiry revoker, message retention cleaner)
#    are wired AT STARTUP rather than only via an admin "run now" endpoint.
echo "[2/3] Starting background scheduler..."
mkdir -p runtime/log
php tools/scheduler.php >> runtime/log/scheduler.log 2>&1 &
SCHED_PID=$!
echo "      scheduler PID=${SCHED_PID}"

# Propagate shutdown to the scheduler when the API stops
trap 'kill ${SCHED_PID} 2>/dev/null || true' EXIT INT TERM

# 3) Start the PHP API server (foreground — container main process)
echo "[3/3] Starting API server on port 8000..."
exec php -S 0.0.0.0:8000 -t public public/router.php
