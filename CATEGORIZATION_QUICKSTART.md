# AI Categorization - Quick Reference

## TL;DR - What It Does

The system dynamically builds AI prompts that:

1. Only include **enabled categories** and sub-categories
2. Cannot be fooled - AI cannot invent or use disabled categories
3. Auto-update when admins change categories
4. Validate AI responses to ensure correctness

## 4 API Endpoints

```
GET  /admin/api/categorization/enabled-categories
GET  /admin/api/categorization/prompt
POST /admin/api/categorization/build-prompt
POST /admin/api/categorization/validate
```

## Usage in 3 Steps

### Step 1: Build Prompt

```bash
POST /admin/api/categorization/build-prompt
{
  "transcript": "Customer transcript text...",
  "direction": "inbound",
  "status": "completed",
  "duration": 300,
  "is_after_hours": false
}
```

Response: Complete `system` + `user` prompts + `model_parameters`

### Step 2: Send to AI

```javascript
const aiResponse = await openai.chat.completions.create({
    model: "gpt-4",
    messages: [
        { role: "system", content: promptData.system },
        { role: "user", content: promptData.user },
    ],
    temperature: promptData.model_parameters.temperature,
    max_tokens: promptData.model_parameters.max_tokens,
});

const categorization = JSON.parse(aiResponse.choices[0].message.content);
```

### Step 3: Validate Response

```bash
POST /admin/api/categorization/validate
{
  "category": "Sales Inquiry",
  "sub_category": "Pricing",
  "confidence": 0.94
}
```

Response:

```json
{
    "valid": true,
    "category_id": 2,
    "sub_category_id": 4,
    "confidence": 0.94
}
```

## System Prompt (Always Included)

```
You are a phone call classification engine.

Your task is to assign the call to ONE category chosen from a predefined list.
These categories are managed by the system administrator and MUST be followed strictly.

You MUST NOT invent new primary categories.
If intent is unclear, choose the closest matching category or "General".

Return valid JSON only.
```

## Dynamic Prompt Format

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
- Duration: 5m 0s
- After hours: No

TRANSCRIPT:
"""
[Full transcript here]
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

## Response Format

```json
{
    "category": "Sales Inquiry",
    "sub_category": "Pricing",
    "confidence": 0.94
}
```

Or without sub-category:

```json
{
    "category": "General",
    "sub_category": null,
    "confidence": 0.45
}
```

## Model Parameters

| Parameter   | Value | Why                         |
| ----------- | ----- | --------------------------- |
| temperature | 0.1   | Focused, consistent results |
| max_tokens  | 150   | Just JSON, no explanation   |

## Validation Rules

✅ Category must exist and be enabled
✅ Sub-category must exist and be enabled (if provided)
✅ Confidence must be 0.0-1.0
✅ Category name must match exactly

## What AI Cannot Do

❌ Invent new categories
❌ Use disabled categories
❌ Use disabled sub-categories
❌ Return multiple categories
❌ Skip the category field
❌ Use invalid confidence values

## Admin Makes Changes → Prompts Update

When admin:

- ✅ Adds category → Immediately available in prompts
- ✅ Disables category → Removed from prompts automatically
- ✅ Enables category → Added back to prompts
- ✅ Adds sub-category → Shows in next prompt
- ✅ Disables sub-category → Hidden from AI

**No code changes needed!**

## Example Flow

```
1. Admin creates "Sales Inquiry" category
   └─ Adds "Pricing" sub-category
   └─ Enables both

2. Call comes in: "How much does the enterprise plan cost?"

3. System calls: POST /build-prompt
   ├─ Includes "Sales Inquiry" → "Pricing"
   └─ Returns prompt

4. AI categorizes:
   {
     "category": "Sales Inquiry",
     "sub_category": "Pricing",
     "confidence": 0.96
   }

5. System calls: POST /validate
   ├─ ✓ "Sales Inquiry" exists and enabled
   ├─ ✓ "Pricing" exists and enabled
   ├─ ✓ 0.96 is valid
   └─ Returns: { valid: true, category_id: 1, sub_category_id: 3, confidence: 0.96 }

6. Call stored with category_id=1, sub_category_id=3

7. Admin disables "Pricing" sub-category

8. Next call with same transcript:
   ├─ AI now sees "Pricing" is NOT available
   └─ Categorizes as "Sales Inquiry" with sub_category=null
```

## Error Handling

Invalid response:

```bash
POST /admin/api/categorization/validate
{
  "category": "InvalidCategory",
  "sub_category": null,
  "confidence": 0.80
}
```

Response (400):

```json
{
    "valid": false,
    "error": "Category 'InvalidCategory' not found or disabled"
}
```

## Integration Checklist

- [ ] Call `/enabled-categories` to see available options
- [ ] Create `Call` model relationship
- [ ] Create `CallCategorization` model to store results
- [ ] In call processing pipeline:
    - [ ] Call `/build-prompt`
    - [ ] Send to AI API
    - [ ] Call `/validate`
    - [ ] Store result in database
- [ ] Create admin UI to view categorization results
- [ ] Add manual override capability
- [ ] Set up metrics dashboard

## Files to Reference

- `AI_CATEGORIZATION.md` - Full API documentation
- `CATEGORIZATION_EXAMPLES.md` - Integration examples
- `PROMPT_EXAMPLES.md` - Real prompt examples
- `app/Services/CallCategorizationPromptService.php` - Core logic
- `app/Http/Controllers/Admin/CallCategorizationController.php` - Endpoints

## Support

See complete documentation:

1. **API Reference**: `AI_CATEGORIZATION.md`
2. **Code Examples**: `CATEGORIZATION_EXAMPLES.md`
3. **Real Prompts**: `PROMPT_EXAMPLES.md`
4. **Implementation**: `CATEGORIZATION_IMPLEMENTATION.md`
