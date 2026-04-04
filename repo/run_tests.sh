#!/bin/bash
set -e

HEALTH_URL="http://localhost:8000/health"
MAX_WAIT=90

cd "$(dirname "$0")"

# Step 1: Ensure containers are running
api_running=$(docker compose ps --status running 2>/dev/null | grep -c "api" || true)
if [ "$api_running" -eq 0 ]; then
  echo "[1/4] Starting containers..."
  docker compose up -d --build
else
  if ! docker compose exec -T api wget -qO- "$HEALTH_URL" >/dev/null 2>&1; then
    echo "[1/4] API unresponsive — restarting..."
    docker compose down && docker compose up -d --build
  else
    echo "[1/4] Containers already running"
  fi
fi

# Step 2: Wait for health
echo "[2/4] Waiting for API..."
elapsed=0
while [ $elapsed -lt $MAX_WAIT ]; do
  if docker compose exec -T api wget -qO- "$HEALTH_URL" 2>/dev/null | grep -q '"status"'; then
    echo "      Ready (${elapsed}s)"
    break
  fi
  sleep 2; elapsed=$((elapsed + 2))
done
if [ $elapsed -ge $MAX_WAIT ]; then
  echo "ERROR: API not healthy after ${MAX_WAIT}s"
  docker compose logs --tail 50 api
  exit 1
fi

# Step 3: Unit tests (inside container)
echo "[3/4] Unit tests..."
UNIT_EXIT=0
docker compose exec -T -e API_BASE_URL=http://localhost:8000 api \
  vendor/bin/phpunit --testsuite unit --colors=always --testdox 2>&1 || UNIT_EXIT=$?

# Step 4: API tests (inside container)
echo "[4/4] API tests..."
API_EXIT=0
docker compose exec -T -e API_BASE_URL=http://localhost:8000 api \
  vendor/bin/phpunit --testsuite api --colors=always --testdox 2>&1 || API_EXIT=$?

# Summary
echo ""
echo "========================================"
[ $UNIT_EXIT -eq 0 ] && echo "  Unit tests:  PASSED" || echo "  Unit tests:  FAILED"
[ $API_EXIT  -eq 0 ] && echo "  API tests:   PASSED" || echo "  API tests:   FAILED"
echo "========================================"
exit $((UNIT_EXIT + API_EXIT))
