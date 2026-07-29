<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'monthly_call_limit')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('monthly_call_limit');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('monthly_call_limit')->nullable()->after('company_id');
        });
    }
};
