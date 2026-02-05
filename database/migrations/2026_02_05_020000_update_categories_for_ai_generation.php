<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultCompanyId = (int) (DB::table('companies')->orderBy('id')->value('id') ?? 1);

        Schema::table('call_categories', function (Blueprint $table) use ($defaultCompanyId) {
            if (! Schema::hasColumn('call_categories', 'company_id')) {
                $table->unsignedBigInteger('company_id')->default($defaultCompanyId)->after('id');
                $table->index('company_id');
            }
            if (! Schema::hasColumn('call_categories', 'source')) {
                $table->enum('source', ['ai', 'admin'])->default('ai')->after('is_enabled');
            }
            if (! Schema::hasColumn('call_categories', 'status')) {
                $table->enum('status', ['active', 'archived'])->default('active')->after('source');
            }
            if (! Schema::hasColumn('call_categories', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('call_categories', 'generated_by_model')) {
                $table->string('generated_by_model', 100)->nullable()->after('generated_at');
            }
        });

        Schema::table('sub_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_categories', 'source')) {
                $table->enum('source', ['ai', 'admin'])->default('ai')->after('is_enabled');
            }
            if (! Schema::hasColumn('sub_categories', 'status')) {
                $table->enum('status', ['active', 'archived'])->default('active')->after('source');
            }
        });

        DB::table('call_categories')->whereNull('source')->update(['source' => 'admin']);
        DB::table('call_categories')->whereNull('status')->update(['status' => 'active']);
        DB::table('call_categories')->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);

        DB::table('sub_categories')->whereNull('source')->update(['source' => 'admin']);
        DB::table('sub_categories')->whereNull('status')->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            if (Schema::hasColumn('sub_categories', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('sub_categories', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('call_categories', function (Blueprint $table) {
            if (Schema::hasColumn('call_categories', 'generated_by_model')) {
                $table->dropColumn('generated_by_model');
            }
            if (Schema::hasColumn('call_categories', 'generated_at')) {
                $table->dropColumn('generated_at');
            }
            if (Schema::hasColumn('call_categories', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('call_categories', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('call_categories', 'company_id')) {
                $table->dropColumn('company_id');
            }
        });
    }
};
