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
        Schema::create('pbxware_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pbx_provider_id')->constrained('pbx_providers')->cascadeOnDelete();
            $table->string('server_id')->index();
            $table->string('tenant_code')->unique();
            $table->string('name');
            $table->string('package_name')->nullable();
            $table->integer('package_id')->nullable();
            $table->integer('ext_length')->nullable();
            $table->integer('country_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->json('raw_data')->nullable(); // Store full response for future use
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pbx_provider_id', 'server_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pbxware_tenants');
    }
};
