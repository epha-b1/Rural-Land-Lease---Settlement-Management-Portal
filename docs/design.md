# Rural Lease Portal - System Design

This document defines the architecture, module boundaries, data model, auth model, and security strategy for the Rural Land Lease and Settlement Management Portal.

## 1) Architecture Diagram (Text)

```text
[Layui Web UI]
  |- Farmer entry
  |- Enterprise entry
  |- Village Collective entry
  |- System Admin entry
       |
       v
[ThinkPHP REST API]
  |- Trace middleware
  |- Auth middleware (session/JWT)
  |- RBAC + Geo-scope middleware
  |- Idempotency middleware (post-auth on protected routes)
       |
       v
[Service Layer]
  |- Auth, verification, profiles, contracts, finance, messaging, audit
       |
       v
[MySQL]
  |- Business tables
  |- Audit trail (append-only)
  |- Idempotency records

[Background Scheduler]
  |- Overdue sync
  |- Delegation expiry revocation
  |- Message retention cleanup
```

## 2) Module Breakdown

| Module | Responsibilities |
| --- | --- |
| `auth` | register/login/logout, password policy, lockout/backoff, admin MFA |
| `users` | account profile, role assignment, scope assignment, status control |
| `verification` | real-name/license submission, approve/reject with required reason |
| `profiles` | farmer/enterprise/collective master records, extra fields, duplicate flags, merge flow |
| `contracts` | contract CRUD, billing schedule generation |
| `invoices` | invoice lifecycle (`unpaid/paid/overdue`), immutable snapshots |
| `payments` | payment posting, idempotency window, receipt payload |
| `refunds` | refund records linked to invoice balances |
| `exports` | ledger/reconciliation CSV/Excel generation |
| `messaging` | conversations, text/voice/image messages, read state, recall, report |
| `risk` | offline keyword/pattern library with warn/block/allow-and-flag actions |
| `delegation` | township/county access delegation with two-person approval |
| `audit` | append-only event log with before/after values |
| `security` | AES-256 encryption/decryption, masking rules, key loading |
| `jobs` | scheduled overdue update, retention purge, delegation expiry processing |

## 3) Database Tables (Key Fields)

| Table | Key Fields |
| --- | --- |
| `users` | `id`, `username`, `password_hash`, `role`, `geo_scope_level`, `geo_scope_id`, `status`, `mfa_enabled`, `mfa_secret_enc` |
| `auth_failures` | `id`, `user_id`, `failed_at`, `ip`, `device_fingerprint` |
| `verification_requests` | `id`, `user_id`, `id_number_enc`, `license_number_enc`, `scan_path`, `status`, `submitted_at`, `reviewed_at` |
| `verification_decisions` | `id`, `request_id`, `reviewer_id`, `decision`, `reason`, `created_at` |
| `entity_profiles` | `id`, `entity_type`, `display_name`, `address`, `id_last4`, `license_last4`, `extra_fields_json`, `status` |
| `duplicate_flags` | `id`, `left_profile_id`, `right_profile_id`, `match_basis`, `status`, `created_at` |
| `profile_merge_history` | `id`, `source_profile_id`, `target_profile_id`, `merged_by`, `diff_json`, `created_at` |
| `contracts` | `id`, `profile_id`, `start_date`, `end_date`, `rent_cents`, `deposit_cents`, `maintenance_cents`, `status` |
| `invoices` | `id`, `contract_id`, `due_date`, `amount_cents`, `late_fee_cents`, `status`, `snapshot_version` |
| `invoice_snapshots` | `id`, `invoice_id`, `snapshot_json`, `created_at` |
| `payments` | `id`, `invoice_id`, `amount_cents`, `paid_at`, `method`, `reference_enc`, `posted_by` |
| `payment_idempotency` | `id`, `actor_id`, `method`, `route`, `idempotency_key`, `request_hash`, `response_json`, `created_at` |
| `refunds` | `id`, `invoice_id`, `amount_cents`, `reason`, `issued_by`, `created_at` |
| `conversations` | `id`, `scope_level`, `scope_id`, `created_by`, `created_at` |
| `messages` | `id`, `conversation_id`, `sender_id`, `body_enc`, `message_type`, `attachment_id`, `read_at`, `recalled_at`, `risk_result` |
| `attachments` | `id`, `file_name`, `mime_type`, `size_bytes`, `storage_path`, `checksum_sha256` |
| `message_reports` | `id`, `message_id`, `reporter_id`, `category`, `reason`, `created_at` |
| `risk_rules` | `id`, `pattern`, `is_regex`, `action`, `category`, `active`, `updated_by` |
| `access_delegations` | `id`, `grantor_id`, `grantee_id`, `scope_level`, `scope_id`, `expires_at`, `status`, `approved_by` |
| `audit_logs` | `id`, `actor_id`, `event_type`, `resource_type`, `resource_id`, `before_json`, `after_json`, `ip`, `device_fingerprint`, `created_at` |

## 4) Auth and Session Design

- Registration accepts username/password and initial role/scope constraints.
- Password policy is server-enforced: min 12 chars with upper/lower/number/symbol.
- Login writes to `auth_failures` on every failed attempt and evaluates rolling 15-minute lockout window.
- Lockout applies after 5 failed attempts within 15 minutes, with exponential backoff on retry delay.
- Admin MFA is optional enrollment; once enabled, login requires valid TOTP after password check.
- Protected routes require valid authenticated context, then enforce RBAC and geo-scope filters in query layer.

## 5) Background Jobs

| Job | Schedule | Purpose |
| --- | --- | --- |
| Overdue invoice updater | Daily 00:05 | Mark `unpaid` invoices as `overdue` when past due date |
| Delegation expiry revoker | Hourly | Revoke expired temporary access delegations |
| Message retention cleaner | Daily 01:00 | Remove/archive messages older than retention period (default 24 months) |
| Risk cache refresher | Every 10 minutes | Reload active rule set for deterministic offline matching |

All jobs must be wired in the startup entrypoint and validated by tests.

## 6) Security Design Summary

- AES-256 encrypts sensitive fields at rest (ID/license numbers, bank references, MFA secret).
- Encryption keys are loaded from a path outside web root and outside public static directories.
- Sensitive values are masked by default in API responses and UI displays.
- Idempotent payment posting uses a 10-minute dedup window keyed by `method + route + actor + idempotency key`.
- Message attachments enforce MIME allowlist and max size 10 MB before persistence.
- Audit log is append-only; no update/delete endpoint is exposed.
- TLS is enabled for local intranet deployment profile.

## 7) Error Response Format

All error responses use:

```json
{
  "status": "error",
  "code": "FORBIDDEN",
  "message": "You do not have access to this village scope",
  "trace_id": "e9e4f132f3f34f2fbe7de5ac8e2fe7a4"
}
```

Error code conventions:

- `VALIDATION_ERROR` -> 400
- `UNAUTHORIZED` -> 401
- `FORBIDDEN` -> 403
- `NOT_FOUND` -> 404
- `CONFLICT` -> 409
- `LOCKED` -> 423
- `RATE_LIMITED` -> 429
- `INTERNAL_ERROR` -> 500
