# EaglePoint Development Workflow

## Core Principle

Everything runs inside Docker. No local environment. No local database. No local Node/Python/Rust. If it doesn't work in `docker compose up`, it doesn't work.

---

## Non-Excusable Requirements

These are never optional. Never simplified. Never mocked. If any of these are missing or faked, the submission fails immediately with no repair opportunity.

### Security — Must Be Real, Not Stubbed

| Requirement | What "Real" Means | What Is NOT Acceptable |
|---|---|---|
| Password policy | Enforced server-side on every registration and password change | Accepting any string, client-side only check |
| Account lockout | After N failures in a rolling time window, account is locked | Counter that never actually blocks login |
| JWT / session auth | Every protected route validates the token before processing | Routes that skip auth check |
| Role-based access | Every endpoint checks the caller's role before executing | Roles stored but never checked |
| Object-level authorization | User can only access their own resources — verified by DB query, not just by ID | Trusting the client-supplied ID without ownership check |
| AES-256 encryption | Sensitive fields encrypted in DB, decrypted only on authorized read | Storing plaintext and calling it "encrypted" |
| Audit log | INSERT-only, no DELETE or UPDATE endpoint exists | Audit log with a delete route, or no audit log at all |
| Sensitive field masking | Passwords, keys, tokens never appear in API responses or logs | Returning `password_hash` in user response |
| Idempotency | Retry-able operations (payments, imports, webhooks) deduplicate within the defined window | No dedup check, or dedup that only works in memory |

### Core Business Logic — Must Match the Prompt Exactly

| Requirement | What "Real" Means | What Is NOT Acceptable |
|---|---|---|
| State machines | All valid states defined as enum, invalid transitions rejected with 409, every transition logged | States stored as strings with no transition validation |
| Financial calculations | Correct formula, correct grace period, correct cap, money in integer cents | Approximate calculation, float arithmetic, missing cap |
| Business rules | Every explicit rule in the prompt implemented (e.g. "5-day grace period", "capped at $250", "max 5 devices") | Ignoring numeric constraints or implementing a simpler version |
| Data scope / isolation | Users can only see data they are authorized for — enforced in every DB query | Returning all records and filtering in the frontend |
| Background jobs | Scheduled jobs actually registered and running at startup — not just defined as functions | Job function exists but is never called |
| Idempotency windows | Exact window from the prompt (e.g. "10-minute dedup window") implemented with timestamp check | No window, or wrong window duration |

### Known Failure Patterns — These Have Happened Before

These are real mistakes that passed initial review but were caught in acceptance. Every one of them looks implemented but is actually broken.

**Idempotency — Actor Not Bound**
- What happened: idempotency middleware stored the key globally. User A's key could be replayed by User B on a different endpoint and return User A's response.
- What correct looks like: the idempotency key is scoped to `method + route + userId`. Same key from a different user or on a different route is treated as a new request, not a replay.
- Test that catches it: send the same `Idempotency-Key` header from two different authenticated users — the second must NOT get the first user's response.

**Lockout — Not a Rolling Window**
- What happened: `failedAttempts` counter incremented on every failure. After 10 failures over 3 days, the account locked. This is not "10 failures in 15 minutes."
- What correct looks like: each failed attempt is timestamped. The lockout check counts failures where `timestamp > now - 15 minutes`. Old failures outside the window don't count.
- Test that catches it: 9 failures, wait 16 minutes, 1 more failure — account must NOT lock. Then 10 failures within 15 minutes — account MUST lock.

**Challenge Rate Limit — Upsert Breaks the Count**
- What happened: the unusual-location challenge stored one record per user+device using upsert. The rate limit checked `count > 3` but upsert always kept exactly 1 record, so the count never exceeded 1.
- What correct looks like: each challenge attempt is a new INSERT with a timestamp. Rate limit counts rows where `created_at > now - 1 hour`. Upsert must not be used.
- Test that catches it: trigger 4 challenge attempts within 1 hour — the 4th must return 429 with rate limit error.

