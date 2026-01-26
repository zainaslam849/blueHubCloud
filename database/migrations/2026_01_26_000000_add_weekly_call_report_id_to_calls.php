<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->unsignedBigInteger('weekly_call_report_id')->nullable()->after('duration_seconds');
            $table->foreign('weekly_call_report_id')
                ->references('id')
                ->on('weekly_call_reports')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropForeign(['weekly_call_report_id']);
            $table->dropColumn('weekly_call_report_id');
        });
    }
};
