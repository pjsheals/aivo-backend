<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Models\User;
use Aivo\Models\Subscription;
use Aivo\Models\StripeEvent;

class WebhookController
{
    // ── POST /api/webhook ─────────────────────────────────────────
    // Stripe sends events here for subscription lifecycle.
    // Webhook signing secret is verified before processing.
    public function handle(): void
    {
        $payload   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret    = env('STRIPE_WEBHOOK_SECRET');

        if (!$secret) {
            log_error('STRIPE_WEBHOOK_SECRET not configured');
            abort(500, 'Webhook secret not configured');
        }

        // Verify signature
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            log_error('Webhook signature verification failed', ['error' => $e->getMessage()]);
            abort(400, 'Invalid signature');
        } catch (\Throwable $e) {
            log_error('Webhook parse error', ['error' => $e->getMessage()]);
            abort(400, 'Invalid payload');
        }

        // Idempotency — skip already processed events
        if (StripeEvent::where('stripe_event_id', $event->id)->exists()) {
            json_response(['received' => true, 'duplicate' => true]);
        }

        // Log the event
        StripeEvent::create([
            'stripe_event_id' => $event->id,
            'event_type'      => $event->type,
            'processed'       => false,
        ]);

        // Dispatch
        try {
            match ($event->type) {
                'checkout.session.completed'       => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.updated'    => $this->handleSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted'    => $this->handleSubscriptionDeleted($event->data->object),
                'invoice.payment_failed'           => $this->handlePaymentFailed($event->data->object),
                default                            => null, // ignore other events
            };

            // Mark processed
            StripeEvent::where('stripe_event_id', $event->id)
                ->update(['processed' => true]);

        } catch (\Throwable $e) {
            log_error('Webhook handler error', [
                'event'   => $event->type,
                'eventId' => $event->id,
                'error'   => $e->getMessage(),
            ]);
            // Return 200 anyway — Stripe will retry on non-2xx
            json_response(['received' => true, 'error' => $e->getMessage()]);
        }

        json_response(['received' => true]);
    }

    // ── checkout.session.completed ────────────────────────────────
    // Fired when a new subscriber completes checkout.
    private function handleCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $tier  = $session->metadata['tier'] ?? null;
        $email = $session->metadata['user_email'] ?? null;

        if (!$email || !$tier) {
            // Try to get email from customer
            if ($session->customer) {
                try {
                    $customer = \Stripe\Customer::retrieve((string)$session->customer);
                    $email    = $customer->email;
                } catch (\Throwable) {}
            }
        }

        if (!$email) {
            log_error('checkout.session.completed — no email in metadata', ['session' => $session->id]);
            return;
        }

        $user = User::where('email', strtolower($email))->first();
        if (!$user) {
            // Create user if they don't exist yet
            $user = User::create([
                'email'              => strtolower($email),
                'name'               => explode('@', $email)[0],
                'plan'               => $tier,
                'stripe_customer_id' => $session->customer,
            ]);
        } else {
            $user->plan                   = $tier;
            $user->stripe_customer_id     = $session->customer;
            $user->stripe_subscription_id = $session->subscription;
            $user->upgraded_at            = now();
            $user->save();
        }

        // Subscription record handled by subscription.updated event
    }

    // ── customer.subscription.updated ────────────────────────────
    // Fired on renewals, plan changes, and reactivations.
    private function handleSubscriptionUpdated(\Stripe\Subscription $sub): void
    {
        $priceId = $sub->items->data[0]->price->id ?? '';
        $tier    = $this->tierFromPriceId($priceId);

        // Find user by customer ID
        $user = User::where('stripe_customer_id', $sub->customer)->first();
        if (!$user) {
            log_error('subscription.updated — user not found', ['customer' => $sub->customer]);
            return;
        }

        // Update plan based on subscription status
        $activePlan = in_array($sub->status, ['active', 'trialing']) ? $tier : 'free';
        $user->plan                   = $activePlan;
        $user->stripe_subscription_id = $sub->id;
        $user->save();

        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $sub->id],
            [
                'user_id'              => $user->id,
                'stripe_price_id'      => $priceId,
                'plan'                 => $tier,
                'status'               => $sub->status,
                'current_period_start' => date('Y-m-d H:i:s', $sub->current_period_start),
                'current_period_end'   => date('Y-m-d H:i:s', $sub->current_period_end),
                'canceled_at'          => $sub->canceled_at ? date('Y-m-d H:i:s', $sub->canceled_at) : null,
            ]
        );
    }

    // ── customer.subscription.deleted ────────────────────────────
    // Fired when a subscription is fully cancelled.
    private function handleSubscriptionDeleted(\Stripe\Subscription $sub): void
    {
        $user = User::where('stripe_customer_id', $sub->customer)->first();
        if (!$user) return;

        $user->plan                   = 'free';
        $user->stripe_subscription_id = null;
        $user->save();

        Subscription::where('stripe_subscription_id', $sub->id)
            ->update(['status' => 'canceled', 'canceled_at' => now()]);
    }

    // ── invoice.payment_failed ────────────────────────────────────
    // Fired when a renewal payment fails.
    private function handlePaymentFailed(\Stripe\Invoice $invoice): void
    {
        $user = User::where('stripe_customer_id', $invoice->customer)->first();
        if (!$user) return;

        // Don't immediately downgrade — Stripe retries.
        // Just log it. If the subscription reaches past_due/canceled,
        // subscription.updated will handle the downgrade.
        log_error('Payment failed for user', [
            'email'    => $user->email,
            'invoice'  => $invoice->id,
            'amount'   => $invoice->amount_due,
        ]);
    }

    private function tierFromPriceId(string $priceId): string
    {
        $map = [
            env('STRIPE_PRICE_GROWTH', '') => 'growth',
            env('STRIPE_PRICE_PRO',    '') => 'pro',
            env('STRIPE_PRICE_AGENCY', '') => 'agency',
        ];
        return $map[$priceId] ?? 'growth';
    }
}
