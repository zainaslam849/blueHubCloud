<?php

namespace App\Services\Billing;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyCreditBalance;
use App\Models\CreditTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for all credit balance mutations.
 *
 * Every mutation writes an append-only CreditTransaction row and updates the
 * cached CompanyCreditBalance.balance inside one DB transaction, with the
 * balance row locked (lockForUpdate) to serialize concurrent writers.
 *
 * Deductions are idempotent per reference: the ledger's unique index on
 * (reference_type, reference_id, type) makes a retried queue job a no-op.
 */
class CreditService
{
    /**
     * Admin-configured credits consumed by one minute of call time.
     */
    public function creditsPerMinute(): float
    {
        $value = (float) (AppSetting::query()->value('credits_per_minute') ?? 1.0);

        return $value > 0 ? $value : 1.0;
    }

    /**
     * Admin-configured USD price of one credit (default 1 credit = 1 USD).
     */
    public function creditPriceUsd(): float
    {
        $value = (float) (AppSetting::query()->value('credit_price_usd') ?? 1.0);

        return $value > 0 ? $value : 1.0;
    }

    public function availableCredits(Company $company): float
    {
        return (float) (CompanyCreditBalance::query()
            ->where('company_id', $company->id)
            ->value('balance') ?? 0.0);
    }

    /**
     * How many seconds of call time the company's balance can pay for.
     * Exact-seconds prorating: cost = duration_seconds / 60 * credits_per_minute.
     */
    public function secondsBudget(Company $company): int
    {
        $balance = $this->availableCredits($company);
        if ($balance <= 0) {
            return 0;
        }

        return (int) floor($balance / $this->creditsPerMinute() * 60);
    }

    /**
     * Credit cost of a single call, prorated to exact seconds.
     */
    public function costForSeconds(int $durationSeconds): float
    {
        return round(max(0, $durationSeconds) / 60 * $this->creditsPerMinute(), 4);
    }

    /**
     * Add credits (purchase, auto-topup, manual adjustment, refund).
     * Returns the ledger row, or null if this reference was already credited
     * (idempotent replay).
     */
    public function credit(
        Company $company,
        float $credits,
        string $type,
        ?object $reference = null,
        array $meta = [],
        ?int $createdBy = null
    ): ?CreditTransaction {
        if ($credits <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return $this->applyLedgerEntry($company, $credits, $type, $reference, $meta, $createdBy);
    }

    /**
     * Deduct credits (negative ledger entry). The balance may go below zero
     * only when $allowOverdraw is set (a call already ingested must be paid
     * for even if it slightly overshoots the remaining balance).
     * Returns the ledger row, or null when this reference was already
     * deducted (idempotent replay).
     */
    public function deduct(
        Company $company,
        float $credits,
        ?object $reference = null,
        array $meta = [],
        bool $allowOverdraw = true
    ): ?CreditTransaction {
        if ($credits <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive.');
        }

        return $this->applyLedgerEntry(
            $company,
            -$credits,
            CreditTransaction::TYPE_DEDUCTION,
            $reference,
            $meta,
            null,
            $allowOverdraw
        );
    }

    protected function applyLedgerEntry(
        Company $company,
        float $signedCredits,
        string $type,
        ?object $reference,
        array $meta,
        ?int $createdBy,
        bool $allowOverdraw = true
    ): ?CreditTransaction {
        return DB::transaction(function () use ($company, $signedCredits, $type, $reference, $meta, $createdBy, $allowOverdraw) {
            $balanceRow = CompanyCreditBalance::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if (! $balanceRow) {
                $balanceRow = CompanyCreditBalance::create(['company_id' => $company->id, 'balance' => 0]);
                // Re-acquire under lock so the balance update below is serialized.
                $balanceRow = CompanyCreditBalance::query()
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->first();
            }

            // Idempotency: skip if this exact reference+type already has a row.
            if ($reference !== null) {
                $exists = CreditTransaction::query()
                    ->where('reference_type', get_class($reference))
                    ->where('reference_id', $reference->id)
                    ->where('type', $type)
                    ->exists();

                if ($exists) {
                    return null;
                }
            }

            $newBalance = round((float) $balanceRow->balance + $signedCredits, 4);

            if ($signedCredits < 0 && ! $allowOverdraw && $newBalance < 0) {
                throw new InsufficientCreditsException(
                    "Company {$company->id} has insufficient credits: balance {$balanceRow->balance}, required " . abs($signedCredits)
                );
            }

            try {
                $transaction = CreditTransaction::create([
                    'company_id' => $company->id,
                    'type' => $type,
                    'credits' => round($signedCredits, 4),
                    'balance_after' => $newBalance,
                    'reference_type' => $reference !== null ? get_class($reference) : null,
                    'reference_id' => $reference?->id,
                    'meta' => $meta ?: null,
                    'created_by' => $createdBy,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against a concurrent identical write: treat as replay.
                Log::info('CreditService: duplicate ledger reference ignored', [
                    'company_id' => $company->id,
                    'type' => $type,
                    'reference_type' => $reference !== null ? get_class($reference) : null,
                    'reference_id' => $reference?->id,
                ]);

                return null;
            }

            $balanceRow->update(['balance' => $newBalance]);

            return $transaction;
        });
    }
}
