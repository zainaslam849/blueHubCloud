<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->boolean('weekly_run_enabled')->default(true)->after('stripe_test_mode');
            // 0 = Sunday ... 6 = Saturday (Carbon dayOfWeek). Default 1 = Monday.
            $table->unsignedTinyInteger('weekly_run_day')->default(1)->after('weekly_run_enabled');
            $table->string('weekly_run_time', 5)->default('02:00')->after('weekly_run_day');
            $table->string('weekly_run_timezone', 64)->default('UTC')->after('weekly_run_time');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'weekly_run_enabled',
                'weekly_run_day',
                'weekly_run_time',
                'weekly_run_timezone',
            ]);
        });
    }
};
