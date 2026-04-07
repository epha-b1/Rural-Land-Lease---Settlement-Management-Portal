# Rural Lease Portal - API Specification

This spec is implementation-facing and must be treated as the contract for ThinkPHP backend and Layui integration.

## Global API Rules

- Base URL: `/`
- Content type: `application/json` unless explicitly marked as file upload/export.
- All protected routes require valid auth context before request handling.
- RBAC and geographic scope enforcement are server-side requirements for every protected endpoint.
- Every response includes `X-Trace-Id` header.
- Error envelope:

```json
{
  "status": "error",
  "code": "VALIDATION_ERROR",
  "message": "human-readable message",
  "trace_id": "uuid-or-random-id"
}
```

## Idempotency Contract (Required)

- Header: `Idempotency-Key` (required for payment posting).
- Scope key: `method + normalized_route + actor_id + idempotency_key`.
- Window: 10 minutes.
- Replay in window: return original status code + response payload snapshot.
- Concurrency-safe: idempotency key is reserved atomically via DB UNIQUE constraint before payment write; concurrent same-key requests deterministically replay the original response.

## Endpoint Catalog

### Foundation

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/health` | public | none | `200 {"status":"ok"}` | 500 |
| GET | `/api/docs` | public | none | `200 {endpoint catalog}` | 500 |

### Auth & Identity

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/auth/captcha` | public | none | `200 {challenge_id,question}` | 500 |
| POST | `/auth/register` | public | `{username,password,role,geo_scope_level,geo_scope_id,captcha_id,captcha_answer}` | `201 {user_id,username,role,scope}` | 400, 403, 409 |
| POST | `/auth/login` | public | `{username,password,captcha_id,captcha_answer,totp_code?}` | `200 {access_token,user,mfa_required?}` | 400, 401, 423, 429 |
| POST | `/auth/logout` | session | none | `200 {status:"ok"}` | 401 |
| GET | `/auth/me` | session | none | `200 {id,username,role,scope,mfa_enabled,status}` | 401 |
| POST | `/auth/mfa/enroll` | system_admin | none | `200 {qr_payload}` | 401, 403 |
| POST | `/auth/mfa/verify` | system_admin | `{totp_code}` | `200 {mfa_enabled:true}` | 400, 401, 403 |
| POST | `/admin/users` | system_admin | `{username,password,role,geo_scope_level,geo_scope_id}` | `201 {user_id}` | 400, 401, 403, 409 |

> **Note:** Public `/auth/register` refuses `system_admin` role (403). Admin accounts must be created by an existing admin via `POST /admin/users`.

### Verification

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/verifications/mine` | session | none | `200 {id,status,submitted_at,reviewed_at,rejection_reason?}` | 401 |
| POST | `/verifications` | session | `{id_number?,license_number?,scan_path?}` or multipart with `scan_file` | `201 {id,status:"pending"}` | 400, 401 |
| GET | `/verifications` | system_admin | query: `status?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/admin/verifications/:id/approve` | system_admin | `{note?}` | `200 {id,status:"approved"}` | 401, 403, 404, 409 |
| POST | `/admin/verifications/:id/reject` | system_admin | `{reason}` (required, non-empty) | `200 {id,status:"rejected",reason}` | 400, 401, 403, 404, 409 |

### Entity Profiles

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/entities/field-definitions` | session | query: `entity_type?` | `200 {items[{field_key,field_label,field_type,options}]}` | 401 |
| GET | `/entities` | scoped | query: `entity_type?,keyword?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/entities` | scoped write | `{entity_type,display_name,address,id_last4?,license_last4?,extra_fields?}` | `201 {id,duplicate_flag?}` | 400, 401, 403 |
| GET | `/entities/:id` | scoped | none | `200 {profile,merge_history[],duplicate_flags[]}` | 401, 403, 404 |
| PATCH | `/entities/:id` | scoped write | `{display_name?,address?,extra_fields?,status?}` | `200 {id,duplicate_flag?}` | 400, 401, 403, 404 |
| POST | `/entities/:id/merge` | scoped write | `{target_id,resolution_map}` | `200 {merged_profile_id,change_history_id}` | 400, 401, 403, 404 |

### Contracts & Invoices

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/contracts` | scoped | query: `status?,profile_id?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/contracts` | scoped write | `{profile_id,start_date,end_date,rent_cents,deposit_cents?,maintenance_cents?,frequency}` | `201 {contract_id,invoices_created}` | 400, 401, 403, 404 |
| GET | `/contracts/:id` | scoped | none | `200 {contract,invoices[]}` | 401, 403, 404 |
| GET | `/invoices` | scoped | query: `contract_id?,status?,due_from?,due_to?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| GET | `/invoices/:id` | scoped | none | `200 {invoice,snapshot}` | 401, 403, 404 |
| GET | `/invoices/:id/receipt` | scoped | none | `200 {invoice,contract,payments[]}` | 401, 403, 404 |

