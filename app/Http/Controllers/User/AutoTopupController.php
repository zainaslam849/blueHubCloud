<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\CompanyCreditBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Customer;
use Stripe\SetupIntent;
use Stripe\Stripe;

/**
 * User-facing auto top-up configuration.
 *
 * Flow: POST /auto-topup/setup-intent returns a SetupIntent client_secret;
 * the frontend confirms it with Stripe.js; POST /auto-topup saves the
 * resulting payment method plus threshold/amount and enables auto top-up.
 */
class AutoTopupController extends Controller
{
    public function createSetupIntent(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'You must be assigned to a company first.'], 422);
        }

        $secretKey = $this->stripeSecretKey();
        if ($secretKey === '') {
            return response()->json(['message' => 'Stripe is not configured. Please contact the administrator.'], 500);
        }

        try {
            Stripe::setApiKey($secretKey);

            $balanceRow = CompanyCreditBalance::firstOrCreate(
                ['company_id' => $user->company_id],
                ['balance' => 0]
            );

            if (! $balanceRow->stripe_customer_id) {
                $customer = Customer::create([
                    'email' => $user->email,
                    'metadata' => ['company_id' => $user->company_id],
                ]);
                $balanceRow->update(['stripe_customer_id' => $customer->id]);
            }

            $setupIntent = SetupIntent::create([
                'customer' => $balanceRow->stripe_customer_id,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
            ]);

            return response()->json([
                'client_secret' => $setupIntent->client_secret,
                'publishable_key' => $this->stripePublicKey(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AutoTopupController: setup intent failed', [
                'company_id' => $user->company_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not start card setup. Please try again later.'], 422);
        }
    }

    /**
     * Save configuration after the SetupIntent was confirmed client-side.
     */
    public function update(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'You must be assigned to a company first.'], 422);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'threshold' => ['required_if:enabled,true', 'nullable', 'numeric', 'min:0'],
            'credits' => ['required_if:enabled,true', 'nullable', 'numeric', 'min:1'],
            'payment_method_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $balanceRow = CompanyCreditBalance::firstOrCreate(
            ['company_id' => $user->company_id],
            ['balance' => 0]
        );

        $attributes = [
            'auto_topup_enabled' => (bool) $validated['enabled'],
            'auto_topup_threshold' => $validated['threshold'] ?? $balanceRow->auto_topup_threshold,
            'auto_topup_credits' => $validated['credits'] ?? $balanceRow->auto_topup_credits,
        ];

        if (! empty($validated['payment_method_id'])) {
            $attributes['stripe_payment_method_id'] = $validated['payment_method_id'];
        }

        // Re-enabling clears the failure pause.
        if ($attributes['auto_topup_enabled']) {
            $attributes['auto_topup_paused_at'] = null;
            $attributes['auto_topup_failure_count'] = 0;

            if (! ($attributes['stripe_payment_method_id'] ?? $balanceRow->stripe_payment_method_id)) {
                return response()->json([
                    'message' => 'Add a payment method before enabling auto top-up.',
                ], 422);
            }
        }

        $balanceRow->update($attributes);

        return response()->json([
            'message' => $attributes['auto_topup_enabled']
                ? 'Auto top-up enabled.'
                : 'Auto top-up disabled.',
            'data' => [
                'enabled' => (bool) $balanceRow->auto_topup_enabled,
                'threshold' => $balanceRow->auto_topup_threshold,
                'credits' => $balanceRow->auto_topup_credits,
                'has_payment_method' => $balanceRow->stripe_payment_method_id !== null,
            ],
        ]);
    }

    private function stripeSecretKey(): string
    {
        $settings = AppSetting::query()->first();

        return (string) ($settings?->stripe_secret_key ?: config('services.stripe.secret', ''));
    }

    private function stripePublicKey(): string
    {
        $settings = AppSetting::query()->first();

        return (string) ($settings?->stripe_public_key ?: config('services.stripe.key', ''));
    }
}
