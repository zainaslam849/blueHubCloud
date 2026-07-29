<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits billing — append-only ledger.
 *
 * credits is signed: positive for purchase/auto_topup/adjustment credits,
 * negative for deductions. The composite unique on
 * (reference_type, reference_id, type) makes per-call deduction idempotent
 * across queue-job retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // purchase | auto_topup | deduction | adjustment | refund
            $table->decimal('credits', 12, 4);
            $table->decimal('balance_after', 12, 4);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->unique(['reference_type', 'reference_id', 'type'], 'credit_transactions_reference_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
