<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Contracts\ProvidesAgentContext;
use Kanvas\Intelligence\Agents\Services\EntityContextBriefService;
use Tests\TestCase;
use Tests\Traits\MakesLeadStatuses;

class EntityContextBriefServiceTest extends TestCase
{
    use MakesLeadStatuses;

    private function makePeople(array $attributes = []): People
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return People::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            ...$attributes,
        ]);
    }

    /**
     * The agent reported a lost lead as an active negotiation because the generic brief copied the
     * integer `status` column, which nothing writes, and never the named status.
     */
    public function testALeadBriefCarriesItsNamedStatusAndWhetherItIsOpen(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Cinedot WhatsApp agent',
            'leads_status_id' => self::lostLeadStatusId(),
        ]);

        $brief = new EntityContextBriefService()->brief($lead);
        $text = new EntityContextBriefService()->renderText($lead);

        $this->assertSame('Lead', $brief['type']);
        $this->assertSame('Lost', $brief['status']);
        $this->assertSame('closed', $brief['state']);
        $this->assertStringContainsString('status: Lost', $text);
        $this->assertStringContainsString('state: closed', $text);
    }

    public function testGenericBriefFromModelAttributes(): void
    {
        $people = $this->makePeople(['firstname' => 'Ana', 'lastname' => 'Rivera']);

        $brief = new EntityContextBriefService()->brief($people);

        $this->assertSame('People', $brief['type']);
        $this->assertSame($people->getId(), $brief['id']);
        $this->assertSame('Ana', $brief['firstname']);
        $this->assertSame('Rivera', $brief['lastname']);
    }

    public function testProvidesAgentContextOverridesGenericBrief(): void
    {
        $model = new class () extends Model implements ProvidesAgentContext {
            public function agentContextBrief(): array
            {
                return ['type' => 'Widget', 'id' => 7, 'flavor' => 'custom'];
            }
        };

        $brief = new EntityContextBriefService()->brief($model);

        $this->assertSame('Widget', $brief['type']);
        $this->assertSame('custom', $brief['flavor']);
    }

    public function testRenderTextProducesCompactLine(): void
    {
        $people = $this->makePeople(['firstname' => 'Ana']);

        $text = new EntityContextBriefService()->renderText($people);

        $this->assertStringStartsWith('People #' . $people->getId(), $text);
        $this->assertStringContainsString('firstname: Ana', $text);
    }
}
