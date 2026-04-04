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

- Header: `Idempotency-Key` (required for payment posting and other retryable mutation endpoints).
- Scope key: `method + normalized_route + actor_id + idempotency_key`.
- Window: 10 minutes.
- Replay in window: return original status code + response payload snapshot.

## Endpoint Catalog

| Method | Path | Auth | Request | Success Response | Error Codes |
| --- | --- | --- | --- | --- | --- |
| GET | `/health` | public | none | `200 {"status":"ok"}` | 500 |
| POST | `/auth/register` | public | `{username,password,role,geo_scope_level,geo_scope_id}` | `201 {user_id,username,role,scope}` | 400, 409 |
| POST | `/auth/login` | public | `{username,password,totp_code?}` | `200 {access_token/session,user,mfa_required}` | 400, 401, 423, 429 |
| POST | `/auth/logout` | session | none | `200 {status:"ok"}` | 401 |
| GET | `/auth/me` | session | none | `200 {id,username,role,scope,verification_status}` | 401 |
| POST | `/auth/mfa/enroll` | admin session | none | `200 {secret_otpauth_url,qr_payload}` | 401, 403 |
| POST | `/auth/mfa/verify` | admin session | `{totp_code}` | `200 {mfa_enabled:true}` | 400, 401, 403 |
| GET | `/users` | admin | query: `role?,status?,scope_level?,scope_id?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| PATCH | `/users/:id` | admin | `{role?,status?,geo_scope_level?,geo_scope_id?}` | `200 {id,updated_fields}` | 400, 401, 403, 404, 409 |
| GET | `/verifications` | admin | query: `status?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/admin/verifications/:id/approve` | system_admin | `{note?}` | `200 {id,status:"approved"}` | 400, 401, 403, 404, 409 |
| POST | `/admin/verifications/:id/reject` | system_admin | `{reason}` | `200 {id,status:"rejected",reason}` | 400, 401, 403, 404, 409 |
| GET | `/entities` | scoped | query: `entity_type?,keyword?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/entities` | scoped write | `{entity_type,display_name,address,id_last4?,license_last4?,extra_fields?}` | `201 {id,duplicate_flag?}` | 400, 401, 403, 409 |
| GET | `/entities/:id` | scoped | none | `200 {profile,merge_history[],duplicate_flags[]}` | 401, 403, 404 |
| PATCH | `/entities/:id` | scoped write | `{display_name?,address?,extra_fields?,status?}` | `200 {id,duplicate_flag?}` | 400, 401, 403, 404, 409 |
| POST | `/entities/:id/merge` | scoped write | `{target_id,resolution_map}` | `200 {merged_profile_id,change_history_id}` | 400, 401, 403, 404, 409 |
| GET | `/contracts` | scoped | query: `status?,profile_id?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/contracts` | scoped write | `{profile_id,start_date,end_date,rent_cents,deposit_cents,maintenance_cents?,frequency}` | `201 {contract_id,invoices_created}` | 400, 401, 403, 404, 409 |
| GET | `/contracts/:id` | scoped | none | `200 {contract,invoices[]}` | 401, 403, 404 |
| GET | `/invoices` | scoped | query: `contract_id?,status?,due_from?,due_to?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| GET | `/invoices/:id` | scoped | none | `200 {invoice,snapshot}` | 401, 403, 404 |
| GET | `/invoices/:id/receipt` | scoped | none | `200 {receipt_payload}` | 401, 403, 404 |
| POST | `/payments` | scoped write + idempotency | headers: `Idempotency-Key`; body: `{invoice_id,amount_cents,paid_at,method,reference?}` | `201 {payment_id,invoice_status,balance_cents}` | 400, 401, 403, 404, 409 |
| POST | `/refunds` | scoped write | `{invoice_id,amount_cents,reason}` | `201 {refund_id,invoice_balance_cents}` | 400, 401, 403, 404, 409 |
| GET | `/conversations` | scoped | query: `participant_id?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| GET | `/conversations/:id/messages` | scoped | query: `before?,after?,page?,size?` | `200 {items[],page,total}` | 401, 403, 404 |
| POST | `/messages` | scoped write | multipart/json: `{conversation_id,type[text|voice|image],content?,attachment?}` | `201 {message_id,risk_action,warning?}` | 400, 401, 403, 404, 409, 413 |
| PATCH | `/messages/:id/recall` | scoped write | none | `200 {message_id,recalled:true}` | 400, 401, 403, 404, 409 |
| POST | `/messages/:id/report` | scoped | `{category,reason}` | `201 {report_id}` | 400, 401, 403, 404 |
| GET | `/admin/risk-keywords` | admin | query: `active?,action?,page?,size?` | `200 {items[],page,total}` | 401, 403 |
| POST | `/admin/risk-keywords` | admin | `{pattern,is_regex,action,category,active}` | `201 {id}` | 400, 401, 403, 409 |
| PATCH | `/admin/risk-keywords/:id` | admin | `{pattern?,action?,category?,active?}` | `200 {id,updated_fields}` | 400, 401, 403, 404, 409 |
| DELETE | `/admin/risk-keywords/:id` | admin | none | `200 {id,disabled:true}` | 401, 403, 404 |
| GET | `/exports/ledger` | scoped | query: `from,to,format[csv|xlsx]` | file stream or `200 {download_url}` | 400, 401, 403 |
| GET | `/exports/reconciliation` | scoped | query: `from,to,format[csv|xlsx]` | file stream or `200 {download_url}` | 400, 401, 403 |
| POST | `/delegations` | county_admin | `{grantee_id,scope_level,scope_id,expires_at}` | `201 {delegation_id,status:"pending_approval"}` | 400, 401, 403, 404, 409 |
| POST | `/delegations/:id/approve` | county_admin (not grantor) | `{approve:true|false,reason?}` | `200 {delegation_id,status}` | 400, 401, 403, 404, 409 |
| GET | `/audit-logs` | admin | query: `event_type?,actor_id?,from?,to?,page?,size?` | `200 {items[],page,total}` | 401, 403 |

## Domain Rule Constraints

- Verification status machine: `pending -> approved|rejected`; no other transition is valid.
- Rejection must include a non-empty `reason`.
- Invoice state machine enforces `unpaid -> paid` and `unpaid -> overdue`; invalid transition returns 409.
- Late fee logic: 5-day grace, then `(amount_cents * 0.015 / 30) * overdue_days_after_grace`, capped at 25000 cents.
- Attachment size max: 10 MB.

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
