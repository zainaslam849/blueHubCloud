<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // Drop old category columns
            if (Schema::hasColumn('calls', 'category')) {
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('calls', 'sub_category')) {
                $table->dropColumn('sub_category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('category')->nullable()->after('did')->index();
            $table->string('sub_category')->nullable()->after('category')->index();
        });
    }
};
