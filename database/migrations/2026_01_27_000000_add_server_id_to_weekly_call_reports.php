<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('weekly_call_reports', 'server_id')) {
                $table->string('server_id')->nullable()->after('company_pbx_account_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('weekly_call_reports', function (Blueprint $table) {
            if (Schema::hasColumn('weekly_call_reports', 'server_id')) {
                $table->dropIndex(['server_id']);
                $table->dropColumn('server_id');
            }
        });
    }
};