**Idempotency — Runs Before Auth**
- What happened: idempotency middleware was registered globally before the auth middleware. An unauthenticated request with a valid idempotency key could replay a previous authenticated response.
- What correct looks like: idempotency middleware runs AFTER auth middleware on protected routes. Unauthenticated requests never hit idempotency replay logic.
- Test that catches it: make an authenticated request, store the idempotency key, then replay it without a token — must get 401, not the stored response.

**Background Jobs — Defined But Never Wired**
- What happened: `processOutbox()` and `resetDailyCap()` were implemented as service functions but never registered with the scheduler. They existed in the codebase but never ran.
- What correct looks like: `server.ts` (or equivalent startup file) explicitly registers every scheduled job with the cron library at startup. Grep for the job function name — it must appear in the startup file.
- Test that catches it: check `server.ts` for cron registration. If the function name only appears in the service file and nowhere else, it's not wired.

**Object-Level Authorization — ID Trusted Without Ownership Check**
- What happened: `GET /itineraries/:id` fetched the itinerary by ID without checking if the authenticated user owned it. Any authenticated user could read any itinerary by guessing the ID.
- What correct looks like: every fetch by ID includes `WHERE id = ? AND owner_id = ?` (or equivalent). The ownership check is in the DB query, not in application code after the fetch.
- Test that catches it: create resource as User A, authenticate as User B, request the resource by ID — must get 403, not 200.

**Notification Daily Cap — Reset Never Runs**
- What happened: `dailySent` counter incremented correctly but the midnight reset job was never wired. After 24 hours the counter was still at the old value and users were permanently blocked.
- What correct looks like: a cron job at midnight resets `dailySent = 0` for all users. The job must be registered at startup.
- Test that catches it: send 20 notifications (hit the cap), simulate midnight reset by calling the reset function directly, send 1 more — must succeed.

### The Test That Proves It

For every item in the two tables above, there must be at least one test that would **fail** if the requirement were removed. If you cannot point to a test that catches the absence of a requirement, the requirement is not considered implemented.

```
Security requirement → test that proves 401/403 is returned when it should be
State machine → test that proves 409 is returned on invalid transition
Financial calculation → test that proves the boundary value (day 5 vs day 6, $249 vs $251)
Data isolation → test that proves user A cannot access user B's resource
Idempotency → test that proves duplicate submission returns original response, not a new record
```

---

---

## Phase 1 — Before Writing Any Code

### 1.1 Read the Prompt Completely

Read the full prompt twice. Extract:
- The business domain and core user flows
- Every explicit technical constraint (stack, versions, auth method, encryption, idempotency rules)
- Every implicit constraint (offline-only, no external APIs, single-node)
- All state machines mentioned (order states, verification states, traceability status)
- All financial or calculation rules (late fees, caps, grace periods)
- All security requirements (password policy, lockout, encryption fields, audit log)

Reject immediately if the prompt requires uncontrollable third-party APIs, Windows-only tools, or has no clear core requirement.

### 1.2 Write questions.md First

Before designing anything, write `questions.md`. For every ambiguity:

```
Question: What exactly is unclear or unspecified.
Assumption: How you interpret the intended behaviour.
Solution: How you will implement it based on that assumption.
```

Minimum 10 questions per project. Focus on:
- State transitions that are not fully defined
- Who can do what (permission matrix gaps)
- Financial calculation edge cases
- What happens on error or conflict
- Data ownership and scope rules

### 1.3 Write docs/design.md

Cover:
- Architecture diagram (text-based is fine)
- Module breakdown with responsibilities
- Database table list with key fields
- Auth and session design
- Background job list
- Security design summary
- Error response format

### 1.4 Write docs/api-spec.md

List every endpoint:
- Method + path
- Auth requirement
- Request body / query params
- Response shape
- Error codes

---

## Phase 2 — Project Setup

