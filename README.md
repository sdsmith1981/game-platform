# game-platform

Shared platform for sdsmith1981's browser games (Ace Vectura, Landfall): a Laravel package plus an npm package of Vue components and TypeScript helpers.

## What's in it

| Area | PHP | JS |
|---|---|---|
| Social login (Google, Facebook, GitHub) | `GamePlatform\Social\*`, `GamePlatform\Http\Middleware\RequirePasswordIfSet` | `components/SocialLogins.vue` |
| Play-time billing (Stripe Checkout, metered play) | `GamePlatform\Billing\*` | `billing` (types, formatting), `playTime` (check-ins), `xsrf` |

More areas (billing, saves, offline, ops, engine utilities) move in one at a time.

## Using it in a game

```bash
composer require sdsmith1981/game-platform
npm install github:sdsmith1981/game-platform
```

- Add `GamePlatform\Social\HasSocialAccounts` to the user model; set `game-platform.user_model` if it isn't `App\Models\User`.
- Publish the `social_accounts` migration if the app doesn't have it: `php artisan vendor:publish --tag=game-platform-migrations`.
- Alias the confirm-password middleware: `$middleware->alias(['password.confirm' => RequirePasswordIfSet::class])`.
- Listen for `GamePlatform\Social\SocialUserCreated` to set up new players.
- Pass `SocialLogin::available()` to the log in and sign up pages and render `@sdsmith1981/game-platform/components/SocialLogins.vue`; add the package to Tailwind's `@source`.
- Billing: the user model implements `GamePlatform\Billing\Player` and uses `HasPlayTime`. Publish the play-time migration if needed. Set products, prices and the free allowance in the app's `config/billing.php`, and make an Inertia page `billing/PlayTime` (props: `playTime`, `products`, `purchases`). Stripe keys go in `services.stripe` (`secret`, `webhook_secret`); the webhook is `POST /stripe/webhook`.
- Free play (a tutorial, say): bind `GamePlatform\Billing\FreePlay`. The game sends what's being played as `game` in its check-ins (`trackPlayTime(..., gameId)`).
- Listen for `Billing\Events\PurchaseFulfilled` (receipts, stats), `CheckoutStarted` and `PlayerActive`.
- Provider credentials live in `config/services.php` (`client_id`, `client_secret`, `redirect` = `/auth/{provider}/callback`).

## Developing

```bash
composer install
composer test
```
