# auditreport2 fix check (static)

Date: 2026-04-07  
Scope: static verification only (no Docker/test execution)

## Overall
- I checked the claimed fixes against the current repository state.
- Most claimed code changes are present.
- Runtime claims ("all tests pass") are **not confirmed** because tests were not executed in this check.

## Claim-by-claim check

1) Refund route restricted to admin  
- **Status:** Confirmed  
- **Evidence:** `repo/route/app.php:54` (`authCheck, system_admin`)

2) Refund service defense-in-depth role check  
- **Status:** Confirmed  
- **Evidence:** `repo/app/service/RefundService.php:13`

3) Canonical balance formula centralized in PaymentService + reused  
- **Status:** Confirmed  
- **Evidence:** `repo/app/service/PaymentService.php:177`, `repo/app/service/PaymentService.php:114`, `repo/app/service/RefundService.php:33`

4) Receipt endpoint now includes refunds + computed balance  
- **Status:** Confirmed  
- **Evidence:** `repo/app/service/PaymentService.php:167`, `repo/app/service/PaymentService.php:170`

5) Contract scope inherits profile scope (not actor scope)  
- **Status:** Confirmed  
- **Evidence:** `repo/app/service/ContractService.php:51`

6) Verification submit rejects empty evidence payload  
- **Status:** Confirmed  
- **Evidence:** `repo/app/service/VerificationService.php:32`

7) Delegation UI added (nav + page + JS handlers)  
- **Status:** Confirmed  
- **Evidence:** `repo/public/static/index.html:87`, `repo/public/static/index.html:532`, `repo/public/static/js/admin.js:54`, `repo/public/static/js/app.js:66`

8) Receipt print UI added (invoice action + print flow)  
- **Status:** Confirmed  
- **Evidence:** `repo/public/static/index.html:414`, `repo/public/static/js/finance.js:74`, `repo/public/static/js/finance.js:107`, `repo/public/static/js/finance.js:170`

9) Client-side verification evidence validation added  
- **Status:** Confirmed  
- **Evidence:** `repo/public/static/js/entities.js:488`

10) Print CSS added  
- **Status:** Confirmed  
- **Evidence:** `repo/public/static/css/app.css:83`

11) New tests added (authorization/balance/UI/scope)  
- **Status:** Confirmed (files present)  
- **Evidence:** `repo/tests/api/RefundAuthorizationTest.php:13`, `repo/tests/api/BalanceCorrectnessTest.php:14`, `repo/tests/api/DelegationUiFrontendTest.php:12`, `repo/tests/api/ReceiptUiFrontendTest.php:12`, `repo/tests/api/ContractScopeAttributionTest.php:14`

12) Verification flow tests extended  
- **Status:** Confirmed  
- **Evidence:** `repo/tests/api/VerificationUserFlowTest.php:93`, `repo/tests/api/VerificationUserFlowTest.php:107`, `repo/tests/api/VerificationUserFlowTest.php:117`, `repo/tests/api/VerificationUserFlowTest.php:130`

13) Payment idempotency tests updated for new refund auth  
- **Status:** Confirmed  
- **Evidence:** `repo/tests/api/PaymentIdempotencyTest.php:25`, `repo/tests/api/PaymentIdempotencyTest.php:95`

## Notes / discrepancies
- The changelog line "`testBalanceAfterPartialPayment`" suggests partial-payment assertion, but the current test pays full amount (`80000` on `80000`) and asserts zero balance.  
  - Evidence: `repo/tests/api/BalanceCorrectnessTest.php:52`, `repo/tests/api/BalanceCorrectnessTest.php:59`, `repo/tests/api/BalanceCorrectnessTest.php:62`
- "All tests validated" remains **Cannot Confirm Statistically** in this check because no tests were run here.

## Final static judgment
- **Implementation presence:** Mostly confirmed.
- **Runtime correctness:** Cannot confirm from this check.
