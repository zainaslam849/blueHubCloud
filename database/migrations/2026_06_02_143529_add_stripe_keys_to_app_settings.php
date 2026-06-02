<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('stripe_public_key')->nullable()->after('admin_notification_email');
            $table->text('stripe_secret_key')->nullable()->after('stripe_public_key');
            $table->text('stripe_webhook_secret')->nullable()->after('stripe_secret_key');
            $table->boolean('stripe_test_mode')->default(true)->after('stripe_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_test_mode']);
        });
    }
};
