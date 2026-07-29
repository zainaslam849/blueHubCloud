<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits billing — per-company balance plus auto-topup configuration.
 *
 * balance is a derived cache of the credit_transactions ledger, updated in
 * the same DB transaction as each ledger write (see CreditService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 4)->default(0);

            $table->boolean('auto_topup_enabled')->default(false);
            $table->decimal('auto_topup_threshold', 12, 4)->nullable();
            $table->decimal('auto_topup_credits', 12, 4)->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_payment_method_id')->nullable();
            $table->timestamp('auto_topup_last_failed_at')->nullable();
            $table->unsignedTinyInteger('auto_topup_failure_count')->default(0);
            $table->timestamp('auto_topup_paused_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_credit_balances');
    }
};
