<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SyncVehicleTagDataAction;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SyncVehicleTagDataActionTest extends TestCase
{
    private Apps $kanvasApp;
    private Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testWritesTagAttributesOnFirstSync(): void
    {
        $vehicle = $this->createVehicleProduct();

        $result = new SyncVehicleTagDataAction(
            $vehicle,
            $this->verifyResponse(balance: 1500.50, account: 'ACC-1'),
        )->execute();

        $this->assertSame('success', $result['status']);

        $vehicle->refresh();
        $this->assertEquals(1500.50, $vehicle->getAttributeBySlug('tag-balance')?->value);
        $this->assertEquals('ACC-1', $vehicle->getAttributeBySlug('tag-account')?->value);
        $this->assertNotNull($vehicle->getAttributeBySlug('tag-balance-fetched-at')?->value);
    }

    public function testSkipsWriteWhenNothingChangedWithinRefreshWindow(): void
    {
        Carbon::setTestNow('2026-07-21 10:00:00');

        $vehicle = $this->createVehicleProduct();
        $response = $this->verifyResponse(balance: 900.00, account: 'ACC-1');

        new SyncVehicleTagDataAction($vehicle, $response)->execute();

        Carbon::setTestNow('2026-07-21 10:01:00');

        $result = new SyncVehicleTagDataAction($vehicle->refresh(), $response)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertEquals(
            '2026-07-21T10:00:00+00:00',
            $vehicle->refresh()->getAttributeBySlug('tag-balance-fetched-at')?->value,
        );
    }

    public function testWritesWhenBalanceChanged(): void
    {
        Carbon::setTestNow('2026-07-21 10:00:00');

        $vehicle = $this->createVehicleProduct();
        new SyncVehicleTagDataAction($vehicle, $this->verifyResponse(balance: 900.00))->execute();

        Carbon::setTestNow('2026-07-21 10:01:00');

        $result = new SyncVehicleTagDataAction(
            $vehicle->refresh(),
            $this->verifyResponse(balance: 250.00),
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertEquals(250.00, $vehicle->refresh()->getAttributeBySlug('tag-balance')?->value);
    }

    public function testWritesWhenRefreshWindowElapsedEvenIfBalanceIsIdentical(): void
    {
        Carbon::setTestNow('2026-07-21 10:00:00');

        $vehicle = $this->createVehicleProduct();
        $response = $this->verifyResponse(balance: 900.00);

        new SyncVehicleTagDataAction($vehicle, $response)->execute();

        Carbon::setTestNow('2026-07-21 10:10:00');

        $result = new SyncVehicleTagDataAction($vehicle->refresh(), $response)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertEquals(
            '2026-07-21T10:10:00+00:00',
            $vehicle->refresh()->getAttributeBySlug('tag-balance-fetched-at')?->value,
        );
    }

    public function testRepositoryResolvesVehicleByTagWithTheProductsOwnId(): void
    {
        $tag = (string) random_int(100000000000, 999999999999);
        $vehicle = $this->createVehicleProduct();
        $vehicle->addAttributes($this->kanvasUser, [['name' => 'tag-number', 'value' => $tag]]);
        $vehicle->save();

        $found = ProductsRepository::findByAttributeValueInApp($this->kanvasApp, 'tag-number', $tag);

        $this->assertNotNull($found);
        // Guards the joined-columns footgun: without select('p.*') this would be attributes.id.
        $this->assertSame($vehicle->getId(), $found->getId());
    }

    public function testRepositoryReturnsNullForUnknownTag(): void
    {
        $this->assertNull(
            ProductsRepository::findByAttributeValueInApp($this->kanvasApp, 'tag-number', 'no-such-tag'),
        );
    }

    private function verifyResponse(float $balance, string $account = 'ACC-1'): VerifyCustomerResponse
    {
        return VerifyCustomerResponse::from([
            'username' => 'Tester',
            'lastname' => 'Driver',
            'device' => 'TAG-X',
            'message' => 'OK',
            'document' => '00000000000',
            'balance' => $balance,
            'type' => 'tag',
            'reference' => 'REF',
            'account' => $account,
            'status' => 'active',
        ]);
    }

    private function createVehicleProduct(): Products
    {
        return Products::factory()
            ->state([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->kanvasUser->getCurrentCompany()->getId(),
                'users_id' => $this->kanvasUser->getId(),
            ])
            ->create();
    }
}
