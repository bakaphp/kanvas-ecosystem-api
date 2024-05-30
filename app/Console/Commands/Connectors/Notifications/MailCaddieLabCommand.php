<?php

namespace App\Console\Commands\Connectors\Notifications;

use Illuminate\Console\Command;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Connectors\Notifications\Jobs\MailCaddieLabJob;

class MailCaddieLabCommand extends Command
{
    protected $signature = 'kanvas:internal-mail-caddie-lab {apps_id} {email?}';

    public function handle()
    {
        $this->info('Sending internal mail to Caddie Lab');
        dump($this->argument('email'));
        $app = AppsRepository::findFirstByKey($this->argument('apps_id'));
        $email = $this->argument('email');
        MailCaddieLabJob::dispatch($app, $email);
    }
}
