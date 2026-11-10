<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use ArrayObject;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\AttachDhlDocumentAction;
use Kanvas\Connectors\Dhl\Actions\SetupCabinetOrderAction;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait CreatesDhlCabinetOrder
{
    use HasDhlConfiguration;

    private array $cabinetStatuses = [];

    protected function setUpCabinetStatuses(): void
    {
        $result = new SetupCabinetOrderAction(app(Apps::class))->execute();

        foreach ($result['statuses'] as $status) {
            $this->cabinetStatuses[$status->slug] = $status;
        }
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

    protected function cabinetOrder(Companies $company, string $statusSlug, array $overrides = [], bool $withAddress = true): Order
    {
        $status = OrderStatus::find($this->cabinetStatuses[$statusSlug]->getId());
        $people = People::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Maria Perez',
        ]);

        $address = $withAddress ? Address::create([
            'peoples_id' => $people->getId(),
            'address' => 'Calle El Sol 12',
            'city' => 'Santiago',
            'state' => 'Santiago',
            'zip' => '51000',
            'countries_id' => Countries::firstOrCreate(['code' => 'do'], ['name' => 'Dominican Republic'])->getId(),
        ]) : null;

        return Order::factory()
            ->withCompanyId($company->getId())
            ->withUserId((int) $company->users_id)
            ->create([
                'people_id' => $people->getId(),
                'order_types_id' => $status->order_types_id,
                'order_status_id' => $status->getId(),
                'shipping_address_id' => $address?->getId(),
                'user_email' => 'maria@customer.test',
                'user_phone' => '+18095550123',
                'metadata' => ['shipping' => $this->dhlShippingMetadata()],
                ...$overrides,
            ])->refresh();
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

    protected function statusSlugOf(Order $order): string
    {
        return $order->refresh()->orderStatus->slug;
    }

    protected function readyForDispatch(): string
    {
        return OrderStatusEnum::READY_FOR_DISPATCH->value;
    }
}
