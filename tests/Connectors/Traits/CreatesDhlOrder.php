<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use ArrayObject;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\AttachDhlDocumentAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\Souk\Concerns\CreatesDeliveryOrder;

trait CreatesDhlOrder
{
    use CreatesDeliveryOrder;
    use HasDhlConfiguration;

    protected function setUpDhlOrderStatuses(): void
    {
        $this->setUpDeliveryOrderStatuses();
    }

    protected function connectDhlIntegration(Companies $company, Regions $region): IntegrationsCompany
    {
        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::DHL->value, 'apps_id' => 0],
            ['handler' => 'Kanvas\\Connectors\\Dhl\\Handlers\\DhlHandler']
        );

        $link = IntegrationsCompany::create([
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
            'region_id' => $region->getId(),
        ]);
        $link->is_active = 1;
        $link->is_deleted = 0;
        $link->saveQuietly();

        return $link;
    }

    protected function dhlOrder(Companies $company, string $statusSlug, array $overrides = [], bool $withAddress = true): Order
    {
        return $this->deliveryOrder(
            $company,
            $statusSlug,
            ['metadata' => ['shipping' => $this->dhlShippingMetadata()], ...$overrides],
            $withAddress
        );
    }

    protected function dhlShippingMetadata(array $meta = []): array
    {
        return [
            'provider' => 'dhl',
            'service' => 'N',
            'quoted_amount' => 842.5,
            'currency' => 'DOP',
            'quoted_at' => now()->toIso8601String(),
            'booking_meta' => $meta + ['product_code' => 'N', 'local_product_code' => 'N', 'signature_code' => 'SD'],
        ];
    }

    protected function fakeDhlDocuments(): ArrayObject
    {
        $stored = new ArrayObject();

        app()->bind(AttachDhlDocumentAction::class, fn (): AttachDhlDocumentAction => new class ($stored) extends AttachDhlDocumentAction {
            public function __construct(private readonly ArrayObject $stored)
            {
            }

            public function execute(
                Order $order,
                string $base64Content,
                string $fileName,
                string $field
            ): void {
                $this->stored[] = [
                    'order' => $order->getId(),
                    'file' => $fileName,
                    'field' => $field,
                    'content' => base64_decode($base64Content),
                ];
            }
        });

        return $stored;
    }

    protected function readyForDispatch(): string
    {
        return DeliveryOrderStatusEnum::READY_FOR_DISPATCH->value;
    }
}
