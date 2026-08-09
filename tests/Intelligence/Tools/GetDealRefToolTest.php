<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealRefTool;
use Tests\TestCase;

class GetDealRefToolTest extends TestCase
{
    public function testReturnsDealDetail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => 'Orlanda',
            'lastname' => 'Kest',
        ]);

        $title = 'Deal ' . uniqid();
        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: $title, description: 'annual plan', people_id: $people->getId());

        $result = new GetDealRefTool()->__invoke(deal_id: (int) $created['deal_id']);

        $this->assertSame((int) $created['deal_id'], $result['deal_id']);
        $this->assertSame($title, $result['title']);
        $this->assertSame($people->getId(), $result['people']['id']);
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = new GetDealRefTool()->__invoke(deal_id: 999999999);

        $this->assertSame('error', $result['status']);
    }
}
