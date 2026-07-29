<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('monthly_call_limit')->nullable()->after('status');
            $table->unsignedInteger('call_limit_used')->default(0)->after('monthly_call_limit');
            $table->date('call_limit_expires_at')->nullable()->after('call_limit_used');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['monthly_call_limit', 'call_limit_used', 'call_limit_expires_at']);
        });
    }
};
