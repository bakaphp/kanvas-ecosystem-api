<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Subscription\Importer\DataTransferObjects\PriceImporter;
use Kanvas\Subscription\Prices\Actions\CreatePriceAction;
use Kanvas\Subscription\Prices\Actions\UpdatePriceAction;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Throwable;

class PriceImporterAction
{
    public function __construct(
        public PriceImporter $importedPrice,
        public UserInterface $user,
        public AppInterface $app,
        public bool $runWorkflow = true
    ) {
    }

    public function execute(): Price
    {
        try {
            DB::connection('mysql')->beginTransaction();

            $priceDto = PriceDto::from([
                'app' => $this->app,
                'user' => $this->user,
                'amount' => $this->importedPrice->amount,
                'currency' => $this->importedPrice->currency,
                'interval' => $this->importedPrice->interval,
                'apps_plans_id' => $this->importedPrice->apps_plans_id,
                'stripe_id' => $this->importedPrice->stripe_id,
                'is_active' => $this->importedPrice->is_active,
            ]);

            try {
                $existingPrice = PriceRepository::getByStripeId($priceDto->stripe_id, $this->app);
                $updatePrice = new UpdatePriceAction($existingPrice, $priceDto);
                $price = $updatePrice->execute(updateInStripe: false);
            } catch (ModelNotFoundException $e) {
                $createPrice = new CreatePriceAction($priceDto);
                $price = $createPrice->execute(createInStripe: false);
            }

            DB::connection('mysql')->commit();
        } catch (Throwable $e) {
            DB::connection('mysql')->rollback();

            throw $e;
        }

        return $price;
    }
}
