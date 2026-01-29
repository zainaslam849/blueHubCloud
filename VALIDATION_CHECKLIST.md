# STEP 2 & 3 Validation Checklist

## Pre-Testing

- [ ] Database migrations applied

    ```bash
    php artisan migrate --path=database/migrations/2026_01_28_120000_add_ai_categorization_to_calls_table.php
    php artisan migrate --path=database/migrations/2026_01_28_150000_remove_old_category_columns.php
    ```

- [ ] Categories seeded

    ```bash
    php artisan db:seed --class=CallCategoriesSeeder
    ```

- [ ] Calls have category_id populated (from Phase 10)
    ```bash
    SELECT COUNT(*) FROM calls WHERE category_id IS NOT NULL;
    ```

---

## Code Validation

### GenerateWeeklyPbxReportsJob.php Changes

- [ ] **baseCallsQuery()**
    - [ ] Uses LEFT JOIN to call_categories
    - [ ] Uses LEFT JOIN to sub_categories
    - [ ] Selects category_id, sub_category_id, category_name, sub_category_name
    - [ ] Still filters by status='answered'

- [ ] **Aggregation Loop (lines 170-200)**
    - [ ] Builds category keys as "id|name"
    - [ ] Handles NULL category gracefully
    - [ ] Groups sub-categories under category keys
    - [ ] Accumulates DID counts
    - [ ] Accumulates hourly distribution

- [ ] **fetchSampleCallsByCategory()**
    - [ ] Accepts category keys in "id|name" format
    - [ ] Parses keys with explode('|', $key, 2)
    - [ ] Queries by category_id (not string)
    - [ ] Returns sample calls properly

- [ ] **generateInsights()**
    - [ ] Extracts category names from "id|name" keys
    - [ ] Uses readableized names in opportunities
    - [ ] Calls getTopSubCategory() with correct key format

- [ ] **generateExecutiveSummary()**
    - [ ] Extracts top category name from "id|name" key
    - [ ] Generates proper narrative text
    - [ ] Handles zero-call case

- [ ] **getTopSubCategory()**
    - [ ] Extracts sub-category names from "id|name" keys
    - [ ] Returns percentage calculations
    - [ ] Handles empty sub-categories

### AdminWeeklyCallReportsController.php

- [ ] **show() method**
    - [ ] Fetches report with relationships
    - [ ] Returns metrics JSON as-is
    - [ ] Extracts category data from metrics
    - [ ] Computes answer_rate properly
    - [ ] Returns proper API shape

### ReportDetailView.vue

- [ ] **Script Setup**
    - [ ] Defines ReportData interface
    - [ ] Uses ref for loading/error states
    - [ ] Implements onMounted with API fetch
    - [ ] Fetches from `/admin/api/weekly-call-reports/{id}`

- [ ] **Computed Properties**
    - [ ] categoryCountsArray: Sorts by count descending
    - [ ] afterHoursPercentage: Calculates non-9-to-5
    - [ ] peakHours: Filters hours > 10% of total

- [ ] **Template - Executive Summary**
    - [ ] Shows header with company name
    - [ ] Shows week range
    - [ ] Shows executive_summary text

- [ ] **Template - Key Metrics**
    - [ ] 6 metric cards in grid
    - [ ] Total Calls
    - [ ] Answer Rate (%)
    - [ ] After-Hours (%)
    - [ ] Missed Calls
    - [ ] Avg Duration
    - [ ] Transcribed Calls

- [ ] **Template - Category Analysis**
    - [ ] Category counts table (sorted)
    - [ ] Sub-category cards for each category
    - [ ] Sample calls table with 4 columns

- [ ] **Template - Time Analysis**
    - [ ] Hourly distribution table (0-23)
    - [ ] Peak hours display (>10%)

- [ ] **Template - Top DIDs**
    - [ ] Shows top 10 DIDs by call volume

- [ ] **Template - Insights**
    - [ ] Recommendations section (if present)
    - [ ] Opportunities section (if present)

