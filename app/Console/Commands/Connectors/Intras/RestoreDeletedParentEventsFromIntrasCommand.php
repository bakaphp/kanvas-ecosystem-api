<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\RestoreDeletedParentEventsAction;

class RestoreDeletedParentEventsFromIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-restore-deleted-parent-events
                            {app_id : The application ID}
                            {company_id : Company ID}';

    protected $description = 'Un-delete imported Intras events that still parent live versions (SIPGO soft-deleted them, Kanvas cannot)';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $restored = new RestoreDeletedParentEventsAction($app, $company)->execute();

        $this->info("Restored {$restored} events for company: {$company->name}");
    }
}
