<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AmendOrderAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = Auth::user();

        // Mirror the real module-access Gate::before (AuthServiceProvider): deny any
        // authorize() whose model argument maps to a module, allow bare-ability checks.
        // If amend() ever passes Order::class again, this denies → the test goes red.
        Gate::before(function ($user, string $ability, array $arguments) {
            if (! empty($arguments)
                && is_string($arguments[0])
                && ModulesRepositories::getModuleIdByModelName($arguments[0]) !== null
            ) {
                return false;
            }

            return true;
        });
    }

    private function createTestOrder(array $overrides = []): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $region = Regions::getDefault($company, $this->kanvasApp);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        return Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($this->kanvasApp->getId())
                ->withCompanyId($company->getId())
                ->withUserId($this->kanvasUser->getId())
                ->create(array_merge([
                    'region_id' => $region->getId(),
                    'people_id' => $person->id,
                ], $overrides))
        );
    }

    public function testAmendOrderDoesNotRequireModuleAccessGate(): void
    {
        $order = $this->createTestOrder([
            'order_number' => 99920,
            'metadata' => ['data' => ['vehiclePlate' => 'OLD123', 'vehicleBrand' => 'Toyota']],
            'reference' => 'Toyota / OLD123 - #99920',
        ]);

        $response = $this->graphQL('
            mutation($order_id: ID!, $data: Mixed) {
                amendOrder(
                    order_id: $order_id
                    correction_type: "correct-plate"
                    reason: "Placa registrada incorrectamente"
                    data: $data
                ) {
                    id
                    reference
                }
            }
        ', [
            'order_id' => $order->id,
            'data' => ['new_plate' => 'NEW999'],
        ]);

        $response->assertSuccessful();
        $this->assertNull(
            $response->json('errors'),
            'amendOrder must authorize by ability only and not require module access (view-module-commerce)'
        );
        $this->assertSame('Toyota / NEW999 - #99920', $response->json('data.amendOrder.reference'));
    }
}
