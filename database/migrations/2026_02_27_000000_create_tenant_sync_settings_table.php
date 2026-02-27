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
        Schema::create('tenant_sync_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_provider_id')->constrained('pbx_providers')->onDelete('cascade');
            $table->boolean('enabled')->default(false);
            $table->enum('frequency', ['hourly', 'daily', 'weekly'])->default('daily');
            $table->time('scheduled_time')->default('02:00'); // 2 AM UTC
            $table->string('scheduled_day')->nullable(); // For weekly: 'monday', 'tuesday', etc.
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('last_sync_count')->default(0); // Track how many tenants synced
            $table->text('last_sync_log')->nullable();
            $table->timestamps();
            
            $table->unique('pbx_provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_sync_settings');
    }
};
