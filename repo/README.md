# Rural Land Lease & Settlement Management Portal

> A secure, offline-first portal for administering agricultural lease contracts,
> entity profiles, financial reconciliation, and in-app communications across
> a county. Built as a ThinkPHP REST API with a Layui web UI, backed by MySQL,
> and shipped as a single Docker Compose stack.

---

## Quick Start

```bash
# 1. Start the stack (API + MySQL) — first run builds images and applies migrations
docker compose up --build -d

# 2. Wait ~15 seconds for the health check, then open the portal
open http://localhost:8000/static/login.html

# 3. Run the full test suite (unit + API, executed inside the container)
./run_tests.sh
```

That's it. No host-side PHP, no manual database setup, no `.env` file to copy.
Everything needed is baked into the image and declared inline in `docker-compose.yml`.

---

## What's Inside

| Layer          | Tech                                    |
| -------------- | --------------------------------------- |
| **Backend**    | ThinkPHP 6 (PHP 8.2)                    |
| **Frontend**   | Layui 2.9 served as static pages        |
| **Database**   | MySQL 8.0                               |
| **Runtime**    | Docker Compose                          |
| **Test stack** | PHPUnit 10 (in-container curl-based)    |

## Where Things Live

| URL                                    | Description                          |
| -------------------------------------- | ------------------------------------ |
| `http://localhost:8000/health`         | Health check (`{"status":"ok"}`)     |
| `http://localhost:8000/api/docs`       | Machine-readable endpoint catalog    |
| `http://localhost:8000/static/login.html`    | Login page (with CAPTCHA)      |
| `http://localhost:8000/static/register.html` | Registration page              |
| `http://localhost:8000/static/index.html`    | Main portal (role-aware nav)   |

---

## First-Time Walkthrough

1. **Open** `http://localhost:8000/static/register.html`
2. **Create an account** — pick a role (`farmer` / `enterprise` / `collective` / `system_admin`),
   pick a scope (`village` 3 / `township` 2 / `county` 1), solve the CAPTCHA math question,
   and use a password with ≥12 chars including upper, lower, digit, and symbol.
3. **Log in** at `/static/login.html`. The CAPTCHA refreshes automatically on each attempt.
4. **Navigate**: the sidebar reveals profiles, contracts, invoices, payments,
   messaging, and (for `system_admin`) verifications, risk keywords, audit log, jobs, and config.
5. **Try the features**:
   - Create an entity profile → duplicate detection flags matching names.
   - Create a contract → invoice schedule auto-generates for the full term.
   - Post a payment → pass an `Idempotency-Key`; a replay within 10 minutes returns the original response.
   - Send a message containing `scam` → the server blocks it (409) per risk policy.
   - Recall a message within 10 minutes → body becomes `[This message was recalled]`.
   - As `system_admin`, visit `/audit-logs` → every action above appears with before/after values.

---

## Testing

```bash
./run_tests.sh
```

Runs the full suite inside the container against the real MySQL database.
**No mocks, no host-side setup, no manual `docker compose up` needed** — the
script detects whether the containers are running and behaves accordingly:

| Container state           | What `run_tests.sh` does                      |
| ------------------------- | --------------------------------------------- |
| Not running               | Builds images + starts API and DB             |
| Running but unresponsive  | Tears down and rebuilds from scratch          |
| Running and healthy       | Skips startup, runs tests immediately         |

After ensuring the stack is up, it waits for the `/health` check, then
executes `phpunit` for both the `unit` and `api` test suites inside the
container.

## Test Credentials

The application does **not** ship with pre-seeded user accounts — every user
is created through the registration flow. To create a test user from the
command line (bypassing the frontend CAPTCHA by calling the API directly):

```bash
# 1. Fetch a CAPTCHA challenge and compute the answer
CAPTCHA=$(curl -s http://localhost:8000/auth/captcha)
CID=$(echo "$CAPTCHA" | python3 -c "import sys,json; print(json.load(sys.stdin)['challenge_id'])")
ANS=$(echo "$CAPTCHA" | python3 -c "
import sys,json,re
q = json.load(sys.stdin)['question']
m = re.match(r'(-?\d+)\s*([+\-*])\s*(-?\d+)', q)
a,op,b = int(m[1]), m[2], int(m[3])
print({'+':a+b,'-':a-b,'*':a*b}[op])")

# 2. Register a system_admin user (county scope)
curl -s -X POST http://localhost:8000/auth/register \
  -H 'Content-Type: application/json' \
  -d "{
    \"username\": \"admin\",
    \"password\": \"AdminP@ss12345\",
    \"role\": \"system_admin\",
    \"geo_scope_level\": \"county\",
    \"geo_scope_id\": 1,
    \"captcha_id\": \"$CID\",
    \"captcha_answer\": \"$ANS\"
  }"
```

