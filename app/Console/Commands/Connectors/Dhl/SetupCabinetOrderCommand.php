<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Dhl;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Dhl\Actions\SetupCabinetOrderAction;
use Kanvas\Connectors\Dhl\Enums\OrderTypeEnum;

class SetupCabinetOrderCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:dhl-setup-cabinet-order {app_id?}';

    protected $description = 'Create the cabinet order type with its statuses and transitions for DHL fulfillment';

    public function handle(): int
    {
        $app = $this->argument('app_id') ? Apps::getById((int) $this->argument('app_id')) : app(Apps::class);
        $this->overwriteAppService($app);

        $result = new SetupCabinetOrderAction($app)->execute();

        $this->info('Order type ' . OrderTypeEnum::CABINET->value . " set up for app: {$app->name}");
        $this->table(
            ['Status', 'Slug', 'Default', 'Final'],
            collect($result['statuses'])->map(
                fn ($status): array => [$status->name, $status->slug, $status->is_default ? 'yes' : 'no', $status->is_final ? 'yes' : 'no']
            )->all()
        );

        return self::SUCCESS;
    }
}
