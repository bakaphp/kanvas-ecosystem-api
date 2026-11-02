<?php

declare(strict_types=1);

namespace App\Console\Commands\Insurance;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\SetupInsuranceOrderPipelineAction;

class SetupInsuranceOrderTypeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:insurance-setup-order-type {app_id : The app ID to set the insurance order type up for}';

    protected $description = 'Create the insurance order type, its statuses and transitions (idempotent)';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $orderType = new SetupInsuranceOrderPipelineAction($app)->execute();

        $this->info('App ' . $app->getId() . ': order type ' . $orderType->name . ' (id ' . $orderType->getId() . ') with ' . $orderType->total_statuses . ' statuses');

        return self::SUCCESS;
    }
}
