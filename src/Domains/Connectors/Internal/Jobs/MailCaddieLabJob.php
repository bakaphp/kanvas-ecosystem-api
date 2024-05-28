<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Notifications\Templates\Blank;

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
        $peoples = PeoplesRepository::getByDaysCreated(7, $this->app);
        $this->sendMails($peoples, 'join-caddie');

        $peoples = PeoplesRepository::getByDaysCreated(28, $this->app);
        $this->sendMails($peoples, 'join-caddie');
    }

    public function sendMails(Collection  $peoples, string $template)
    {
        foreach ($peoples as $people) {
            $email = $people->emails()->first();
            $url = $this->app->get('billing_url') . '/' . '?email=' . $email->value . '&paid=false';
            $notification = new Blank(
                $template,
                ['membershipUpgradeUrl' => $url],
                ['mail'],
                $people
            );
            $notification->setSubject('Join to Caddie Lab');
            echo ' Sending email to ' . $email->value . "\n";
            if (! $people->get('paid_subscription')) {
                Notification::route('mail', $email->value)->notify($notification);
            }
        }
    }
}
