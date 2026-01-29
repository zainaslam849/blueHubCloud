# Call Data Freezing for Weekly Reports

## Overview

When a weekly report is generated, calls are **frozen** to that report via the `weekly_call_report_id` column. This ensures:

✅ **No double-counting** - Each call is assigned to exactly one report  
✅ **No call loss** - No call is missed or skipped  
✅ **Report immutability** - Once a call is assigned to a report, it NEVER changes  
✅ **Audit trail** - Each call record shows which report it belongs to

---

## Call Selection Rules

### CRITERIA (in order of filtering)

1. **Company Match**
    - `company_id = <target_company_id>`
    - All calls belong to a company; reports are company-specific

2. **Status Filter** ✅ NEW
    - `status = 'answered'` (COMPLETED CALLS ONLY)
    - Missed calls (`status != 'answered'`) are tracked separately in metadata
    - Never lock incomplete/failed calls into reports

3. **Date Range** (Company timezone-aware)
    - `started_at >= week_start_utc`
    - `started_at <= week_end_utc`
    - Calculated per company timezone (e.g., PST, EST)
    - One week = Monday 00:00 to Sunday 23:59 (local time)

4. **PBX Account** (Per company)
    - `company_pbx_account_id = <pbx_account_id>`
    - A company may have multiple PBX accounts
    - Each report is per PBX account

5. **Server** (Optional, when present)
    - `server_id = <server_id>` (if server_id not null)
    - If PBX account spans multiple servers, separate reports per server
    - If server_id is null for a call, match null in report

6. **Unassigned Only**
    - `weekly_call_report_id IS NULL`
    - CRITICAL: Only lock calls that haven't been assigned yet
    - Prevents reassignment, which would corrupt historical reports

---

## Assignment Logic

### Normal Flow (Weekly job)

```php
// Select calls to assign
$affectedRows = DB::table('calls')
    ->where('company_id', $companyId)
    ->where('company_pbx_account_id', $pbxAccountId)
    ->where('status', 'answered')           // ✅ Only completed
    ->whereDate('started_at', '>=', $start)  // ✅ Date range
    ->whereDate('started_at', '<=', $end)
    ->whereNull('weekly_call_report_id')     // ✅ Not yet assigned
    ->where('server_id', $serverId)         // ✅ Server match
    ->update(['weekly_call_report_id' => $reportId]);
```

**Result:** All matching calls are now "frozen" to this report.

---

### Regeneration Flow (Re-run for a past week)

When regenerating a report for a past week:

```php
// IF regenerating (date bounds provided):
// 1. RESET all calls in that date range
DB::table('calls')
    ->where('company_id', $companyId)
    ->whereBetween('started_at', [$from, $to])
    ->update(['weekly_call_report_id' => null]);

// 2. RECALCULATE the report
// 3. RE-ASSIGN calls with the new report_id
```

**Why reset first?**

- Allows correcting categorization or other data changes
- Old report still exists (immutable)
- New report gets recalculated data
- Calls now point to new report

**Safety:** Only resets calls in the specified date range, never touches other weeks.

---

## Immutability Guarantee

### What CANNOT change:

- ❌ A call cannot be moved from one report to another (except during full reset)
- ❌ A call cannot be in two reports at once
- ❌ A call's basic data (from, to, duration, transcript) after report lock
- ❌ Historical reports once finalized

### What CAN change:

- ✅ Call categorization (category_id, sub_category_id, category_source)
    - These are metadata, not locked to report
    - Reports read current category values at view time
- ✅ Server or PBX account settings (for future reports)
- ✅ Report metadata (summary stats, averages, top DIDs)

---

## Database Constraints

```sql
-- Call to Report relationship
ALTER TABLE calls
ADD CONSTRAINT fk_calls_weekly_report
FOREIGN KEY (weekly_call_report_id)
REFERENCES weekly_call_reports(id)
ON DELETE SET NULL;  -- If report deleted, call becomes unassigned
```

**Behavior:**

- If a report is deleted, calls revert to `weekly_call_report_id = NULL`
- On next weekly run, these calls will be re-assigned to a new report
- Soft deletes recommended for audit trail

---

## Query Performance

### Indexes to add:

```sql
CREATE INDEX idx_calls_company_status ON calls(company_id, status);
CREATE INDEX idx_calls_started_at ON calls(started_at);
CREATE INDEX idx_calls_pbx_account ON calls(company_pbx_account_id);
CREATE INDEX idx_calls_report_id ON calls(weekly_call_report_id);
CREATE INDEX idx_calls_unassigned ON calls(weekly_call_report_id)
  WHERE weekly_call_report_id IS NULL;
```

**Impact:**

- Assignment query processes 2000+ calls in chunks
- Bulk update with indexed columns runs in ~100ms

---

## Monitoring & Audit

### Log Entries (per report):

```
[INFO] Assigned calls to weekly report
  report_id: 456
  company_id: 1
  company_pbx_account_id: 10
  week_start: 2026-01-27
  week_end: 2026-02-02
  affected_rows: 127  ← How many calls locked
```

### Query to verify:

```sql
-- All calls in a report
SELECT COUNT(*)
FROM calls
WHERE weekly_call_report_id = 456;

-- Unassigned calls
SELECT COUNT(*)
FROM calls
WHERE weekly_call_report_id IS NULL
  AND company_id = 1
  AND status = 'answered';
```

---

## Edge Cases

### Case 1: Call arrives after report generated

**Scenario:** Call completes at 11:59 PM Monday, report runs at 1 AM Tuesday

**Solution:**

- Call has `started_at` on Monday
- Next week's job won't touch it (wrong date range)
- Next report run will include it (looks at all unassigned in its week)

### Case 2: Duplicate calls (same transcript, same time)

**Scenario:** Webhook fires twice, two identical calls created

**Solution:**

- Both get unique call IDs
- Both get assigned to same report
- Reports count both (business logic: more accurate to count duplicates than lose calls)
- Can be cleaned up separately via duplicate detection

### Case 3: PBX account deleted

**Scenario:** A company deletes a PBX account

**Solution:**

- Calls keep `company_pbx_account_id` value
- Reports already generated still reference deleted account
- Future reports won't include these calls (account_id won't match active accounts)
- Historical reports remain valid

---

## Testing Checklist

- [ ] Call with `status = 'answered'` is assigned ✅
- [ ] Call with `status = 'missed'` is NOT assigned ✅
- [ ] Call outside date range is NOT assigned ✅
- [ ] Call without transcript is still assigned ✅
- [ ] Call already assigned is NOT reassigned ✅
- [ ] Regeneration resets only specified date range ✅
- [ ] Multiple PBX accounts per company work correctly ✅
- [ ] Server matching works (null and non-null) ✅
- [ ] Report has correct call count ✅
- [ ] Report aggregation reads current categories ✅

---

## Future Improvements

1. **Materialized metrics** - Store computed category counts in report metadata
2. **Call versioning** - Track when call categorization changes
3. **Report snapshots** - Store call data at report time, not at view time
4. **Audit table** - Log all report_id changes for compliance
