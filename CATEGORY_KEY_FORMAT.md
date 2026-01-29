# Category Key Format Reference

## Overview

Throughout the system, categories are identified using a **composite key format** to balance uniqueness, traceability, and readability:

```
format: "id|name"
example: "1|Property Enquiry"
         │  └─ Human-readable name (display in UI)
         └───── Database ID (ensures uniqueness)
```

---

## Why This Format?

### Problem 1: ID-only Keys Are Not Readable

```php
$report['category_counts']['1'] = 261;  // ❌ What is category 1?
$report['category_counts']['1|Property Enquiry'] = 261;  // ✅ Clear!
```

### Problem 2: Name-only Keys Can Collide

```
Company A: "General"
Company B: "General"  // Same name, different category

// Solution: Store with ID to prevent collision
Company A: "1|General"
Company B: "2|General"  // Clearly different
```

### Solution: Composite Key

✅ Readable in reports and logs
✅ Unique across system
✅ Traceable to database
✅ Easy to parse

---

## Where It's Used

### 1. Database - metrics JSON Column

```json
{
    "category_counts": {
        "1|Property Enquiry": 261,
        "2|Maintenance Request": 13,
        "3|Other": 66
    }
}
```

### 2. Job - Accumulation

```php
$categoryKey = $categoryId . '|' . $categoryName;
$accumulators[$key]['category_counts'][$categoryKey]++;
$accumulators[$key]['category_breakdowns'][$categoryKey] = [
  'sub_categories' => [
    '10|Availability/Pricing' => 161,
    '11|Viewing/Inspection' => 41
  ]
];
```

### 3. API Response

```json
{
    "category_breakdowns": {
        "counts": {
            "1|Property Enquiry": 261
        },
        "details": {
            "1|Property Enquiry": {
                "count": 261,
                "sub_categories": {
                    "10|Availability/Pricing": 161
                }
            }
        }
    }
}
```

### 4. Vue Component - Display

```vue
<template>
    <!-- Raw key from API -->
    {{ category }}
    <!-- Output: "1|Property Enquiry" -->

    <!-- Extract name for display -->
    {{ category.split("|")[1] }}
    <!-- Output: "Property Enquiry" -->

    <!-- Extract ID for logic -->
    {{ category.split("|")[0] }}
    <!-- Output: "1" -->
</template>
```

---

## How to Parse

### JavaScript / TypeScript

```javascript
const categoryKey = "1|Property Enquiry";

// Get ID
const id = categoryKey.split("|")[0]; // "1"

// Get name
const name = categoryKey.split("|")[1]; // "Property Enquiry"

// Or with destructuring
const [id, name] = categoryKey.split("|");

// Safe with fallback
const [id, name] = (categoryKey + "|").split("|").slice(0, 2);
```

### PHP

```php
$categoryKey = "1|Property Enquiry";

// Split
[$categoryId, $categoryName] = explode('|', $categoryKey, 2);

// Get ID
$categoryId = (int) $categoryId;  // 1

// Get name
echo $categoryName;  // "Property Enquiry"

// Safe with fallback
[$id, $name] = explode('|', $categoryKey, 2) + [null, null];
if ($id === null) return; // Handle error
```

### SQL

```sql
-- Extract ID from JSON key
SELECT
  JSON_EXTRACT(metrics, '$.category_counts') as counts,
  JSON_KEYS(metrics->'$.category_counts') as category_keys
FROM weekly_call_reports;

-- Would show: ["1|Property Enquiry", "2|Maintenance Request", ...]
```

---

## Format Rules

### ID Part

- Type: Integer (stored as string in key)
- Source: `call_categories.id` or `sub_categories.id`
- Required: Yes
- Format: Numeric string, no leading zeros

### Separator

- Character: `|` (pipe)
- Not escaped
- Fixed position: Always between ID and name

### Name Part

- Type: String
- Source: `call_categories.name` or `sub_categories.name`
- Required: Yes
- Trimmed: Yes (leading/trailing whitespace removed)
- Max length: Variable (no enforced limit)

### Full Format

```
"12|Sub Category Name With Spaces and Special Chars (é)"
 ↑         ↑
 ID        Name (can contain anything except the ID is always numeric)
```

---

## Examples Throughout System

### Generation (GenerateWeeklyPbxReportsJob.php)

```php
// Read from joined tables
$categoryId = $call->category_id;           // 1 (int)
$categoryName = $call->category_name;       // "Property Enquiry" (string)

// Create key
$categoryKey = $categoryId . '|' . $categoryName;  // "1|Property Enquiry"

// Use as accumulator key
$accumulators[$key]['category_counts'][$categoryKey] = 261;
```

