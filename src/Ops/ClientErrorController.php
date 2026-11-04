<?php

namespace GamePlatform\Ops;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Errors from players' browsers, reported like the server's own so they reach
 * Nightwatch. Anything that looks like an email address is blanked out first, and only
 * the page's path is kept, not its query string.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0'],
            'column' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);

        $page = parse_url((string) ($validated['page'] ?? ''), PHP_URL_PATH);
        // Scripts are reported by their path, so the same file groups together on every host.
        $source = isset($validated['source']) ? (parse_url($validated['source'], PHP_URL_PATH) ?: null) : null;

        report(new BrowserError(
            self::scrub($validated['message']),
            $source,
            isset($validated['line']) ? (int) $validated['line'] : null,
            array_filter([
                'page' => is_string($page) ? $page : '',
                'column' => isset($validated['column']) ? (string) $validated['column'] : '',
                'stack' => self::scrub((string) ($validated['stack'] ?? '')),
                'browser' => (string) $request->userAgent(),
            ]),
        ));

        return response()->noContent();
    }

    private static function scrub(string $text): string
    {
        return (string) preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+\.[a-z]{2,}/i', '[email]', $text);
    }
}
