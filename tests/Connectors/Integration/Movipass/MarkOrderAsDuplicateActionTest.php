<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\MarkOrderAsDuplicateAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class MarkOrderAsDuplicateActionTest extends TestCase
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

    public function testItMarksOrderAsDuplicate(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $original = $this->createTestOrder($app, $user, [
            'order_number' => 31001,
            'metadata' => ['data' => ['vehiclePlate' => 'DUP001']],
        ]);

        $duplicate = $this->createTestOrder($app, $user, [
            'order_number' => 31002,
            'metadata' => ['data' => ['vehiclePlate' => 'DUP001']],
        ]);

        $result = Order::withoutSyncingToSearch(
            fn () => new MarkOrderAsDuplicateAction(
                $duplicate,
                $user,
                $original,
                'Misma placa registrada dos veces',
            )->execute()
        );

        $this->assertEquals($original->id, $result->metadata['data']['duplicate_of_order_id']);
        $this->assertEquals($original->order_number, $result->metadata['data']['duplicate_of_order_number']);

        $log = Activity::where('subject_id', $duplicate->id)
            ->where('subject_type', Order::class)
            ->where('description', 'mark-duplicate')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($original->id, $log->properties['changes']['duplicate_of_order_id']['new']);
        $this->assertEquals('Misma placa registrada dos veces', $log->properties['reason']);
    }

    public function testItBlocksMarkingOnFinalStatus(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $orderType = OrderTypes::where('name', 'impound_lot')->fromApp($app)->first();

        if (! $orderType) {
            $this->markTestSkipped('impound_lot order type not set up in this environment');
        }

        $releasedStatus = OrderStatus::where('slug', MovipassOrderStatusEnum::RELEASED->value)
            ->where('order_types_id', $orderType->id)
            ->first();

        if (! $releasedStatus) {
            $this->markTestSkipped('released_from_lot status not found — run movipass:setup-impound-lot first');
        }

        $original = $this->createTestOrder($app, $user, ['order_number' => 31003]);

        $finalOrder = $this->createTestOrder($app, $user, [
            'order_number' => 31004,
            'order_status_id' => $releasedStatus->id,
        ]);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new MarkOrderAsDuplicateAction($finalOrder, $user, $original, 'should fail')->execute()
        );
    }
}
