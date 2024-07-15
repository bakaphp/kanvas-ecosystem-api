<?php

namespace App\Console\Commands\Connectors\Notifications;

use Illuminate\Console\Command;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Connectors\Notifications\Jobs\MailCaddieLabJob;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;

class MailCaddieLabCommand extends Command
{
    protected $signature = 'kanvas:internal-mail-caddie-lab {apps_id} {email?}';

    public function handle()
    {
        $this->info('Sending internal mail to Caddie Lab');

        $app = AppsRepository::findFirstByKey($this->argument('apps_id'));
        $email = $this->argument('email');

        $peoplesIn7Days = PeoplesRepository::getByDaysCreated(7, $app);
        $peoplesIn28Days = PeoplesRepository::getByDaysCreated(28, $app);

        $this->info('We will be sending email to ' . count($peoplesIn7Days) . ' people that have been created in the last 7 days');
        $this->info('We will be sending email to ' . count($peoplesIn28Days) . ' people that have been created in the last 28 days');

        if ($this->confirm('Are you sure you want to send this email?')) {
            MailCaddieLabJob::dispatch($app, $email);
            $this->info('Emails have been dispatched.');
        } else {
            $this->info('Email sending has been canceled.');
        }
    }
}
