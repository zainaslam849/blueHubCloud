# AI Call Categorization System

## Overview

The AI Call Categorization System dynamically generates prompts for AI services to classify phone calls into admin-defined categories and sub-categories. The system ensures that:

- Only **enabled categories** are presented to the AI
- Only **enabled sub-categories** are available per category
- The AI cannot invent new categories
- Responses are validated against the current admin configuration

## API Endpoints

### 1. Get Enabled Categories with Sub-Categories

**Endpoint:** `GET /admin/api/categorization/enabled-categories`

**Purpose:** Fetch the current enabled categories and their sub-categories for display or API documentation.

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Sales Inquiry",
            "description": "Sales-related calls",
            "sub_categories": [
                {
                    "id": 1,
                    "name": "Product Questions",
                    "description": "Questions about products"
                },
                {
                    "id": 2,
                    "name": "Pricing",
                    "description": "Pricing inquiries"
                }
            ]
        },
        {
            "id": 2,
            "name": "Support",
            "description": "Customer support calls",
            "sub_categories": [
                {
                    "id": 3,
                    "name": "Technical Issues",
                    "description": "Technical support"
                }
            ]
        }
    ]
}
```

### 2. Get AI System Prompt and Model Parameters

**Endpoint:** `GET /admin/api/categorization/prompt`

**Purpose:** Get the system prompt and model parameters for AI API configuration.

**Response:**

```json
{
  "system_prompt": "You are a phone call classification engine...",
  "model_parameters": {
    "temperature": 0.1,
    "max_tokens": 150
  },
  "categories": [
    {
      "id": 1,
      "name": "Sales Inquiry",
      "sub_categories": [...]
    }
  ]
}
```

### 3. Build Dynamic Prompt for a Specific Call

**Endpoint:** `POST /admin/api/categorization/build-prompt`

**Request Body:**

```json
{
    "transcript": "The call transcript text here...",
    "direction": "inbound",
    "status": "completed",
    "duration": 180,
    "is_after_hours": false
}
```

**Optional Parameters:**

- `direction`: "inbound" or "outbound" (default: "inbound")
- `status`: "completed", "missed", or "failed" (default: "completed")
- `duration`: Duration in seconds (default: 0)
- `is_after_hours`: Boolean (default: false)

**Response:**

```json
{
    "data": {
        "system": "You are a phone call classification engine...",
        "user": "AVAILABLE CATEGORIES:\n- Sales Inquiry\n  - Product Questions\n  - Pricing\n- Support\n  - Technical Issues\n\nCALL CONTEXT:\n- Direction: inbound\n- Status: completed\n- Duration: 3m 0s\n- After hours: No\n\nTRANSCRIPT:\n\"\"\"\nThe call transcript...\n\"\"\"\n\nRULES:\n1. If status is \"missed\"...",
        "model_parameters": {
            "temperature": 0.1,
            "max_tokens": 150
        }
    }
}
```

### 4. Validate AI Categorization Response

**Endpoint:** `POST /admin/api/categorization/validate`

**Request Body:**

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Product Questions",
    "confidence": 0.95
}
```

**Response (Success - 200):**

```json
{
    "valid": true,
    "category_id": 1,
    "sub_category_id": 1,
    "confidence": 0.95
}
```

**Response (Error - 400):**

```json
{
    "valid": false,
    "error": "Category 'Invalid Category' not found or disabled"
}
```

## Usage Flow

### For an External AI Service Integration

1. **Initialize:** Call `GET /admin/api/categorization/prompt` to get the system prompt and model parameters
2. **Build Prompt:** Call `POST /admin/api/categorization/build-prompt` with the call transcript and metadata
3. **Call AI API:** Send the `system` and `user` prompts to your AI service with the `model_parameters`
4. **Validate Response:** Call `POST /admin/api/categorization/validate` with the AI's JSON response
5. **Store Result:** Save the validated `category_id` and `sub_category_id` with the call record

### Example Integration (Pseudocode)

```javascript
// 1. Get prompt template
const promptData = await fetch("/admin/api/categorization/build-prompt", {
    method: "POST",
    body: JSON.stringify({
        transcript: callTranscript,
        direction: "inbound",
        status: "completed",
        duration: callDurationInSeconds,
        is_after_hours: isAfterHours,
    }),
}).then((r) => r.json());

// 2. Call your AI service
const aiResponse = await aiService.chat({
    system: promptData.data.system,
    user: promptData.data.user,
    temperature: promptData.data.model_parameters.temperature,
    max_tokens: promptData.data.model_parameters.max_tokens,
});

// Parse JSON from AI response
const categorization = JSON.parse(aiResponse.content);

// 3. Validate
const validation = await fetch("/admin/api/categorization/validate", {
    method: "POST",
    body: JSON.stringify(categorization),
}).then((r) => r.json());

if (validation.valid) {
    // 4. Store in database
    await saveCallCategorization({
        call_id: callId,
        category_id: validation.category_id,
        sub_category_id: validation.sub_category_id,
        confidence: validation.confidence,
    });
}
```

## System Prompt Details

The system prompt instructs the AI to:

1. **Classify calls** into ONE category from the available list
2. **NOT invent** new categories
3. **Be strict** about following the admin-defined categories
4. **Return JSON only** in the specified format

**Key Rules Enforced:**

- Missed calls → Choose appropriate category for missed calls
- After-hours → Choose category suitable for after-hours handling
- Only use available categories
- Return null for sub-category if none fits

## Model Parameters

- **Temperature:** 0.1 (low, for consistent, focused categorization)
- **Max Tokens:** 150 (minimal output, just the JSON response)

## Validation Rules

The validation endpoint ensures:

1. ✅ Category exists and is **enabled**
2. ✅ Sub-category exists and is **enabled** (if provided)
3. ✅ Confidence is a number between 0 and 1
4. ✅ Response matches exact category/sub-category names

## Example: Complete Category Hierarchy

```
Sales Inquiry (enabled)
├── Product Questions (enabled)
├── Pricing (enabled)
└── Licensing (disabled) ← AI cannot select this

Support (enabled)
├── Technical Issues (enabled)
└── Billing (enabled)

General (enabled)
└── (no sub-categories)
```

In this example, the AI will only see:

- Sales Inquiry → Product Questions, Pricing
- Support → Technical Issues, Billing
- General

The disabled "Licensing" sub-category will NOT appear in the prompt.

## Response Format

The AI MUST return valid JSON:

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Product Questions",
    "confidence": 0.92
}
```

Or, if no sub-category fits:

```json
{
    "category": "General",
    "sub_category": null,
    "confidence": 0.45
}
```

## Admin Management

Admins can:

1. **Add/Edit/Delete Categories** → Available for immediate AI use
2. **Enable/Disable Categories** → Dynamically included/excluded from prompts
3. **Manage Sub-Categories** → Automatically reflected in AI prompts
4. **Change Category Names** → Existing categorizations remain valid (by ID)

Changes are **immediately reflected** in AI prompts without redeployment.

## Security Notes

- All endpoints require admin authentication (`admin` middleware)
- Only enabled categories/sub-categories are exposed
- Validation ensures AI responses match current configuration
- Invalid categorizations are rejected with clear error messages
