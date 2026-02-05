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
        Schema::table('call_categories', function (Blueprint $table) {
            $table->enum('source', ['ai', 'admin'])->default('ai')->after('is_enabled');
            $table->enum('status', ['active', 'archived'])->default('active')->after('source');
            $table->string('generated_by_model')->nullable()->after('status');
        });

        Schema::table('sub_categories', function (Blueprint $table) {
            $table->enum('source', ['ai', 'admin'])->default('ai')->after('is_enabled');
            $table->enum('status', ['active', 'archived'])->default('active')->after('source');
        });

        DB::table('call_categories')->update(['source' => 'admin', 'status' => 'active']);
        DB::table('sub_categories')->update(['source' => 'admin', 'status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropColumn(['source', 'status']);
        });

        Schema::table('call_categories', function (Blueprint $table) {
            $table->dropColumn(['source', 'status', 'generated_by_model']);
        });
    }
};
