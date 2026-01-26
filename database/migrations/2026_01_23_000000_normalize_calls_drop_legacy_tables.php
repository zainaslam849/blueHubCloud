<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fromCol = $this->quoteIdentifier('from');
        $toCol = $this->quoteIdentifier('to');
        $pbxUniqueIdCol = $this->quoteIdentifier('pbx_unique_id');

        if (Schema::hasTable('calls')) {
            Schema::table('calls', function (Blueprint $table) {
                if (! Schema::hasColumn('calls', 'server_id')) {
                    $table->string('server_id')->nullable()->index();
                }

                if (! Schema::hasColumn('calls', 'pbx_unique_id')) {
                    $table->string('pbx_unique_id')->nullable();
                }

                if (! Schema::hasColumn('calls', 'from')) {
                    $table->string('from')->nullable();
                }

                if (! Schema::hasColumn('calls', 'to')) {
                    $table->string('to')->nullable();
                }

                if (! Schema::hasColumn('calls', 'direction')) {
                    $table->string('direction')->default('unknown')->index();
                }

                if (! Schema::hasColumn('calls', 'status')) {
                    $table->string('status')->nullable()->index();
                }

                if (! Schema::hasColumn('calls', 'started_at')) {
                    $table->timestamp('started_at')->nullable()->index();
                }

                if (! Schema::hasColumn('calls', 'duration_seconds')) {
                    $table->integer('duration_seconds')->default(0);
                }

                if (! Schema::hasColumn('calls', 'has_transcription')) {
                    $table->boolean('has_transcription')->default(false)->index();
                }

                if (! Schema::hasColumn('calls', 'transcript_text')) {
                    $table->longText('transcript_text')->nullable();
                }
            });

            // Backfill renamed columns (best-effort, only if legacy columns exist).
            if (Schema::hasColumn('calls', 'call_uid')) {
                DB::statement("UPDATE calls SET {$pbxUniqueIdCol} = call_uid WHERE ({$pbxUniqueIdCol} IS NULL OR {$pbxUniqueIdCol} = '') AND call_uid IS NOT NULL");
            }
            if (Schema::hasColumn('calls', 'from_number')) {
                DB::statement("UPDATE calls SET {$fromCol} = from_number WHERE ({$fromCol} IS NULL OR {$fromCol} = '') AND from_number IS NOT NULL");
            }
            if (Schema::hasColumn('calls', 'to_number')) {
                DB::statement("UPDATE calls SET {$toCol} = to_number WHERE ({$toCol} IS NULL OR {$toCol} = '') AND to_number IS NOT NULL");
            }

            // Backfill transcription onto calls from legacy table (latest row wins).
            if (Schema::hasTable('call_transcriptions')) {
                DB::statement(
                    "UPDATE calls "
                    . "SET transcript_text = (SELECT ct.transcript_text FROM call_transcriptions ct WHERE ct.call_id = calls.id ORDER BY ct.id DESC LIMIT 1), "
                    . "has_transcription = CASE WHEN EXISTS (SELECT 1 FROM call_transcriptions ct2 WHERE ct2.call_id = calls.id) THEN 1 ELSE has_transcription END "
                    . "WHERE EXISTS (SELECT 1 FROM call_transcriptions ct3 WHERE ct3.call_id = calls.id)"
                );
            }

            // Drop legacy columns.
            Schema::table('calls', function (Blueprint $table) {
                if (Schema::hasColumn('calls', 'recording_available')) {
                    try {
                        $table->dropIndex(['recording_available']);
                    } catch (Throwable $e) {
                        // Ignore: index name differs by driver.
                    }
                    $table->dropColumn('recording_available');
                }

                foreach (['call_uid', 'from_number', 'to_number', 'pbx_provider_id'] as $legacy) {
                    if (Schema::hasColumn('calls', $legacy)) {
                        $table->dropColumn($legacy);
                    }
                }
            });

            // Ensure required composite uniqueness (best-effort).
            try {
                Schema::table('calls', function (Blueprint $table) {
                    $table->unique(['company_pbx_account_id', 'server_id', 'pbx_unique_id'], 'calls_account_server_pbx_unique');
                });
            } catch (Throwable $e) {
                // Ignore if it already exists or columns aren't present.
            }
        }

        // Drop legacy tables (safe even on fresh installs).
        Schema::dropIfExists('transcription_usages');
        Schema::dropIfExists('call_speaker_segments');
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('call_transcriptions');
    }

    public function down(): void
    {
        // Intentionally non-reversible: legacy transcription-related tables are removed by policy.
    }

    private function quoteIdentifier(string $name): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => '"' . str_replace('"', '""', $name) . '"',
            'sqlsrv' => '[' . str_replace(']', ']]', $name) . ']',
            default => '`' . str_replace('`', '``', $name) . '`',
        };
    }
};
