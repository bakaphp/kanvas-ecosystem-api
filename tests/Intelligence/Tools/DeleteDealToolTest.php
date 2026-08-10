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

        $result = new DeleteDealTool()->__invoke(deal_id: $dealId);

        $this->assertSame('success', $result['status']);

        $isDeleted = DB::connection('crm')->table('deals')->where('id', $dealId)->value('is_deleted');
        $this->assertSame(1, (int) $isDeleted);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = new DeleteDealTool()->__invoke(deal_id: 999999999);

        $this->assertSame('error', $result['status']);
    }
}
