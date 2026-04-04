# Rural Lease Portal Build Order (Workflow-Compliant, Fullstack)

This build order is tailored to the `w2t50` stack and domain, while keeping the same submission rules from `.tmp/eaglepoint-workflow.md`.

Framework baseline:
- Backend: ThinkPHP (REST-style API)
- Frontend: Layui web UI
- Database: MySQL
- Runtime/testing: Docker Compose + `run_tests.sh`

Core execution rules:
- Build one slice at a time; do not move forward until gate passes.
- Every backend endpoint introduced in a slice must be integrated in UI in the same slice.
- Every slice gate requires backend + frontend verification.
- Tests run in container flow, not host-only shortcuts.

## Global Non-Negotiables

- `docker-compose.yml` uses inline env vars only (no `env_file` dependency).
- API service has `/health` healthcheck and `restart: unless-stopped`.
- DB service has healthcheck and explicit ports are declared.
- `run_tests.sh` orchestrates docker startup + in-container unit/API tests.
- RBAC and geographic data scope are enforced server-side on every request.
- Audit log stays append-only; no delete/update path.

## Preflight (Before Slice 1)

- Confirm docs are aligned: `prompt.md`, `docs/questions.md`, `docs/build-order.md`.
- Lock project skeleton: `Dockerfile`, `docker-compose.yml`, `run_tests.sh`, migrations, `unit_tests/`, `API_tests/`.
- Define standard error envelope and trace-id strategy.
- Gate:
  - `docker compose up --build` starts cleanly
  - `/health` responds and compose healthchecks pass
  - `./run_tests.sh` executes with clear pass/fail summary

## Slice 1 - Foundation

- ThinkPHP app bootstrap and environment-safe config loading.
- MySQL connection + migration/seeding baseline.
- `GET /health` returns `{"status":"ok"}`.
- Structured logs + trace id emitted on every request.
- `X-Trace-Id` response header on all responses.
- Layui app shell, base API client, and health status integration.
- Tests:
  - health endpoint contract
  - trace id header test
  - migration/bootstrap test
  - frontend build check + health call integration check
- Gate:
  - foundation backend/frontend tests pass in Docker

## Slice 2 - Auth and Identity

- Registration, login, logout, session handling.
- Password policy: min 12 + upper/lower/number/symbol.
- Lockout after 5 failures in 15 minutes with exponential backoff.
- Optional TOTP MFA flow for admins.
- Layui auth pages and role-aware routing.
- Tests:
  - register/login/logout happy path
  - weak password -> 400
  - lockout + backoff boundary tests
  - MFA enroll/verify admin path
  - unauthorized/forbidden route behavior (401/403)
- Gate:
  - auth unit + API + UI auth integration tests pass

## Slice 3 - Profiles, Verification, and Scope

- Entity profile CRUD (farmer/enterprise/collective) + configurable extra fields.
- Verification workflow (`pending/approved/rejected`) with mandatory reject reason.
- Geographic scope filters (village/township/county) enforced in query layer.
- Duplicate flagging by name + address + last4 id/license.
- Layui profile/verification pages with status and reviewer feedback.
- Tests:
  - scope isolation tests (cross-scope denial)
  - reject-without-reason -> 400
  - duplicate flag generation on create/update
- Gate:
  - profile/verification backend + UI integration tests pass

## Slice 4 - Contracts and Billing Schedule

- Contract CRUD and full-term invoice schedule generation.
- Invoice lifecycle (`unpaid/paid/overdue`) + daily overdue update path.
- Immutable invoice snapshot semantics on financial events.
- Layui contract and invoice screens with timeline/status cues.
- Tests:
  - schedule generation correctness
  - immutable snapshot behavior
  - invalid state transitions -> 409
- Gate:
  - contract/billing state-machine tests pass

## Slice 5 - Payments, Refunds, and Reconciliation

- Payment posting with idempotency (10-minute dedup window).
- Refund recording linked to invoice (early termination/overpay/manual).
- Late fee rule: 5-day grace, 1.5% monthly applied daily, cap $250/invoice.
- Receipt print payload and CSV/Excel ledger export endpoints.
- Layui payment/refund/ledger/reconciliation pages.
- Tests:
  - idempotent payment replay behavior
  - late fee boundary tests (day 5 vs day 6)
  - cap enforcement tests
  - export endpoint auth/scope checks
- Gate:
  - finance calculations + API/UI flow tests pass

## Slice 6 - Messaging and Risk Controls

- In-app conversations (text/voice/image) with read indicators.
- Recall within 10 minutes (soft recall placeholder behavior).
- Attachment validation (type/size <= 10MB) + local checksum.
- Offline risk keyword/pattern actions: warn/block/allow-and-flag.
- Layui messaging panel with risk warnings and recall UX.
- Tests:
  - recall window boundary tests
  - attachment validation tests
  - risk action mode tests (warn/block/flag)
- Gate:
  - messaging/risk backend + UI tests pass

## Slice 7 - Security Hardening and Audit

- AES-256 at-rest encryption for ID/license/bank references.
- Key management outside web root + rotation process.
- TLS-ready local deployment profile.
- Strict masking in API/UI defaults.
- Append-only audit logging for verification, contracts, billing, payments/refunds, exports.
- Tests:
  - encryption round-trip + no-plaintext-at-rest checks
  - audit append-only enforcement tests
  - masking regression tests (API + UI)
  - 403 matrix tests per role/scope
- Gate:
  - security suite passes in Docker

## Slice 8 - Background Jobs, Admin Config, and Final Polish

- Wire and verify scheduled jobs (overdue updates, retention, delegation expiry).
- Admin config for retention defaults and risk-library management.
- API docs endpoint and README accuracy (ports, credentials, startup/test commands).
- Cold-start validation (`docker compose down -v` then full bring-up).
- Full regression (unit + API + key UI flows).
- Gate:
  - `./run_tests.sh` passes from cold start
  - acceptance checklist satisfied for submission rules