### 2.1 Folder Structure

```
[project_root]/
├── docs/
│   ├── design.md
│   ├── api-spec.md
│   ├── features.md
│   ├── build-order.md
│   └── questions.md
├── repo/                    ← all source code lives here
│   ├── src/
│   ├── unit_tests/
│   ├── API_tests/
│   ├── run_tests.sh
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── README.md
│   └── [config files]
├── sessions/
│   └── develop-1.json       ← exported session trajectory
├── metadata.json
└── prompt.md
```

### 2.2 Docker Rules — Non-Negotiable

**Dockerfile:**
- Single-stage or multi-stage — both fine, but the final image must contain everything needed to run AND test
- Copy test files (`unit_tests/`, `API_tests/`, `run_tests.sh`) into the image
- No `npm install` or `pip install` at runtime — all deps installed at build time
- No `.env` file dependency — all env vars declared inline in `docker-compose.yml`

**docker-compose.yml:**
- All env vars hardcoded inline — no `env_file:` pointing to a `.env`
- API service has a healthcheck on the `/health` endpoint using `wget`
- `restart: unless-stopped` on the API service
- DB service has a healthcheck
- All ports explicitly declared

**Example docker-compose.yml pattern:**
```yaml
services:
  api:
    build: .
    ports:
      - "3000:3000"
    environment:
      DATABASE_URL: mysql://app:app@db:3306/appdb
      JWT_SECRET: dev-secret-min-32-chars-xxxxxxxxx
      ENCRYPTION_KEY: 00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:3000/health"]
      interval: 10s
      retries: 5
      start_period: 15s
    restart: unless-stopped

  db:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_USER: app
      MYSQL_PASSWORD: app
      MYSQL_DATABASE: appdb
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      retries: 10
    volumes:
      - dbdata:/var/lib/mysql

volumes:
  dbdata:
```

### 2.3 run_tests.sh — Required Pattern

The script runs from the host machine. It manages Docker itself.

```sh
#!/bin/bash
set -e

HEALTH_URL="http://localhost:3000/health"
MAX_WAIT=60

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
    echo "[1/4] Containers already running ✓"
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
  docker compose logs --tail 30 api
  exit 1
fi

# Step 3: Unit tests (inside container)
echo "[3/4] Unit tests..."
UNIT_EXIT=0
docker compose exec -T api npx jest --testPathPattern=unit_tests --verbose --no-cache || UNIT_EXIT=$?

# Step 4: API tests (inside container)
echo "[4/4] API tests..."
API_EXIT=0
docker compose exec -T api npx jest --testPathPattern=API_tests --verbose --no-cache --runInBand || API_EXIT=$?

# Summary
echo "========================================"
[ $UNIT_EXIT -eq 0 ] && echo "  Unit tests:  PASSED" || echo "  Unit tests:  FAILED"
[ $API_EXIT  -eq 0 ] && echo "  API tests:   PASSED" || echo "  API tests:   FAILED"
echo "========================================"
exit $((UNIT_EXIT + API_EXIT))
```

---

## Phase 3 — Build Order

Build one slice at a time. Do not move to the next slice until the current one passes tests.

### Slice 1 — Foundation
- App boots, connects to DB, migrations run
- `GET /health` returns `{"status":"ok"}`
- Structured logging with trace IDs on every request
- `X-Trace-Id` header on every response
- `run_tests.sh` runs and produces output
- **Gate:** `docker compose up` clean, health passes, bootstrap tests pass

### Slice 2 — Auth and Identity
- Registration, login, logout
- Password policy enforced (min length, complexity)
- JWT or session-based auth
- Account lockout after N failures
- Protected routes return 401/403 correctly
- **Gate:** auth unit tests + API tests pass

### Slice 3 — Core Business Domain (Slice A)
- The primary entities from the prompt (users, products, contracts, intakes — whatever the domain is)
- CRUD with real DB reads/writes
- No hardcoded responses
- **Gate:** domain unit tests + API tests pass

