<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ScanDuplicateOrdersCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    private function createTestOrder(Apps $app, Users $user, array $overrides = []): Order
    {
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        return Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->create(array_merge([
                    'region_id' => $region->getId(),
                    'people_id' => $person->id,
                ], $overrides))
        );
    }

    public function testItDetectsAndFlagsDuplicateOrders(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $orderType = OrderTypes::where('name', 'impound_lot')->fromApp($app)->first();

        if (! $orderType) {
            $this->markTestSkipped('impound_lot order type not set up — run movipass:setup-impound-lot first');
        }

        if (empty($orderType->config['validate_metadata_duplicated_field'])) {
            $this->markTestSkipped('order type missing validate_metadata_duplicated_field config');
        }

        $plate = 'SCAN' . rand(1000, 9999);

        $original = $this->createTestOrder($app, $user, [
            'order_types_id' => $orderType->id,
            'metadata' => ['data' => ['vehiclePlate' => $plate]],
        ]);

        $duplicate = $this->createTestOrder($app, $user, [
            'order_types_id' => $orderType->id,
            'metadata' => ['data' => ['vehiclePlate' => $plate]],
        ]);

        $this->artisan('movipass:scan-duplicate-orders', [
            'app_id' => $app->getId(),
            '--order_type_id' => $orderType->id,
        ])->assertSuccessful();

        $duplicate->refresh();

        $this->assertEquals(
            $original->id,
            $duplicate->metadata['data']['duplicate_of_order_id'] ?? null
        );
    }

    public function testItSkipsAlreadyFlaggedOrders(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $orderType = OrderTypes::where('name', 'impound_lot')->fromApp($app)->first();

        if (! $orderType) {
            $this->markTestSkipped('impound_lot order type not set up');
        }

        if (empty($orderType->config['validate_metadata_duplicated_field'])) {
            $this->markTestSkipped('order type missing validate_metadata_duplicated_field config');
        }

        $plate = 'SKIP' . rand(1000, 9999);

        $original = $this->createTestOrder($app, $user, [
            'order_types_id' => $orderType->id,
            'metadata' => ['data' => ['vehiclePlate' => $plate]],
        ]);

        $alreadyFlagged = $this->createTestOrder($app, $user, [
            'order_types_id' => $orderType->id,
            'metadata' => [
                'data' => [
                    'vehiclePlate' => $plate,
                    'duplicate_of_order_id' => $original->id,
                    'duplicate_of_order_number' => $original->order_number,
                ],
            ],
        ]);

        $this->artisan('movipass:scan-duplicate-orders', [
            'app_id' => $app->getId(),
            '--order_type_id' => $orderType->id,
        ])->assertSuccessful();

        // Activity log should NOT have a new mark-duplicate entry (already was flagged)
        $logCount = \Kanvas\Activities\Models\Activity::where('subject_id', $alreadyFlagged->id)
            ->where('description', 'mark-duplicate')
            ->count();

        $this->assertEquals(0, $logCount);
    }
}
