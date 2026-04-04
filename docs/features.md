# Rural Lease Portal - Feature Checklist

Use this checklist to track complete implementation.

## Authentication and Identity

- [ ] Registration, login, logout implemented
- [ ] Password policy: >= 12 chars with upper/lower/number/symbol
- [ ] Lockout after 5 failures in 15 minutes with exponential backoff
- [ ] Optional TOTP MFA for admins
- [ ] RBAC enforced on all endpoints
- [ ] Geographic scope filtering enforced server-side

## Verification and Profiles

- [ ] Real-name/business verification with pending/approved/rejected
- [ ] Rejection requires reason
- [ ] Profile CRUD for farmer/enterprise/collective
- [ ] Configurable extra profile fields (text/number/date/select)
- [ ] Duplicate detection using name + address + last4 ID/license
- [ ] Guided merge with visible change history

## Contracts, Invoices, and Finance

- [ ] Contract CRUD with auto-generated billing schedule
- [ ] Invoice status: paid/unpaid/overdue
- [ ] Late fee rule with grace, daily accrual, and cap
- [ ] Payment posting is idempotent (10-minute window)
- [ ] Refund workflow linked to invoices
- [ ] Printable receipt view
- [ ] Ledger and reconciliation export (CSV/Excel)

## Messaging and Risk

- [ ] Conversation panel supports text, voice notes, images
- [ ] Read indicators
- [ ] Message recall within 10 minutes
- [ ] Report action for harassment/fraud
- [ ] Attachment type/size validation (max 10 MB)
- [ ] Risk keyword/pattern library with warn/block/allow-and-flag
- [ ] Admin-managed risk rules offline

## Security and Compliance

- [ ] AES-256 encryption at rest for sensitive fields
- [ ] Keys stored outside web root
- [ ] Sensitive fields masked in UI and API responses
- [ ] Local TLS profile for intranet deployment
- [ ] Audit log append-only with before/after values

## Fullstack Delivery Rule

- [ ] Every backend endpoint integrated into Layui UI in the same slice
- [ ] Every slice validated with backend + frontend checks
- [ ] Docker-first run and tests (`docker compose`, `run_tests.sh`)
