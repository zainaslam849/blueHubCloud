<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('sub_categories', 'sub_categories_name_unique')) {
            Schema::table('sub_categories', function (Blueprint $table) {
                $table->dropUnique('sub_categories_name_unique');
            });
        }

        Schema::table('sub_categories', function (Blueprint $table) {
            if (! $this->indexExists('sub_categories', 'sub_categories_category_id_name_unique')) {
                $table->unique(['category_id', 'name'], 'sub_categories_category_id_name_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            if ($this->indexExists('sub_categories', 'sub_categories_category_id_name_unique')) {
                $table->dropUnique('sub_categories_category_id_name_unique');
            }
        });

        if (! $this->indexExists('sub_categories', 'sub_categories_name_unique')) {
            Schema::table('sub_categories', function (Blueprint $table) {
                $table->unique('name', 'sub_categories_name_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection()->getName();

        if ($connection === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            return collect($indexes)->contains(fn ($row) => $row->name === $index);
        }

        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);
        return ! empty($indexes);
    }
};
