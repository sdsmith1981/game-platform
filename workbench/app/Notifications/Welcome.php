<?php

namespace Workbench\App\Notifications;

use GamePlatform\Mail\QueuedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A stand-in for a game's queued mail, for the package's tests. */
class Welcome extends Notification implements ShouldQueue
{
    use Queueable, QueuedMail;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('Welcome.');
    }
}
