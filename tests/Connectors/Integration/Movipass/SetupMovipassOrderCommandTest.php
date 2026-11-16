<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\TestCase;

final class SetupMovipassOrderCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    public function testItKeepsExistingConfigWhenMarkingTheTypeExpirable(): void
    {
        $app = app(Apps::class);
        OrderTypes::updateOrCreate(
            ['apps_id' => $app->getId(), 'name' => OrderTypeEnum::MOVIPASS->value],
            [
                'companies_id' => 0,
                'is_default' => false,
                'config' => [
                    'event_booking' => ['product_types' => ['parking']],
                    'validate_metadata_duplicated_field' => 'data.vehiclePlate',
                ],
            ]
        );

        $this->artisan('kanvas:movipass-setup-movipass-order', ['app_id' => $app->getId()])->assertExitCode(0);

        $orderType = OrderTypes::where('name', OrderTypeEnum::MOVIPASS->value)->fromApp($app)->firstOrFail();

        $this->assertTrue($orderType->isExpirable());
        $this->assertSame(['product_types' => ['parking']], $orderType->config['event_booking']);
        $this->assertSame('data.vehiclePlate', $orderType->config['validate_metadata_duplicated_field']);
    }

    public function testCreatedAndActiveOrdersCanBeCancelled(): void
    {
        $app = app(Apps::class);

        $this->artisan('kanvas:movipass-setup-movipass-order', ['app_id' => $app->getId()])->assertExitCode(0);

        $orderType = OrderTypes::where('name', OrderTypeEnum::MOVIPASS->value)->fromApp($app)->firstOrFail();
        $status = fn (MovipassOrderStatusEnum $slug) => OrderStatus::where('order_types_id', $orderType->getId())
            ->where('slug', $slug->value)
            ->firstOrFail()
            ->getId();
        $cancelled = $status(MovipassOrderStatusEnum::CANCELLED);

        foreach ([MovipassOrderStatusEnum::CREATED, MovipassOrderStatusEnum::ACTIVE] as $from) {
            $this->assertDatabaseHas('order_status_transitions', [
                'order_types_id' => $orderType->getId(),
                'from_status_id' => $status($from),
                'to_status_id' => $cancelled,
            ], 'commerce');
        }
    }
}
