<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Users\Models\Users;
use Kanvas\Notifications\Templates\Blank;
use Illuminate\Support\Facades\Notification;

class SendEmailToUserJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Users $user,
        protected string $subject,
        protected array $body = [],
        protected string $emailTemplateName = 'Default',
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $notification = new Blank(
            $this->emailTemplateName,
            $this->body,
            ['mail'],
            $this->user
        );
        $notification->setSubject($this->subject);
        Notification::route('mail', $this->user->email)->notify($notification);
        Log::info("Email sent to user {$this->user->email}");
    }
}
