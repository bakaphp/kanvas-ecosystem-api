<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Connectors\Notifications\Jobs\MailCaddieLabJob;
use Kanvas\Apps\Repositories\AppsRepository;

class MailCaddieLabCommand extends Command
{
    protected $signature = 'kanvas:internal-mail-caddie-lab {apps_id}';

    public function handle()
    {
        $this->info('Sending internal mail to Caddie Lab');
        MailCaddieLabJob::dispatch(AppsRepository::findFirstByKey($this->argument('apps_id')));
    }
}
