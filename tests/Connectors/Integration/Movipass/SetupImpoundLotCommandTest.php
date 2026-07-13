<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\TestCase;

final class SetupImpoundLotCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    public function testItSetsUpImpoundLotIdempotently(): void
    {
        $app = app(Apps::class);

        $this->artisan('movipass:setup-impound-lot', ['app_id' => $app->getId()])->assertExitCode(0);
        $this->artisan('movipass:setup-impound-lot', ['app_id' => $app->getId()])->assertExitCode(0);

        $orderType = OrderTypes::where('name', OrderTypeEnum::IMPOUND_LOT->value)
            ->fromApp($app)
            ->firstOrFail();

        foreach (['in_transit', 'awaiting_delivery_confirmation', 'delivered', 'paid', 'released_from_lot', 'cancelled', 'trial_phase'] as $slug) {
            $this->assertDatabaseHas('order_statuses', [
                'apps_id' => $app->getId(),
                'order_types_id' => $orderType->id,
                'slug' => $slug,
            ], 'commerce');
        }

        $depositado = OrderStatus::where('slug', 'delivered')->where('order_types_id', $orderType->id)->firstOrFail();
        $validarTraslado = OrderStatus::where('slug', 'awaiting_delivery_confirmation')->where('order_types_id', $orderType->id)->firstOrFail();

        $this->assertDatabaseHas('order_status_transitions', [
            'order_types_id' => $orderType->id,
            'from_status_id' => $depositado->id,
            'to_status_id' => $validarTraslado->id,
        ], 'commerce');

        $this->assertDatabaseHas('order_status_transitions', [
            'order_types_id' => $orderType->id,
            'from_status_id' => $validarTraslado->id,
            'to_status_id' => $depositado->id,
        ], 'commerce');

        $ourStatusIds = OrderStatus::whereIn('slug', [
            'in_transit', 'awaiting_delivery_confirmation', 'delivered',
            'paid', 'released_from_lot', 'cancelled', 'trial_phase',
        ])->where('order_types_id', $orderType->id)->pluck('id');

        $count = OrderStatusTransitions::where('order_types_id', $orderType->id)
            ->whereIn('from_status_id', $ourStatusIds)
            ->whereIn('to_status_id', $ourStatusIds)
            ->count();

        $this->assertEquals(10, $count);
    }
}
