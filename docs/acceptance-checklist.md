# Rural Lease Portal - Acceptance Checklist

## 1. Runtime and Startup

- [ ] `docker compose up --build` succeeds
- [ ] API and DB healthchecks are healthy
- [ ] `GET /health` returns `{"status":"ok"}`
- [ ] `./run_tests.sh` runs from host and executes tests in container

## 2. Security Gates

- [ ] Password policy enforced
- [ ] Lockout and backoff enforced
- [ ] RBAC enforced on protected routes
- [ ] Geographic scope enforced on all scoped resources
- [ ] Sensitive fields encrypted at rest and masked by default
- [ ] Audit log append-only

## 3. Domain Gates

- [ ] Verification workflow works with required reject reason
- [ ] Duplicate detection and merge flow works
- [ ] Contract schedule generation works from real DB state
- [ ] Late fee rule and cap are accurate
- [ ] Payment idempotency works in 10-minute window
- [ ] Messaging recall and risk actions work as specified

## 4. Fullstack Gates

- [ ] Every implemented endpoint is wired to Layui UI
- [ ] UI handles loading/success/error/policy-blocked states
- [ ] No hardcoded mock responses in production paths

## 5. Test Gates

- [ ] Unit tests cover password, lockout, fee calculation, idempotency, encryption
- [ ] API tests cover happy path + 400/401/403/409 paths
- [ ] API tests cover scope isolation and role denial
- [ ] End-to-end flow works: register -> verify -> contract -> invoice -> payment -> export
