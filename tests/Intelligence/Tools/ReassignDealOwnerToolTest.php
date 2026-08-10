<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReassignDealOwnerTool;
use Tests\TestCase;

class ReassignDealOwnerToolTest extends TestCase
{
    public function testReassignsDealToCompanyUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());
        $dealId = (int) $created['deal_id'];

        $result = new ReassignDealOwnerTool()
            ->withContext($app, $company, $user)
            ->__invoke(deal_id: $dealId, new_owner: $user->email);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($user->getId(), $result['new_owner']['id']);
        $this->assertSame($user->getId(), Deal::getById($dealId)->owner_id);
    }

    public function testUnknownOwnerReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid());

        $result = new ReassignDealOwnerTool()
            ->withContext($app, $company, $user)
            ->__invoke(deal_id: (int) $created['deal_id'], new_owner: 'nobody-' . uniqid() . '@nowhere.test');

        $this->assertArrayHasKey('error', $result);
    }
}
