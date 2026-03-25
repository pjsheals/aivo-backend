<?php

declare(strict_types=1);

namespace Aivo\Controllers;

use Aivo\Models\User;
use Aivo\Models\Subscription;

class CheckoutController
{
    // ── POST /api/create-checkout-session ─────────────────────────
    // Called by the HTML when a user clicks Upgrade.
    // Creates a Stripe Checkout session and returns the URL.
    public function createSession(): void
    {
        $body       = request_body();
        $priceId    = $body['priceId']    ?? null;
        $tier       = $body['tier']       ?? null;
        $userEmail  = $body['userEmail']  ?? null;
        $userName   = $body['userName']   ?? null;
        $successUrl = $body['successUrl'] ?? null;
        $cancelUrl  = $body['cancelUrl']  ?? null;

        if (!$priceId || !$userEmail || !$successUrl || !$cancelUrl) {
            abort(422, 'Missing required fields: priceId, userEmail, successUrl, cancelUrl');
        }

        // Validate email
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Invalid email address');
        }

        try {
            // Find or create user record
            $user = User::firstOrCreate(
                ['email' => strtolower(trim($userEmail))],
                [
                    'name'    => $userName ?? explode('@', $userEmail)[0],
                    'plan'    => 'free',
                ]
            );

            // Get or create Stripe customer
            $customerId = $user->stripe_customer_id;

            if (!$customerId) {
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name'  => $user->name,
                    'metadata' => [
                        'aivo_user_id' => $user->id,
                        'tier'         => $tier,
                    ],
                ]);
                $customerId = $customer->id;
                $user->stripe_customer_id = $customerId;
                $user->save();
            }

            // Create Checkout Session
            $session = \Stripe\Checkout\Session::create([
                'customer'             => $customerId,
                'payment_method_types' => ['card'],
                'mode'                 => 'subscription',
                'line_items'           => [[
                    'price'    => $priceId,
                    'quantity' => 1,
                ]],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'metadata'    => [
                    'aivo_user_id' => $user->id,
                    'tier'         => $tier,
                    'user_email'   => $user->email,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'aivo_user_id' => $user->id,
                        'tier'         => $tier,
                    ],
                ],
                'allow_promotion_codes' => true,
            ]);

            json_response(['url' => $session->url, 'sessionId' => $session->id]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            log_error('Stripe API error in createSession', ['error' => $e->getMessage(), 'email' => $userEmail]);
            abort(502, 'Payment provider error: ' . $e->getMessage());
        } catch (\Throwable $e) {
            log_error('createSession error', ['error' => $e->getMessage()]);
            abort(500, 'Internal error');
        }
    }

    // ── POST /api/verify-session ──────────────────────────────────
    // Called by the HTML after Stripe redirects back.
    // Confirms the session succeeded and returns the confirmed plan.
    public function verifySession(): void
    {
        $body      = request_body();
        $sessionId = $body['sessionId'] ?? null;
        $userEmail = $body['userEmail'] ?? null;

        if (!$sessionId || !$userEmail) {
            abort(422, 'Missing sessionId or userEmail');
        }

        try {
            $session = \Stripe\Checkout\Session::retrieve([
                'id'     => $sessionId,
                'expand' => ['subscription', 'customer'],
            ]);

            if ($session->payment_status !== 'paid' && $session->status !== 'complete') {
                abort(402, 'Payment not completed');
            }

            // Determine tier from metadata or price
            $tier = $session->metadata['tier'] ?? null;
            if (!$tier) {
                $tier = $this->tierFromPriceId(
                    $session->subscription?->items?->data[0]?->price?->id ?? ''
                );
            }

            // Update user
            $user = User::where('email', strtolower(trim($userEmail)))->first();
            if ($user) {
                $user->plan                   = $tier;
                $user->stripe_subscription_id = $session->subscription?->id;
                $user->upgraded_at            = now();
                $user->save();

                // Upsert subscription record
                if ($session->subscription) {
                    Subscription::updateOrCreate(
                        ['stripe_subscription_id' => $session->subscription->id],
                        [
                            'user_id'               => $user->id,
                            'stripe_price_id'       => $session->subscription->items->data[0]->price->id ?? '',
                            'plan'                  => $tier,
                            'status'                => $session->subscription->status,
                            'current_period_start'  => date('Y-m-d H:i:s', $session->subscription->current_period_start),
                            'current_period_end'    => date('Y-m-d H:i:s', $session->subscription->current_period_end),
                        ]
                    );
                }
            }

            json_response([
                'verified' => true,
                'plan'     => $tier,
                'email'    => $userEmail,
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            log_error('Stripe verify error', ['error' => $e->getMessage(), 'session' => $sessionId]);
            abort(502, 'Could not verify session: ' . $e->getMessage());
        } catch (\Throwable $e) {
            log_error('verifySession error', ['error' => $e->getMessage()]);
            abort(500, 'Internal error');
        }
    }

    // ── Map Stripe price ID → tier string ────────────────────────
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
