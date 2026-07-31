<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class FindSalesOrderAffiliateCommissionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    protected Apps $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testSurfacesAffiliateCommissionWhenOrderHasConversion(): void
    {
        $code = 'UAX' . uniqid();
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId, $code . ' Distributor');
        $order = $this->createOrder();

        $this->createConversion($affiliateId, $order->getId());

        $result = new FindSalesOrderTool()
            ->withContext($this->apps, $this->company, $this->user)
            ->__invoke(order_number: (string) $order->order_number);

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['affiliate_commissions']);

        $commission = $result['affiliate_commissions'][0];
        $this->assertSame(10.0, $commission['commission_amount']);
        $this->assertSame('pending', $commission['status']);
        $this->assertStringContainsString($code, (string) $commission['affiliate']);
    }

    public function testAffiliateCommissionsEmptyWhenOrderHasNone(): void
    {
        $order = $this->createOrder();

        $result = new FindSalesOrderTool()
            ->withContext($this->apps, $this->company, $this->user)
            ->__invoke(order_number: (string) $order->order_number);

        $this->assertTrue($result['found']);
        $this->assertSame([], $result['affiliate_commissions']);
    }

    private function createProgram(): string
    {
        return $this->graphQL('
            mutation($input: AffiliateProgramInput!) {
                createAffiliateProgram(input: $input) { id }
            }
        ', ['input' => ['name' => 'Program ' . uniqid(), 'default_commission_rate' => 10.00]])
            ->assertSuccessful()
            ->json('data.createAffiliateProgram.id');
    }

    private function createAffiliate(string $programId, string $name): string
    {
        return $this->graphQL('
            mutation($input: AffiliateInput!) {
                createAffiliate(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_program_id' => $programId,
            'name' => $name,
            'email' => fake()->unique()->email(),
            'commission_rate' => 10.00,
        ]])
            ->assertSuccessful()
            ->json('data.createAffiliate.id');
    }

    private function createConversion(string $affiliateId, int $orderId): void
    {
        $this->graphQL('
            mutation($input: AffiliateConversionInput!) {
                createAffiliateConversion(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_id' => $affiliateId,
            'order_id' => $orderId,
            'order_total' => 100.00,
            'eligible_amount' => 100.00,
            'commission_type' => 'PERCENTAGE',
            'commission_rate' => 10.00,
            'commission_amount' => 10.00,
        ]])->assertSuccessful();
    }

    private function createOrder(): Order
    {
        $people = People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();

        return Order::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create([
                'people_id' => $people->getId(),
                'order_number' => crc32(uniqid('ord', true)),
            ]);
    }
}