### Slice 4 — Core Business Domain (Slice B)
- Secondary entities and relationships
- State machines implemented (order states, verification states, etc.)
- Idempotency keys on all mutating operations that require them
- **Gate:** state machine tests pass

### Slice 5 — Business Rules and Calculations
- Financial calculations (fees, caps, grace periods)
- Validation rules (file types, sizes, formats)
- Conflict detection and resolution
- **Gate:** calculation unit tests pass with edge cases

### Slice 6 — Security Hardening
- AES-256 encryption on sensitive fields
- Audit log (append-only, no delete)
- Sensitive field masking in responses and logs
- RBAC enforced on every endpoint
- **Gate:** security tests pass, 403 tests pass

### Slice 7 — Background Jobs
- Scheduled jobs wired to cron
- Retry logic with exponential backoff
- Job state persisted in DB
- **Gate:** job behavior tested

### Slice 8 — Final Polish
- Swagger/OpenAPI at `/api/docs`
- README accurate with real credentials and ports
- All tests passing
- No `node_modules/`, `dist/`, `.env` in repo
- **Gate:** `./run_tests.sh` passes all tests from cold start

---

## Phase 4 — Testing Standards

### Unit Tests (`unit_tests/`)
Cover:
- Password policy validation
- State machine transitions (valid and invalid)
- Financial calculations with edge cases (grace period boundary, cap enforcement)
- Encryption/decryption round-trip
- Idempotency key logic
- Any pure business logic function

### API Tests (`API_tests/`)
Cover every endpoint with:
- Happy path (201/200 with correct response shape)
- Missing required fields (400)
- Wrong credentials (401)
- Wrong role (403)
- Duplicate/conflict (409)
- Full business flow end-to-end (register → login → create → update → delete)

**Target: 90%+ of all API endpoints covered.**

Tests run inside the container via `docker compose exec`. They connect to the real DB. No mocks for DB calls in API tests.

### Test File Naming
```
unit_tests/auth.spec.ts
unit_tests/billing.spec.ts
unit_tests/state-machine.spec.ts
API_tests/auth.api.spec.ts
API_tests/contracts.api.spec.ts
API_tests/security.api.spec.ts
```

---

## Phase 5 — Business Logic Standards

### Idempotency
Any operation that can be retried must be idempotent:
- Payment posting: unique transaction key, 10-minute dedup window
- Import/batch operations: idempotency key stored, duplicate returns original response
- Webhook callbacks: nonce + timestamp window

### State Machines
Every entity with a lifecycle must have an explicit state machine:
- Define all valid states as an enum
- Define all valid transitions
- Reject invalid transitions with 409
- Log every transition in the audit log

### Financial Calculations
- All money in integer cents — never floats
- Grace periods: check `days_overdue > grace_period` before applying fee
- Caps: `Math.min(calculated_fee, cap_amount)`
- Late fee formula: document it in `questions.md` and test the boundary (day 5 vs day 6)

### Audit Log
- Every significant business operation writes to audit log
- Fields: `actor_id`, `action`, `resource_type`, `resource_id`, `before`, `after`, `ip`, `trace_id`, `created_at`
- No DELETE or UPDATE on audit log table — INSERT only
- Sensitive fields masked as `[REDACTED]` in audit exports

---

## Phase 6 — Delivery Checklist

Before submitting, verify every item:

