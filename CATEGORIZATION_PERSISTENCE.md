# Call Categorization Persistence

## Overview

This system persists AI categorization results to the database with intelligent fallback handling and validation rules.

## Database Schema

### Calls Table (New Columns)

```sql
category_id              INT UNSIGNED NULL           -- FK to call_categories.id
sub_category_id          INT UNSIGNED NULL           -- FK to sub_categories.id
sub_category_label       VARCHAR(255) NULL           -- Text label when no sub-category ID
category_source          ENUM('ai','manual','default') -- Categorization source
category_confidence      DECIMAL(3,2) NULL           -- AI confidence (0.00-1.00)
categorized_at           TIMESTAMP NULL              -- When categorized
```

## Validation Rules

### 1. Low Confidence Handling

**Rule:** If `confidence < 0.4` → Assign to **"Other"** category

- Automatically creates "Other" category if it doesn't exist
- Logs warning with call ID and confidence score
- Returns `fallback_used: true`

### 2. Category Not Found

**Rule:** If category name not found in database → Assign to **"General"** category

- Automatically creates "General" category if it doesn't exist
- Logs warning with original category name
- Returns `fallback_used: true`

### 3. Sub-Category Not Found

**Rule:** If sub-category name provided but not found → Store as `sub_category_label`

- Stores original AI text in `sub_category_label` column
- Sets `sub_category_id` to NULL
- Logs info message for tracking
- Does NOT use fallback category

### 4. Only Enabled Categories

- Only searches **enabled** categories (`is_enabled = 1`)
- Only searches **enabled** sub-categories within the category
- Disabled items are treated as "not found"

## API Endpoints

### 1. Persist Single Categorization

**Endpoint:** `POST /admin/api/categorization/persist`

**Request:**

```json
{
    "call_id": 123,
    "category": "Support",
    "sub_category": "Technical Issue",
    "confidence": 0.92
}
```

**Success Response (200):**

```json
{
    "success": true,
    "call": {
        "id": 123,
        "category_id": 5,
        "sub_category_id": 12,
        "sub_category_label": null,
        "category_source": "ai",
        "category_confidence": 0.92,
        "categorized_at": "2026-01-28T12:34:56.000000Z",
        "category": {
            "id": 5,
            "name": "Support",
            "description": "Customer support inquiries"
        },
        "subCategory": {
            "id": 12,
            "name": "Technical Issue",
            "description": "Technical problems"
        }
    },
    "fallback_used": false,
    "reason": null
}
```

**Fallback Response (200) - Low Confidence:**

```json
{
    "success": true,
    "call": {
        "id": 123,
        "category_id": 8,
        "sub_category_id": null,
        "sub_category_label": null,
        "category_source": "ai",
        "category_confidence": 0.35,
        "categorized_at": "2026-01-28T12:34:56.000000Z",
        "category": {
            "id": 8,
            "name": "Other",
            "description": "Low confidence or unclassifiable calls"
        }
    },
    "fallback_used": true,
    "reason": "Low confidence score"
}
```

**Fallback Response (200) - Category Not Found:**

```json
{
    "success": true,
    "call": {
        "id": 123,
        "category_id": 1,
        "sub_category_id": null,
        "sub_category_label": null,
        "category_source": "ai",
        "category_confidence": 0.87,
        "categorized_at": "2026-01-28T12:34:56.000000Z",
        "category": {
            "id": 1,
            "name": "General",
            "description": "Default fallback category for unclassified calls"
        }
    },
    "fallback_used": true,
    "reason": "Category 'InvalidCategory' not found"
}
```

**Error Response (500):**

```json
{
    "success": false,
    "error": "Call not found"
}
```

**Validation Errors (422):**

```json
{
    "message": "The call id field is required.",
    "errors": {
        "call_id": ["The call id field is required."]
    }
}
```

### 2. Bulk Persist Categorizations

**Endpoint:** `POST /admin/api/categorization/bulk-persist`

**Request:**

```json
{
    "categorizations": [
        {
            "call_id": 101,
            "category": "Sales",
            "sub_category": "Pricing Inquiry",
            "confidence": 0.95
        },
        {
            "call_id": 102,
            "category": "Support",
            "sub_category": null,
            "confidence": 0.78
        },
        {
            "call_id": 103,
            "category": "Invalid",
            "sub_category": null,
            "confidence": 0.65
        }
    ]
}
```

**Response (200):**

```json
{
  "success_count": 3,
  "failed_count": 0,
  "results": [
    {
      "success": true,
      "call": { ... },
      "fallback_used": false,
      "reason": null
    },
    {
      "success": true,
      "call": { ... },
      "fallback_used": false,
      "reason": null
    },
    {
      "success": true,
      "call": { ... },
      "fallback_used": true,
      "reason": "Category 'Invalid' not found"
    }
  ]
}
```

