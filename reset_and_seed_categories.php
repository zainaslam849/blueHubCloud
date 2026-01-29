<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

echo "⚠ This will delete all existing categories and sub-categories!\n";
echo "Calls will lose their category assignments (category_id set to NULL).\n\n";
echo "Continue? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'yes') {
    echo "❌ Aborted.\n";
    exit(0);
}

echo "\n🔄 Resetting categories...\n\n";

// Disable foreign key checks temporarily
DB::statement('SET FOREIGN_KEY_CHECKS=0');

// Clear call categorizations first
DB::table('calls')->update([
    'category_id' => null,
    'sub_category_id' => null,
    'sub_category_label' => null,
    'category_source' => null,
    'category_confidence' => null,
    'categorized_at' => null,
]);
echo "✓ Cleared categorizations from calls table\n";

// Truncate tables
DB::table('sub_categories')->truncate();
echo "✓ Cleared sub_categories table\n";

DB::table('call_categories')->truncate();
echo "✓ Cleared call_categories table\n";

// Reset auto-increment
DB::statement('ALTER TABLE call_categories AUTO_INCREMENT = 1');
DB::statement('ALTER TABLE sub_categories AUTO_INCREMENT = 1');

// Re-enable foreign key checks
DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "\n🌱 Seeding new categories...\n\n";

Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CallCategoriesSeeder']);

echo Artisan::output();

echo "\n✅ Done! Categories have been reset and seeded.\n\n";
echo "Next steps:\n";
echo "  1. Re-categorize existing calls: php artisan calls:categorize --force --limit=6\n";
echo "  2. Check results: php check_categorization.php\n";
