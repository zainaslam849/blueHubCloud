<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->unsignedInteger('minutes_consumed')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->dropColumn('minutes_consumed');
        });
    }
};
