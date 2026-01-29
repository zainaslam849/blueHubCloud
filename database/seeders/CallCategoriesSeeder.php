<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\CallCategory;
use App\Models\SubCategory;

class CallCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'General',
                'description' => 'General inquiries and informational calls',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'Product Inquiry', 'description' => 'Questions about products or services'],
                    ['name' => 'Account Question', 'description' => 'General account-related questions'],
                    ['name' => 'Information Request', 'description' => 'Requests for general information'],
                    ['name' => 'Follow-up', 'description' => 'Follow-up calls to previous interactions'],
                ],
            ],
            [
                'name' => 'Support',
                'description' => 'Technical support and troubleshooting calls',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'Technical Issue', 'description' => 'Technical problems or bugs'],
                    ['name' => 'Password Reset', 'description' => 'Password recovery requests'],
                    ['name' => 'Account Access', 'description' => 'Login or access issues'],
                    ['name' => 'Configuration Help', 'description' => 'Help with system configuration'],
                    ['name' => 'Bug Report', 'description' => 'Reporting software bugs'],
                ],
            ],
            [
                'name' => 'Sales',
                'description' => 'Sales inquiries and new business opportunities',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'New Customer', 'description' => 'New customer inquiries'],
                    ['name' => 'Product Demo', 'description' => 'Product demonstration requests'],
                    ['name' => 'Pricing', 'description' => 'Pricing and plan inquiries'],
                    ['name' => 'Upgrade', 'description' => 'Upgrade or upsell opportunities'],
                    ['name' => 'Partnership', 'description' => 'Partnership or collaboration inquiries'],
                ],
            ],
            [
                'name' => 'Billing',
                'description' => 'Billing, invoicing, and payment-related calls',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'Invoice Question', 'description' => 'Questions about invoices'],
                    ['name' => 'Payment Issue', 'description' => 'Payment processing problems'],
                    ['name' => 'Refund Request', 'description' => 'Refund or credit requests'],
                    ['name' => 'Subscription Change', 'description' => 'Changes to subscription plans'],
                    ['name' => 'Billing Address', 'description' => 'Billing address updates'],
                ],
            ],
            [
                'name' => 'Complaint',
                'description' => 'Customer complaints and escalations',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'Service Quality', 'description' => 'Complaints about service quality'],
                    ['name' => 'Product Issue', 'description' => 'Complaints about product problems'],
                    ['name' => 'Staff Behavior', 'description' => 'Complaints about staff interactions'],
                    ['name' => 'Escalation', 'description' => 'Escalated issues requiring management'],
                ],
            ],
            [
                'name' => 'Other',
                'description' => 'Uncategorized or low-confidence calls',
                'is_enabled' => true,
                'sub_categories' => [
                    ['name' => 'Spam', 'description' => 'Spam or robocalls'],
                    ['name' => 'Wrong Number', 'description' => 'Misdirected calls'],
                    ['name' => 'Unclear', 'description' => 'Purpose unclear or ambiguous'],
                ],
            ],
        ];

        DB::transaction(function () use ($categories) {
            foreach ($categories as $categoryData) {
                $subCategories = $categoryData['sub_categories'] ?? [];
                unset($categoryData['sub_categories']);

                // Create or update category
                $category = CallCategory::updateOrCreate(
                    ['name' => $categoryData['name']],
                    $categoryData
                );

                $this->command->info("✓ Category: {$category->name} (ID: {$category->id})");

                // Create sub-categories
                foreach ($subCategories as $subCategoryData) {
                    $subCategory = SubCategory::updateOrCreate(
                        [
                            'category_id' => $category->id,
                            'name' => $subCategoryData['name'],
                        ],
                        array_merge($subCategoryData, ['is_enabled' => true])
                    );

                    $this->command->info("  - {$subCategory->name} (ID: {$subCategory->id})");
                }
            }
        });

        $this->command->info("\n✅ Successfully seeded call categories and sub-categories!");
        $this->command->info("Total Categories: " . CallCategory::count());
        $this->command->info("Total Sub-Categories: " . SubCategory::count());
    }
}
