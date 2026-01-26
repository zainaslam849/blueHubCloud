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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('company_pbx_account_id')
                ->constrained('company_pbx_accounts')
                ->cascadeOnDelete();

            // PBXware does not expose call media downloads. This system relies on PBX-provided transcriptions only.
            $table->string('server_id')->index();
            $table->string('pbx_unique_id');

            $table->string('from')->nullable();
            $table->string('to')->nullable();

            $table->string('direction')->default('unknown')->index();
            $table->string('status')->index();
            $table->timestamp('started_at')->index();
            $table->integer('duration_seconds')->default(0);

            $table->boolean('has_transcription')->default(false)->index();
            $table->longText('transcript_text')->nullable();

            $table->unique(['company_pbx_account_id', 'server_id', 'pbx_unique_id'], 'calls_account_server_pbx_unique');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
