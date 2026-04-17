# Rural Land Lease & Settlement Management Portal

**Project type: fullstack** (ThinkPHP REST API backend + Layui web UI frontend, MySQL persistence).

> A secure, offline-first portal for administering agricultural lease contracts,
> entity profiles, financial reconciliation, and in-app communications across
> a county. Built as a ThinkPHP REST API with a Layui web UI, backed by MySQL,
> and shipped as a single Docker Compose stack.

---

## Quick Start

```bash
# 1. Start the stack (API + MySQL) — first run builds images and applies migrations.
#    Both the modern V2 plugin form and the legacy hyphenated binary are supported:
docker compose up --build -d         # Docker Compose V2 (plugin)
# docker-compose up --build -d       # Docker Compose V1 (legacy binary, equivalent)

# 2. Wait ~15 seconds for the health check, then open the portal in your browser:
#    http://localhost:8000/static/login.html
#    (On macOS you can run `open <url>`; on Linux `xdg-open <url>`; Windows `start <url>`.)

# 3. Run the full test suite (unit + API, executed inside the container)
./run_tests.sh

# 4. (optional) Run the frontend JS unit tests on the host (Node 18+):
npm install && npm test
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
2. **Create an account** — pick a role (`farmer` / `enterprise` / `collective`),
   scope is `village` (public registration is restricted to village scope; township/county
   access requires admin provisioning via `POST /admin/users`), solve the CAPTCHA math
   question, and use a password with ≥12 chars including upper, lower, digit, and symbol.
   > **Note:** `system_admin` cannot be self-registered. Admin and township/county-scoped
   > accounts must be created by an existing admin via `POST /admin/users` (see "Admin Bootstrap" below).
3. **Log in** at `/static/login.html`. The CAPTCHA refreshes automatically on each attempt.
4. **Navigate**: the sidebar reveals verification, profiles, contracts, invoices, payments,
   messaging, and (for `system_admin`) admin verifications, risk keywords, audit log, jobs, and config.
5. **Try the features**:
   - **Verification:** Submit your real-name/qualification data (ID number, business license,
     scan upload) from "My Verification" in the sidebar. Status shows Pending/Approved/Rejected
     with rejection reason displayed when denied.
   - **Entity profiles:** Create a profile with configurable extra fields (primary crop,
     equipment needs, etc.) that load dynamically per entity type.
   - **Duplicate merge:** When duplicate flags appear, click "Start Guided Merge" for a
     side-by-side comparison with editable resolution map — choose which values to keep.
   - **Contracts & invoices:** Create a contract → invoice schedule auto-generates. Overdue
     invoices accrue late fees automatically (5-day grace, 1.5%/month daily, capped at $250).
   - **Payments:** Post a payment with `Idempotency-Key` header; concurrent same-key requests
     are atomically deduplicated — only one payment row is created.
   - **Messaging:** Send a message containing `scam` → the server blocks it (409) per risk policy.
     Recall a message within 10 minutes → body becomes `[This message was recalled]`.
   - **Receipts:** From the Invoices list, click "Receipt" on any invoice to preview a
     printable receipt with payment/refund details and outstanding balance. Click "Print"
     to open the browser print dialog.
   - **Delegations:** As `system_admin`, navigate to Admin → Delegations to create, list,
     and approve/reject access delegations. Two-person rule enforced.
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

# 2. Register a farmer user (village scope)
curl -s -X POST http://localhost:8000/auth/register \
  -H 'Content-Type: application/json' \
  -d "{
    \"username\": \"farmer\",
    \"password\": \"FarmerP@ss12345\",
    \"role\": \"farmer\",
    \"geo_scope_level\": \"village\",
    \"geo_scope_id\": 3,
    \"captcha_id\": \"$CID\",
    \"captcha_answer\": \"$ANS\"
  }"
```

> **Admin accounts** cannot be self-registered. The first admin must be seeded
> out-of-band (see "Admin Bootstrap" below). Subsequent admins are created by
> existing admins via `POST /admin/users`.

### Sample User Matrix

| Role            | Username  | Password          | Scope Level | Scope ID | Notes                         |
| --------------- | --------- | ----------------- | ----------- | -------- | ----------------------------- |
| `system_admin`  | `admin`   | `AdminP@ss12345`  | `county`    | `1`      | Must be bootstrapped (see below) |
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

- **Backend (route coverage):** every defined HTTP route has ≥1 real-transport
  test assertion (no mocks) under `tests/api/` and `tests/unit/`. Reproducible
  analyzer: `docker compose exec -T api php tools/coverage.php`.
- **Backend (module/page asset coverage):** every JS module file, page section
  and HTML page is verified by PHP-based static-content tests under
  `tests/api/FrontendIntegrationTest.php` and
  `tests/api/FrontendModuleCoverageTest.php`.
- **Frontend (JS unit tests):** Vitest + happy-dom unit tests live under
  `tests/frontend/*.test.js` and exercise the seven Layui modules
  (`api-client`, `app`, `auth`, `entities`, `finance`, `messaging`, `admin`)
  directly by loading the real source from `public/static/js/`. Run with
  `npm install && npm test` on the host.

