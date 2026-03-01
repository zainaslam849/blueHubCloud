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
            $table->string('answered_by_extension')->nullable()->after('to')->index();
            $table->string('caller_extension')->nullable()->after('answered_by_extension')->index();
            
            // Ring group / Queue / Department
            $table->string('ring_group')->nullable()->after('caller_extension')->index();
            $table->string('queue_name')->nullable()->after('ring_group')->index();
            $table->string('department')->nullable()->after('queue_name')->index();
            
            // DID (already exists but ensure indexed)
            if (!Schema::hasColumn('calls', 'did')) {
                $table->string('did')->nullable()->after('department')->index();
            } else {
                // Just add index if column exists
                $table->index('did');
            }
            
            // Metadata for additional PBX data
            $table->json('pbx_metadata')->nullable()->after('did');
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