```
[ ] docker compose up --build completes with no errors
[ ] Cold start tested — no pre-existing volumes or containers
[ ] GET /health returns {"status":"ok"}
[ ] ./run_tests.sh passes all unit and API tests
[ ] API test coverage ≥ 90% of endpoints
[ ] No hardcoded responses — all endpoints read from real DB
[ ] No mock data in production code paths
[ ] README has: startup command, ports, test credentials, test command
[ ] All env vars inline in docker-compose.yml — no .env file
[ ] No node_modules/, dist/, .env, .venv/ in repo
[ ] No personal credentials or absolute paths in any file
[ ] questions.md has ≥ 10 entries with question/assumption/solution
[ ] docs/design.md covers architecture, modules, DB tables, security
[ ] docs/api-spec.md covers every endpoint
[ ] Audit log is append-only
[ ] Sensitive fields encrypted at rest and masked in responses
[ ] State machines reject invalid transitions with 409
[ ] Idempotency enforced on all retry-able operations
[ ] Background jobs wired to scheduler (not just defined as functions)
[ ] Swagger UI accessible at /api/docs
[ ] Session trajectory exported and saved to sessions/develop-1.json
[ ] metadata.json present with correct fields
[ ] prompt.md present and unmodified
```

---

## Common Mistakes to Avoid

| Mistake | Consequence | Fix |
|---|---|---|
| Using `.env` file in docker-compose | Fails on any machine without the file | Inline all env vars |
| Running tests on host machine | Tests pass locally, fail in CI | Always `docker compose exec` |
| Hardcoding `localhost` in API tests | Fails inside container | Use service name from docker-compose |
| Storing money as float | Rounding errors in financial calculations | Use integer cents |
| Forgetting to copy test files into Docker image | Tests can't run inside container | Add `COPY unit_tests/ API_tests/ run_tests.sh` to Dockerfile |
| Mock DB in API tests | Tests pass but real endpoints broken | API tests must hit real DB |
| Not wiring cron jobs | Jobs defined but never run | Register in server startup |
| Audit log with DELETE endpoint | Instant fail on security review | INSERT only, no delete route |
| Sensitive fields in logs | Security violation | Mask before logging |
| State machine without rejection | Invalid transitions silently accepted | Always validate and return 409 |

---

## Phase 7 — Acceptance Inspection (Post-Build)

This phase runs after the project is complete. Use this as the acceptance inspector prompt. Run it in a fresh session against the finished repo. Do not start Docker or run any commands — static analysis only.

---

### 7.1 Acceptance Inspector Prompt

Paste this into a fresh session with the finished project in context:

