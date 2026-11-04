<?php

namespace GamePlatform\Ops;

use GamePlatform\Billing\Player;
use GamePlatform\Billing\PlayTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class FeedbackController extends Controller
{
    /** Most details the browser may send, and how long each may be. */
    private const int MAX_META = 40;

    private const int MAX_ERRORS = 10;

    /** A player's bug report or suggestion: kept here, and sent on to GitHub. */
    public function store(Request $request, FeedbackContext $context): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([Feedback::BUG, Feedback::SUGGESTION])],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'game' => ['nullable', 'string', 'max:64'],
            'include_state' => ['boolean'],
            'meta' => ['nullable', 'array', 'max:'.self::MAX_META],
            'errors' => ['nullable', 'array', 'max:'.self::MAX_ERRORS],
            'errors.*' => ['string', 'max:2000'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof Model, 403);
        $game = isset($validated['game']) ? $context->game($user, $validated['game']) : null;

        $feedback = Feedback::query()->create([
            'user_id' => $user->getKey(),
            'game_id' => $game?->getKey(),
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'meta' => [
                ...$this->clientDetails($validated['meta'] ?? []),
                ...$this->serverDetails($user, $context->details($game)),
                'errors' => $validated['errors'] ?? [],
            ],
            // Only ever the player's own game, and only if they agreed.
            'state' => $game && $request->boolean('include_state') ? $context->snapshot($game) : null,
            'status' => Feedback::PENDING,
        ]);

        self::send($feedback);

        return response()->json(['id' => $feedback->id, 'sent' => $feedback->status === Feedback::SENT]);
    }

    /** Send to GitHub, recording how it went; a failure is kept to try again. */
    public static function send(Feedback $feedback): void
    {
        try {
            $feedback->update(['issue_url' => GithubIssues::create($feedback), 'status' => Feedback::SENT]);
        } catch (Throwable $e) {
            Log::warning('Feedback not sent to GitHub', ['feedback' => $feedback->id, 'error' => $e->getMessage()]);
            $feedback->update(['status' => Feedback::FAILED]);
        }
    }

    /**
     * What the browser told us, kept short and flat.
     *
     * @param  array<mixed>  $meta
     * @return array<string, string|int|float|bool|null>
     */
    private function clientDetails(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            if (! is_string($key) || ! (is_scalar($value) || $value === null)) {
                continue;
            }
            $clean[Str::limit($key, 40, '')] = is_string($value) ? Str::limit($value, 300) : $value;
        }
        // Never pass on an email address, whatever the browser sent.
        unset($clean['email']);

        return $clean;
    }

    /**
     * What the server knows: never the email address.
     *
     * @param  array<string, string|int|bool|null>  $game  What the game adds (FeedbackContext).
     * @return array<string, string|int|bool|null>
     */
    private function serverDetails(Model $user, array $game): array
    {
        $created = $user->getAttribute('created_at');
        $details = [
            'user' => $user->getKey(),
            'account age (days)' => $created instanceof \DateTimeInterface ? (int) now()->diffInDays($created, true) : null,
        ];

        if ($user instanceof Player) {
            $playTime = PlayTime::status($user);
            $details['play-time'] = $playTime['unlimited'] ? 'unlimited' : intdiv($playTime['remainingSeconds'], 60).' min left';
        }

        return [
            ...$details,
            ...$game,
            'app version' => Config::get('app.version') ?: 'unknown',
            'environment' => app()->environment(),
            'reported at' => now()->toIso8601String(),
        ];
    }
}
