<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
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

        $result = $this->withTenant(new GetDealRefTool())->__invoke(deal_id: (int) $created['deal_id']);

        $this->assertSame((int) $created['deal_id'], $result['deal_id']);
        $this->assertSame($title, $result['title']);
        $this->assertSame($people->getId(), $result['people']['id']);
    }

    /** KANVAS-ECOSYSTEM-6ED: NeuronAI clones the registered tool per call, so the repeat must hold across clones. */
    public function testRepeatedReadOfAnUnchangedDealTellsTheModelToStop(): void
    {
        $dealId = $this->createDeal();
        $registered = $this->withTenant(new GetDealRefTool());

        $first = (clone $registered)->__invoke(deal_id: $dealId);
        $second = (clone $registered)->__invoke(deal_id: $dealId);

        $this->assertArrayNotHasKey('repeat_call', $first);
        $this->assertTrue($second['repeat_call']);
        $this->assertSame($first['title'], $second['title']);
    }

    /** Notes are a custom field that never bumps updated_at — a re-read after writing one must not be a "repeat". */
    public function testReReadAfterTheDealChangesReturnsTheNewState(): void
    {
        $dealId = $this->createDeal();
        $registered = $this->withTenant(new GetDealRefTool());

        (clone $registered)->__invoke(deal_id: $dealId);

        $user = auth()->user();
        Deal::getByIdFromCompanyApp($dealId, $user->getCurrentCompany(), app(Apps::class))
            ->set('deal_notes', 'budget approved');

        $second = (clone $registered)->__invoke(deal_id: $dealId);

        $this->assertArrayNotHasKey('repeat_call', $second);
        $this->assertSame('budget approved', $second['notes']);
    }

    public function testRunBudgetIsKeyedPerDeal(): void
    {
        $tool = new GetDealRefTool();

        $tool->setInputs(['deal_id' => 1]);
        $firstKey = $tool->getRunKey();
        $tool->setInputs(['deal_id' => 2]);

        $this->assertNotSame($firstKey, $tool->getRunKey());
    }

    public function testHallucinatedDealIdReturnsError(): void
    {
        $result = $this->withTenant(new GetDealRefTool())->__invoke(deal_id: 999999999);

        $this->assertSame('error', $result['status']);
    }

    private function createDeal(): int
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $created = new CreateDealTool($app, $company, $user)
            ->__invoke(title: 'Deal ' . uniqid(), description: 'annual plan', people_id: $people->getId());

        return (int) $created['deal_id'];
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
