<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchDealsTool;
use Tests\TestCase;

class SearchDealsToolTest extends TestCase
{
    public function testFindsDealByTitleAndContact(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Zephyrus' . uniqid();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => $token,
            'lastname' => 'Vane',
        ]);

        $match = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal for ' . $token, people_id: $people->getId());
        $other = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Unrelated deal ' . uniqid());

        $byTitle = new SearchDealsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'all', limit: 100);

        $ids = array_column($byTitle['deals'], 'deal_id');
        $this->assertContains((int) $match['deal_id'], $ids);
        $this->assertNotContains((int) $other['deal_id'], $ids);
    }

    public function testEmptyQueryReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new SearchDealsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: '   ');

        $this->assertSame(0, $result['count']);
        $this->assertArrayHasKey('error', $result);
    }
}
