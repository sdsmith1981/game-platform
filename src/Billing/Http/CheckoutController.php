<?php

namespace GamePlatform\Billing\Http;

use GamePlatform\Billing\Events\CheckoutStarted;
use GamePlatform\Billing\Player;
use GamePlatform\Billing\PlayTime;
use GamePlatform\Billing\Purchase;
use GamePlatform\Billing\StripeCheckout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use UnexpectedValueException;

class CheckoutController extends Controller
{
    public function __construct(private readonly StripeCheckout $stripe) {}

    /** Start paying: record the purchase, then send the player to Stripe's Checkout page. */
    public function store(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'product' => ['required', Rule::in(array_keys(Config::array('billing.products')))],
            'terms' => ['accepted'],
            'start_now' => ['accepted'],
            'return_to' => ['nullable', 'string', 'starts_with:/', 'max:200'],
        ], [
            'terms.accepted' => 'Please agree to the terms to continue.',
            'start_now.accepted' => 'Please confirm that play-time starts straight away.',
        ]);

        $user = self::player($request);
        $product = Config::array("billing.products.{$validated['product']}");

        $purchase = $user->purchases()->create([
            'product' => $validated['product'],
            'amount' => (int) $product['price'],
            'currency' => Config::string('billing.currency'),
            'status' => Purchase::PENDING,
            'terms_version' => (string) Config::get('legal.terms_version', ''),
        ]);

        $request->session()->put('checkout_return_to', $validated['return_to'] ?? null);

        try {
            $session = $this->stripe->create(
                $purchase,
                $user,
                route('checkout.success'),
                url($validated['return_to'] ?? route('play-time.index', absolute: false)),
            );
        } catch (Throwable $e) {
            Log::error('Stripe checkout failed', ['purchase' => $purchase->id, 'error' => $e->getMessage()]);
            $purchase->delete();

            return back()->withErrors(['product' => "We couldn't reach the payment provider. Please try again."]);
        }

        $purchase->update(['stripe_session_id' => $session['id']]);
        event(new CheckoutStarted($purchase));

        // Stripe's page is on another site, so leave Inertia for it.
        return Inertia::location($session['url']);
    }

    /** Back from Stripe: add the play-time now, rather than waiting for the webhook. */
    public function success(Request $request): RedirectResponse
    {
        $sessionId = (string) $request->query('session_id');
        $purchase = self::player($request)->purchases()->where('stripe_session_id', $sessionId)->first();

        if ($purchase && $purchase->status !== Purchase::PAID && $this->stripe->isPaid($sessionId)) {
            PlayTime::fulfil($purchase);
        }

        $paid = $purchase?->refresh()->status === Purchase::PAID;
        Inertia::flash('toast', $paid
            ? ['type' => 'success', 'message' => 'Thank you! Your play-time has been added.']
            : ['type' => 'info', 'message' => "Your payment is being confirmed. Your play-time will appear as soon as it's through."]);

        $returnTo = $request->session()->pull('checkout_return_to');

        return redirect(is_string($returnTo) ? $returnTo : route('play-time.index'));
    }

    /** Stripe telling us a payment went through, whether or not the player came back. */
    public function webhook(Request $request): Response
    {
        try {
            $sessionId = $this->stripe->paidSessionFromWebhook(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        if ($sessionId !== null) {
            $purchase = Purchase::query()->where('stripe_session_id', $sessionId)->first();
            if ($purchase) {
                PlayTime::fulfil($purchase);
            }
        }

        return response()->noContent();
    }

    private static function player(Request $request): Model&Player
    {
        return PlayTime::player($request->user());
    }
}
