<?php

namespace App\Services\Billing;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyCreditBalance;
use App\Models\CreditTransaction;
use App\Models\PlanPurchase;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Off-session auto top-up: when a company opted in and its balance fell
 * below its threshold, charge the saved card for the configured credit
 * amount before the weekly run fetches calls.
 *
 * Payment failures NEVER abort the pipeline: they are recorded on the
 * balance row and, after MAX_CONSECUTIVE_FAILURES, auto-topup is paused
 * until the user re-enables it.
 */
class AutoTopupService
{
    public const MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(protected CreditService $creditService)
    {
    }

    /**
     * Attempt a top-up if the company qualifies. $periodKey scopes the
     * Stripe idempotency key so at most one charge is attempted per company
     * per weekly period, even across job retries.
     *
     * Returns true when a top-up was successfully charged.
     */
    public function maybeTopUp(Company $company, string $periodKey): bool
    {
        $balanceRow = CompanyCreditBalance::query()->where('company_id', $company->id)->first();

        if (! $balanceRow || ! $balanceRow->isAutoTopupActive()) {
            return false;
        }

        if ((float) $balanceRow->balance >= (float) $balanceRow->auto_topup_threshold) {
            return false;
        }

        $credits = (float) $balanceRow->auto_topup_credits;
        $amountUsd = round($credits * $this->creditService->creditPriceUsd(), 2);
        $amountCents = (int) round($amountUsd * 100);

        if ($credits <= 0 || $amountCents <= 0) {
            return false;
        }

        $secretKey = $this->stripeSecretKey();
        if ($secretKey === '') {
            Log::warning('AutoTopupService: Stripe not configured; skipping top-up', ['company_id' => $company->id]);

            return false;
        }

        try {
            Stripe::setApiKey($secretKey);

            $paymentIntent = PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => 'usd',
                'customer' => $balanceRow->stripe_customer_id,
                'payment_method' => $balanceRow->stripe_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => 'Automatic credit top-up (' . $credits . ' credits)',
                'metadata' => [
                    'company_id' => $company->id,
                    'source' => 'auto_topup',
                    'period' => $periodKey,
                ],
            ], [
                'idempotency_key' => 'topup:' . $company->id . ':' . $periodKey,
            ]);

            if (($paymentIntent->status ?? null) !== 'succeeded') {
                $this->recordFailure($balanceRow, 'PaymentIntent status: ' . ($paymentIntent->status ?? 'unknown'));

                return false;
            }

            $purchase = PlanPurchase::create([
                'user_id' => null,
                'company_id' => $company->id,
                'plan_id' => null,
                'stripe_session_id' => 'auto-topup-' . $paymentIntent->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount_paid' => $amountUsd,
                'currency' => 'usd',
                'minutes_added' => 0,
                'credits_added' => $credits,
                'plan_name' => 'Auto top-up',
                'plan_price' => $amountUsd,
                'status' => 'completed',
                'purchased_at' => now(),
                'stripe_metadata' => ['source' => 'auto_topup', 'period' => $periodKey],
            ]);

            $this->creditService->credit(
                $company,
                $credits,
                CreditTransaction::TYPE_AUTO_TOPUP,
                $purchase,
                ['period' => $periodKey, 'amount_usd' => $amountUsd]
            );

            $balanceRow->update([
                'auto_topup_failure_count' => 0,
                'auto_topup_last_failed_at' => null,
            ]);

            Log::info('AutoTopupService: top-up succeeded', [
                'company_id' => $company->id,
                'credits' => $credits,
                'amount_usd' => $amountUsd,
                'payment_intent' => $paymentIntent->id,
            ]);

            return true;
        } catch (CardException $e) {
            $this->recordFailure($balanceRow, $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            // Includes authentication_required and network errors. Never rethrow.
            $this->recordFailure($balanceRow, $e->getMessage());

            return false;
        }
    }

    protected function recordFailure(CompanyCreditBalance $balanceRow, string $reason): void
    {
        $failures = (int) $balanceRow->auto_topup_failure_count + 1;

        $attributes = [
            'auto_topup_failure_count' => $failures,
            'auto_topup_last_failed_at' => now(),
        ];

        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $attributes['auto_topup_paused_at'] = now();
        }

        $balanceRow->update($attributes);

        Log::warning('AutoTopupService: top-up failed', [
            'company_id' => $balanceRow->company_id,
            'failure_count' => $failures,
            'paused' => $failures >= self::MAX_CONSECUTIVE_FAILURES,
            'reason' => $reason,
        ]);
    }

    protected function stripeSecretKey(): string
    {
        $settings = AppSetting::query()->first();

        return (string) ($settings?->stripe_secret_key ?: config('services.stripe.secret', ''));
    }
}