### Sample User Matrix

Use these through the registration page (solve the CAPTCHA shown on screen)
or the curl snippet above. All passwords meet the policy (≥12 chars, upper,
lower, digit, symbol).

| Role            | Username  | Password          | Scope Level | Scope ID | Notes                         |
| --------------- | --------- | ----------------- | ----------- | -------- | ----------------------------- |
| `system_admin`  | `admin`   | `AdminP@ss12345`  | `county`    | `1`      | Full access, can enroll MFA   |
| `collective`    | `village` | `CollP@ss12345`   | `village`   | `3`      | Village-collective admin      |
| `enterprise`    | `biz`     | `BizP@ss12345`    | `township`  | `2`      | Business-scoped user          |
| `farmer`        | `farmer`  | `FarmerP@ss12345` | `village`   | `3`      | Standard farmer user          |

> **Important:** These are suggested credentials for local testing only.
> The application always requires the CAPTCHA answer from `/auth/captcha`
> to be included with every `/auth/register` and `/auth/login` request.
> Challenges are single-use and expire after 5 minutes.

### Database Credentials

The MySQL instance is reachable from inside the Docker network as `db:3306`
and from the host as `localhost:3307`. Credentials are inline in
`docker-compose.yml` (no `.env` file):

| Setting    | Value           |
| ---------- | --------------- |
| Database   | `rural_lease`   |
| Username   | `app`           |
| Password   | `app`           |
| Host (int) | `db`            |
| Host (ext) | `localhost:3307`|

```bash
# Inspect tables directly from the host
mysql -h 127.0.0.1 -P 3307 -u app -papp rural_lease
```

### Coverage Snapshot

- **Backend (route coverage):** 100% — every defined route has ≥1 explicit test assertion.
- **Frontend (module/page coverage):** 100% — every JS module, page section, and HTML page is verified.
- Reproducible analyzer: `docker compose exec -T api php tools/coverage.php`

---

## Architecture at a Glance

```
┌─────────────────┐       ┌────────────────────┐       ┌──────────────┐
│  Layui Web UI   │──────▶│   ThinkPHP API     │──────▶│   MySQL 8    │
│  (static files) │       │   (PHP built-in    │       │              │
└─────────────────┘       │    HTTP server)    │       └──────────────┘
                          │                    │
                          │  middleware:       │       ┌──────────────┐
                          │   • TraceId        │──────▶│  runtime/log │
                          │   • JsonResponse   │       │  (JSON lines)│
                          │   • AuthCheck      │       └──────────────┘
                          │                    │
                          │  background:       │       ┌──────────────┐
                          │   • scheduler loop │──────▶│  audit_logs  │
                          │     (overdue,      │       │  (append)    │
                          │      retention,    │       └──────────────┘
                          │      delegation)   │
                          └────────────────────┘
```

### Security Controls (all enforced server-side)

| Control                         | Where                                                     |
| ------------------------------- | --------------------------------------------------------- |
| Password policy (≥12 + symbols) | `PasswordService`                                         |
| Rolling-window lockout          | `AuthService` — 5 failures in 15 min, exponential backoff |
| Local CAPTCHA (offline math)    | `CaptchaService` + `/auth/captcha` endpoint               |
| Optional TOTP MFA (admins only) | `MfaService` — enroll + verify + login challenge          |
| Bearer token sessions           | `TokenService` + `AuthCheck` middleware                   |
| Role-based access control       | Route-level `authCheck:system_admin`                      |
| Geographic scope isolation      | `ScopeService` applied to every scoped query              |
| AES-256 encryption at rest      | `EncryptionService` — ID / license / bank / MFA secrets   |
| Sensitive field masking         | `LogService` auto-redacts password/token/secret keys      |
| Append-only audit log           | `AuditService` — verification, contract, payment, export  |
| Payment idempotency             | `PaymentService` — 10-min window, `method+route+actor+key` scope |
| Message recall + risk policy    | `MessagingService` + `RiskService`                        |
| Attachment validation           | `MessagingService::processAttachment` — MIME + 10 MB + SHA-256 |

### Business Rules

