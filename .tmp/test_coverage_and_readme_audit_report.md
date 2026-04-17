# Test Coverage Audit

## Scope, Method, and Project Type
- Audit mode: static inspection only (no test execution, no runtime validation).
- Repository inspected: `repo/`.
- README location check: `repo/README.md` exists.
- Project type declaration at README top: **missing** (required keyword not present in opening section).
- Inferred project type (light inspection): **fullstack** (ThinkPHP API + static Layui web UI), evidenced in `repo/README.md:5` and `repo/README.md:33`.

## Backend Endpoint Inventory
Source of truth: `repo/route/app.php:6` through `repo/route/app.php:89`.

Total endpoints discovered: **52** (unique `METHOD + PATH`).

1. `GET /health`
2. `GET /`
3. `GET /auth/captcha`
4. `POST /auth/register`
5. `POST /auth/login`
6. `POST /auth/logout`
7. `GET /auth/me`
8. `POST /auth/mfa/enroll`
9. `POST /auth/mfa/verify`
10. `POST /admin/users`
11. `GET /entities/field-definitions`
12. `POST /entities/:id/merge`
13. `GET /entities/:id`
14. `PATCH /entities/:id`
15. `GET /entities`
16. `POST /entities`
17. `POST /admin/verifications/:id/approve`
18. `POST /admin/verifications/:id/reject`
19. `GET /verifications/mine`
20. `POST /verifications`
21. `GET /verifications`
22. `GET /contracts/:id`
23. `GET /contracts`
24. `POST /contracts`
25. `GET /invoices/:id/receipt`
26. `GET /invoices/:id`
27. `GET /invoices`
28. `POST /payments`
29. `POST /refunds`
30. `GET /exports/ledger`
31. `GET /exports/reconciliation`
32. `GET /conversations/:id/messages`
33. `GET /conversations`
34. `POST /conversations`
35. `POST /messages/preflight-risk`
36. `POST /messages`
37. `PATCH /messages/:id/recall`
38. `POST /messages/:id/report`
39. `GET /attachments/:id`
40. `GET /admin/risk-keywords`
41. `POST /admin/risk-keywords`
42. `PATCH /admin/risk-keywords/:id`
43. `DELETE /admin/risk-keywords/:id`
44. `GET /audit-logs`
45. `POST /delegations/:id/approve`
46. `GET /delegations`
47. `POST /delegations`
48. `GET /api/docs`
49. `GET /admin/jobs`
50. `POST /admin/jobs/run`
51. `GET /admin/config`
52. `PATCH /admin/config/:key`

## API Test Mapping Table

