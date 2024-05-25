<?php

declare(strict_types=1);

namespace Kanvas\Users\Jobs;

use Baka\Contracts\Queue\QueueableJobInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Repositories\UsersRepository;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;


class MailCaddieLabJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use KanvasJobsTrait;
    use Queueable;

    public function __construct(
        public Apps $app
    ) {
    }

    public function handle()
    {
        $users = UsersRepository::getUsersByDaysCreated(7, $this->app);
        foreach ($users as $user) {
            $notification = new Blank(
                'join-caddie',
                [],
                ['mail'],
                $user
            );
            $notification->setSubject('Join to Caddie Lab');
            echo " Sending email to " . $user->email . "\n";
            if (!$user->get('paid_subscription')) {
                Notification::route('mail', $user->email)->notify($notification);
            }
        }

        $users = UsersRepository::getUsersByDaysCreated(28, $this->app);
        foreach ($users as $user) {
            $notification = new Blank(
                'deal-caddie',
                [],
                ['mail'],
                $user
            );
            $notification->setSubject('Join to Caddie Lab');
            if ($user->get('paid_subscription')) {
                Notification::route('mail', $user->email)->notify($notification);
            }
        }
    }
}
