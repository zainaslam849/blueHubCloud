# Weekly Report Category Integration

## Overview

Weekly reports now include **category metrics** based on stored call categorization data. The system uses **only stored values** from the `calls` table and **never re-runs AI** or modifies calls during report generation.

## What's Included

### 1. Category Counts

Simple count of calls per category in the reporting period.

**Location:** `weekly_call_reports.metadata->category_counts`

**Structure:**

```json
[
    {
        "category_id": 5,
        "category_name": "Support",
        "call_count": 142
    },
    {
        "category_id": 3,
        "category_name": "Sales",
        "call_count": 87
    }
]
```

### 2. Category Breakdowns

Detailed metrics for each category including sub-categories, sources, and confidence.

**Location:** `weekly_call_reports.metadata->category_breakdowns`

**Structure:**

```json
[
    {
        "category_id": 5,
        "category_name": "Support",
        "total_calls": 142,
        "total_duration_seconds": 85200,
        "avg_confidence": 0.89,
        "sources": {
            "ai": 120,
            "manual": 15,
            "default": 7
        },
        "sub_categories": [
            {
                "id": 12,
                "name": "Technical Issue",
                "count": 78
            },
            {
                "id": 13,
                "name": "Account Question",
                "count": 45
            },
            {
                "id": null,
                "name": "Password Reset",
                "count": 19
            }
        ]
    }
]
```

## Data Sources

### From `calls` Table (Read-Only)

- `category_id` - Foreign key to call_categories
- `sub_category_id` - Foreign key to sub_categories (nullable)
- `sub_category_label` - Text label when no sub_category_id
- `category_source` - Source of categorization (ai/manual/default)
- `category_confidence` - AI confidence score (0-1)

### From `call_categories` Table

- Category names resolved via join

### From `sub_categories` Table

- Sub-category names resolved via join

## Implementation Details

### Service: `WeeklyReportAggregationService`

**Updated Query:**

```php
$query = DB::table('calls')
    ->select([
        'id',
        'company_id',
        'duration_seconds',
        'status',
        'from',
        'to',
        'started_at',
        'category_id',           // NEW
        'sub_category_id',       // NEW
        'sub_category_label',    // NEW
        'category_source',       // NEW
        'category_confidence',   // NEW
    ])
    ->where('company_id', $companyId);
```

**Accumulation Logic:**

- Tracks `category_counts` (category_id => count)
- Tracks `category_details` with:
    - Total calls per category
    - Total duration per category
    - Sub-category distribution
    - Source distribution (ai/manual/default)
    - Confidence sum for averaging

**Processing:**

- Loads category names from `call_categories` table
- Loads sub-category names from `sub_categories` table
- Sorts categories by call count (descending)
- Sorts sub-categories by count within each category

### Key Methods

#### `buildCategoryMetrics()`

Transforms accumulated category data into structured metrics:

1. Loads category and sub-category names from database
2. Builds `category_counts` array sorted by volume
3. Builds `category_breakdowns` with detailed stats
4. Calculates average confidence per category
5. Formats sub-categories (handles both IDs and labels)

**Returns:**

```php
[
    'category_counts' => [...],
    'category_breakdowns' => [...]
]
```

## Guarantees

### ✅ Read-Only Operations

- **Never modifies** call records
- **Never updates** category_id or related fields
- **Never triggers** AI categorization

### ✅ Uses Stored Values Only

- Reads category_id from calls table
- No recalculation or re-categorization
- Empty categories if no stored data

### ✅ Handles Missing Data

- Uncategorized calls (category_id = null) are excluded from metrics
- Sub-category labels without IDs are preserved
- Unknown category IDs show as "Unknown (ID: X)"

## API Access

### Endpoint: `GET /admin/api/weekly-call-reports/{id}`

**Response includes:**

```json
{
  "id": 123,
  "company_id": 1,
  "reporting_period_start": "2026-01-20",
  "reporting_period_end": "2026-01-26",
  "total_calls": 456,
  "metadata": {
    "short_calls_count": 23,
    "category_counts": [...],
    "category_breakdowns": [...]
  }
}
```

