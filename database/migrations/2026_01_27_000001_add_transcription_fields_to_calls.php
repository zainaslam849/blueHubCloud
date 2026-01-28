<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            if (! Schema::hasColumn('calls', 'has_transcription')) {
                $table->boolean('has_transcription')->default(false)->after('weekly_call_report_id')->index();
            }

            if (! Schema::hasColumn('calls', 'transcript_text')) {
                $table->text('transcript_text')->nullable()->after('has_transcription');
            }

            if (! Schema::hasColumn('calls', 'transcription_checked_at')) {
                $table->timestamp('transcription_checked_at')->nullable()->after('transcript_text')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'transcription_checked_at')) {
                $table->dropIndex(['transcription_checked_at']);
                $table->dropColumn('transcription_checked_at');
            }

            if (Schema::hasColumn('calls', 'transcript_text')) {
                $table->dropColumn('transcript_text');
            }

            if (Schema::hasColumn('calls', 'has_transcription')) {
                $table->dropIndex(['has_transcription']);
                $table->dropColumn('has_transcription');
            }
        });
    }
};
