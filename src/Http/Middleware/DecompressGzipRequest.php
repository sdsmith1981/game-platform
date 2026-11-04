<?php

namespace GamePlatform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept a gzipped JSON body (Content-Encoding: gzip), as the game sends its saves.
 * Unzipped bodies are capped, so a small upload can't expand without limit.
 */
class DecompressGzipRequest
{
    /** The most an unzipped body may hold, in bytes. */
    public const int MAX_BYTES = 32 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if (strtolower((string) $request->header('Content-Encoding')) !== 'gzip') {
            return $next($request);
        }

        $body = self::unzip($request->getContent());
        if ($body === null) {
            abort(400, 'The request body could not be unzipped, or is too large.');
        }

        $data = json_decode($body, true);
        if (! is_array($data)) {
            abort(400, 'The request body is not valid JSON.');
        }

        $request->headers->remove('Content-Encoding');
        $request->setJson(new InputBag($data));
        $request->request->replace($data);

        return $next($request);
    }

    /**
     * Unzip a little at a time, giving up as soon as the result passes MAX_BYTES, so a
     * small upload that unzips to gigabytes never gets the chance. (gzdecode's own
     * length limit doesn't stop it.) Null if it isn't gzip, is cut short, or is too large.
     */
    private static function unzip(string $gzipped): ?string
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            return null;
        }

        $body = '';
        foreach (str_split($gzipped, 8192) as $chunk) {
            $part = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                return null;
            }

            $body .= $part;
            if (strlen($body) > self::MAX_BYTES) {
                return null;
            }

            if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                return $body;
            }
        }

        return null;
    }
}
