# Rural Land Lease & Settlement Management Portal

## Quick Start

```bash
docker compose up --build -d
./run_tests.sh
```

## Services

| Service | Port | Description |
|---------|------|-------------|
| API     | 8000 | ThinkPHP REST API |
| MySQL   | 3307 | MySQL 8.0 (external debug port) |

## URLs

- Health: http://localhost:8000/health
- Frontend: http://localhost:8000/static/index.html
- API Docs: http://localhost:8000/api/docs
- Login: http://localhost:8000/static/login.html
- Register: http://localhost:8000/static/register.html

## Test Credentials

Database (inline in docker-compose.yml):
- Host: `db` (internal) / `localhost:3307` (external)
- Database: `rural_lease`
- User: `app` / Password: `app`

## Testing

```bash
# Full test suite (unit + API, executed in container)
./run_tests.sh
```

## Stack

- **Backend:** ThinkPHP 6 (PHP 8.2)
- **Frontend:** Layui 2.9
- **Database:** MySQL 8.0
- **Runtime:** Docker Compose

## Architecture

- REST API with JSON responses
- Bearer token authentication
- RBAC + geographic scope enforcement
- X-Trace-Id on all responses
- Structured error envelope: `{status, code, message, trace_id}`
- AES-256 encryption for sensitive fields
- Append-only audit log
- Idempotent payment posting (10-minute window)

## Modules

Auth, Profiles, Verification, Contracts, Invoices, Payments, Refunds,
Messaging, Risk Controls, Audit, Admin Config, Background Jobs, Exports.
