<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            // Stripe identifiers
            $table->string('stripe_session_id')->nullable()->unique()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();

            // Amounts
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('minutes_added')->default(0);

            // Snapshot of plan at purchase time
            $table->string('plan_name')->nullable();
            $table->decimal('plan_price', 10, 2)->nullable();

            // Status: pending → completed | failed | refunded
            $table->string('status', 20)->default('pending')->index();

            $table->timestamp('purchased_at')->nullable();
            $table->json('stripe_metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_purchases');
    }
};
