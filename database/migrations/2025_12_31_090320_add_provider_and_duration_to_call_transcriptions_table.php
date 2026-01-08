<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('call_transcriptions', function (Blueprint $table) {
            $table->string('provider_name')->default('unknown')->after('call_id');
            $table->integer('duration_seconds')->default(0)->after('transcript_text');

            $table->unique(['call_id', 'provider_name', 'language'], 'call_transcriptions_call_provider_lang_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_transcriptions', function (Blueprint $table) {
            $table->dropUnique('call_transcriptions_call_provider_lang_unique');
            $table->dropColumn(['provider_name', 'duration_seconds']);
        });
    }
};