- [ ] **Styling**
    - [ ] Professional color scheme (#3995c6 primary)
    - [ ] Responsive grid layout
    - [ ] Table styling (dark headers, alternating rows)
    - [ ] Metric cards with gradient background

---

## Runtime Testing

### Manual Test Steps

1. **Generate Report**

    ```bash
    php artisan tinker
    >>> dispatch(new \App\Jobs\GenerateWeeklyPbxReportsJob());
    >>> exit
    ```

    Expected: No errors, report created/updated

2. **Verify Database**

    ```bash
    SELECT
      id,
      company_id,
      week_start_date,
      total_calls,
      JSON_KEYS(metrics) as metric_keys
    FROM weekly_call_reports
    ORDER BY id DESC
    LIMIT 1;
    ```

    Expected:
    - total_calls matches actual answered calls count
    - metrics has keys: category_counts, category_breakdowns, top_dids, hourly_distribution, insights

3. **Check Category Format**

    ```bash
    SELECT
      JSON_KEYS(metrics->'$.category_counts') as categories
    FROM weekly_call_reports
    ORDER BY id DESC
    LIMIT 1;
    ```

    Expected: `["1|Property Enquiry", "2|Maintenance Request", ...]`

4. **View Report in Browser**

    ```
    http://localhost:8000/admin/weekly-call-reports/1
    ```

    Check Network tab for API request:

    ```
    GET /admin/api/weekly-call-reports/1
    ```

    Expected: 200 OK, returns full report data

5. **Verify All Sections Render**
    - [ ] Executive Summary (text visible)
    - [ ] 6 Metric cards (with numbers)
    - [ ] Category table (names visible, no IDs)
    - [ ] Sub-category cards
    - [ ] Sample calls (transcripts visible)
    - [ ] Hourly distribution table
    - [ ] Peak hours (if present)
    - [ ] Top DIDs table
    - [ ] Recommendations (if present)
    - [ ] Opportunities (if present)

6. **Inspect Page Elements**
    - [ ] No console errors
    - [ ] All category names displayed without IDs
    - [ ] Sample transcripts truncated at 300 chars
    - [ ] Percentages calculated correctly
    - [ ] Dates formatted properly

---

## Data Integrity Checks

### Category Keys

```bash
php artisan tinker
>>> $r = App\Models\WeeklyCallReport::latest()->first();
>>> foreach ($r->metrics['category_counts'] as $k => $v) {
...   echo "$k => $v\n";
... }
```

Expected output:

```
1|Property Enquiry => 261
2|Maintenance Request => 13
3|Other => 66
```

❌ NOT acceptable:

```
Property Enquiry => 261  (no ID)
1 => 261  (no name)
Property Enquiry|1 => 261  (reversed)
```

### Sample Calls

```bash
php artisan tinker
>>> $r = App\Models\WeeklyCallReport::latest()->first();
>>> $samples = $r->metrics['category_breakdowns']['1|Property Enquiry']['sample_calls'] ?? [];
>>> echo "Sample count: " . count($samples) . "\n";
>>> if ($samples) { echo $samples[0]['transcript']; }
```

Expected:

- Sample count: 3-5
- Transcript: Actual call transcript (not null, not empty)
- Date: ISO8601 format
- DID/src: Phone numbers or empty string

### Insights

```bash
php artisan tinker
>>> $r = App\Models\WeeklyCallReport::latest()->first();
>>> echo "AI opportunities: " . count($r->metrics['insights']['ai_opportunities']) . "\n";
>>> echo "Recommendations: " . count($r->metrics['insights']['recommendations']) . "\n";
>>> print_r($r->metrics['insights']['recommendations'][0] ?? 'None');
```

Expected:

- At least one recommendation for high-volume categories
- Messages are readable and actionable
- Peak hours identification working

---

## Error Scenarios

### Test Error Handling

1. **Invalid Report ID**

    ```
    GET /admin/api/weekly-call-reports/99999
    ```

    Expected: 404 response

2. **No Reports Generated**

    ```
    http://localhost:8000/admin/weekly-call-reports
    ```

    Expected: Empty list or "No reports found" message

3. **Missing Metrics**
    - Manually null metrics in DB
    - Try to load report detail page
    - Expected: Graceful display (empty sections)

---

## Performance Checks

### Report Generation Time

```bash
time php artisan tinker <<< "dispatch(new \App\Jobs\GenerateWeeklyPbxReportsJob()); exit"
```

Expected: < 30 seconds for typical dataset

### API Response Time

Open DevTools → Network tab → Click report → Check GET request time

Expected: < 500ms

### Vue Component Rendering

Open DevTools → Lighthouse → Run performance audit

Expected: > 90 score, all sections render within 1s

---

## Browser Compatibility

- [ ] Chrome/Edge (latest)
    - [ ] All sections visible
    - [ ] Tables scroll on small screens
    - [ ] No layout issues

- [ ] Firefox (latest)
    - [ ] Same as Chrome

- [ ] Safari (latest)
    - [ ] Grid layout works
    - [ ] Tables readable

- [ ] Mobile (iPhone/Android)
    - [ ] Metric cards stack to 2 columns
    - [ ] Tables have horizontal scroll
    - [ ] Text readable without zoom

---

## Final Sign-Off

After completing all checks above:

### Code Quality

- [ ] No TypeScript errors in console
- [ ] No PHP errors in logs
- [ ] No database errors
- [ ] All linting passes

### Functionality

- [ ] Report generation works
- [ ] API returns correct data shape
- [ ] UI displays all sections
- [ ] Category names display without IDs
- [ ] Calculations are correct

### User Experience

- [ ] Page loads quickly
- [ ] Professional appearance
- [ ] Responsive on all devices
- [ ] Error messages helpful
- [ ] Loading state visible

### Data Integrity

- [ ] Category keys in "id|name" format
- [ ] Metrics persist correctly
- [ ] Sample calls populated
- [ ] Insights generated

---

## Deployment Readiness

### Pre-Production Checklist

- [ ] All tests passing
- [ ] Code reviewed
- [ ] Database backups created
- [ ] Migration scripts prepared
- [ ] Rollback plan documented

### Production Deployment Steps

1. Backup production database
2. Run migrations: `php artisan migrate`
3. Run job: `php artisan tinker` → `dispatch(new GenerateWeeklyPbxReportsJob())`
4. Verify report appears: `http://prod.example.com/admin/weekly-call-reports`
5. Check logs: `tail -f storage/logs/laravel.log`

### Rollback Steps

1. Restore database from backup
2. Revert code to previous version
3. Clear caches: `php artisan cache:clear`

---

## Success Criteria

✅ Report generation completes without errors
✅ API endpoint returns valid JSON with all required fields
✅ Vue component renders all sections correctly
✅ Category names display without IDs
✅ Sample calls show actual transcripts
✅ Calculations (answer rate, after-hours %) are accurate
✅ Page is responsive and professional-looking
✅ No console errors or warnings
✅ Performance is acceptable (< 500ms API, < 1s render)
✅ Works across browsers and devices

---

**Status:** Ready for sign-off once all checks completed ✅
