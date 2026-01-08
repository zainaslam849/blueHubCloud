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
        Schema::create('call_speaker_segments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('call_id')
                ->constrained('calls')
                ->cascadeOnDelete();

            $table->string('speaker_label');
            $table->integer('start_second');
            $table->integer('end_second');
            $table->text('text');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_speaker_segments');
    }
};
