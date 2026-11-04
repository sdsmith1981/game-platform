<?php

namespace GamePlatform\Mail;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mail is sent from the queue, so a slow or failing mail provider never holds up a
 * page, and a failed send is tried again later instead of being lost.
 *
 * The usual failure is Brevo refusing the server: Laravel Cloud's outgoing IP can
 * change, and Brevo's "Authorised IPs" setting rejects addresses it hasn't seen.
 * Retrying can't fix that until someone allows the new IP, so the retries are spread
 * out over twelve hours. Mail that still fails ends up in `failed_jobs`, where
 * `php artisan queue:retry all` sends it again once the problem is fixed.
 */
trait QueuedMail
{
    /** Keep trying for this long after the mail was queued. */
    protected function retryForSeconds(): int
    {
        return 12 * 3600;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->retryForSeconds());
    }

    /**
     * Seconds to wait before each retry; the last gap repeats.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 3 * 3600];
    }

    /** Every try has failed: say why, and what to do about it. */
    public function failed(Throwable $e): void
    {
        $context = [
            'notification' => static::class,
            'error' => $e->getMessage(),
        ];

        if (self::isBlockedIp($e)) {
            Log::critical(
                "Brevo refused to send mail from this server's IP address. Allow it in Brevo ".
                '(Security > Authorised IPs), or turn the restriction off for API keys, then run '.
                '`php artisan queue:retry all`.',
                $context,
            );

            return;
        }

        Log::error('Mail could not be sent after every retry. Run `php artisan queue:retry all` to try again.', $context);
    }

    /** Brevo's answer when the API key is used from an IP address it hasn't authorised. */
    public static function isBlockedIp(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unrecognised ip')
            || str_contains($message, 'unrecognized ip')
            || str_contains($message, 'authorised_ips');
    }
}
