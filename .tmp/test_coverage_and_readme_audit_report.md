# Test Coverage Audit

## Scope and Project Type
- Method: static inspection only (no execution).
- README exists at `repo/README.md`.
- Project type declaration at top: `Project type: fullstack` present (`repo/README.md:3`).
- Light inspection confirms fullstack structure: backend routes (`repo/route/app.php`) + web frontend modules (`repo/public/static/js/`).

## Backend Endpoint Inventory
Source: `repo/route/app.php:6-89`

Total endpoints: **52**

`GET /health`, `GET /`, `GET /auth/captcha`, `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/mfa/enroll`, `POST /auth/mfa/verify`, `POST /admin/users`, `GET /entities/field-definitions`, `POST /entities/:id/merge`, `GET /entities/:id`, `PATCH /entities/:id`, `GET /entities`, `POST /entities`, `POST /admin/verifications/:id/approve`, `POST /admin/verifications/:id/reject`, `GET /verifications/mine`, `POST /verifications`, `GET /verifications`, `GET /contracts/:id`, `GET /contracts`, `POST /contracts`, `GET /invoices/:id/receipt`, `GET /invoices/:id`, `GET /invoices`, `POST /payments`, `POST /refunds`, `GET /exports/ledger`, `GET /exports/reconciliation`, `GET /conversations/:id/messages`, `GET /conversations`, `POST /conversations`, `POST /messages/preflight-risk`, `POST /messages`, `PATCH /messages/:id/recall`, `POST /messages/:id/report`, `GET /attachments/:id`, `GET /admin/risk-keywords`, `POST /admin/risk-keywords`, `PATCH /admin/risk-keywords/:id`, `DELETE /admin/risk-keywords/:id`, `GET /audit-logs`, `POST /delegations/:id/approve`, `GET /delegations`, `POST /delegations`, `GET /api/docs`, `GET /admin/jobs`, `POST /admin/jobs/run`, `GET /admin/config`, `PATCH /admin/config/:key`.

## API Test Mapping Table

| Endpoint | Covered | Test type | Test file(s) | Evidence |
|---|---|---|---|---|
| `GET /health` | yes | true no-mock HTTP | `tests/unit/HealthEndpointTest.php` | `testHealthReturnsOkStatus` (`repo/tests/unit/HealthEndpointTest.php:24`) |
| `GET /` | yes | true no-mock HTTP | `tests/api/RootAndVerificationListTest.php` | `testRootRedirectsToStaticIndex` (`repo/tests/api/RootAndVerificationListTest.php:32`) |
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
| `GET /verifications` | yes | true no-mock HTTP | `tests/api/RootAndVerificationListTest.php` | `testAdminCanListVerifications` (`repo/tests/api/RootAndVerificationListTest.php:60`) |
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
| `POST /conversations` | yes | true no-mock HTTP | `tests/api/DelegationUiFrontendTest.php` | `testConversationPanelWiring` (`repo/tests/api/DelegationUiFrontendTest.php:58`) |
| `POST /messages/preflight-risk` | yes | true no-mock HTTP | `tests/api/PreflightRiskTest.php` | `testRequiresAuth` (`repo/tests/api/PreflightRiskTest.php:42`) |
| `POST /messages` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `testCleanMessageAllowed` (`repo/tests/unit/RiskDetectionTest.php:29`) |
| `PATCH /messages/:id/recall` | yes | true no-mock HTTP | `tests/unit/RiskDetectionTest.php` | `testRecallWithinWindow` (`repo/tests/unit/RiskDetectionTest.php:62`) |
| `POST /messages/:id/report` | yes | true no-mock HTTP | `tests/api/MessageReportAuthTest.php` | `testSameScopeUserCanReportMessage` (`repo/tests/api/MessageReportAuthTest.php:39`) |
| `GET /attachments/:id` | yes | true no-mock HTTP | `tests/api/AttachmentRetrievalTest.php` | `testNonExistentAttachmentReturns404` (`repo/tests/api/AttachmentRetrievalTest.php:42`) |
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

1. **True No-Mock HTTP**
- API/HTTP behavior is exercised through real request calls (`curl_init` + real paths), e.g. `repo/tests/api/AuthFlowTest.php:196`, `repo/tests/api/RootAndVerificationListTest.php:36`.
- Endpoint coverage class: 52/52 endpoints are covered by this category.

2. **HTTP with Mocking**
- None detected for API route execution paths.

3. **Non-HTTP (unit/integration without HTTP)**
- Direct service/file tests exist (examples): `repo/tests/unit/LateFeeTest.php`, `repo/tests/unit/EncryptionKeyGuardTest.php`, `repo/tests/unit/LogLeakageTest.php`.
- Frontend JS unit tests run without live HTTP transport (Vitest + happy-dom): `repo/tests/frontend/*.test.js`.

## Mock Detection
- Scan patterns `jest.mock`, `vi.mock`, `sinon.stub`, PHPUnit mock builders: not found in route-level API tests.
- API-route execution-path mocking evidence: none.
- Direct DB seed/setup usage exists (not mocks): `repo/tests/AdminBootstrap.php:45`, `repo/tests/api/PrivilegeEscalationTest.php:130`.

## Coverage Summary
- Total endpoints: **52**
- Endpoints with HTTP tests: **52**
- Endpoints with true no-mock tests: **52**
- HTTP coverage: **100%**
- True API coverage: **100%**

