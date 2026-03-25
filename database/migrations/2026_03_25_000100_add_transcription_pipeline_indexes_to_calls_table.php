<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->index(
                ['company_id', 'status', 'has_transcription', 'started_at'],
                'calls_company_status_transcription_started_idx'
            );

            $table->index(
                ['company_id', 'status', 'has_transcription', 'transcription_checked_at'],
                'calls_company_status_transcription_checked_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('calls_company_status_transcription_started_idx');
            $table->dropIndex('calls_company_status_transcription_checked_idx');
        });
    }
};
