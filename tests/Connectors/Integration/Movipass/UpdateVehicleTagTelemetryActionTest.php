<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\UpdateVehicleTagTelemetryAction;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class UpdateVehicleTagTelemetryActionTest extends TestCase
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

    public function testWritesTelemetryAttributesOntoVehicleWhenBalanceLookupSucceeds(): void
    {
        $tag = $this->uniqueTag();
        $rechargeAmount = 750.00;
        $expectedBalance = 1500.50;

        $vehicle = $this->createVehicleProduct();
        $order = $this->createPasoRapidoOrder($tag, $rechargeAmount);

        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldReceive('fetchTagBalance')
            ->once()
            ->with($tag)
            ->andReturn(VerifyCustomerResponse::from([
                'username' => 'Tester',
                'lastname' => 'Driver',
                'device' => 'TAG-X',
                'message' => 'OK',
                'document' => '00000000000',
                'balance' => $expectedBalance,
                'type' => 'tag',
                'reference' => $tag,
                'account' => '0',
                'status' => 'active',
            ]));

        $result = new UpdateVehicleTagTelemetryAction(
            $vehicle,
            $order,
            $this->kanvasApp,
            $tag,
            $rechargeAmount,
            $service,
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame($vehicle->getId(), $result['product_id']);
        $this->assertSame($expectedBalance, $result['tag_balance']);
        $this->assertNull($result['tag_balance_error']);

        $vehicle->refresh();
        $this->assertEquals($expectedBalance, $vehicle->getAttributeBySlug('tag-balance')?->value);
        $this->assertEquals($rechargeAmount, $vehicle->getAttributeBySlug('last-recharge-amount')?->value);
        $this->assertEquals($order->getId(), $vehicle->getAttributeBySlug('last-order-id')?->value);
        $this->assertNotNull($vehicle->getAttributeBySlug('last-recharge-date')?->value);
        $this->assertNotNull($vehicle->getAttributeBySlug('tag-balance-fetched-at')?->value);
    }

    public function testSkipsOnEmptyTag(): void
    {
        $vehicle = $this->createVehicleProduct();
        $order = $this->createPasoRapidoOrder('', 500.00);

        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldNotReceive('fetchTagBalance');

        $result = new UpdateVehicleTagTelemetryAction(
            $vehicle,
            $order,
            $this->kanvasApp,
            '',
            500.00,
            $service,
        )->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('empty tag', $result['reason']);
    }

    public function testPersistsRechargeFieldsEvenWhenBalanceLookupFails(): void
    {
        $tag = $this->uniqueTag();
        $rechargeAmount = 200.00;

        $vehicle = $this->createVehicleProduct();
        $order = $this->createPasoRapidoOrder($tag, $rechargeAmount);

        $service = Mockery::mock(PasoRapidoService::class);
        $service->shouldReceive('fetchTagBalance')
            ->once()
            ->with($tag)
            ->andThrow(new RuntimeException('upstream timeout'));

        $result = new UpdateVehicleTagTelemetryAction(
            $vehicle,
            $order,
            $this->kanvasApp,
            $tag,
            $rechargeAmount,
            $service,
        )->execute();

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['tag_balance']);
        $this->assertSame('upstream timeout', $result['tag_balance_error']);

        $vehicle->refresh();
        $this->assertNull($vehicle->getAttributeBySlug('tag-balance'));
        $this->assertEquals($rechargeAmount, $vehicle->getAttributeBySlug('last-recharge-amount')?->value);
        $this->assertEquals($order->getId(), $vehicle->getAttributeBySlug('last-order-id')?->value);
    }

    private function uniqueTag(): string
    {
        return (string) random_int(100000000000, 999999999999);
    }

    private function createVehicleProduct(): Products
    {
        $companyId = $this->kanvasUser->getCurrentCompany()->getId();

        return Products::factory()
            ->state([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $companyId,
                'users_id' => $this->kanvasUser->getId(),
            ])
            ->create();
    }

    private function createPasoRapidoOrder(string $tag, float $amount): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->withPeopleId($people->getId())
            ->create([
                'total_gross_amount' => $amount,
                'total_net_amount' => $amount,
                'currency' => 'DOP',
                'payment_status' => 'paid',
                'metadata' => [
                    'data' => ['paso_rapido_tag' => $tag],
                ],
            ]);
    }
}