### Payments, Refunds, Exports

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| POST | `/payments` | scoped + `Idempotency-Key` header | `{invoice_id,amount_cents,paid_at,method,reference?}` | `201 {payment_id,invoice_status,balance_cents}` | 400, 401, 403, 404, 409 |
| POST | `/refunds` | scoped write | `{invoice_id,amount_cents,reason}` | `201 {refund_id,invoice_balance_cents}` | 400, 401, 403, 404 |
| GET | `/exports/ledger` | scoped | query: `from,to,format[csv\|xlsx]` | file stream (CSV or XLSX) | 400, 401, 403 |
| GET | `/exports/reconciliation` | scoped | query: `from,to,format[csv\|xlsx]` | file stream (CSV or XLSX) | 400, 401, 403 |

### Messaging & Risk

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/conversations` | scoped | query: `page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/conversations` | session | none | `201 {id}` | 401 |
| GET | `/conversations/:id/messages` | scoped | query: `page?,size?` | `200 {items[],page,total}` | 401, 403, 404 |
| POST | `/messages/preflight-risk` | session | `{content}` | `200 {action,matched_rules[]}` | 400, 401 |
| POST | `/messages` | scoped write | `{conversation_id,type[text\|voice\|image],content?,attachment?}` | `201 {message_id,risk_action,warning?}` | 400, 401, 403, 404, 409, 413 |
| PATCH | `/messages/:id/recall` | scoped write | none | `200 {message_id,recalled:true}` | 400, 401, 403, 404, 409 |
| POST | `/messages/:id/report` | scoped | `{category,reason}` | `201 {report_id}` | 400, 401, 403, 404 |

### Admin

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/admin/risk-keywords` | system_admin | none | `200 {items[]}` | 401, 403 |
| POST | `/admin/risk-keywords` | system_admin | `{pattern,is_regex,action,category,active}` | `201 {id}` | 400, 401, 403 |
| PATCH | `/admin/risk-keywords/:id` | system_admin | `{pattern?,action?,category?,active?}` | `200 {id,updated_fields}` | 400, 401, 403, 404 |
| DELETE | `/admin/risk-keywords/:id` | system_admin | none | `200 {id,disabled:true}` | 401, 403, 404 |
| GET | `/admin/jobs` | system_admin | none | `200 {items[]}` | 401, 403 |
| POST | `/admin/jobs/run` | system_admin | none | `200 {results}` | 401, 403 |
| GET | `/admin/config` | system_admin | none | `200 {items[]}` | 401, 403 |
| PATCH | `/admin/config/:key` | system_admin | `{value}` | `200 {key,value}` | 400, 401, 403, 404 |
| GET | `/audit-logs` | system_admin | query: `event_type?,actor_id?,from?,to?,page?,size?` | `200 {items[],page,total}` | 401, 403 |

### Delegation

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/delegations` | system_admin | query: `status?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/delegations` | system_admin | `{grantee_id,scope_level,scope_id,expires_at}` | `201 {delegation_id,status}` | 400, 401, 403, 404 |
| POST | `/delegations/:id/approve` | system_admin (not grantor) | `{approve:true}` | `200 {delegation_id,status}` | 400, 401, 403, 404, 409 |

## Domain Rule Constraints

- **Verification state machine:** `pending -> approved|rejected`; no other transition is valid (409).
- **Rejection reason:** mandatory non-empty string; 400 if missing.
- **Invoice state machine:** `unpaid -> paid`, `unpaid -> overdue`; invalid transition returns 409.
- **Late fee rule:** 5-day grace period, then 1.5%/month applied daily (0.05%/day = 5 bps), capped at $250.00 (25000 cents) per invoice. Late fees are recalculated idempotently on each scheduler tick and persisted in `late_fee_cents`.
- **Payment idempotency:** Atomic reservation via UNIQUE constraint; 10-minute replay window; deterministic response replay.
- **Attachment limit:** 10 MB max, MIME allowlist enforced.
- **Message recall:** Within 10 minutes of send only; body replaced with `[This message was recalled]`.
- **Delegation:** 30-day max expiry; two-person rule (grantor != approver).
- **Registration:** `system_admin` role blocked on public register; admin bootstrap via `POST /admin/users`.

## Shared Error Codes

- 400 `VALIDATION_ERROR`
- 401 `UNAUTHORIZED`
- 403 `FORBIDDEN`
- 404 `NOT_FOUND`
- 409 `CONFLICT`
- 413 `PAYLOAD_TOO_LARGE`
- 423 `LOCKED`
- 429 `RATE_LIMITED`
- 500 `INTERNAL_ERROR`
