<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('weekly_call_reports', 'company_pbx_account_id')) {
                $table->foreignId('company_pbx_account_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('company_pbx_accounts')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn('weekly_call_reports', 'week_start_date')) {
                $table->date('week_start_date')->nullable()->after('company_pbx_account_id');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'week_end_date')) {
                $table->date('week_end_date')->nullable()->after('week_start_date');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'answered_calls')) {
                $table->integer('answered_calls')->default(0)->after('total_calls');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'missed_calls')) {
                $table->integer('missed_calls')->default(0)->after('answered_calls');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'calls_with_transcription')) {
                $table->integer('calls_with_transcription')->default(0)->after('missed_calls');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'total_call_duration_seconds')) {
                $table->integer('total_call_duration_seconds')->default(0)->after('calls_with_transcription');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'avg_call_duration_seconds')) {
                $table->integer('avg_call_duration_seconds')->default(0)->after('total_call_duration_seconds');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'first_call_at')) {
                $table->timestamp('first_call_at')->nullable()->after('avg_call_duration_seconds');
            }

            if (! Schema::hasColumn('weekly_call_reports', 'last_call_at')) {
                $table->timestamp('last_call_at')->nullable()->after('first_call_at');
            }
        });

        // Backfill week_start/end from existing reporting_period_* columns (best-effort).
        if (Schema::hasColumn('weekly_call_reports', 'reporting_period_start') && Schema::hasColumn('weekly_call_reports', 'reporting_period_end')) {
            DB::table('weekly_call_reports')
                ->whereNull('week_start_date')
                ->update([
                    'week_start_date' => DB::raw('reporting_period_start'),
                    'week_end_date' => DB::raw('reporting_period_end'),
                ]);
        }

        // Keep legacy columns in place, but switch uniqueness to the required key.
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            // Old unique: company_id + reporting_period_start + reporting_period_end
            if (Schema::hasColumn('weekly_call_reports', 'reporting_period_start') && Schema::hasColumn('weekly_call_reports', 'reporting_period_end')) {
                $table->dropUnique('weekly_call_reports_company_period_unique');
            }

            $table->unique(
                ['company_id', 'company_pbx_account_id', 'week_start_date'],
                'weekly_call_reports_company_account_week_start_unique'
            );

            $table->index(['company_id', 'week_start_date'], 'weekly_call_reports_company_week_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            $table->dropIndex('weekly_call_reports_company_week_start_index');
            $table->dropUnique('weekly_call_reports_company_account_week_start_unique');

            // Restore the old unique if the legacy columns exist.
            if (Schema::hasColumn('weekly_call_reports', 'reporting_period_start') && Schema::hasColumn('weekly_call_reports', 'reporting_period_end')) {
                $table->unique(
                    ['company_id', 'reporting_period_start', 'reporting_period_end'],
                    'weekly_call_reports_company_period_unique'
                );
            }

            $table->dropColumn([
                'company_pbx_account_id',
                'week_start_date',
                'week_end_date',
                'answered_calls',
                'missed_calls',
                'calls_with_transcription',
                'total_call_duration_seconds',
                'avg_call_duration_seconds',
                'first_call_at',
                'last_call_at',
            ]);
        });
    }
};