## Financial Exports (CSV / XLSX)

Both ledger and reconciliation exports support **CSV** and **XLSX** (Open XML
Spreadsheet). The format is chosen via a `?format=` query parameter:

| Endpoint                      | Parameter          | Content-Type                                                               |
| ----------------------------- | ------------------ | -------------------------------------------------------------------------- |
| `GET /exports/ledger`         | `?format=csv`      | `text/csv`                                                                 |
| `GET /exports/ledger`         | `?format=xlsx`     | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`        |
| `GET /exports/reconciliation` | `?format=csv`      | `text/csv`                                                                 |
| `GET /exports/reconciliation` | `?format=xlsx`     | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`        |

Both endpoints also accept `from` and `to` date range parameters (ISO
`YYYY-MM-DD`). Omitted format defaults to CSV. Unknown formats return 400.

```bash
# Ledger as CSV (default)
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/exports/ledger?from=2026-01-01&to=2026-12-31" \
  -o ledger.csv

# Ledger as XLSX (opens in Excel / LibreOffice / Google Sheets)
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/exports/ledger?format=xlsx&from=2026-01-01&to=2026-12-31" \
  -o ledger.xlsx

# Reconciliation XLSX
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/exports/reconciliation?format=xlsx&from=2026-01-01&to=2026-12-31" \
  -o reconciliation.xlsx
```

Both endpoints are scope-filtered: a village/township user only receives
rows for the areas they (and any active delegations they hold) can reach.
Every export call writes an append-only audit log entry including the row
count, format, caller IP and User-Agent.

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
| Role-based access control       | Route-level `authCheck:system_admin` + service-layer defense-in-depth |
| Refund authorization            | `POST /refunds` restricted to `system_admin` (route + service guard)  |
| Geographic scope isolation      | `ScopeService` applied to every scoped query (incl. admin list endpoints) |
| Public scope restriction        | Public registration limited to village scope; township/county via admin only |
| Scope-level validation          | `geo_scope_level` must match `geo_areas.level` for the referenced area |
| Role-specific entry UX          | Login redirects to role-appropriate landing page (`?role=` parameter) |
| AES-256 encryption at rest      | `EncryptionService` — ID / license / bank / MFA / scan files |
| Attachment content inspection   | Server-side `finfo` magic-byte verification against declared MIME |
| Sensitive field masking         | `LogService` auto-redacts password/token/secret keys      |
| Append-only audit log           | `AuditService` — verification, contract, payment, export  |
| Payment idempotency (atomic)    | `PaymentService` — 10-min window, atomic reserve-first via UNIQUE constraint |
| Message recall + risk policy    | `MessagingService` + `RiskService`                        |
| Attachment validation           | `MessagingService::processAttachment` — MIME + 10 MB + SHA-256 |

### Business Rules

| Rule                          | Location                                       |
| ----------------------------- | ---------------------------------------------- |
| Late fee: 5-day grace         | `LateFeeService::GRACE_DAYS`                   |
| Late fee: 1.5% monthly daily  | `LateFeeService::DAILY_RATE_BPS` (5 bps/day = 0.05%/day) |
| Late fee: cap $250/invoice    | `LateFeeService::CAP_CENTS` (25000)            |
| Late fee: lifecycle integration | `InvoiceService::markOverdue` + `updateLateFees` — persists `late_fee_cents` |
| Verification state machine    | `VerificationService` — `pending → approved/rejected` |
| Verification user flow        | `GET /verifications/mine` — user checks own status + rejection reason |
| Invoice state machine         | `InvoiceService` — `unpaid → paid/overdue`     |
| Delegation: 30-day max        | `DelegationService::MAX_EXPIRY_DAYS`           |
| Delegation: two-person rule   | `DelegationService::approve` — grantor ≠ approver |
| Verification: evidence required | `VerificationService::submit` — at least one of id_number, license_number, or scan_path |
| Balance formula (canonical)   | `outstanding = invoice_amount + late_fee - totalPaid + totalRefunded` (consistent across payment/refund/receipt) |
| Contract scope attribution    | `ContractService::create` — scope from target profile, not actor |

---

## Environment Variables

All env vars are declared inline in `docker-compose.yml`. No `.env` file required.

| Var                    | Default                                  | Purpose                                                                                 |
| ---------------------- | ---------------------------------------- | --------------------------------------------------------------------------------------- |
| `APP_ENV`              | `development`                            | Controls the production guard on the dev encryption key (see below).                    |
| `APP_DEBUG`            | `false`                                  | Debug logging                                                                           |
| `DB_HOST`              | `db`                                     | DB hostname (service name)                                                              |
| `DB_PORT`              | `3306`                                   | DB port                                                                                 |
| `DB_DATABASE`          | `rural_lease`                            | DB name                                                                                 |
| `DB_USERNAME`          | `app`                                    | DB user                                                                                 |
| `DB_PASSWORD`          | `app`                                    | DB password                                                                             |
| `ENCRYPTION_KEY`       | dev marker (`…DEADBEEF`)                 | AES-256 at-rest key (64-char hex). **Rejected in production mode — see below.**         |
| `ENCRYPTION_KEY_FILE`  | _(unset)_                                | Preferred: path to a secret-mounted file containing the 64-char hex key.                |
| `JWT_SECRET`           | 32+ char secret (in compose file)        | Token signing                                                                           |

