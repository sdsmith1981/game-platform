<?php

namespace GamePlatform\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The little of Stripe the games use: hosted Checkout pages for one-off payments,
 * and the webhook that confirms them. Kept in one class so tests can stand in for it.
 */
class StripeCheckout
{
    private function client(): StripeClient
    {
        return new StripeClient(Config::string('services.stripe.secret'));
    }

    /**
     * Start a Checkout page for a purchase.
     *
     * @return array{id: string, url: string}
     */
    public function create(Purchase $purchase, Model $user, string $successUrl, string $cancelUrl): array
    {
        $product = Config::array("billing.products.{$purchase->product}");

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => (string) $user->getAttribute('email'),
            'client_reference_id' => (string) $user->getKey(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $purchase->currency,
                    'unit_amount' => $purchase->amount,
                    'product_data' => [
                        'name' => (string) $product['name'],
                        'description' => (string) $product['description'],
                    ],
                ],
            ]],
            'metadata' => ['purchase_id' => (string) $purchase->id],
            // Stripe fills in the session id.
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return ['id' => $session->id, 'url' => (string) $session->url];
    }

    /** True if the Checkout session has been paid. */
    public function isPaid(string $sessionId): bool
    {
        return $this->client()->checkout->sessions->retrieve($sessionId)->payment_status === Session::PAYMENT_STATUS_PAID;
    }

    /**
     * The id of a Checkout session a verified webhook says was paid, or null for any
     * other event. Throws if the request didn't come from Stripe.
     *
     * @throws SignatureVerificationException|UnexpectedValueException
     */
    public function paidSessionFromWebhook(string $payload, string $signature): ?string
    {
        $event = Webhook::constructEvent($payload, $signature, Config::string('services.stripe.webhook_secret'));

        if (! in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return null;
        }

        /** @var Session $session */
        $session = $event->data->object;

        return $session->payment_status === Session::PAYMENT_STATUS_PAID ? $session->id : null;
    }
}
