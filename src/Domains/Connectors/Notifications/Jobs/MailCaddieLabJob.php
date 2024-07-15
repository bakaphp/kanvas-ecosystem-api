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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Notifications\Templates\Blank;

use function Sentry\captureException;

use Throwable;

class MailCaddieLabJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use KanvasJobsTrait;
    use Queueable;

    public function __construct(
        public Apps $app,
        public ?string $email = null
    ) {
    }

    public function handle()
    {
        $peoples = PeoplesRepository::getByDaysCreated(7, $this->app);
        $this->sendMails(
            $peoples,
            'join-caddie',
            $this->app->get('billing_url'),
            $this->app->get('subject-join-caddie')
        );

        $peoples = PeoplesRepository::getByDaysCreated(28, $this->app);
        $this->sendMails(
            $peoples,
            'deal-caddie',
            $this->app->get('billing_url_pro'),
            $this->app->get('subject-join-caddie-pro')
        );
    }

    public function sendMails(Collection $peoples, string $template, string $baseUrl, string $subject)
    {
        foreach ($peoples as $people) {
            try {
                $email = $people->emails()->first();

                if (! $email) {
                    continue;
                }

                $url = $baseUrl . '/' . '?email=' . $email->value . '&paid=false';
                $notification = new Blank(
                    $template,
                    ['membershipUpgradeUrl' => $url, 'app' => $this->app],
                    ['mail'],
                    $people
                );
                $notification->setSubject($subject);
                if (! $people->get('paid_subscription')) {
                    $mail = $this->email ?? $email->value;
                    echo ' Sending email to ' . $mail . "\n";
                    Notification::route('mail', $mail)->notify($notification);
                    if ($this->email) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                Log::error($e->getMessage());
                captureException($e);
            }
        }
    }
}
