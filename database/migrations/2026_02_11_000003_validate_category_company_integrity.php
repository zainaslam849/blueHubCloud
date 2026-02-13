<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orphanedCategories = DB::table('call_categories')
            ->leftJoin('companies', 'companies.id', '=', 'call_categories.company_id')
            ->whereNull('companies.id')
            ->count();

        if ($orphanedCategories > 0) {
            throw new \RuntimeException('Orphaned call_categories found without valid company_id. Migration halted.');
        }

        $orphanedSubCategories = DB::table('sub_categories')
            ->leftJoin('call_categories', 'call_categories.id', '=', 'sub_categories.category_id')
            ->whereNull('call_categories.id')
            ->count();

        if ($orphanedSubCategories > 0) {
            throw new \RuntimeException('Orphaned sub_categories found without valid category. Migration halted.');
        }
    }

    public function down(): void
    {
        // No-op: validation only
    }
};