| Rule                          | Location                                       |
| ----------------------------- | ---------------------------------------------- |
| Late fee: 5-day grace         | `LateFeeService::GRACE_DAYS`                   |
| Late fee: 1.5% monthly daily  | `LateFeeService::DAILY_RATE_BPS` (50 bps/day)  |
| Late fee: cap $250/invoice    | `LateFeeService::CAP_CENTS` (25000)            |
| Verification state machine    | `VerificationService` — `pending → approved/rejected` |
| Invoice state machine         | `InvoiceService` — `unpaid → paid/overdue`     |
| Delegation: 30-day max        | `DelegationService::MAX_EXPIRY_DAYS`           |
| Delegation: two-person rule   | `DelegationService::approve` — grantor ≠ approver |

---

## Environment Variables

All env vars are declared inline in `docker-compose.yml`. No `.env` file required.

| Var                | Default                                | Purpose                   |
| ------------------ | -------------------------------------- | ------------------------- |
| `APP_DEBUG`        | `false`                                | Debug logging             |
| `DB_HOST`          | `db`                                   | DB hostname (service name)|
| `DB_PORT`          | `3306`                                 | DB port                   |
| `DB_DATABASE`      | `rural_lease`                          | DB name                   |
| `DB_USERNAME`      | `app`                                  | DB user                   |
| `DB_PASSWORD`      | `app`                                  | DB password               |
| `ENCRYPTION_KEY`   | 64-char hex string (in compose file)   | AES-256 key for at-rest   |
| `JWT_SECRET`       | 32+ char secret (in compose file)      | Token signing             |

### Optional: TLS Intranet Profile

For intranet deployment with HTTPS:

```bash
docker compose -f docker-compose.yml -f docker-compose.tls.yml up --build -d
# Portal available at https://localhost:8443 (self-signed cert auto-generated on first run)
```

The `docker-compose.tls.yml` overlay adds an nginx TLS terminator in front of the
API. Self-signed certificates are generated into `storage/tls/` on first run.

---

## Project Layout

```
repo/
├── app/
│   ├── controller/      # REST endpoints (Auth, Entity, Contract, Invoice, Payment, Message, Audit, Admin, Delegation, Captcha)
│   ├── service/         # Business logic (Auth, Scope, Encryption, Audit, Late fee, Risk, Job, etc.)
│   ├── middleware/      # TraceId, JsonResponse, AuthCheck
│   └── ExceptionHandle.php
├── config/              # app, database, middleware, log, route, cache
├── database/
│   ├── migrate.php      # Migration runner
│   └── migrations/      # 001–009 SQL files
├── public/
│   ├── index.php        # ThinkPHP web entry
│   ├── router.php       # PHP built-in server router (static passthrough)
│   └── static/
│       ├── index.html   # Main app shell
│       ├── login.html   # Login page (+ CAPTCHA)
│       ├── register.html
│       ├── css/
│       └── js/          # api-client, app, auth, entities, finance, messaging, admin
├── route/app.php        # Route definitions grouped by slice
├── storage/
│   ├── uploads/         # Attachment storage (10 MB cap, SHA-256)
│   ├── exports/         # CSV exports
│   ├── keys/            # Encryption keys (outside web root)
│   └── tls/             # TLS certs + nginx.conf (for TLS profile)
├── tests/
│   ├── unit/            # Unit tests (in-container)
│   ├── api/             # API integration tests
│   └── TestCaptchaHelper.php
├── tools/
│   ├── coverage.php     # Route + frontend coverage analyzer
│   └── scheduler.php    # Background job loop (started by entrypoint)
├── Dockerfile
├── docker-compose.yml
├── docker-compose.tls.yml  # Optional TLS intranet overlay
├── docker-entrypoint.sh    # Migrations → scheduler → API server
├── phpunit.xml
├── run_tests.sh         # Host-side test orchestrator
└── composer.json
```

---

## Troubleshooting

**"CAPTCHA is required" on the frontend**
The login/register pages fetch a fresh CAPTCHA challenge on page load.
If you see the error, click the refresh icon next to the CAPTCHA field
or reload the page. Challenges expire in 5 minutes and are single-use.

**Tests fail with "Connection refused"**
The DB container may not be fully ready. Wait a few seconds and re-run
`./run_tests.sh` — the script has built-in retries but a cold start can
take up to 20 seconds on slower machines.

**Frontend shows "System is unreachable"**
Check that the API container is healthy: `docker compose ps` — the status
should say `(healthy)`. If not, inspect `docker compose logs api`.

**Migration failed to apply**
Run `docker compose down -v` to clear the database volume and start fresh.
All 9 migrations will re-run automatically on next startup.

---

## License & Scope

This project is a reference implementation for the Rural Land Lease & Settlement
Management Portal. It is designed to run fully offline on a county intranet with
no external API dependencies. All payment, verification, and messaging flows
persist to a local MySQL database, and every sensitive field is encrypted at rest.