### Storage (weekly_call_reports.metrics JSON)

```json
{
    "category_counts": {
        "1|Property Enquiry": 261
    }
}
```

### API (AdminWeeklyCallReportsController.php)

```php
$metrics = $report->metrics ?? [];
return response()->json([
  'data' => [
    'category_breakdowns' => [
      'counts' => $metrics['category_counts'] ?? [],  // Keys stay as "1|Property Enquiry"
      'details' => $metrics['category_breakdowns'] ?? [],
    ]
  ]
]);
```

### Display (ReportDetailView.vue)

```vue
<script setup>
const categoryCountsArray = computed(() => {
    return Object.entries(reportData.value.category_breakdowns.counts)
        .map(([category, count]) => {
            // Extract name from "1|Property Enquiry"
            const categoryName = category.includes("|")
                ? category.split("|")[1]
                : category;

            return {
                category: categoryName, // Display: "Property Enquiry"
                count: count,
                percentage: ((count / total) * 100).toFixed(1),
            };
        })
        .sort((a, b) => b.count - a.count);
});
</script>

<template>
    <table>
        <tr v-for="cat in categoryCountsArray">
            <td>{{ cat.category }}</td>
            <!-- Output: "Property Enquiry" (no ID) -->
            <td>{{ cat.count }}</td>
        </tr>
    </table>
</template>
```

---

## Edge Cases

### What if ID has leading zeros?

```
Category ID from DB: 01
Key: "01|Property Enquiry"
Parse: split("|")[0] = "01"
Cast to int: (int) "01" = 1  // ✓ Safe, leading zeros stripped
```

### What if name contains pipe character?

```
Name: "Category | Subcategory"
Key: "1|Category | Subcategory"
Split: explode('|', key, 2) = ["1", "Category | Subcategory"]  // ✓ Safe with limit=2
```

### What if name is empty?

```
$categoryId = 1;
$categoryName = '';  // Empty!

// Job checks before accumulating:
if ($categoryId !== null && $categoryName !== '') {
    $categoryKey = $categoryId . '|' . $categoryName;  // Only if both exist
}
// ✓ Prevents "1|" keys
```

### What if name has SQL special chars?

```
Name: "O'Reilly's Info; DROP TABLE"
Key: "1|O'Reilly's Info; DROP TABLE"
// ✓ Stored as JSON string (already escaped)
// ✓ Safe to display in Vue (auto-escaped)
// ✓ No SQL risk (pulled from DB, not user input)
```

---

## Testing the Format

### Check a Report's Actual Keys

```bash
php artisan tinker
>>> $report = App\Models\WeeklyCallReport::with('metrics')->latest()->first();
>>> foreach ($report->metrics['category_counts'] as $key => $count) {
...   echo "Key: $key => Count: $count\n";
... }

# Expected output:
# Key: 1|Property Enquiry => Count: 261
# Key: 2|Maintenance Request => Count: 13
# Key: 3|Other => Count: 66
```

### Parse Keys Safely

```bash
php artisan tinker
>>> foreach ($report->metrics['category_counts'] as $key => $count) {
...   [$id, $name] = explode('|', $key, 2) + [null, null];
...   echo "ID: $id, Name: $name, Count: $count\n";
... }

# Output:
# ID: 1, Name: Property Enquiry, Count: 261
# ID: 2, Name: Maintenance Request, Count: 13
# ID: 3, Name: Other, Count: 66
```

---

## Migration Notes

If you ever need to change this format:

1. **Old format (if any):** Pure string names (no IDs)
2. **New format:** "id|name" composite keys
3. **Backward compatibility:** Vue checks `category.includes("|")` to handle both
4. **Data migration:** Would need to update all metrics JSON in reports

---

## Summary

| Aspect          | Value                                                  |
| --------------- | ------------------------------------------------------ |
| Format          | `id\|name`                                             |
| Separator       | Pipe (`\|`)                                            |
| ID              | From database (int, stored as string)                  |
| Name            | From database (string)                                 |
| Example         | `1\|Property Enquiry`                                  |
| Parse in JS     | `key.split("\|")[0]` (ID), `key.split("\|")[1]` (name) |
| Parse in PHP    | `explode('\|', $key, 2)` → `[$id, $name]`              |
| Display in Vue  | `key.includes("\|") ? key.split("\|")[1] : key`        |
| Immutable?      | Yes (set once during report generation)                |
| Case-sensitive? | Yes (depends on database collation)                    |
