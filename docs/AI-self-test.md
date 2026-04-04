# Rural Lease Portal - AI Self-Test Matrix

Map each requirement to an implementation target and verification evidence.

| Status | Requirement | Implementation Artifact | Verification |
| --- | --- | --- | --- |
| [ ] | Password policy | auth validator/service | unit test: password rules |
| [ ] | Lockout + backoff | auth lockout service | API test: repeated login failures |
| [ ] | Admin optional MFA | MFA enroll/verify handlers | API test: admin MFA flow |
| [ ] | Geo scope isolation | query scope middleware/service | API test: cross-scope denial |
| [ ] | Verification reject reason | verification controller/service | API test: reject without reason -> 400 |
| [ ] | Duplicate detection | entity profile matching service | unit/API tests for duplicate flagging |
| [ ] | Contract schedule generation | contracts service | API test: contract creates invoices |
| [ ] | Late fee formula + cap | finance calculator | unit tests: day 5/day 6/cap boundary |
| [ ] | Idempotent payments | payment idempotency service/table | API test: duplicate payment replay |
| [ ] | Refund tracking | refunds service + invoice update | API test: refund balance update |
| [ ] | Message recall window | messaging recall service | API test: recall within/after 10 min |
| [ ] | Risk warn/block/flag | risk keyword engine | API/UI tests by action mode |
| [ ] | Attachment validation + checksum | messaging upload handler | API test: invalid file/oversize |
| [ ] | AES-256 at rest | encryption service | unit test: encrypt/decrypt roundtrip |
| [ ] | Masking in response/UI | serializer + frontend formatting | API/UI snapshot tests |
| [ ] | Append-only audit log | audit repository/service | API test: no update/delete path |
| [ ] | Fullstack endpoint wiring | frontend modules + API client | UI integration tests per slice |
| [ ] | Docker-first test flow | Dockerfile/compose/run_tests.sh | cold-start run + in-container tests |

## Release Gate

- [ ] All rows completed
- [ ] `docs/features.md` all checked
- [ ] `./run_tests.sh` passes from cold start
