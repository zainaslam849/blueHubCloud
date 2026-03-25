<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an indexed `transcription_status` column to the calls table.
 *
 * This replaces the previous approach of storing transcription terminal state
 * exclusively in the JSON `pbx_metadata` column, which required slow
 * JSON_EXTRACT queries for candidate promotion filtering.
 *
 * Values used by FetchTranscriptionsJob:
 *   null               – never attempted (new call)
 *   'pending'          – queued for (re)verification
 *   'saved'            – transcript successfully stored
 *   'terminal_no_transcription' – PBX confirmed no transcription exists
 *   'terminal_error'   – max retries / exception exhausted
 *
 * The down() migration also backfills the value back into
 * pbx_metadata so the JSON-based query can resume if rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('transcription_status', 40)->nullable()->after('transcription_checked_at');
            $table->index('transcription_status', 'calls_transcription_status_idx');
        });

        // Backfill existing rows that already have a terminal/saved status stored
        // in the legacy pbx_metadata->transcription_verification_status JSON field.
        // This ensures the new candidate-promotion query (which uses the plain column)
        // correctly excludes calls that were already resolved before this migration.
        DB::statement("
            UPDATE calls
            SET transcription_status = JSON_UNQUOTE(
                JSON_EXTRACT(pbx_metadata, '$.transcription_verification_status')
            )
            WHERE pbx_metadata IS NOT NULL
              AND JSON_EXTRACT(pbx_metadata, '$.transcription_verification_status') IS NOT NULL
              AND transcription_status IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('calls_transcription_status_idx');
            $table->dropColumn('transcription_status');
        });
    }
};
