<?php
/**
 * Seed UKO (company 144) categories based on the client CDR analysis report.
 *
 * Safe to run multiple times: uses firstOrCreate / firstOrNew.
 * Skips categories that already exist as 'admin' source (leaves them untouched).
 *
 * Run: php seed_uko_categories.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CallCategory;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;

$companyId = 144;

$categories = [
    'Property Enquiry' => [
        'Availability/Pricing',
        'Viewing/Inspection',
        'Application/Lease',
        'Amenities/Features',
    ],
    'Maintenance Request' => [
        'General Repair',
        'Appliance',
        'Plumbing',
        'Electrical',
    ],
    'Appointment Scheduling' => [
        'Viewing Booking',
        'Move-in Coordination',
        'Inspection Booking',
    ],
    'Access Issue' => [
        'Key/Fob Access',
        'Door Entry',
        'Car Park Access',
    ],
    'General Enquiry' => [],
    'Other'            => [],
    'General'          => [],  // fallback — always required
];

echo "Seeding categories for company {$companyId} (UKO)...\n\n";

DB::beginTransaction();
try {
    foreach ($categories as $categoryName => $subNames) {
        $existing = CallCategory::query()
            ->where('company_id', $companyId)
            ->where('name', $categoryName)
            ->first();

        if ($existing && $existing->source === 'admin') {
            echo "  SKIP  [{$existing->source}] {$categoryName} (admin-owned)\n";
            $category = $existing;
        } elseif ($existing) {
            $existing->update([
                'is_enabled' => true,
                'status'     => 'active',
                'source'     => 'admin',
            ]);
            echo "  UPDATE {$categoryName} → id={$existing->id}\n";
            $category = $existing->fresh();
        } else {
            $category = CallCategory::create([
                'company_id'  => $companyId,
                'name'        => $categoryName,
                'description' => null,
                'is_enabled'  => true,
                'status'      => 'active',
                'source'      => 'admin',
            ]);
            echo "  CREATE {$categoryName} → id={$category->id}\n";
        }

        foreach ($subNames as $subName) {
            $sub = SubCategory::query()
                ->where('category_id', $category->id)
                ->where('name', $subName)
                ->first();

            if ($sub && $sub->source === 'admin') {
                echo "    SKIP  [{$sub->source}] {$subName}\n";
            } elseif ($sub) {
                $sub->update(['is_enabled' => true, 'status' => 'active', 'source' => 'admin']);
                echo "    UPDATE {$subName}\n";
            } else {
                SubCategory::create([
                    'category_id' => $category->id,
                    'name'        => $subName,
                    'description' => null,
                    'is_enabled'  => true,
                    'status'      => 'active',
                    'source'      => 'admin',
                ]);
                echo "    CREATE {$subName}\n";
            }
        }
    }

    DB::commit();
    echo "\nDone. Active categories for company {$companyId}:\n";
    $all = CallCategory::query()
        ->where('company_id', $companyId)
        ->where('is_enabled', true)
        ->where('status', 'active')
        ->with(['subCategories' => fn($q) => $q->where('is_enabled', true)->where('status', 'active')])
        ->get();

    foreach ($all as $cat) {
        $subs = $cat->subCategories->pluck('name')->implode(', ');
        echo "  [{$cat->id}] {$cat->name}" . ($subs ? " → {$subs}" : '') . "\n";
    }
} catch (\Throwable $e) {
    DB::rollBack();
    echo "\nFAILED: {$e->getMessage()}\n";
    exit(1);
}
