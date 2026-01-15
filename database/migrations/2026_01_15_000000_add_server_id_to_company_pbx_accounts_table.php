<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->string('server_id')->nullable()->after('api_secret')->index();
        });
    }

    public function down(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropIndex(['server_id']);
            $table->dropColumn('server_id');
        });
    }
};
