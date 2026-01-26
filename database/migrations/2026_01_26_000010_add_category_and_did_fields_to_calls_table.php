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
            if (! Schema::hasColumn('calls', 'did')) {
                $table->string('did')->nullable()->after('to')->index();
            }

            if (! Schema::hasColumn('calls', 'category')) {
                $table->string('category')->nullable()->after('did')->index();
            }

            if (! Schema::hasColumn('calls', 'sub_category')) {
                $table->string('sub_category')->nullable()->after('category')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['did']);
            $table->dropIndex(['category']);
            $table->dropIndex(['sub_category']);

            $table->dropColumn([
                'did',
                'category',
                'sub_category',
            ]);
        });
    }
};
