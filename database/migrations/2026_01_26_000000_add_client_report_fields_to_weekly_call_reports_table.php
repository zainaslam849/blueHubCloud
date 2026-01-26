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
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            // Client report structure fields
            if (! Schema::hasColumn('weekly_call_reports', 'executive_summary')) {
                $table->text('executive_summary')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'metrics')) {
                $table->json('metrics')->nullable()->after('executive_summary');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->dropColumn([
                'executive_summary',
                'metrics',
            ]);
        });
    }
};
