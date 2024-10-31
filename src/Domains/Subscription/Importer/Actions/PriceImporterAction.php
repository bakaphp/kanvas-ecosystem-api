<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Importer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Importer\DataTransferObjects\PriceImporter;
use Kanvas\Subscription\Prices\Actions\CreatePrice;
use Kanvas\Subscription\Prices\Actions\UpdatePrice;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Exceptions\ModelNotFoundException;
use Throwable;

class PriceImporterAction
{
    protected ?Price $price = null;

    /**
     * __construct.
     */
    public function __construct(
        public PriceImporter $importedPrice,
        public ?AppInterface $app = null,
        public UserInterface $user,
        public bool $runWorkflow = true
    ) {
        $this->app = $this->app ?? app(Apps::class);
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
                $this->price = UpdatePrice::import($existingPrice, $priceDto);
            } catch (ModelNotFoundException $e) {
                $this->price = CreatePrice::import($priceDto);
            }

            DB::connection('mysql')->commit();
        } catch (Throwable $e) {
            DB::connection('mysql')->rollback();

            throw $e;
        }

        return $this->price;
    }
}
