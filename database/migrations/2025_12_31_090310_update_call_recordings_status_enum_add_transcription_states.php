<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL enum modification (Laravel Schema Builder cannot alter enum values without additional tooling).
        DB::statement("ALTER TABLE `call_recordings` MODIFY `status` ENUM('uploaded','stored','queued','processing','completed','transcribing','transcribed','failed') NOT NULL DEFAULT 'uploaded'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `call_recordings` MODIFY `status` ENUM('uploaded','stored','queued','processing','completed','failed') NOT NULL DEFAULT 'uploaded'");
    }
};
