<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SyncVehicleTagDataAction;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;

/**
 * Keeps the vehicle's stored tag data fresh on every balance check, not only on recharge.
 * Runs out of band so the read path neither waits on the write nor pays for the lookup.
 */
class SyncVehicleTagDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly string $tag,
        public readonly VerifyCustomerResponse $response,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $attributeSlug = $this->app->get(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG->value);

        if (empty($attributeSlug)) {
            return;
        }

        $vehicle = ProductsRepository::findByAttributeValueInApp(
            $this->app,
            (string) $attributeSlug,
            $this->tag,
        );

        if ($vehicle === null) {
            return;
        }

        new SyncVehicleTagDataAction($vehicle, $this->response)->execute();
    }
}
