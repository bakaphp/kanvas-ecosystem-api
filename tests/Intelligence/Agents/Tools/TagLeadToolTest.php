<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagLeadTool;
use Tests\TestCase;

/**
 * A fresh company per test: the dealer-tag list is a company setting, which lands in Redis and
 * never rolls back.
 */
final class TagLeadToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testAddsAndRemovesTags(): void
    {
        [$tool, $lead] = $this->setUpLead();

        $added = $tool->__invoke(lead_id: $lead->getId(), tags: ['vip', 'hot']);

        $this->assertSame('Tags added.', $added['message']);
        $this->assertEqualsCanonicalizing(['vip', 'hot'], $added['tags']);

        $removed = $tool->__invoke(lead_id: $lead->getId(), tags: ['hot'], remove: true);

        $this->assertSame('Tags removed.', $removed['message']);
        $this->assertSame(['vip'], $removed['tags']);
    }

    public function testRefusesDealerTagsButAppliesTheRest(): void
    {
        [$tool, $lead] = $this->setUpLead();

        $result = $tool->__invoke(lead_id: $lead->getId(), tags: ['store north', 'vip']);

        $this->assertSame(['store north'], $result['refused_dealer_tags']);
        $this->assertSame(['vip'], $result['tags']);
    }

    public function testCannotRemoveADealerTag(): void
    {
        [$tool, $lead] = $this->setUpLead();
        $lead->addTag('Store North');

        $result = $tool->__invoke(lead_id: $lead->getId(), tags: ['Store North'], remove: true);

        $this->assertSame('Nothing changed: dealer tags are assigned automatically.', $result['message']);
        $this->assertSame(['Store North'], $result['tags']);
    }

    public function testALeadOfAnotherCompanyIsNotResolved(): void
    {
        [$tool] = $this->setUpLead();
        [, $foreignLead] = $this->setUpLead();

        $result = $tool->__invoke(lead_id: $foreignLead->getId(), tags: ['vip']);

        $this->assertArrayNotHasKey('tags', $result);
        $this->assertSame([], $foreignLead->tags()->pluck('name')->all());
    }

    public function testRequiresATag(): void
    {
        [$tool, $lead] = $this->setUpLead();

        $this->assertArrayHasKey('error', $tool->__invoke(lead_id: $lead->getId(), tags: ['  ']));
    }

    /**
     * @return array{0: TagLeadTool, 1: Lead}
     */
    private function setUpLead(): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $company->set(ConfigurationEnum::LEAD_DEALER_TAGS->value, [
            ['tag' => 'Store North', 'stock_prefixes' => ['N']],
        ]);

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        return [new TagLeadTool()->withContext($app, $company, $user), $lead];
    }
}
