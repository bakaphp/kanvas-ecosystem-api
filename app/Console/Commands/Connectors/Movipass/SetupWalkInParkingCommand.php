<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Parking\Actions\SetupWalkInParkingAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;

class SetupWalkInParkingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:setup-walk-in-parking
        {app_id : The app that owns the parking product}
        {product_id : The parking product}
        {--hourly=0.75 : Price of each started hour}
        {--expire-minutes=30 : Minutes before an unscanned walk-in visit is cancelled}';

    protected $description = 'Make a product a walk-in parking product: walk_in attribute, hourly rate, order type booking config and the two pass-scan workflow rules';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $product = Products::getById((int) $this->argument('product_id'), $app);

        try {
            $checklist = new SetupWalkInParkingAction(
                product: $product,
                hourlyRate: (float) $this->option('hourly'),
                expireUnusedMinutes: (int) $this->option('expire-minutes'),
            )->execute();
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Walk-in parking is set up for product %d.', $product->getId()));
        $this->table(['Check', 'Status', 'Detail'], $checklist);

        return self::SUCCESS;
    }
}
