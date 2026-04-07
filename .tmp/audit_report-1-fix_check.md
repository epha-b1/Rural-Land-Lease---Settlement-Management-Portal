# Fix Check for `audit_report-1.md` (Static Re-check)

## Scope
- Source of issues checked: `.tmp/audit_report-1.md` (Issue #1 High, Issue #2 Medium).
- Method: static code/document inspection only.
- Not executed: runtime, Docker, tests, browser interactions.

## Results

| Issue ID | Severity | Title | Status | Evidence | Fix-Check Notes |
|---|---|---|---|---|---|
| 1 | High | Guided merge resolution is recorded but not applied to target profile fields | **Fixed** | `repo/app/service/EntityService.php:345`, `repo/app/service/EntityService.php:393`, `repo/app/service/EntityService.php:408`, `repo/tests/api/GuidedMergeTest.php:38`, `repo/tests/api/GuidedMergeTest.php:94` | Merge now validates `resolution_map`, applies selected core/extra-field values to target (`targetUpdate`), wraps operations in transaction, and tests assert applied values on target profile. |
| 2 | Medium | README late-fee rate text inconsistent with code/constants | **Fixed** | `repo/README.md:256`, `repo/app/service/LateFeeService.php:15` | README now matches implementation: `5 bps/day = 0.05%/day`, consistent with `DAILY_RATE_BPS = 5`. |

## Overall Fix-Check Verdict
- **Pass**
- Both issues listed in `.tmp/audit_report-1.md` are resolved by static evidence.

## Minimum Remaining Action
1. None.
