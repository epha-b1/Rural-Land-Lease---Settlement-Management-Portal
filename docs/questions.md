# Questions and Clarifications — Rural Land Lease & Settlement Management Portal

---

## 1. Geographic Scope Hierarchy — How Many Levels?

**Question:** The prompt mentions village, township, and county scope. How many levels does the geographic hierarchy have, and can a user belong to multiple villages or townships?

**Assumption:** Three levels: county > township > village. A user is assigned to exactly one geographic scope at registration. A village collective user can only see records within their village. A township-level user sees all villages in their township. County admin sees everything.

**Solution:** `users` table has a `geo_scope_level` enum (village/township/county) and a `geo_scope_id` FK. All data queries filter by the user's scope level and ID.

---

## 2. Verification Workflow — Who Can Approve?

**Question:** Users submit real-name and qualification details for verification. Who is the reviewer — any admin, or a specific role?

**Assumption:** Only System Admins (county-level) can approve or reject verifications. Township and village admins cannot approve verifications. Rejection requires a non-empty reason string.

**Solution:** `POST /admin/verifications/:id/approve` and `POST /admin/verifications/:id/reject` are restricted to `system_admin` role. Rejection stores the reason in `verification_rejections` table.

---

## 3. Duplicate Detection — When Does It Trigger?

**Question:** The system flags likely duplicates based on name + address + last 4 of ID/license. Does this trigger on every profile save, or only on initial creation?

**Assumption:** Triggers on both create and update. If a match is found, the save is not blocked — instead a warning is shown and the user is offered a guided merge. The merge is optional and can be dismissed.

**Solution:** After every profile upsert, a background check compares name + address + last4. If similarity score exceeds threshold, a `duplicate_flags` record is created. The UI shows a merge prompt on next profile view.

---

## 4. Late Charge Calculation — Daily or Monthly?

**Question:** The prompt says "1.5% per month applied daily." Does this mean 1.5%/30 per day, or 1.5% charged once per month?

**Assumption:** Daily accrual at the daily equivalent rate: 1.5% / 30 = 0.05% per day. Applied from day 6 (after 5-day grace period) until paid or cap reached. Cap is $250.00 per invoice, not per contract.

**Solution:** `calculateLateFee(invoiceAmount, daysOverdue)` — if `daysOverdue <= 5` return 0. Otherwise: `fee = invoiceAmount * 0.0005 * (daysOverdue - 5)`, capped at 250.00.

---

## 5. Billing Schedule — When Are Invoices Generated?

**Question:** Contracts auto-generate billing schedules. Are invoices generated at contract creation for the full term, or generated periodically (monthly/quarterly)?

**Assumption:** All invoices for the full contract term are generated at contract creation time. Each invoice has a `due_date`, `amount`, and `status` (unpaid/paid/overdue). A scheduled job updates overdue status daily.

**Solution:** On `POST /contracts`, the service calculates all billing periods based on contract start/end dates and payment frequency, and bulk-inserts invoice records. A daily cron job marks invoices as overdue if `due_date < today` and `status = unpaid`.

---

## 6. Message Recall — What Happens to Recalled Messages?

**Question:** Messages can be recalled within 10 minutes. Is the message deleted from the recipient's view immediately, or marked as recalled?

**Assumption:** The message is soft-deleted — it remains in the database but is marked `recalled = true`. The recipient's UI shows "This message was recalled" in place of the content. After 10 minutes, recall is no longer possible.

**Solution:** `PATCH /messages/:id/recall` checks `created_at + 10 min > now` and sets `recalled = true`, clears `content` and `attachment_path`. Recipients see a placeholder.

---

## 7. Risk Content Detection — Warn vs Block vs Flag

**Question:** The prompt says risk content detection has three configurable actions: warn, block, or allow-and-flag. Who configures these, and per keyword or globally?

**Assumption:** System Admin configures a keyword/pattern library with per-entry action settings. Each keyword entry has: `pattern` (string or regex), `action` (warn/block/flag), `category` (harassment/fraud/other). The check runs client-side before send (warn/block) and server-side on receipt (flag).

**Solution:** `risk_keywords` table with `pattern`, `action`, `category`, `active`. API endpoint `GET /admin/risk-keywords` and `POST/PATCH/DELETE /admin/risk-keywords/:id`. Message send endpoint checks all active patterns server-side.

---

## 8. TOTP MFA — Is It Required or Optional for Admins?

**Question:** The prompt says "optional TOTP-based MFA for admins." Does this mean admins can choose to enable it, or that the system supports it but doesn't enforce it?

**Assumption:** Optional per admin user — each admin can enroll their own TOTP device. Once enrolled, MFA is required for that admin on every login. Non-admin users cannot use MFA.

**Solution:** `users.mfa_secret` (AES-256 encrypted). `POST /auth/mfa/enroll` generates a TOTP secret and QR code. `POST /auth/mfa/verify` validates the TOTP code. Login flow: if `mfa_secret` is set, require TOTP after password.

---

## 9. Access Delegation — How Does It Work?

**Question:** "Admin users can delegate township/county access with explicit approval." Who approves the delegation, and is it time-limited?

**Assumption:** A county admin can grant a township admin temporary county-level access. The delegation requires approval from another county admin (two-person rule). Delegations have an expiry date (max 30 days). Expired delegations auto-revoke.

**Solution:** `access_delegations` table: `grantor_id`, `grantee_id`, `scope_level`, `scope_id`, `expires_at`, `approved_by`, `status`. Approval endpoint requires a second county admin.

---

## 10. Refunds — What Triggers a Refund?

**Question:** The prompt mentions refunds in the audit log but doesn't define when refunds occur. What business events trigger a refund?

**Assumption:** Refunds occur when: (1) a contract is terminated early and a deposit must be returned, (2) an overpayment is recorded, or (3) an admin manually issues a refund. Refunds are recorded as negative payment entries linked to the original invoice.

**Solution:** `refunds` table: `invoice_id`, `amount`, `reason`, `issued_by`, `created_at`. Refund reduces the outstanding balance on the invoice. Audit log records before/after balance.

---

## 11. Configurable Extra Fields — What Types Are Supported?

**Question:** Entity profiles support configurable extra fields like "primary crop." What field types are supported?

**Assumption:** Text, number, date, and select (dropdown with admin-defined options). Extra fields are defined per entity type (farmer/enterprise/collective) by system admin. Values are stored as JSON in a `profile_extra_fields` column.

**Solution:** `extra_field_definitions` table: `entity_type`, `field_key`, `field_label`, `field_type`, `options` (JSON for select). Profile save validates extra field values against their definitions.

---

## 12. Payment Receipt — What Format?

**Question:** Users can "print receipts." Is this a PDF, HTML print view, or something else?

**Assumption:** The receipt is an HTML page rendered by the Layui frontend that the browser can print to PDF using the browser's native print dialog. No server-side PDF generation is required.

**Solution:** `GET /invoices/:id/receipt` returns a JSON payload. The frontend renders it as a printable HTML page with CSS print styles. No external PDF library needed.
