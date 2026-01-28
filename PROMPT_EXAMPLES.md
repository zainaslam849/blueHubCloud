# AI Categorization Prompt Examples

## System Prompt (Static)

```
You are a phone call classification engine.

Your task is to assign the call to ONE category chosen from a predefined list.
These categories are managed by the system administrator and MUST be followed strictly.

You MUST NOT invent new primary categories.
If intent is unclear, choose the closest matching category or "General".

Return valid JSON only.
```

## User Prompt (Dynamic) - Example 1: Sales Call

Given these admin categories:

- Sales Inquiry
    - Product Questions
    - Pricing
- Support
    - Technical Issues
- General

The generated user prompt would be:

```
AVAILABLE CATEGORIES:
- Sales Inquiry
  - Product Questions
  - Pricing
- Support
  - Technical Issues
- General

CALL CONTEXT:
- Direction: inbound
- Status: completed
- Duration: 5m 30s
- After hours: No

TRANSCRIPT:
"""
Customer: Hi, I'm interested in learning more about your enterprise plan pricing.
Agent: Sure! Our enterprise plan starts at $500 per month and includes...
Customer: What about annual pricing discounts?
Agent: We offer 20% off for annual commitments...
"""

RULES:
1. If status is "missed" → choose the category that represents missed calls.
2. If after hours → choose the category that best fits after-hours handling.
3. Choose ONLY from the available categories above.
4. If no sub-category fits, return null.

OUTPUT FORMAT (JSON ONLY):
{
  "category": "<exact category name>",
  "sub_category": "<exact sub-category name or null>",
  "confidence": 0.0-1.0
}
```

**Expected AI Response:**

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Pricing",
    "confidence": 0.96
}
```

---

## User Prompt Example 2: Support Call with Disabled Sub-Category

Given these admin categories:

- Sales Inquiry
    - Product Questions
    - Pricing
    - Licensing (DISABLED)
- Support
    - Technical Issues
    - Account Management
- General

Notice: "Licensing" is NOT in the prompt because it's disabled.

Generated prompt:

```
AVAILABLE CATEGORIES:
- Sales Inquiry
  - Product Questions
  - Pricing
- Support
  - Technical Issues
  - Account Management
- General

CALL CONTEXT:
- Direction: inbound
- Status: completed
- Duration: 12m 15s
- After hours: No

TRANSCRIPT:
"""
Customer: I can't log into my account, it keeps saying invalid credentials.
Agent: Let me help you with that. What email address are you using?
Customer: It's john@example.com
Agent: Let me check... I see the issue. You recently changed your email but didn't update it in the system...
"""

RULES:
1. If status is "missed" → choose the category that represents missed calls.
2. If after hours → choose the category that best fits after-hours handling.
3. Choose ONLY from the available categories above.
4. If no sub-category fits, return null.

OUTPUT FORMAT (JSON ONLY):
{
  "category": "<exact category name>",
  "sub_category": "<exact sub-category name or null>",
  "confidence": 0.0-1.0
}
```

**Expected AI Response:**

```json
{
    "category": "Support",
    "sub_category": "Account Management",
    "confidence": 0.94
}
```

---

## User Prompt Example 3: Missed Call After Hours

Generated prompt:

```
AVAILABLE CATEGORIES:
- Sales Inquiry
  - Product Questions
  - Pricing
- Support
  - Technical Issues
- General

CALL CONTEXT:
- Direction: inbound
- Status: missed
- Duration: 0s
- After hours: Yes

TRANSCRIPT:
"""
(No transcript - call was missed)
"""

RULES:
1. If status is "missed" → choose the category that represents missed calls.
2. If after hours → choose the category that best fits after-hours handling.
3. Choose ONLY from the available categories above.
4. If no sub-category fits, return null.

OUTPUT FORMAT (JSON ONLY):
{
  "category": "<exact category name>",
  "sub_category": "<exact sub-category name or null>",
  "confidence": 0.0-1.0
}
```

**Expected AI Response:**

```json
{
    "category": "General",
    "sub_category": null,
    "confidence": 0.85
}
```

---

## User Prompt Example 4: Ambiguous Call

Generated prompt:

```
AVAILABLE CATEGORIES:
- Sales Inquiry
  - Product Questions
  - Pricing
- Support
  - Technical Issues
- General

CALL CONTEXT:
- Direction: inbound
- Status: completed
- Duration: 2m 30s
- After hours: No

TRANSCRIPT:
"""
Caller: Hi, I'm calling from the tech department about your software...
Agent: Hi, how can I help?
Caller: We'd like to discuss a potential partnership opportunity.
Agent: Great! Let me connect you with our business development team...
"""

RULES:
1. If status is "missed" → choose the category that represents missed calls.
2. If after hours → choose the category that best fits after-hours handling.
3. Choose ONLY from the available categories above.
4. If no sub-category fits, return null.

OUTPUT FORMAT (JSON ONLY):
{
  "category": "<exact category name>",
  "sub_category": "<exact sub-category name or null>",
  "confidence": 0.0-1.0
}
```

**Expected AI Response:**

```json
{
    "category": "Sales Inquiry",
    "sub_category": null,
    "confidence": 0.65
}
```

Note: Confidence is lower (0.65) because the intent is unclear and doesn't fit a specific sub-category.

---

## Key Observations

1. **Dynamic Categories**: The prompt changes based on admin configuration
2. **Disabled Categories Hidden**: "Licensing" never appears in prompts
3. **Null Sub-Categories**: AI can return null if no sub-category fits
4. **Call Context**: Direction, status, duration, and after-hours info help AI decide
5. **Low Temperature**: Results in consistent, focused categorization
6. **JSON Only**: AI knows to output only JSON, no explanation text

---

## What AI Cannot Do

❌ Cannot invent categories like "Complaints" if not in the list
❌ Cannot use disabled sub-categories like "Licensing"
❌ Cannot return multiple categories (only one)
❌ Cannot skip category field (required)
❌ Cannot return random confidence (must be 0.0-1.0)

---

## Validation Examples

### Valid Response

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Pricing",
    "confidence": 0.92
}
```

✅ Category exists and enabled
✅ Sub-category exists and enabled
✅ Confidence is between 0 and 1

### Valid Response (No Sub-Category)

```json
{
    "category": "General",
    "sub_category": null,
    "confidence": 0.45
}
```

✅ Category exists and enabled
✅ Sub-category is null (allowed)
✅ Confidence is valid

### Invalid Response (Disabled Category)

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Licensing",
    "confidence": 0.88
}
```

❌ Error: Sub-category "Licensing" not found or disabled

### Invalid Response (Invented Category)

```json
{
    "category": "Complaints",
    "sub_category": null,
    "confidence": 0.75
}
```

❌ Error: Category "Complaints" not found or disabled

### Invalid Response (Multiple Categories)

```json
{
    "category": "Sales Inquiry|Support",
    "sub_category": "Pricing|Technical Issues",
    "confidence": 0.8
}
```

❌ Error: Category "Sales Inquiry|Support" not found or disabled
