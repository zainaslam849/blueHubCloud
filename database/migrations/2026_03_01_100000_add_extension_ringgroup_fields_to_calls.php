<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // Extension information
            if (!Schema::hasColumn('calls', 'answered_by_extension')) {
                $table->string('answered_by_extension')->nullable()->after('to')->index();
            }
            if (!Schema::hasColumn('calls', 'caller_extension')) {
                $table->string('caller_extension')->nullable()->after('answered_by_extension')->index();
            }
            
            // Ring group / Queue / Department
            if (!Schema::hasColumn('calls', 'ring_group')) {
                $table->string('ring_group')->nullable()->after('caller_extension')->index();
            }
            if (!Schema::hasColumn('calls', 'queue_name')) {
                $table->string('queue_name')->nullable()->after('ring_group')->index();
            }
            if (!Schema::hasColumn('calls', 'department')) {
                $table->string('department')->nullable()->after('queue_name')->index();
            }
            
            // DID (already exists but ensure indexed)
            if (!Schema::hasColumn('calls', 'did')) {
                $table->string('did')->nullable()->after('department')->index();
            }
            
            // Metadata for additional PBX data
            if (!Schema::hasColumn('calls', 'pbx_metadata')) {
                $table->json('pbx_metadata')->nullable()->after('did');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn([
                'answered_by_extension',
                'caller_extension',
                'ring_group',
                'queue_name',
                'department',
                'pbx_metadata'
            ]);
        });
    }
};