## Usage Examples

### Example 1: Accessing Category Metrics

```php
use App\Models\WeeklyCallReport;

$report = WeeklyCallReport::find(123);
$metadata = $report->metadata; // Auto-decoded JSON

// Get category counts
$categoryCounts = $metadata['category_counts'] ?? [];
foreach ($categoryCounts as $item) {
    echo "{$item['category_name']}: {$item['call_count']} calls\n";
}

// Get detailed breakdown
$breakdowns = $metadata['category_breakdowns'] ?? [];
foreach ($breakdowns as $breakdown) {
    echo "\n{$breakdown['category_name']}:\n";
    echo "  Total Calls: {$breakdown['total_calls']}\n";
    echo "  Avg Duration: " . ($breakdown['total_duration_seconds'] / $breakdown['total_calls']) . "s\n";
    echo "  Avg Confidence: {$breakdown['avg_confidence']}\n";

    echo "  Sources:\n";
    foreach ($breakdown['sources'] as $source => $count) {
        echo "    - {$source}: {$count}\n";
    }

    echo "  Sub-Categories:\n";
    foreach ($breakdown['sub_categories'] as $sub) {
        echo "    - {$sub['name']}: {$sub['count']}\n";
    }
}
```

### Example 2: Frontend Display

```javascript
// Fetch report
const report = await fetch("/admin/api/weekly-call-reports/123").then((r) =>
    r.json(),
);
const metadata = report.data.metadata;

// Display category breakdown
metadata.category_breakdowns.forEach((cat) => {
    console.log(`${cat.category_name}: ${cat.total_calls} calls`);
    console.log(`  Avg Confidence: ${(cat.avg_confidence * 100).toFixed(0)}%`);

    // Show top sub-categories
    cat.sub_categories.slice(0, 3).forEach((sub) => {
        console.log(`    - ${sub.name}: ${sub.count}`);
    });
});
```

### Example 3: Dashboard Widget

```vue
<template>
    <div class="category-breakdown">
        <h3>Call Categories This Week</h3>
        <div
            v-for="cat in categoryCounts"
            :key="cat.category_id"
            class="category-item"
        >
            <div class="category-name">{{ cat.category_name }}</div>
            <div class="category-count">{{ cat.call_count }}</div>
            <div
                class="category-bar"
                :style="{ width: (cat.call_count / totalCalls) * 100 + '%' }"
            ></div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps(["report"]);
const categoryCounts = computed(
    () => props.report.metadata?.category_counts || [],
);
const totalCalls = computed(() => props.report.total_calls);
</script>
```

## Migration Path

### For Existing Reports

Old reports generated before this update will have:

- `metadata.category_counts` = `[]` (empty)
- `metadata.category_breakdowns` = `[]` (empty)

**To populate historical data:**

```bash
php artisan reports:regenerate --from=2026-01-01 --to=2026-01-31
```

### For New Reports

All new weekly reports generated after this update will automatically include category metrics if:

- Calls have `category_id` set
- Categories exist in `call_categories` table

## Performance Notes

- Category lookups use `whereIn()` with indexed IDs (fast)
- Sub-category lookups are batched (single query per report)
- Data is pre-aggregated during weekly report generation
- No real-time calculation needed for dashboards

## Testing Checklist

- [ ] Generate report with categorized calls
- [ ] Verify category_counts array in metadata
- [ ] Verify category_breakdowns includes all metrics
- [ ] Test with calls having sub_category_id
- [ ] Test with calls having sub_category_label only
- [ ] Test with mixed sources (ai/manual/default)
- [ ] Test with uncategorized calls (category_id = null)
- [ ] Verify no call modifications during generation
- [ ] Verify no AI calls during generation
- [ ] Test report regeneration for existing periods

## Next Steps

1. **Frontend Dashboard**: Display category metrics in weekly report view
2. **Export Enhancement**: Include category breakdowns in PDF/Excel exports
3. **Trend Analysis**: Track category changes week-over-week
4. **Alerting**: Notify when category distribution changes significantly
