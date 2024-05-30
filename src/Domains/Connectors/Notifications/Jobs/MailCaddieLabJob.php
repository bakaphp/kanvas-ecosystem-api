<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Notifications\Jobs;

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
        $this->sendMails($peoples, 'join-caddie', $this->app->get('billing_url'));

        $peoples = PeoplesRepository::getByDaysCreated(28, $this->app);
        $this->sendMails($peoples, 'deal-caddie', $this->app->get('billing_url_pro'));
    }

    public function sendMails(Collection $peoples, string $template, string $baseUrl)
    {
        foreach ($peoples as $people) {
            $email = $people->emails()->first();
            $url = $baseUrl. '/' . '?email=' . $email->value . '&paid=false';
            $notification = new Blank(
                $template,
                ['membershipUpgradeUrl' => $url, 'app' => $this->app],
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
