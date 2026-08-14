<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\DeleteDealTool;
use Tests\TestCase;

class DeleteDealToolTest extends TestCase
{
    public function testSoftDeletesDeal(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());
        $dealId = (int) $created['deal_id'];

        $result = $this->withTenant(new DeleteDealTool())->__invoke(deal_id: $dealId);

        $this->assertSame('success', $result['status']);

        $isDeleted = DB::connection('crm')->table('deals')->where('id', $dealId)->value('is_deleted');
        $this->assertSame(1, (int) $isDeleted);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = $this->withTenant(new DeleteDealTool())->__invoke(deal_id: 999999999);

        $this->assertSame('error', $result['status']);
    }

    /**
     * Deal tools resolve their deal against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
