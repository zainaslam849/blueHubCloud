<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits billing — global pricing settings.
 *
 * 1 credit = 1 USD by default (credit_price_usd is the purchase price of one
 * credit); credits_per_minute is how many credits one minute of call time
 * consumes. Both are admin-configurable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->decimal('credit_price_usd', 8, 2)->default(1.00)->after('weekly_run_timezone');
            $table->decimal('credits_per_minute', 8, 4)->default(1.0000)->after('credit_price_usd');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['credit_price_usd', 'credits_per_minute']);
        });
    }
};
