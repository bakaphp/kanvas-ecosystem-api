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

    /** No query is fine as long as a date window narrows the book — "which deals moved today". */
    public function testADateWindowIsEnoughOfAFilterOnItsOwn(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Movedtoday ' . uniqid());

        $result = new SearchDealsTool()
            ->withContext($app, $company, $user)
            ->__invoke(status: 'all', updated_since: 'today', limit: 100);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertContains((int) $created['deal_id'], array_column($result['deals'], 'deal_id'));
    }

    public function testAnUnparseableUpdatedSinceIsRejected(): void
    {
        $result = new SearchDealsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(updated_since: 'sometime last week');

        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('updated_since', $result['error']);
    }
}
