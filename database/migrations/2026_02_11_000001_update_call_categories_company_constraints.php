<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('call_categories', 'call_categories_name_unique')) {
            Schema::table('call_categories', function (Blueprint $table) {
                $table->dropUnique('call_categories_name_unique');
            });
        }

        Schema::table('call_categories', function (Blueprint $table) {
            if (! $this->foreignKeyExists('call_categories', 'call_categories_company_id_foreign')) {
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            }

            if (! $this->indexExists('call_categories', 'call_categories_company_id_name_unique')) {
                $table->unique(['company_id', 'name'], 'call_categories_company_id_name_unique');
            }

            if (! $this->indexExists('call_categories', 'call_categories_company_id_index')) {
                $table->index('company_id', 'call_categories_company_id_index');
            }

            if (! $this->indexExists('call_categories', 'call_categories_status_index')) {
                $table->index('status', 'call_categories_status_index');
            }

            if (! $this->indexExists('call_categories', 'call_categories_source_index')) {
                $table->index('source', 'call_categories_source_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('call_categories', function (Blueprint $table) {
            if ($this->foreignKeyExists('call_categories', 'call_categories_company_id_foreign')) {
                $table->dropForeign('call_categories_company_id_foreign');
            }

            if ($this->indexExists('call_categories', 'call_categories_company_id_name_unique')) {
                $table->dropUnique('call_categories_company_id_name_unique');
            }

            if ($this->indexExists('call_categories', 'call_categories_company_id_index')) {
                $table->dropIndex('call_categories_company_id_index');
            }

            if ($this->indexExists('call_categories', 'call_categories_status_index')) {
                $table->dropIndex('call_categories_status_index');
            }

            if ($this->indexExists('call_categories', 'call_categories_source_index')) {
                $table->dropIndex('call_categories_source_index');
            }
        });

        if (! $this->indexExists('call_categories', 'call_categories_name_unique')) {
            Schema::table('call_categories', function (Blueprint $table) {
                $table->unique('name', 'call_categories_name_unique');
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

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $connection = Schema::getConnection()->getName();

        if ($connection === 'sqlite') {
            $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");
            return collect($foreignKeys)->contains(fn ($row) => $row->id !== null && $row->from === 'company_id');
        }

        $keys = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $foreignKey]
        );

        return ! empty($keys);
    }
};
