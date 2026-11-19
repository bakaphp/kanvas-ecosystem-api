<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\MeetingAgentEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Services\MeetingAgentLocatorService;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class MeetingAgentSettingTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testTheFirstAgentOfEachTypeIsSetOnTheCompany(): void
    {
        $company = $this->freshCompany();
        $salesManager = $this->agentOfType($company, SalesManagerAgent::class);
        $pm = $this->agentOfType($company, ProjectManagerAgent::class);

        $this->assertSame($salesManager->getId(), (int) $company->get(MeetingAgentEnum::LEAD->value));
        $this->assertSame($pm->getId(), (int) $company->get(MeetingAgentEnum::ORGANIZATION->value));
    }

    public function testASecondAgentDoesNotTakeOver(): void
    {
        $company = $this->freshCompany();
        $first = $this->agentOfType($company, SalesManagerAgent::class);
        $this->agentOfType($company, SalesManagerAgent::class);

        $this->assertSame($first->getId(), (int) $company->get(MeetingAgentEnum::LEAD->value));
    }

    public function testDeletingTheSetAgentMovesToTheNextOrClears(): void
    {
        $company = $this->freshCompany();
        $first = $this->agentOfType($company, SalesManagerAgent::class);
        $second = $this->agentOfType($company, SalesManagerAgent::class);

        $first->delete();
        $this->assertSame($second->getId(), (int) $company->get(MeetingAgentEnum::LEAD->value));

        $second->delete();
        $this->assertNull($company->get(MeetingAgentEnum::LEAD->value));
    }

    public function testAManualChoiceOfAnyAgentIsHonored(): void
    {
        $company = $this->freshCompany();
        $this->agentOfType($company, SalesManagerAgent::class);
        $pm = $this->agentOfType($company, ProjectManagerAgent::class);

        $company->set(MeetingAgentEnum::LEAD->value, $pm->getId());

        $this->assertSame($pm->getId(), new MeetingAgentLocatorService()->resolve(MeetingAgentEnum::LEAD, app(Apps::class), $company)?->getId());
    }

    public function testASettingPointingAtAnotherCompanysAgentIsIgnored(): void
    {
        $company = $this->freshCompany();
        $own = $this->agentOfType($company, SalesManagerAgent::class);
        $foreign = $this->agentOfType($this->freshCompany(), SalesManagerAgent::class);

        $company->set(MeetingAgentEnum::LEAD->value, $foreign->getId());

        $this->assertSame($own->getId(), new MeetingAgentLocatorService()->resolve(MeetingAgentEnum::LEAD, app(Apps::class), $company)?->getId());
    }

    public function testTheCommandFillsACompanyThatHasNoSetting(): void
    {
        $company = $this->freshCompany();
        $salesManager = $this->agentOfType($company, SalesManagerAgent::class);
        $company->del(MeetingAgentEnum::LEAD->value);

        $this->artisan('kanvas:nervous-system:ensure-meeting-agents', ['--company' => $company->getId(), '--dry-run' => true])
            ->assertSuccessful();
        $this->assertNull($company->get(MeetingAgentEnum::LEAD->value));

        $this->artisan('kanvas:nervous-system:ensure-meeting-agents', ['--company' => $company->getId()])
            ->assertSuccessful();
        $this->assertSame($salesManager->getId(), (int) $company->get(MeetingAgentEnum::LEAD->value));
    }

    public function testCreatingAnAgentLeavesItsTypeUnloaded(): void
    {
        $agent = $this->agentOfType($this->freshCompany(), SalesManagerAgent::class);

        $this->assertFalse($agent->relationLoaded('type'));
    }
}
