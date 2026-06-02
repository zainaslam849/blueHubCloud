<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\CompanyMinuteBalance;
use App\Models\Plan;
use App\Models\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeController extends Controller
{
    /** Load Stripe secret key from DB settings, fall back to .env */
    private function stripeSecretKey(): string
    {
        $settings = AppSetting::query()->first();
        return $settings?->stripe_secret_key ?: config('services.stripe.secret', '');
    }

    /** Load Stripe webhook secret from DB settings, fall back to .env */
    private function stripeWebhookSecret(): string
    {
        $settings = AppSetting::query()->first();
        return $settings?->stripe_webhook_secret ?: config('services.stripe.webhook_secret', '');
    }

    /**
     * POST /api/v1/stripe/create-checkout
     * Creates a Stripe Checkout Session and returns the URL.
     */
    public function createCheckout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json([
                'message' => 'You must be assigned to a company before purchasing a plan.',
            ], 422);
        }

        $plan = Plan::where('is_active', true)->findOrFail($validated['plan_id']);

        $priceInCents = (int) round((float) $plan->effective_price * 100);

        if ($priceInCents <= 0) {
            return response()->json(['message' => 'Plan price must be greater than zero.'], 422);
        }

        $secretKey = $this->stripeSecretKey();

        if (empty($secretKey)) {
            return response()->json(['message' => 'Stripe is not configured. Please contact the administrator.'], 500);
        }

        Stripe::setApiKey($secretKey);

        $appUrl = rtrim(config('app.url'), '/');

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $priceInCents,
                    'product_data' => [
                        'name'        => $plan->name,
                        'description' => "{$plan->minute_limit} call minutes included",
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => "{$appUrl}/purchase/success?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url'  => "{$appUrl}/select-plan?cancelled=1",
            'metadata'    => [
                'user_id'    => $user->id,
                'company_id' => $user->company_id,
                'plan_id'    => $plan->id,
            ],
            'customer_email' => $user->email,
        ]);

        // Create a pending purchase record
        PlanPurchase::create([
            'user_id'          => $user->id,
            'company_id'       => $user->company_id,
            'plan_id'          => $plan->id,
            'stripe_session_id'=> $session->id,
            'amount_paid'      => $plan->effective_price,
            'currency'         => 'usd',
            'minutes_added'    => $plan->minute_limit,
            'plan_name'        => $plan->name,
            'plan_price'       => $plan->effective_price,
            'status'           => 'pending',
        ]);

        return response()->json(['checkout_url' => $session->url]);
    }

    /**
     * POST /stripe/webhook  (CSRF excluded)
     * Handles Stripe events — credits minutes on successful payment.
     */
    public function webhook(Request $request)
    {
        $webhookSecret = $this->stripeWebhookSecret();
        $payload       = $request->getContent();
        $sigHeader     = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook parse error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook error'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event->data->object);
        }

        return response()->json(['received' => true]);
    }

    /**
     * GET /api/v1/stripe/verify/{sessionId}
     * Polls session status so the success page can confirm payment.
     */
    public function verify(string $sessionId): JsonResponse
    {
        $purchase = PlanPurchase::where('stripe_session_id', $sessionId)->first();

        if (! $purchase) {
            return response()->json(['status' => 'not_found'], 404);
        }

        // If still pending, try to fulfil from Stripe directly
        if ($purchase->status === 'pending') {
            try {
                Stripe::setApiKey($this->stripeSecretKey());
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
                if ($session->payment_status === 'paid') {
                    $this->fulfilPurchase($purchase);
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe verify fallback failed', ['error' => $e->getMessage()]);
            }
        }

        $purchase->refresh();

        return response()->json([
            'status'       => $purchase->status,
            'plan_name'    => $purchase->plan_name,
            'minutes_added'=> $purchase->minutes_added,
            'amount_paid'  => $purchase->amount_paid,
        ]);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $purchase = PlanPurchase::where('stripe_session_id', $session->id)->first();

        if (! $purchase) {
            // Session may have been created outside our flow — ignore
            Log::warning('Stripe webhook: no matching purchase for session', ['session_id' => $session->id]);
            return;
        }

        if ($purchase->isCompleted()) {
            return; // Already processed (idempotency guard)
        }

        $this->fulfilPurchase($purchase, $session->payment_intent ?? null);
    }

    private function fulfilPurchase(PlanPurchase $purchase, ?string $paymentIntentId = null): void
    {
        DB::transaction(function () use ($purchase, $paymentIntentId) {
            // Add minutes to company balance
            CompanyMinuteBalance::updateOrCreate(
                ['company_id' => $purchase->company_id],
                ['plan_id' => $purchase->plan_id, 'purchased_minutes' => 0, 'used_minutes' => 0]
            );

            CompanyMinuteBalance::where('company_id', $purchase->company_id)
                ->increment('purchased_minutes', $purchase->minutes_added);

            // Mark purchase as completed
            $purchase->update([
                'status'                   => 'completed',
                'purchased_at'             => now(),
                'stripe_payment_intent_id' => $paymentIntentId ?? $purchase->stripe_payment_intent_id,
            ]);

            Log::info('Plan purchase fulfilled', [
                'purchase_id' => $purchase->id,
                'user_id'     => $purchase->user_id,
                'company_id'  => $purchase->company_id,
                'minutes'     => $purchase->minutes_added,
            ]);
        });
    }
}
