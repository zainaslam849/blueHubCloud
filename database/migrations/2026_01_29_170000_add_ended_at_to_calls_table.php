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
            // Add ended_at timestamp if it doesn't exist
            if (!Schema::hasColumn('calls', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('duration_seconds');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'ended_at')) {
                $table->dropColumn('ended_at');
            }
        });
    }
};
