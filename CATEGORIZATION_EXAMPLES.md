<?php

// Usage Example: Test AI Categorization Endpoints
// This demonstrates how to use the categorization API

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

// Note: This assumes you're running in a context with authentication
// In a real scenario, you'd call these endpoints via HTTP

return <<<'EXAMPLE'

## Example 1: Get Enabled Categories

GET /admin/api/categorization/enabled-categories

Response shows all enabled categories and their enabled sub-categories:
{
  "data": [
    {
      "id": 1,
      "name": "General",
      "description": "Default category",
      "sub_categories": []
    }
  ]
}

---

## Example 2: Get Prompt Template

GET /admin/api/categorization/prompt

Response includes system prompt, model parameters, and available categories:
{
  "system_prompt": "You are a phone call classification engine...",
  "model_parameters": {
    "temperature": 0.1,
    "max_tokens": 150
  },
  "categories": [...]
}

---

## Example 3: Build Dynamic Prompt for a Call

POST /admin/api/categorization/build-prompt

Request:
{
  "transcript": "Hi, I have a question about your pricing plans. I'm interested in the enterprise package.",
  "direction": "inbound",
  "status": "completed",
  "duration": 420,
  "is_after_hours": false
}

Response:
{
  "data": {
    "system": "You are a phone call classification engine...",
    "user": "AVAILABLE CATEGORIES:\n- Sales Inquiry\n  - Product Questions\n  - Pricing\n- Support\n  - Technical Issues\n- General\n\nCALL CONTEXT:\n- Direction: inbound\n- Status: completed\n- Duration: 7m 0s\n- After hours: No\n\nTRANSCRIPT:\n\"\"\"Hi, I have a question about your pricing plans...\"\"\"\n\nRULES:\n1. If status is \"missed\"...",
    "model_parameters": {
      "temperature": 0.1,
      "max_tokens": 150
    }
  }
}

---

## Example 4: Validate AI Response

POST /admin/api/categorization/validate

Request (AI's JSON response):
{
  "category": "Sales Inquiry",
  "sub_category": "Pricing",
  "confidence": 0.94
}

Success Response (200):
{
  "valid": true,
  "category_id": 2,
  "sub_category_id": 4,
  "confidence": 0.94
}

Error Response (400) - If category doesn't exist:
{
  "valid": false,
  "error": "Category 'Invalid Category' not found or disabled"
}

---

## Complete Integration Flow

1. Admin creates categories:
   - "Sales Inquiry" (enabled)
   - "Sales Inquiry" → "Pricing" (enabled)
   - "Sales Inquiry" → "Licensing" (disabled)

2. Backend gets the prompt:
   POST /admin/api/categorization/build-prompt
   {
     "transcript": "How much does the enterprise plan cost?",
     "direction": "inbound",
     "status": "completed",
     "duration": 300,
     "is_after_hours": false
   }

3. AI receives prompt including only enabled categories
   (Licensing will NOT be in the prompt)

4. AI responds with JSON:
   {
     "category": "Sales Inquiry",
     "sub_category": "Pricing",
     "confidence": 0.96
   }

5. Validate response:
   POST /admin/api/categorization/validate
   {
     "category": "Sales Inquiry",
     "sub_category": "Pricing",
     "confidence": 0.96
   }

6. System returns:
   {
     "valid": true,
     "category_id": 2,
     "sub_category_id": 4,
     "confidence": 0.96
   }

7. Store in database with call record:
   - call_id: 12345
   - category_id: 2
   - sub_category_id: 4
   - ai_confidence: 0.96

---

## Key Features

✓ Dynamic Prompt Generation
  - Only enabled categories are included
  - Only enabled sub-categories are shown
  - Automatically formatted for AI consumption

✓ Strict Validation
  - AI cannot use disabled categories
  - AI cannot invent new categories
  - Response must match exact names

✓ Real-time Updates
  - Admin changes categories → Immediately affect prompts
  - No API version changes needed
  - Backward compatible (by category ID)

✓ Low Temperature (0.1)
  - Consistent, focused categorization
  - Minimal hallucination
  - Reliable results

✓ Minimal Output (150 tokens)
  - Fast response times
  - Lower API costs
  - Just JSON output

EXAMPLE;