## Unit Test Summary

### Backend Unit Tests
- Unit files present in `repo/tests/unit/`: **14**.
- Covered modules include controllers via HTTP (`Auth`, `Admin`, `Verification`, `Invoice`, `Contract`, `Payment`, `Message`, `Health`) and direct services (`EncryptionService`, `LateFeeService`, `LogService`).
- Repositories are not explicitly unit-tested as a separate layer (no dedicated repository tests found).
- Important backend modules without clear direct unit-level isolation still include: `AuthService`, `CaptchaService`, `ContractService`, `DelegationService`, `DuplicateService`, `EntityService`, `ExportService`, `InvoiceService`, `JobService`, `MessagingService`, `MfaService`, `PaymentService`, `RefundService`, `RiskService`, `ScopeService`, `TokenService`, `VerificationService` (many are integration-tested via HTTP instead).

### Frontend Unit Tests (STRICT REQUIREMENT)
- Frontend unit files detected:
  - `repo/tests/frontend/api-client.test.js`
  - `repo/tests/frontend/app.test.js`
  - `repo/tests/frontend/auth.test.js`
  - `repo/tests/frontend/entities.test.js`
  - `repo/tests/frontend/finance.test.js`
  - `repo/tests/frontend/messaging.test.js`
  - `repo/tests/frontend/admin.test.js`
- Framework/tooling detected: Vitest + happy-dom (`repo/package.json:7-13`, `repo/vitest.config.js:1-9`).
- Tests execute real frontend modules from `public/static/js` via loader (`repo/tests/frontend/loadModule.js:35`) and per-module calls (`repo/tests/frontend/api-client.test.js:34`, `repo/tests/frontend/app.test.js:40`, `repo/tests/frontend/auth.test.js:45`, `repo/tests/frontend/entities.test.js:55`, `repo/tests/frontend/finance.test.js:45`, `repo/tests/frontend/messaging.test.js:52`, `repo/tests/frontend/admin.test.js:37`).
- Components/modules covered: `api-client.js`, `app.js`, `auth.js`, `entities.js`, `finance.js`, `messaging.js`, `admin.js`.
- Important frontend components/modules not tested: no major untested JS module under `repo/public/static/js/`.

**Frontend unit tests: PRESENT**

### Cross-Layer Observation
- Backend API coverage is full at route level.
- Frontend now has explicit JS unit tests for all seven core modules.
- Balance is improved vs prior backend-heavy state.

## API Observability Check
- Endpoint + input + response assertions are explicit in most API tests (e.g. `repo/tests/api/AuthFlowTest.php`, `repo/tests/api/VerificationUserFlowTest.php`, `repo/tests/api/RootAndVerificationListTest.php`).
- Some tests remain status-centric on negative paths, but overall observability is strong.

## Tests Check
- `run_tests.sh` remains Docker-based orchestration (`repo/run_tests.sh:20-61`) -> acceptable for backend tests.
- Frontend unit tests are documented/run via Node tooling (`repo/package.json`, `repo/tests/frontend/*.test.js`).

## End-to-End Expectations
- No browser-level FE↔BE E2E suite is visible.
- This is partially compensated by full API-route tests + frontend unit coverage, but not equivalent to full user-journey E2E.

## Test Coverage Score (0-100)
- **92/100**

## Score Rationale
- Full route coverage with true HTTP transport and no API mocking detected.
- Frontend unit tests now satisfy strict presence requirements and cover all frontend JS modules.
- Remaining deduction: no full browser E2E layer and some endpoint checks are shallow on payload semantics.

## Key Gaps
- No dedicated browser E2E tests for fullstack FE↔BE user journeys.
- Several service classes still rely mostly on HTTP/integration tests instead of isolated unit tests.

## Confidence & Assumptions
- Confidence: high for static presence/coverage mapping; medium for runtime sufficiency because tests were not executed.
- Assumption: dynamic path calls and query variants are valid evidence for parameterized route coverage.

---

# README Audit

## High Priority Issues
- README introduces host-side frontend test install/run instructions using `npm install` (`repo/README.md:28` and `repo/README.md:194`), which violates strict Docker-contained environment rule in this audit policy.

## Medium Priority Issues
- Quick start now mixes Docker-first backend flow with host-side Node workflow, creating operational inconsistency (`repo/README.md:24-29`).

## Low Priority Issues
- None significant beyond consistency issue above.

## Hard Gate Failures
- **Environment Rules (STRICT): FAIL**
  - Forbidden runtime install command appears: `npm install` (`repo/README.md:28`, `repo/README.md:194`).

## Hard Gate Checks (Other)
- Formatting/readability: PASS.
- Startup instruction includes required literal `docker-compose up`: PASS (`repo/README.md:18`).
- Access method (URL+port): PASS (`repo/README.md:48-54`).
- Verification method: PASS (`repo/README.md:58-89`, `repo/README.md:119-142`).
- Demo credentials with auth roles: PASS (`repo/README.md:150-155`).
- Project type declaration at top: PASS (`repo/README.md:3`).

## README Verdict
- **FAIL** (hard gate failure present).

---

## Final Verdicts
- **Test Coverage Audit:** PASS (with noted non-blocking gaps).
- **README Audit:** FAIL.
