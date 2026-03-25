<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->boolean('ai_incomplete')->default(false)->after('status');
            $table->unsignedInteger('ai_incomplete_call_count')->default(0)->after('ai_incomplete');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->dropColumn('ai_incomplete');
            $table->dropColumn('ai_incomplete_call_count');
        });
    }
};