```
You are the "Delivery Acceptance / Project Architecture Review" inspector.

Conduct item-by-item verification of the project in the current working directory.
Output results strictly based on the acceptance criteria below.

Business Prompt: [paste the original task prompt here]

---

ACCEPTANCE CRITERIA:

1. MANDATORY THRESHOLDS

1.1 Can it actually run and be verified?
- Does it provide clear startup instructions?
- Can it start without modifying core code?
- Does the actual runtime result match the delivery description?

1.2 Does it severely deviate from the Prompt theme?
- Does the delivered content revolve around the business goal in the Prompt?
- Has the core problem definition been arbitrarily replaced, weakened, or ignored?

2. DELIVERY COMPLETENESS

2.1 Are all core requirements explicitly stated in the Prompt implemented?
- Every core functional point must be present.

2.2 Does it have a complete 0-to-1 delivery form?
- No mock/hardcode replacing real logic without explanation.
- Complete project structure, not scattered code or single-file examples.
- Basic project documentation (README or equivalent) provided.

3. ENGINEERING AND ARCHITECTURE QUALITY

3.1 Reasonable engineering structure and module division?
- Project structure clear, module responsibilities clear.
- No redundant/unnecessary files.
- No code stacking within a single file.

3.2 Basic maintainability and extensibility?
- No obviously chaotic high coupling.
- Core logic has basic room for extension, not completely hardcoded.

4. ENGINEERING DETAILS AND PROFESSIONALISM

4.1 Error handling, logging, validation, interface design?
- Error handling has basic reliability and user-friendliness.
- Logging assists problem localization — not arbitrary printing or complete absence.
- Necessary validations at key inputs and boundary conditions.

4.2 Real product/service form, not demo-level?
- Appears as a real application, not a teaching example.

5. PROMPT REQUIREMENT UNDERSTANDING AND FITNESS

5.1 Accurately understands business goals, usage scenarios, and implicit constraints?
- Core business goal accurately achieved.
- No implementations that clearly misunderstand semantic requirements.
- Key constraint conditions not arbitrarily changed or ignored.

6. AESTHETICS (full-stack and frontend only)

6.1 Visuals and interaction appropriate for the scenario?
- Different functional areas have clear visual distinction.
- Layout reasonable, alignment and spacing consistent.
- Interface elements render normally.
- Basic interactive feedback (hover, click, transitions).
- Fonts, sizes, colors, icon styles basically uniform.

---

HARD RULES FOR YOUR INSPECTION:

RULE 1 — Item-by-Item Output
Create a plan checklist with all major acceptance items. Execute in order. After completing all items, write the full report to ./.tmp/delivery-acceptance-report.md.

RULE 2 — No Omissions
Cover all secondary and tertiary entries under each major item. If not applicable, clearly mark "Not Applicable" with reason.

RULE 3 — Traceable Evidence
All key conclusions must provide locatable evidence (file path + line number, e.g., README.md:10, src/auth.ts:42). No reasoning solely on inference.

RULE 4 — Runnable First
If the project can be started/run/tested, verify according to instructions. If blocked by environment restrictions (ports, Docker/socket, network, system permissions, read-only filesystem), clearly state the blocking point, provide commands the user can reproduce locally, and state the boundary of what is currently confirmable vs unconfirmable. Environment restriction blocks are NOT project issues and must NOT be factored into defect classification.

RULE 5 — Theoretical Basis
Every judgment of "reasonable/unreasonable/pass/fail" must explain the basis and reasoning chain aligned with the acceptance criteria, common engineering practices, or runtime results.

RULE 6 — Payment Mock Exception
Payment capabilities implemented using mock/stub/fake are NOT issues when the topic does not require real third-party integration. However, explain the mock scope, activation conditions, and any risk of accidental deployment in production.

RULE 7 — Security Priority
During acceptance, prioritize authentication, authorization, and privilege escalation security issues over general coding style issues. Check in this order:
1. Authentication entry points
2. Route-level authorization
3. Object-level authorization (resource ownership verification — not just ID-based read/write)
4. Feature-level authorization
5. Tenant/user data isolation
6. Protection of admin/debug interfaces
Provide evidence and judgment basis for each.

RULE 8 — Test and Log Audit
Unit tests, API interface functional tests, and log printing categorization must be checked and judged. State their existence, executability, whether coverage satisfies core flows and basic exception paths, whether log categorization is clear, and whether there is a risk of sensitive information leakage.

RULE 9 — Static Test Coverage Audit (MANDATORY)
This section is required in every report. Do not skip it.

Step A: Extract core requirement points and implicit constraints from the Prompt, forming a "Requirement Checklist." Include: auth/authorization/data isolation/boundary conditions/error handling/idempotency/pagination/concurrency/data consistency.

Step B: Locate test files and cases one by one. Establish a mapping:
  Requirement Point → Corresponding Test Case/Assertion

Step C: For each requirement point, provide a coverage judgment:
  Sufficient / Basic Coverage / Insufficient / Missing / Not Applicable / Unconfirmed
  Explain the judgment basis. Provide traceable evidence (test file path+line, code under test path+line, key assertion/fixture/mock location).

Step D: Coverage minimum baseline — check item-by-item:
- Core business happy paths: at least one end-to-end or multi-step chained test for key flows
- Core exception paths: input validation failure, 401, 403, 404, 409/duplicate, selected based on project
- Security: authentication entry points, route-level authorization, object-level authorization, data isolation
- Key boundaries: pagination/sorting/filtering, empty data, extreme values, time fields, concurrent/repetitive requests, transactions/rollback

Step E: Logs and sensitive info — do tests or code expose tokens/passwords/keys to logs/responses? (Judge statically.)

Step F: Mock/stub handling — explain mock scope, activation conditions, and risk of accidental deployment in production.

Step G: Output a section titled "Test Coverage Assessment (Static Audit)" in the report with:
- Test overview (existence, framework, README commands)
- Coverage mapping table (Requirement Point | Test Case file:line | Key Assertion file:line | Coverage Judgment | Gap | Minimal Addition Suggestion)
- Security coverage audit (authentication, route authorization, object-level authorization, data isolation — conclusion + reproduction idea for each)
- Overall judgment: Pass / Partially Pass / Fail / Unconfirmed
- Judgment boundary: which key risks are covered, which lack of coverage would allow tests to pass while severe defects still exist

---

OUTPUT FORMAT:

For each secondary/tertiary entry under each major item:
- Conclusion: Pass / Partially Pass / Fail / Not Applicable / Unconfirmed
- Reason: theoretical basis
- Evidence: path:line
- Reproducible Verification: command/steps/expected result

Issues must be prioritized: Blocking / High / Medium / Low
Each issue must have evidence, impact description, and a minimal actionable improvement suggestion.

Do NOT report sandbox environment permission issues as project problems.
Do NOT report payment mock (when compliant with topic/docs) as a project problem.
Security issues (missing auth, authorization bypass, object-level auth failure, data isolation failure) must be reported with priority and a reproduction path or minimal verification steps.

Final report must be written to: ./.tmp/delivery-acceptance-report.md

DO NOT start Docker or run any Docker commands.
```

