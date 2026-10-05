<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchDealsTool;
use Tests\TestCase;
use Tests\Traits\MakesLeadStatuses;

class SearchDealsToolTest extends TestCase
{
    use MakesLeadStatuses;

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

    /** A deal marked Lost in the CRM is closed by its named status; the integer column says nothing. */
    public function testAClosedDealIsReportedByItsNamedStatusAndFilteredOut(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $token = 'Perdido' . uniqid();

        $created = new CreateDealTool($app, $company, $user)->__invoke(title: $token . ' deal');
        $deal = Deal::getByIdFromCompanyApp((int) $created['deal_id'], $company, $app);
        $deal->status_id = self::lostLeadStatusId();
        $deal->saveOrFail();

        $open = new SearchDealsTool()->withContext($app, $company, $user)->__invoke(query: $token, status: 'open', limit: 100);
        $closed = new SearchDealsTool()->withContext($app, $company, $user)->__invoke(query: $token, status: 'closed', limit: 100);

        $this->assertSame(0, $open['count']);
        $this->assertSame([$deal->getId()], array_column($closed['deals'], 'deal_id'));
        $this->assertSame('Lost', $closed['deals'][0]['status']);
        $this->assertFalse($closed['deals'][0]['is_open']);
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
