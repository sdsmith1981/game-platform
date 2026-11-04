<?php

namespace GamePlatform\Ops;

use RuntimeException;

/**
 * An error in a player's browser, sent in by the page (see ClientErrorController), so
 * it shows in Nightwatch beside the server's own. It takes the script and line the
 * error happened at as its own, so errors from different places are grouped apart.
 */
final class BrowserError extends RuntimeException
{
    /**
     * @param  array<string, string>  $details
     */
    public function __construct(string $message, ?string $source, ?int $line, private readonly array $details)
    {
        parent::__construct($message);

        if ($source !== null && $source !== '') {
            $this->file = $source;
            $this->line = $line ?? 0;
        }
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return $this->details;
    }
}