---

### 7.2 What the Acceptance Report Must Cover

The report written to `.tmp/delivery-acceptance-report.md` must include all of these sections:

```
1. Runnable Verification
   - Startup instructions present and accurate
   - Cold start feasibility
   - Health endpoint confirmed

2. Delivery Completeness
   - Core requirements mapped to implementation (file:line evidence)
   - No hardcoded/mock responses in production paths
   - Complete project structure present

3. Engineering and Architecture Quality
   - Module separation
   - No single-file code dumps
   - Layered architecture (routes → controllers → services → DB)

4. Engineering Details and Professionalism
   - Error handling (structured JSON errors, no stack traces)
   - Logging (structured, with trace IDs, no sensitive data)
   - Input validation on all write endpoints

5. Prompt Requirement Understanding
   - Business goal achieved
   - No silent simplifications
   - Implicit constraints respected

6. Security Review (Priority)
   - Authentication entry points
   - Route-level authorization
   - Object-level authorization
   - Data isolation
   - Admin/debug interface protection

7. Test Coverage Assessment (Static Audit)
   - Test overview
   - Coverage mapping table
   - Security coverage audit
   - Overall judgment with boundary statement

8. Prioritized Issue List
   - Blocking issues (instant fail)
   - High issues
   - Medium issues
   - Low issues
   Each with: evidence, impact, minimal fix suggestion

9. Aesthetics (if applicable)
```

---

### 7.3 Issue Severity Definitions

| Severity | Definition | Example |
|---|---|---|
| Blocking | Instant fail — submission rejected | Docker fails to start, hardcoded responses, missing core feature |
| High | Severe defect — likely repair required | Auth bypass, missing 403 on protected route, object-level auth missing |
| Medium | Quality issue — affects score | Missing test coverage, log exposes sensitive data, no idempotency |
| Low | Minor issue — noted but not blocking | Inconsistent naming, missing Swagger docs, minor UI misalignment |

---

### 7.4 Acceptance vs Repair Flow

```
Build complete
    ↓
Run acceptance inspector (fresh session, static analysis)
    ↓
Report written to .tmp/delivery-acceptance-report.md
    ↓
Blocking issues? → Fix immediately → Re-run acceptance
    ↓
High issues? → Fix in repair round (max 3 rounds)
    ↓
Medium/Low issues? → Fix if time allows, document if not
    ↓
All Blocking and High resolved → Submit
```

Maximum 3 repair rounds. If Blocking issues remain after 3 rounds, the task is not settled.