## Usage Examples

### Example 1: Standard Flow (AI → Validate → Persist)

```php
use App\Services\CallCategorizationPromptService;
use App\Services\CallCategorizationPersistenceService;

// 1. Build prompt for call
$prompt = CallCategorizationPromptService::buildPromptObject(
    transcript: $call->transcript_text,
    direction: $call->direction,
    status: $call->status,
    duration: $call->duration_seconds,
    isAfterHours: $call->is_after_hours
);

// 2. Call OpenAI/Claude API
$aiResponse = $openai->chat()->create([
    'model' => 'gpt-4',
    'messages' => [
        ['role' => 'system', 'content' => $prompt['system']],
        ['role' => 'user', 'content' => $prompt['user']],
    ],
    'temperature' => 0.1,
    'max_tokens' => 150,
]);

$aiResult = json_decode($aiResponse->choices[0]->message->content, true);

// 3. Validate response (optional but recommended)
$validation = CallCategorizationPromptService::validateCategorization($aiResult);

if (!$validation['valid']) {
    throw new \Exception('Invalid AI response: ' . $validation['error']);
}

// 4. Persist to database
$result = CallCategorizationPersistenceService::persistCategorization(
    callId: $call->id,
    categoryName: $aiResult['category'],
    subCategoryName: $aiResult['sub_category'] ?? null,
    confidence: $aiResult['confidence']
);

if ($result['fallback_used']) {
    Log::warning('Fallback category used', [
        'call_id' => $call->id,
        'reason' => $result['reason'],
    ]);
}
```

### Example 2: Bulk Processing

```php
use App\Services\CallCategorizationPersistenceService;

$categorizations = [
    ['call_id' => 1, 'category' => 'Sales', 'sub_category' => 'Demo Request', 'confidence' => 0.95],
    ['call_id' => 2, 'category' => 'Support', 'sub_category' => null, 'confidence' => 0.82],
    ['call_id' => 3, 'category' => 'General', 'sub_category' => null, 'confidence' => 0.45],
];

$result = CallCategorizationPersistenceService::bulkPersist($categorizations);

echo "Success: {$result['success_count']}, Failed: {$result['failed_count']}";
```

### Example 3: Via HTTP API

```javascript
// Single categorization
const response = await fetch("/admin/api/categorization/persist", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
        call_id: 123,
        category: "Sales",
        sub_category: "Pricing Inquiry",
        confidence: 0.92,
    }),
});

const result = await response.json();

if (result.fallback_used) {
    console.warn("Fallback category used:", result.reason);
}

// Bulk categorization
const bulkResponse = await fetch("/admin/api/categorization/bulk-persist", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
        categorizations: [
            {
                call_id: 101,
                category: "Sales",
                sub_category: "Demo",
                confidence: 0.95,
            },
            {
                call_id: 102,
                category: "Support",
                sub_category: null,
                confidence: 0.78,
            },
        ],
    }),
});

const bulkResult = await bulkResponse.json();
console.log(`Processed ${bulkResult.success_count} calls`);
```

## Logging

All persistence operations log to Laravel's default log channel:

### Info Logs

- Created "General" or "Other" fallback categories
- Sub-category not found (stored as label)

### Warning Logs

- Fallback category used (with reason)
- Low confidence categorization

### Error Logs

- Failed persistence operations (with exception details)

## Database Transactions

All persistence operations are wrapped in database transactions:

- Successful operations are committed automatically
- Failed operations are rolled back
- No partial data persisted on errors

## Model Relationships

```php
// In Call model
public function category(): BelongsTo
{
    return $this->belongsTo(CallCategory::class, 'category_id');
}

public function subCategory(): BelongsTo
{
    return $this->belongsTo(SubCategory::class, 'sub_category_id');
}

// Usage
$call = Call::with(['category', 'subCategory'])->find(123);
echo $call->category->name; // "Support"
echo $call->subCategory->name; // "Technical Issue"
```

## Testing Checklist

- [ ] Persist with valid category and sub-category
- [ ] Persist with valid category, no sub-category
- [ ] Persist with confidence < 0.4 (should use "Other")
- [ ] Persist with non-existent category (should use "General")
- [ ] Persist with valid category, non-existent sub-category (should store as label)
- [ ] Persist with disabled category (should use "General")
- [ ] Bulk persist multiple calls
- [ ] Verify database transactions rollback on error
- [ ] Verify logging for all fallback scenarios
- [ ] Verify relationships load correctly

## Next Steps

1. **Admin UI**: Display categorization results in call detail page
2. **Manual Override**: Allow admins to manually change categories
3. **Metrics Dashboard**: Track categorization accuracy and confidence distribution
4. **Automated Jobs**: Create scheduled job to categorize uncategorized calls
5. **Re-categorization**: Add endpoint to re-categorize existing calls with new AI model
