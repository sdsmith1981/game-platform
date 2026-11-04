<?php

namespace GamePlatform\Console;

use GamePlatform\Ops\Feedback;
use GamePlatform\Ops\FeedbackController;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('feedback:resend')]
#[Description('Send player feedback that could not be sent to GitHub earlier')]
class ResendFeedback extends Command
{
    public function handle(): int
    {
        $failed = Feedback::query()->where('status', Feedback::FAILED)->oldest()->get();

        foreach ($failed as $feedback) {
            FeedbackController::send($feedback);
            $this->line("#{$feedback->id}: {$feedback->status}".($feedback->issue_url ? " {$feedback->issue_url}" : ''));
        }

        $this->info($failed->isEmpty() ? 'Nothing to resend.' : 'Done.');

        return self::SUCCESS;
    }
}
