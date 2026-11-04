<?php

namespace GamePlatform\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WhatsNewController extends Controller
{
    /** The player closed the "What's new" window: don't show these releases again. */
    public function store(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Model, 403);
        WhatsNew::markSeen($user);

        return response()->noContent();
    }
}
