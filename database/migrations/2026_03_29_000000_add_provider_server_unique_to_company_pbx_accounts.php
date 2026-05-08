<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1 — Multi-PBX schema correctness.
 *
 * A given (pbx_provider_id, server_id) pair represents a single PBX tenant
 * (e.g. server_id=10 on PBXware Provider A is NOT the same tenant as
 * server_id=10 on PBXware Provider B). Without a composite unique index on
 * company_pbx_accounts, the same physical tenant can be linked to multiple
 * companies via duplicate rows, and provider-blind lookups silently match
 * tenants from other providers.
 *
 * This migration:
 *   1. Detects existing duplicate (pbx_provider_id, server_id) pairs and
 *      aborts with a clear error so an operator can resolve manually rather
 *      than dropping data.
 *   2. Adds the composite unique index when no duplicates are found.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_pbx_accounts')) {
            return;
        }

        // Guard: detect duplicates before applying the unique constraint.
        $duplicates = DB::table('company_pbx_accounts')
            ->select('pbx_provider_id', 'server_id', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('pbx_provider_id')
            ->whereNotNull('server_id')
            ->groupBy('pbx_provider_id', 'server_id')
            ->having('occurrences', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates
                ->map(fn ($row) => "(pbx_provider_id={$row->pbx_provider_id}, server_id={$row->server_id}, count={$row->occurrences})")
                ->implode(', ');

            throw new \RuntimeException(
                'Cannot add unique index company_pbx_accounts_pbx_provider_id_server_id_unique: '
                . 'duplicate rows exist. Resolve manually before re-running migrate. Duplicates: ' . $detail
            );
        }

        // Skip if the index already exists (idempotent). Use information_schema
        // / pragma fallback rather than Doctrine since Laravel 11 dropped DBAL.
        if ($this->indexExists('company_pbx_accounts', 'company_pbx_accounts_pbx_provider_id_server_id_unique')) {
            return;
        }

        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->unique(
                ['pbx_provider_id', 'server_id'],
                'company_pbx_accounts_pbx_provider_id_server_id_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_pbx_accounts')) {
            return;
        }

        if (! $this->indexExists('company_pbx_accounts', 'company_pbx_accounts_pbx_provider_id_server_id_unique')) {
            return;
        }

        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropUnique('company_pbx_accounts_pbx_provider_id_server_id_unique');
        });
    }

    /**
     * Cross-driver index existence check (MySQL / SQLite / PostgreSQL).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = (string) Schema::getConnection()->getDriverName();

        try {
            switch ($driver) {
                case 'mysql':
                case 'mariadb':
                    $rows = DB::select(
                        'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                        [$table, $indexName]
                    );

                    return ! empty($rows);

                case 'pgsql':
                    $rows = DB::select(
                        "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1",
                        [$table, $indexName]
                    );

                    return ! empty($rows);

                case 'sqlite':
                    $rows = DB::select(
                        "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ? LIMIT 1",
                        [$table, $indexName]
                    );

                    return ! empty($rows);

                default:
                    return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
    }
};
