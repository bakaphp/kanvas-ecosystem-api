<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use NeuronAI\Tools\ToolPropertyInterface;
use Tests\TestCase;

class SearchLeadsToolTest extends TestCase
{
    public function testFindsLeadByContactName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Zeraphina' . uniqid();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => $token,
            'lastname' => 'Quill',
        ]);
        $match = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Deal for ' . $token,
            'people_id' => $people->getId(),
            'status' => 0,
        ]);
        $other = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Unrelated lead ' . uniqid(),
            'status' => 0,
        ]);

        $result = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, limit: 100);

        $ids = array_column($result['leads'], 'lead_id');
        $this->assertContains($match->getId(), $ids);
        $this->assertNotContains($other->getId(), $ids);
    }

    public function testFindsLeadByTitleAndRespectsStatusFilter(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Vulcanox' . uniqid();
        $openLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => $token . ' open',
            'status' => 0,
        ]);
        $closedLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => $token . ' closed',
            'status' => 2,
        ]);

        $openOnly = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'open', limit: 100);
        $openIds = array_column($openOnly['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $openIds);
        $this->assertNotContains($closedLead->getId(), $openIds);

        $all = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'all', limit: 100);
        $allIds = array_column($all['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $allIds);
        $this->assertContains($closedLead->getId(), $allIds);
    }

    /**
     * "What open leads do I have" carries no search term, so the model calls this tool with only
     * `status` — which threw MissingCallbackParameter while `query` was required, killing the whole
     * turn (Sentry KANVAS-ECOSYSTEM-65P).
     */
    public function testListsLeadsWhenQueryIsOmitted(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $openLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Kryonex ' . uniqid(),
            'status' => 0,
        ]);
        $closedLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Kryonex ' . uniqid(),
            'status' => 2,
        ]);

        $tool = new SearchLeadsTool()->withContext($app, $company, $user);
        $tool->setInputs(['status' => 'open']);
        $tool->execute();

        $result = json_decode($tool->getResult(), true);
        $ids = array_column($result['leads'], 'lead_id');

        $this->assertContains($openLead->getId(), $ids);
        $this->assertNotContains($closedLead->getId(), $ids);
    }

    public function testQueryPropertyIsOptional(): void
    {
        $query = array_values(array_filter(
            new SearchLeadsTool()->getProperties(),
            fn (ToolPropertyInterface $property): bool => $property->getName() === 'query',
        ));

        $this->assertNotEmpty($query);
        $this->assertFalse($query[0]->isRequired());
    }
}
