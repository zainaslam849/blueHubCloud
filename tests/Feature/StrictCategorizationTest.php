<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallCategory;
use App\Models\Company;
use App\Models\SubCategory;
use App\Services\CallCategorizationPromptService;
use Tests\TestCase;

/**
 * Tests for strict 90% categorization enforcement.
 *
 * Validates that:
 * - Existing categories are only assigned when confidence >= 0.90
 * - Weak matches (< 0.90) are rejected and new categories are created instead
 * - Test/AI-noise categories are excluded from candidate matching
 * - No random/unrelated category assignments occur
 */
class StrictCategorizationTest extends TestCase
{
    private Company $company;
    private CallCategory $supportCategory;
    private CallCategory $testCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        $this->company = Company::create([
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        // Create "Support" category (admin-created, approved)
        $this->supportCategory = CallCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Support',
            'description' => 'Customer support requests',
            'source' => 'admin',
            'is_enabled' => true,
            'status' => 'active',
        ]);

        // Create "Test" category (AI-created, should be excluded from matching)
        $this->testCategory = CallCategory::create([
            'company_id' => $this->company->id,
            'name' => 'TestRandomCategory',
            'description' => 'Auto-created test category',
            'source' => 'ai',
            'is_enabled' => true,
            'status' => 'active',
        ]);
    }

    /**
     * Test: Existing category assigned when confidence >= 0.90
     */
    public function test_existing_category_assigned_at_high_confidence(): void
    {
        $response = [
            'category' => 'Support',
            'sub_category' => null,
            'confidence' => 0.95, // High confidence
        ];

        $validation = CallCategorizationPromptService::validateCategorization(
            $response,
            $this->company->id,
            'I need help with my account. There is a billing issue I need to resolve.'
        );

        $this->assertTrue($validation['valid']);
        $this->assertEquals('Support', $validation['category_name']);
        $this->assertEquals($this->supportCategory->id, $validation['category_id']);
    }

    /**
     * Test: Existing category rejected when confidence < 0.90 (call left uncategorized)
     */
    public function test_existing_category_rejected_at_low_confidence(): void
    {
        $response = [
            'category' => 'Support',
            'sub_category' => null,
            'confidence' => 0.75, // Below 0.90 threshold
        ];

        $validation = CallCategorizationPromptService::validateCategorization(
            $response,
            $this->company->id,
            'Something about a call that might be support-related but not really clear.'
        );

        // Should reject validation - weak match to existing category
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('Existing category', $validation['error']);
        $this->assertStringContainsString('insufficient confidence', $validation['error']);
    }

    /**
     * Test: New category created when no existing category matches
     */
    public function test_new_category_created_on_no_match(): void
    {
        $response = [
            'category' => 'Technical Installation',
            'sub_category' => null,
            'confidence' => 0.92,
        ];

        $validation = CallCategorizationPromptService::validateCategorization(
            $response,
            $this->company->id,
            'Customer called about installing a custom module for their system.'
        );

        $this->assertTrue($validation['valid']);
        $this->assertEquals('Technical Installation', $validation['category_name']);
        
        // Verify category was created
        $created = CallCategory::where('company_id', $this->company->id)
            ->where('name', 'Technical Installation')
            ->first();
        $this->assertNotNull($created);
        $this->assertEquals('ai', $created->source);
    }

    /**
     * Test: AI-created/test categories are excluded from candidate list
     */
    public function test_ai_noise_categories_excluded_from_candidates(): void
    {
        // Get the active categories that would be passed to the prompt
        // This uses the filtered getActiveCategories() method
        $prompt = CallCategorizationPromptService::buildUserPrompt(
            transcriptText: 'I have a support question about my account.',
            direction: 'inbound',
            status: 'completed',
            duration: 120,
            isAfterHours: false,
            companyId: $this->company->id
        );

        // The prompt should contain "Support" (manual category)
        $this->assertStringContainsString('Support', $prompt);
        
        // The prompt should NOT contain "TestRandomCategory" (AI-created category)
        $this->assertStringNotContainsString('TestRandomCategory', $prompt);
    }

    /**
     * Test: General category rejected for substantive content
     */
    public function test_general_category_rejected_for_substantive_content(): void
    {
        // Create a General category for this company
        $general = CallCategory::create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'description' => 'General inquiries',
            'source' => 'admin',
            'is_enabled' => true,
            'status' => 'active',
        ]);

        $response = [
            'category' => 'General',
            'sub_category' => null,
            'confidence' => 0.95,
        ];

        $validation = CallCategorizationPromptService::validateCategorization(
            $response,
            $this->company->id,
            'I want to order a new service package for our company. What are the pricing options?'
        );

        // General should be rejected for substantive content
        $this->assertFalse($validation['valid']);
        $this->assertEquals('General category rejected for substantive transcript', $validation['error']);
    }

    /**
     * Test: Confidence threshold is consistently 0.90
     */
    public function test_confidence_threshold_is_90_percent(): void
    {
        // Test with exactly 0.89 (below threshold) for existing category - should be rejected
        $responseLow = [
            'category' => 'Support',
            'sub_category' => null,
            'confidence' => 0.89,
        ];

        $validationLow = CallCategorizationPromptService::validateCategorization(
            $responseLow,
            $this->company->id,
            'I need help with something.'
        );

        // Should reject weak match to existing category
        $this->assertFalse($validationLow['valid']);
        
        // Test with exactly 0.90 (at threshold) - should accept existing category
        $responseAt = [
            'category' => 'Support',
            'sub_category' => null,
            'confidence' => 0.90,
        ];

        $validationAt = CallCategorizationPromptService::validateCategorization(
            $responseAt,
            $this->company->id,
            'I need support with my billing issue.'
        );

        // Should accept existing category at exactly 0.90
        $this->assertTrue($validationAt['valid']);
        $this->assertEquals('Support', $validationAt['category_name']);
        
        // Test with new (non-existing) category at any confidence - should create it
        $responseNew = [
            'category' => 'Technical Support',
            'sub_category' => null,
            'confidence' => 0.50, // Even low confidence for NEW category is OK
        ];

        $validationNew = CallCategorizationPromptService::validateCategorization(
            $responseNew,
            $this->company->id,
            'I have a technical issue that needs help.'
        );

        // Should create new category even if confidence is lower
        $this->assertTrue($validationNew['valid']);
        $this->assertEquals('Technical Support', $validationNew['category_name']);
    }

    /**
     * Test: Multiple ordered by name for deterministic prompts
     */
    public function test_categories_ordered_by_name_for_determinism(): void
    {
        // Create multiple categories
        CallCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Zebra Category',
            'source' => 'admin',
            'is_enabled' => true,
            'status' => 'active',
        ]);

        CallCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Alpha Category',
            'source' => 'admin',
            'is_enabled' => true,
            'status' => 'active',
        ]);

        // Build prompt twice and verify order is deterministic
        $prompt1 = CallCategorizationPromptService::buildUserPrompt(
            transcriptText: 'Test',
            companyId: $this->company->id
        );

        $prompt2 = CallCategorizationPromptService::buildUserPrompt(
            transcriptText: 'Test',
            companyId: $this->company->id
        );

        $this->assertEquals($prompt1, $prompt2, 'Prompts should be identical for deterministic AI context');
        
        // Verify categories appear in alphabetical order
        $alpha_pos = strpos($prompt1, 'Alpha Category');
        $support_pos = strpos($prompt1, 'Support');
        $zebra_pos = strpos($prompt1, 'Zebra Category');
        
        $this->assertLessThan($support_pos, $alpha_pos, 'Alpha should come before Support');
        $this->assertLessThan($zebra_pos, $support_pos, 'Support should come before Zebra');
    }
}
