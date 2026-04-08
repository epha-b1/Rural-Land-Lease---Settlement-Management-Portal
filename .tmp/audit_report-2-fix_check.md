# Fix-Check Report (Targeted Remediation)

## 1) Verdict
- **Pass (for previously flagged remediation scope)**

## 2) Verification Boundary
- Static code review only (no runtime execution in this check).
- Test execution result is **reported by submitter**: `308 tests, 876 assertions, 0 failures`.

## 3) Fix Matrix

### A. Attachment retrieval mismatch (Blocker)
- **Status:** Fixed
- **What changed:** Attachment ownership now resolved via `messages.attachment_id` and guarded for recalled messages + participant access.
- **Evidence:**
  - `route/app.php:68`
  - `app/controller/Message.php:134`
  - `app/controller/Message.php:138`
  - `app/controller/Message.php:145`
- **Tests:**
  - `tests/api/AttachmentRetrievalTest.php:31`
  - `tests/api/AttachmentRetrievalTest.php:42`
  - `tests/api/AttachmentRetrievalTest.php:49`
  - `tests/api/AttachmentRetrievalTest.php:59`

### B. Partial payment incorrectly reported/handled as paid (High)
- **Status:** Fixed
- **What changed:** Final invoice status is now conditional on outstanding balance; only transitions to `paid` when fully settled.
- **Evidence:**
  - `app/service/PaymentService.php:114`
  - `app/service/PaymentService.php:115`
  - `app/service/PaymentService.php:122`

### C. Audit after-values hardcoded to paid (follow-up correctness)
- **Status:** Fixed
- **What changed:** Audit `after.status` now uses computed `$finalStatus`.
- **Evidence:**
  - `app/service/PaymentService.php:148`

### D. Test sufficiency gap: persisted invoice status after partial payment
- **Status:** Fixed
- **What changed:** Test now validates both response status and persisted invoice status via `GET /invoices/:id` before and after full settlement.
- **Evidence:**
  - `tests/api/BalanceCorrectnessTest.php:65`
  - `tests/api/BalanceCorrectnessTest.php:73`
  - `tests/api/BalanceCorrectnessTest.php:86`
  - `tests/api/BalanceCorrectnessTest.php:92`

### E. Offline build dependency for Layui (High)
- **Status:** Fixed
- **What changed:** Build no longer fetches Layui from internet; vendored asset is verified locally.
- **Evidence:**
  - `Dockerfile:36`
  - `Dockerfile:38`
  - `public/static/layui/layui.js`
  - `README.md:401`
  - `README.md:454`

### F. Public registration scope UI mismatch (Medium)
- **Status:** Fixed
- **What changed:** Public register scope set to village-only in UI, aligned with backend policy.
- **Evidence:**
  - `public/static/register.html:49`
  - `public/static/register.html:52`

## 4) Final Conclusion
- All previously requested remediation items in this fix-check scope are now statically verified as fixed.
