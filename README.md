# game-platform

Shared platform for sdsmith1981's browser games (Ace Vectura, Landfall): a Laravel package plus an npm package of Vue components and TypeScript helpers.

## What's in it

| Area | PHP | JS |
|---|---|---|
| Social login (Google, Facebook, GitHub) | `GamePlatform\Social\*`, `GamePlatform\Http\Middleware\RequirePasswordIfSet` | `components/SocialLogins.vue` |
| Saves (numbered revisions in R2, conflict check, gzip uploads) | `GamePlatform\Saves\*`, `GamePlatform\Http\Middleware\DecompressGzipRequest` | `saving` (send, load) |
| Offline play (service worker, games kept in IndexedDB, sync with conflicts) | `GamePlatform\Offline\ServiceWorker` | `offline/store`, `offline/sync`, `offline/register` |
| Ops: browser errors, feedback to GitHub issues, daily stats, what's new, queued mail, meta tags | `GamePlatform\Ops\*`, `GamePlatform\Mail\QueuedMail`, `stats:report`, `feedback:resend`, `game-platform::meta` | `errorLog` |
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
- Saves: name the storage disk in `filesystems.games` (an R2/S3 bucket in production). The save model uses `StoresRevisions` (columns `revision`, `path`, `state`); the save action extends `RevisionedSave`, which refuses (null, so the app replies 409) a save based on an old revision. Put `DecompressGzipRequest` on the save route. Extend `SaveFiles` to name a game's other files.
- Offline: serve `ServiceWorker::response('my-game-', ['/offline', ...icons], $versionSalt)` at `/sw.js` and call `registerOfflineWorker()` on the game's pages. Make a store with `createOfflineStore<MyGame>({ dbName })` (never rename the database) and its uploads with `createOfflineSync(store, { saveUrl, refreshUrl, importUrl, newGameBody })`. The offline page itself is the game's.
- Ops: call `installErrorLog()` at startup. Feedback goes to `services.github_issues` (`token`, `repo`); bind `FeedbackContext` to add a game's details and save. Schedule `feedback:resend` hourly. Release notes go in `config/whats-new.php` (`releases`, newest first); share `WhatsNew::unseenBy($user)` with pages. Count sign-ups and games started with `Stats::record()`; billing counts itself. Use `QueuedMail` on queued notifications, and `@include('game-platform::meta', ['meta' => [...]])` on public pages.
- Free play (a tutorial, say): bind `GamePlatform\Billing\FreePlay`. The game sends what's being played as `game` in its check-ins (`trackPlayTime(..., gameId)`).
- Listen for `Billing\Events\PurchaseFulfilled` (receipts, stats), `CheckoutStarted` and `PlayerActive`.
- Provider credentials live in `config/services.php` (`client_id`, `client_secret`, `redirect` = `/auth/{provider}/callback`).

## Developing

```bash
composer install
composer test
```
