<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Credits;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\IssueCompanyCreditTool;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Users\Models\UserCompanyApps;
use Tests\TestCase;

final class IssueCompanyCreditToolTest extends TestCase
{
    use BuildsCreditScenarios;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountTypes();
    }

    public function testIssuesACreditToAClientByIdThatTheNextOrderConsumes(): void
    {
        $client = Companies::factory()->create(['users_id' => auth()->user()->getId()]);

        $result = $this->tool()->__invoke(
            amount: 75.5,
            reason: '2 units of SKU ABC unavailable',
            company_id: $client->getId(),
        );

        $this->assertTrue($result['issued']);
        $credit = Discount::findOrFail($result['credit_id']);
        $this->assertSame($client->getId(), (int) $credit->companies_id);
        $this->assertSame(75.5, $credit->value);
        $this->assertTrue($credit->isAutoAppliedCredit());
        $this->assertNull($credit->code);

        $order = $this->orderFor($client, 100);
        new DiscountService($order->app, $order->company)->applyFirstAvailableCredit($order);
        $this->assertSame(24.5, $order->refresh()->total_net_amount);
    }

    public function testResolvesTheClientByExactNameAndRecordsTheSourceOrder(): void
    {
        $client = Companies::factory()->create(['users_id' => auth()->user()->getId(), 'name' => 'Ferretería ' . uniqid()]);
        $order = $this->orderFor($client, 100);

        $result = $this->tool()->__invoke(
            amount: 10,
            reason: 'short shipment',
            company_name: $client->name,
            source_order_number: (string) $order->order_number,
        );

        $this->assertTrue($result['issued']);
        $this->assertSame($client->name, $result['company']);
        $this->assertStringContainsString("order {$order->order_number}", Discount::findOrFail($result['credit_id'])->description);
    }

    public function testRejectsUnknownCompaniesAndNonPositiveAmounts(): void
    {
        $foreign = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        UserCompanyApps::where('companies_id', $foreign->getId())->delete();

        $this->assertFalse($this->tool()->__invoke(amount: 10, reason: 'x', company_id: $foreign->getId())['issued']);
        $this->assertFalse($this->tool()->__invoke(amount: 10, reason: 'x', company_name: 'no-such-company-' . uniqid())['issued']);
        $this->assertFalse($this->tool()->__invoke(amount: 10, reason: 'x')['issued']);
        $this->assertFalse($this->tool()->__invoke(amount: 0, reason: 'x', company_id: auth()->user()->getCurrentCompany()->getId())['issued']);
    }

    private function tool(): IssueCompanyCreditTool
    {
        $user = auth()->user();

        return new IssueCompanyCreditTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
