<?php

namespace GamePlatform\Billing\Http;

use GamePlatform\Billing\FreePlay;
use GamePlatform\Billing\Player;
use GamePlatform\Billing\PlayTime;
use GamePlatform\Billing\Purchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

class PlayTimeController extends Controller
{
    /** The play-time page (the app's Inertia page, billing.page): what's left, and buying more. */
    public function index(Request $request): Response
    {
        $player = self::player($request);

        return Inertia::render(Config::string('billing.page', 'billing/PlayTime'), [
            'playTime' => PlayTime::status($player),
            'products' => PlayTime::products(),
            'purchases' => $player->purchases()
                ->where('status', Purchase::PAID)
                ->latest('paid_at')
                ->limit(20)
                ->get()
                ->map(fn (Purchase $p): array => [
                    'id' => $p->id,
                    'name' => $p->productName(),
                    'amount' => $p->amount,
                    'paidAt' => $p->paid_at?->format('j M Y, H:i'),
                ]),
        ]);
    }

    /** The game checking in while it's being played. */
    public function store(Request $request, FreePlay $freePlay): JsonResponse
    {
        $player = self::player($request);
        // What's being played, if the page says: it may play free.
        $free = $freePlay->find($player, $request->filled('game') ? $request->string('game')->toString() : null);
        PlayTime::record($player, $request->boolean('resumed'), $free);

        return response()->json(PlayTime::status($player, $free !== null));
    }

    private static function player(Request $request): Model&Player
    {
        return PlayTime::player($request->user());
    }
}