### Secrets & Encryption Key Management (Issue I-10)

The encryption key is the ONLY secret that protects sensitive at-rest data
(MFA secrets, message bodies, attachment files, ID/license/bank references).
To avoid shipping a real key in version-controlled config, the default
`ENCRYPTION_KEY` in `docker-compose.yml` is the well-known
**DEV marker key** (`00000000000000000000000000000000000000000000000000000000DEADBEEF`).

`EncryptionService::getKey()` enforces the following policy:

1. **Precedence:** if `ENCRYPTION_KEY_FILE` is set AND readable, its contents
   are used. Otherwise `ENCRYPTION_KEY` is used.
2. **Format:** the value must be a 64-character hexadecimal string (32 raw
   bytes → AES-256). Shorter or non-hex values throw at boot.
3. **Production guard:** if `APP_ENV` is anything other than
   `development` / `dev` / `test` / `testing` / `local`, the service
   REFUSES to operate with the DEV marker key. Every encrypt/decrypt call
   throws `RuntimeException` until the key is rotated.

**Production deployment recipe:**

```bash
# 1. Generate a fresh 32-byte random key as hex (64 chars)
openssl rand -hex 32 > /run/secrets/rural_lease_encryption_key
chmod 600 /run/secrets/rural_lease_encryption_key

# 2. Mount it read-only into the api container via docker-compose.override.yml:
#
#    services:
#      api:
#        environment:
#          APP_ENV: production
#          ENCRYPTION_KEY_FILE: /run/secrets/rural_lease_encryption_key
#          # Unset ENCRYPTION_KEY so the file takes precedence
#        secrets:
#          - source: rural_lease_encryption_key
#            target: /run/secrets/rural_lease_encryption_key
#
#    secrets:
#      rural_lease_encryption_key:
#        file: /run/secrets/rural_lease_encryption_key

# 3. Rotate keys by writing a new file and restarting the api container.
```

> **Key rotation is destructive for existing ciphertext.** Rotate keys
> only during a maintenance window with a documented re-encryption plan.

### Admin Bootstrap (Issue I-09)

After the privilege-escalation remediation, **public `/auth/register`
refuses to mint `system_admin` accounts.** Farmer, enterprise, and
collective registrations continue to work from the public page.

Admin accounts must be created by an existing system_admin via:

```
POST /admin/users        (middleware: authCheck:system_admin)
Body: { username, password, role, geo_scope_level, geo_scope_id }
```

The very first admin must be seeded out-of-band — e.g. by a one-time
SQL insert during deployment or by a dedicated bootstrap migration.
Test suites use `tests/AdminBootstrap.php` which writes the row via
PDO then performs a normal login, keeping tests honest to the security
posture without reopening the public register path.

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
│       ├── js/          # api-client, app, auth, entities, finance, messaging, admin
│       └── layui/       # Layui 2.9 assets (vendored, no internet required at build)
├── route/app.php        # Route definitions grouped by slice
├── storage/
│   ├── uploads/         # Attachment storage (10 MB cap, SHA-256 checksum, AES-256 at rest)
│   ├── exports/         # Financial exports (CSV + XLSX / Open XML)
│   ├── keys/            # Encryption keys (outside web root)
│   └── tls/             # TLS certs + nginx.conf (for TLS profile)
├── tests/
│   ├── unit/            # Backend unit tests (in-container)
│   ├── api/             # Backend API integration tests (real HTTP, no mocks)
│   ├── frontend/        # Frontend JS unit tests (Vitest + happy-dom)
│   └── TestCaptchaHelper.php
├── tools/
│   ├── coverage.php     # Route + frontend coverage analyzer
│   └── scheduler.php    # Background job loop (started by entrypoint)
├── Dockerfile
├── docker-compose.yml
├── docker-compose.tls.yml  # Optional TLS intranet overlay
├── docker-entrypoint.sh    # Migrations → scheduler → API server
├── phpunit.xml
├── run_tests.sh         # Host-side test orchestrator (backend suites)
├── package.json         # Frontend test tooling (Vitest)
├── vitest.config.js     # Vitest config for tests/frontend/**
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
Management Portal. At runtime it operates fully offline on a county intranet with
no external API dependencies — all payment, verification, and messaging flows
persist to a local MySQL database, and every sensitive field is encrypted at rest.
Frontend assets (Layui) are vendored in-repo so Docker builds require only the
base PHP image and Composer registry (no GitHub/CDN fetch for UI assets).
