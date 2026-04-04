# Rural Lease Portal - Architecture

## Stack

| Layer | Technology |
| --- | --- |
| Backend | ThinkPHP (REST-style API) |
| Frontend | Layui (browser UI) |
| Database | MySQL |
| Auth | Session or token-based auth in ThinkPHP |
| Encryption | AES-256 at rest |
| Logging | Structured logs with trace IDs |
| Jobs | Scheduler/cron-driven background jobs |

Runs fully offline on a local network, no external cloud dependencies.

## Runtime and Deployment

- Docker-first runtime: validated via `docker compose up --build`.
- `docker-compose.yml` must use inline env vars (no `.env` dependency).
- API service has `/health` healthcheck and `restart: unless-stopped`.
- DB service has healthcheck and explicit port mappings.
- Tests run through `./run_tests.sh` from host, executed inside containers.

## Core Modules

| Module | Responsibility |
| --- | --- |
| auth | register, login, logout, password policy, lockout, optional admin MFA |
| users | profile management, role assignment, geo scope assignment |
| verification | real-name/business verification workflow |
| entities | farmer/enterprise/collective master records + extra fields |
| contracts | lease contracts and billing schedule generation |
| invoices | invoice lifecycle and immutable snapshots |
| payments | payment posting, idempotency, receipts |
| refunds | refund lifecycle linked to invoice balances |
| messaging | text/voice/image messaging, recall, read states |
| risk | keyword/pattern detection with warn/block/flag actions |
| exports | ledger/reconciliation CSV/Excel exports |
| delegation | township/county access delegation with approval |
| audit | append-only audit trail |
| security | encryption key management and masking rules |
| jobs | overdue updates, retention, delegation expiry |

## Request Flow

Frontend (Layui)
-> ThinkPHP API
-> Middleware (trace ID, auth, RBAC, scope filter, idempotency)
-> Service layer
-> MySQL

## Security Model

- Password policy: minimum 12 chars with upper/lower/digit/symbol.
- Lockout: 5 failures in 15 minutes, exponential backoff.
- Optional TOTP for admins.
- RBAC + geographic scope enforcement on every request.
- Sensitive fields encrypted with AES-256; key stored outside web root.
- Sensitive displays masked by default.
- Local TLS profile for intranet deployment.
- Audit log is INSERT-only.

## Data Scope Model

- Geographic hierarchy: county > township > village.
- User has scope level + scope id.
- Queries enforce scope predicates server-side.
- Delegation is explicit, approved, and time-bounded.
