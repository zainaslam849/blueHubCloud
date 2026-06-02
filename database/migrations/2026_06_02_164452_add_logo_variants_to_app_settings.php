<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // logo_light = used on dark backgrounds (white/light logo)
            // logo_dark  = used on light backgrounds (dark/coloured logo)
            $table->string('admin_logo_light_url')->nullable()->after('admin_logo_url');
            $table->string('admin_logo_dark_url')->nullable()->after('admin_logo_light_url');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['admin_logo_light_url', 'admin_logo_dark_url']);
        });
    }
};
