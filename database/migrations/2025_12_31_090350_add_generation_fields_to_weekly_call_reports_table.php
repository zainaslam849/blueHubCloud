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
            $table->string('status')->default('pending')->index()->after('metadata');

            $table->string('pdf_disk')->nullable()->after('status');
            $table->string('pdf_path')->nullable()->after('pdf_disk');

            $table->string('csv_disk')->nullable()->after('pdf_path');
            $table->string('csv_path')->nullable()->after('csv_disk');

            $table->timestamp('generated_at')->nullable()->after('csv_path');
            $table->text('error_message')->nullable()->after('generated_at');

            $table->index(['company_id', 'status'], 'weekly_call_reports_company_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->dropIndex('weekly_call_reports_company_status_index');
            $table->dropColumn([
                'status',
                'pdf_disk',
                'pdf_path',
                'csv_disk',
                'csv_path',
                'generated_at',
                'error_message',
            ]);
        });
    }
};