| Endpoint | Covered | Test Type | Test Files | Evidence |
|---|---|---|---|---|
| `GET /health` | yes | true no-mock HTTP | `tests/unit/HealthEndpointTest.php` | `testHealthReturnsOkStatus` (`repo/tests/unit/HealthEndpointTest.php:24`) |
| `GET /` | no | none | none | no matching request found in `tests/` |
| `GET /auth/captcha` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testCaptchaEndpointReturnsChallenge` (`repo/tests/api/EndpointCoverageTest.php:272`) |
| `POST /auth/register` | yes | true no-mock HTTP | `tests/api/AuthFlowTest.php` | `testCompleteAuthFlow` (`repo/tests/api/AuthFlowTest.php:24`) |
| `POST /auth/login` | yes | true no-mock HTTP | `tests/api/AuthFlowTest.php` | `testCompleteAuthFlow` (`repo/tests/api/AuthFlowTest.php:24`) |
| `POST /auth/logout` | yes | true no-mock HTTP | `tests/api/AuthFlowTest.php` | `testCompleteAuthFlow` (`repo/tests/api/AuthFlowTest.php:24`) |
| `GET /auth/me` | yes | true no-mock HTTP | `tests/api/AuthFlowTest.php` | `testCompleteAuthFlow` (`repo/tests/api/AuthFlowTest.php:24`) |
| `POST /auth/mfa/enroll` | yes | true no-mock HTTP | `tests/api/AuthMfaTest.php` | `testAdminCanEnrollMfa` (`repo/tests/api/AuthMfaTest.php:27`) |
| `POST /auth/mfa/verify` | yes | true no-mock HTTP | `tests/api/AuthMfaTest.php` | `testNonAdminDeniedMfaVerify` (`repo/tests/api/AuthMfaTest.php:52`) |
| `POST /admin/users` | yes | true no-mock HTTP | `tests/api/PrivilegeEscalationTest.php` | `testAdminCanCreateSystemAdminViaAdminUsers` (`repo/tests/api/PrivilegeEscalationTest.php:73`) |
| `GET /entities/field-definitions` | yes | true no-mock HTTP | `tests/api/ExtraFieldDynamicTest.php` | `testFieldDefinitionsEndpoint` (`repo/tests/api/ExtraFieldDynamicTest.php:28`) |
| `POST /entities/:id/merge` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testMergeEntitiesHappyPath` (`repo/tests/api/EndpointCoverageTest.php:30`) |
| `GET /entities/:id` | yes | true no-mock HTTP | `tests/api/EntityCrudTest.php` | `testGetEntityById` (`repo/tests/api/EntityCrudTest.php:56`) |
| `PATCH /entities/:id` | yes | true no-mock HTTP | `tests/api/EntityCrudTest.php` | `testUpdateEntity` (`repo/tests/api/EntityCrudTest.php:109`) |
| `GET /entities` | yes | true no-mock HTTP | `tests/api/EntityCrudTest.php` | `testListEntities` (`repo/tests/api/EntityCrudTest.php:41`) |
| `POST /entities` | yes | true no-mock HTTP | `tests/api/EntityCrudTest.php` | `testCreateEntityHappyPath` (`repo/tests/api/EntityCrudTest.php:28`) |
| `POST /admin/verifications/:id/approve` | yes | true no-mock HTTP | `tests/api/VerificationUserFlowTest.php` | `testSubmitAndApproveFlow` (`repo/tests/api/VerificationUserFlowTest.php:33`) |
| `POST /admin/verifications/:id/reject` | yes | true no-mock HTTP | `tests/api/VerificationUserFlowTest.php` | `testSubmitAndRejectShowsReason` (`repo/tests/api/VerificationUserFlowTest.php:69`) |
| `GET /verifications/mine` | yes | true no-mock HTTP | `tests/api/VerificationUserFlowTest.php` | `testSubmitAndApproveFlow` (`repo/tests/api/VerificationUserFlowTest.php:33`) |
| `POST /verifications` | yes | true no-mock HTTP | `tests/api/VerificationUserFlowTest.php` | `testSubmitAndApproveFlow` (`repo/tests/api/VerificationUserFlowTest.php:33`) |
| `GET /verifications` | no | none | none | no matching `GET /verifications` request found in `tests/` |
| `GET /contracts/:id` | yes | true no-mock HTTP | `tests/unit/InvoiceStateMachineTest.php` | `testContractDetailHasInvoices` (`repo/tests/unit/InvoiceStateMachineTest.php:49`) |
| `GET /contracts` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testContractListHappyPath` (`repo/tests/api/EndpointCoverageTest.php:235`) |
| `POST /contracts` | yes | true no-mock HTTP | `tests/unit/InvoiceStateMachineTest.php` | `testScheduleGenerationMonthly` (`repo/tests/unit/InvoiceStateMachineTest.php:25`) |
| `GET /invoices/:id/receipt` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testReceiptHappyPath` (`repo/tests/api/EndpointCoverageTest.php:72`) |
| `GET /invoices/:id` | yes | true no-mock HTTP | `tests/unit/InvoiceStateMachineTest.php` | `testInvoiceDetailHasSnapshot` (`repo/tests/unit/InvoiceStateMachineTest.php:82`) |
| `GET /invoices` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testInvoiceListHappyPath` (`repo/tests/api/EndpointCoverageTest.php:54`) |
| `POST /payments` | yes | true no-mock HTTP | `tests/api/PaymentIdempotencyTest.php` | `testMissingIdempotencyKeyReturns400` (`repo/tests/api/PaymentIdempotencyTest.php:77`) |
| `POST /refunds` | yes | true no-mock HTTP | `tests/api/RefundAuthorizationTest.php` | `testSystemAdminCanRefund` (`repo/tests/api/RefundAuthorizationTest.php:45`) |
| `GET /exports/ledger` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testExportLedgerReturnsCsv` (`repo/tests/api/EndpointCoverageTest.php:87`) |
| `GET /exports/reconciliation` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testExportReconciliationReturnsCsv` (`repo/tests/api/EndpointCoverageTest.php:104`) |
| `GET /conversations/:id/messages` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testConversationMessagesHappyPath` (`repo/tests/api/EndpointCoverageTest.php:121`) |
| `GET /conversations` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testConversationListHappyPath` (`repo/tests/api/EndpointCoverageTest.php:254`) |
| `POST /conversations` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `setUp` (`repo/tests/unit/RiskDetectionTest.php:17`) |
| `POST /messages/preflight-risk` | yes | true no-mock HTTP | `tests/api/PreflightRiskTest.php` | `testRequiresAuth` (`repo/tests/api/PreflightRiskTest.php:42`) |
| `POST /messages` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `testCleanMessageAllowed` (`repo/tests/unit/RiskDetectionTest.php:29`) |
| `PATCH /messages/:id/recall` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `testRecallWithinWindow` (`repo/tests/unit/RiskDetectionTest.php:62`) |
| `POST /messages/:id/report` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `testReportMessage` (`repo/tests/unit/RiskDetectionTest.php:84`) |
| `GET /attachments/:id` | yes | true no-mock HTTP | `tests/api/AttachmentRetrievalTest.php` | `testDownloadAttachmentOwnerCanAccess` (`repo/tests/api/AttachmentRetrievalTest.php:75`) |
| `GET /admin/risk-keywords` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testListRiskKeywords` (`repo/tests/api/EndpointCoverageTest.php:155`) |
| `POST /admin/risk-keywords` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testCreateRiskKeyword` (`repo/tests/api/EndpointCoverageTest.php:171`) |
| `PATCH /admin/risk-keywords/:id` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testUpdateRiskKeyword` (`repo/tests/api/EndpointCoverageTest.php:182`) |
| `DELETE /admin/risk-keywords/:id` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testDeleteRiskKeyword` (`repo/tests/api/EndpointCoverageTest.php:196`) |
| `GET /audit-logs` | yes | true no-mock HTTP | `tests/api/AuditIntegrationTest.php` | `testRefundWritesAuditLog` (`repo/tests/api/AuditIntegrationTest.php:136`) |
| `POST /delegations/:id/approve` | yes | true no-mock HTTP | `tests/api/DelegationWorkflowTest.php` | `testCreateAndApproveDelegationHappyPath` (`repo/tests/api/DelegationWorkflowTest.php:29`) |
| `GET /delegations` | yes | true no-mock HTTP | `tests/api/DelegationWorkflowTest.php` | `testAdminCanListDelegations` (`repo/tests/api/DelegationWorkflowTest.php:149`) |
| `POST /delegations` | yes | true no-mock HTTP | `tests/api/DelegationWorkflowTest.php` | `testCreateAndApproveDelegationHappyPath` (`repo/tests/api/DelegationWorkflowTest.php:29`) |
| `GET /api/docs` | yes | true no-mock HTTP | `tests/unit/JobsTest.php` | `testApiDocsEndpoint` (`repo/tests/unit/JobsTest.php:24`) |
| `GET /admin/jobs` | yes | true no-mock HTTP | `tests/unit/JobsTest.php` | `testAdminCanListJobs` (`repo/tests/unit/JobsTest.php:36`) |
| `POST /admin/jobs/run` | yes | true no-mock HTTP | `tests/unit/JobsTest.php` | `testAdminCanRunJobs` (`repo/tests/unit/JobsTest.php:49`) |
| `GET /admin/config` | yes | true no-mock HTTP | `tests/unit/JobsTest.php` | `testAdminCanAccessConfig` (`repo/tests/unit/JobsTest.php:63`) |
| `PATCH /admin/config/:key` | yes | true no-mock HTTP | `tests/api/EndpointCoverageTest.php` | `testUpdateAdminConfig` (`repo/tests/api/EndpointCoverageTest.php:210`) |

## API Test Classification

### 1) True No-Mock HTTP
- Evidence of real HTTP layer: repeated `curl_init($this->baseUrl . ...)` in API tests, e.g. `repo/tests/api/AuthFlowTest.php:196`, `repo/tests/api/EndpointCoverageTest.php:304`.
- Test files in this class: all `tests/api/*.php` (36 files), plus several `tests/unit/*.php` that still hit HTTP endpoints (e.g. `repo/tests/unit/JobsTest.php`, `repo/tests/unit/VerificationTest.php`, `repo/tests/unit/InvoiceStateMachineTest.php`).

### 2) HTTP with Mocking
- **None found** by static scan.

### 3) Non-HTTP (unit/integration without HTTP)
- `repo/tests/unit/LateFeeTest.php` (direct `LateFeeService::calculate` calls).
- `repo/tests/unit/EncryptionKeyGuardTest.php` (direct `EncryptionService` calls).
- `repo/tests/unit/LogLeakageTest.php` (direct `LogService::info` and log file inspection).

## Mock Detection
- `jest.mock`, `vi.mock`, `sinon.stub`: **not found** (scan across `repo/tests/**/*.php`).
- PHPUnit mocking primitives (`createMock`, `createStub`, `getMockBuilder`, `expects`): **not found**.
- DI override patterns in tests: **not found**.
- Direct controller invocation in tests: **not found**.
- Note: some tests seed data directly via PDO (`repo/tests/AdminBootstrap.php:45`, `repo/tests/api/PrivilegeEscalationTest.php:130`), but this is setup/seeding, not transport or service mocking.

## Coverage Summary
- Total endpoints: **52**.
- Endpoints with HTTP tests: **50**.
- Endpoints with true no-mock HTTP tests: **50**.
- Uncovered endpoints: `GET /`, `GET /verifications`.
- HTTP coverage: **96.2%** (`50/52`).
- True API coverage: **96.2%** (`50/52`).

## Unit Test Summary

### Backend Unit Tests
- Unit test files detected: 14 files under `repo/tests/unit/`.
- Controllers covered (via HTTP): Auth, Verification, Admin, Contract, Invoice, Payment, Message, Audit, Health (examples: `repo/tests/unit/VerificationTest.php`, `repo/tests/unit/JobsTest.php`, `repo/tests/unit/HealthEndpointTest.php`).
- Services covered directly: `EncryptionService`, `LateFeeService`, `LogService` (evidence: `repo/tests/unit/EncryptionKeyGuardTest.php:7`, `repo/tests/unit/LateFeeTest.php:7`, `repo/tests/unit/LogLeakageTest.php:7`).
- Auth/guards/middleware covered: lockout/auth (`repo/tests/unit/LockoutTest.php`), trace-id middleware behavior (`repo/tests/unit/TraceIdTest.php`), auth gate via admin endpoints (`repo/tests/unit/SecurityTest.php:45`).
- Repositories explicitly tested: no dedicated repository-layer tests found (no repository test files and no repository namespace imports in unit tests).
- Important backend modules not directly unit-tested (service-level direct tests absent): `AuthService`, `CaptchaService`, `ContractService`, `DelegationService`, `DuplicateService`, `EntityService`, `ExportService`, `InvoiceService`, `JobService`, `MessagingService`, `MfaService`, `PaymentService`, `RefundService`, `RiskService`, `ScopeService`, `TokenService`, `VerificationService` (these are exercised mostly through HTTP/integration, not isolated unit tests).

### Frontend Unit Tests (STRICT REQUIREMENT)
- Frontend test files with JS/TS unit naming (`*.test.*`, `*.spec.*`): **NONE** (scan: `repo/**/*.test.{js,jsx,ts,tsx}`, `repo/**/*.spec.{js,jsx,ts,tsx}`).
- Framework/tool evidence for frontend unit testing (Jest/Vitest/RTL/etc): **NONE** in frontend test files (because files are absent).
- Frontend components/modules covered by frontend unit tests: **NONE**.
- Important frontend components/modules not unit-tested: `public/static/js/api-client.js`, `public/static/js/app.js`, `public/static/js/auth.js`, `public/static/js/entities.js`, `public/static/js/finance.js`, `public/static/js/messaging.js`, `public/static/js/admin.js`, plus pages `public/static/index.html`, `public/static/login.html`, `public/static/register.html`.
- Existing frontend-related tests are HTTP/static asset assertions in PHP (examples: `repo/tests/api/FrontendIntegrationTest.php`, `repo/tests/api/FrontendModuleCoverageTest.php`), not frontend unit tests.

**Frontend unit tests: MISSING**

**CRITICAL GAP** (fullstack project + missing frontend unit tests under strict detection rules).

### Cross-Layer Observation
- Backend/API tests are extensive and mostly route-level.
- Frontend testing is backend-driven static-content verification, with no JS unit/component test suite.
- Result: **backend-heavy test strategy with frontend unit-testing absence**.

## API Observability Check
- Strong observability in many API tests: explicit method/path/input/expected response fields (e.g. `repo/tests/api/AuthFlowTest.php:24`, `repo/tests/api/VerificationUserFlowTest.php:33`).
- Weak spots: some tests assert mainly status code with limited payload semantics (e.g. several denial-path tests in `repo/tests/api/EndpointCoverageTest.php` and `repo/tests/api/DelegationWorkflowTest.php`).
- Overall observability rating: **moderate-strong**, with localized weak assertions.

## Tests Check
- `run_tests.sh` is Docker-based orchestrated execution (`docker compose ...`) and runs tests inside container (`repo/run_tests.sh:20`, `repo/run_tests.sh:54`, `repo/run_tests.sh:60`) -> **OK**.
- No host-side package manager installs required by script -> **OK**.

## End-to-End Expectations (Fullstack)
- Expected: real FE↔BE end-to-end tests.
- Found: no browser-level E2E flow tests; only API HTTP tests and static frontend asset/content checks.
- Compensation: strong API HTTP coverage partially compensates, but does not replace FE↔BE runtime interaction coverage.

## Test Coverage Score (0-100)
- **78 / 100**

## Score Rationale
- High route-level HTTP coverage with real HTTP transport and no mocking patterns detected.
- Two uncovered backend endpoints (`GET /`, `GET /verifications`).
- Missing frontend unit tests in a fullstack project is a strict critical gap.
- Limited true end-to-end frontend interaction testing reduces confidence in integrated user flows.

## Key Gaps
- Uncovered endpoints: `GET /`, `GET /verifications`.
- Frontend unit test suite absent (strict failure for fullstack/web).
- No browser-driven FE↔BE E2E tests.

## Confidence & Assumptions
- Confidence: **high** for static coverage presence/absence; **medium** for behavioral sufficiency.
- Assumptions: query-string requests counted as coverage for same route path; no runtime execution was performed.

---

# README Audit

## Hard Gate Evaluation

### Formatting
- PASS: Markdown is structured and readable (`repo/README.md`).

### Startup Instructions (Backend/Fullstack)
- FAIL (strict string gate): required literal `docker-compose up` is not present.
- Found command is `docker compose up --build -d` (`repo/README.md:14`), which is operationally valid but does not satisfy the strict literal requirement.

### Access Method
- PASS: URL + port provided (`repo/README.md:42` to `repo/README.md:47`).

### Verification Method
- PASS: clear validation flows via UI walkthrough and API calls (examples: `repo/README.md:50` to `repo/README.md:82`, `repo/README.md:111` to `repo/README.md:134`).

### Environment Rules (Docker-contained, no runtime install steps)
- PASS: no `npm install`, `pip install`, `apt-get`, or manual DB setup steps documented; Docker-first guidance is explicit (`repo/README.md:23` to `repo/README.md:24`, `repo/README.md:91` to `repo/README.md:99`).

### Demo Credentials (Auth Present)
- PASS (conditional): auth exists and README provides role matrix with usernames/passwords for all roles (`repo/README.md:140` to `repo/README.md:147`).

## High Priority Issues
- README top does not declare project type with required keyword (`backend/fullstack/web/android/ios/desktop`) in opening section.
- Coverage claim states frontend coverage 100% (`repo/README.md:176`), but strict audit finds frontend unit tests missing.

## Medium Priority Issues
- Quick-start uses `open` command (`repo/README.md:17`), which is macOS-specific and not portable for Linux shells.
- Test credentials section mixes “no pre-seeded users” with sample matrix; operational bootstrap path exists but could be clearer about exactly what is pre-created vs user-created.

## Low Priority Issues
- README is very long and repeats some operational concepts (health/test behavior/bootstrapping), reducing signal density.

## Hard Gate Failures
- Missing strict required startup literal: `docker-compose up`.

## README Verdict
- **FAIL** (hard gate failure present).

## Final Verdicts
- Test Coverage Audit verdict: **PARTIAL PASS** (high backend HTTP coverage, but strict critical frontend unit-test gap + 2 uncovered endpoints).
- README Audit verdict: **FAIL**.
