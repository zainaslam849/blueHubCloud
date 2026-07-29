<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits billing — plans become credit bundles. minute_limit is retained
 * (nullable) for historical rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('credits', 12, 4)->default(0)->after('minute_limit');
        });

        Schema::table('plan_purchases', function (Blueprint $table) {
            $table->decimal('credits_added', 12, 4)->default(0)->after('minutes_added');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('credits');
        });

        Schema::table('plan_purchases', function (Blueprint $table) {
            $table->dropColumn('credits_added');
        });
    }
};
