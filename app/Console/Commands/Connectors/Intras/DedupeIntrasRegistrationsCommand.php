<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\DedupeIntrasRegistrationsAction;

class DedupeIntrasRegistrationsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-dedupe-registrations
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {--apply : Delete the duplicates; without it this only reports them}';

    protected $description = 'Remove the untracked copy of Intras registrations imported twice';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $apply = (bool) $this->option('apply');

        $result = new DedupeIntrasRegistrationsAction($app, $company, $apply)->execute();

        $this->info(sprintf(
            '%s %d duplicate registrations across %d versions for company: %s',
            $apply ? 'Removed' : 'Found',
            $apply ? $result['removed'] : $result['duplicates'],
            $result['versions'],
            $company->name
        ));

        if ($apply && $result['removed'] > 0) {
            $this->warn(sprintf(
                'Rebuild the flat tables: php artisan kanvas:reporting-rebuild %d --company=%d',
                $app->getId(),
                $company->getId()
            ));
        } elseif (! $apply) {
            $this->comment('Dry run. Re-run with --apply to delete them.');
        }
    }
}
