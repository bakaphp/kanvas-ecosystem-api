<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\CompanyAssistantSettingEnum;
use Kanvas\Intelligence\Agents\Services\CompanyAssistantLocatorService;
use Kanvas\NervousSystem\Project\Actions\EnsureCompanyOperationsProjectAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;

final class CompanyOperationsProjectTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain();
    }

    /**
     * A company setting is written to Redis and the `ecosystem` connection, neither of which a test
     * transaction rolls back.
     */
    protected function tearDown(): void
    {
        try {
            $this->company->del(CompanyAssistantSettingEnum::AGENT_ID->value);
        } finally {
            parent::tearDown();
        }
    }

    public function testCreatesOneOperationsProjectOwnedByTheAssistant(): void
    {
        $project = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        $this->assertTrue($project->isCompanyOperations());
        $this->assertSame($this->brain->getId(), (int) $project->agent_id);
        $this->assertSame((int) $this->brain->user_id, (int) $project->users_id);
        $this->assertSame('Operations', $project->title);
    }

    public function testASecondCallReturnsTheSameProject(): void
    {
        $first = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();
        $second = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(1, $this->operationsBoards()->count());
    }

    #[Group('serial')]
    public function testTheDesignatedAssistantOwnsItWhateverAgentTriggeredTheCreation(): void
    {
        $newer = $this->makeBrainAgent();
        $this->company->set(CompanyAssistantSettingEnum::AGENT_ID->value, $newer->getId());

        $project = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        $this->assertSame($newer->getId(), (int) $project->agent_id);
    }

    #[Group('serial')]
    public function testTheProjectFollowsAChangeOfDesignatedAssistant(): void
    {
        $project = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();
        $newer = $this->makeBrainAgent();
        $this->company->set(CompanyAssistantSettingEnum::AGENT_ID->value, $newer->getId());

        $again = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        $this->assertSame($project->getId(), $again->getId());
        $this->assertSame($newer->getId(), (int) $again->agent_id);
        $this->assertSame((int) $newer->user_id, (int) $again->users_id);
    }

    public function testTheProjectIsRepointedWhenItsOwnerIsNoLongerActive(): void
    {
        $project = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();
        $this->brain->is_active = false;
        $this->brain->saveOrFail();
        $replacement = $this->makeBrainAgent();

        $again = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        $this->assertSame($project->getId(), $again->getId());
        $this->assertSame($replacement->getId(), (int) $again->agent_id);
    }

    #[Group('serial')]
    public function testLocatorPrefersTheCompanySettingThenTheOldestActiveBrain(): void
    {
        $older = $this->brain;
        $newer = $this->makeBrainAgent();

        $this->assertTrue($older->is(CompanyAssistantLocatorService::for($this->tenantApp, $this->company)));

        $this->company->set(CompanyAssistantSettingEnum::AGENT_ID->value, $newer->getId());
        $this->assertTrue($newer->is(CompanyAssistantLocatorService::for($this->tenantApp, $this->company)));
    }

    public function testACompanyWithNoBrainHasNoAssistantAndNoProject(): void
    {
        $this->brain->is_active = false;
        $this->brain->saveOrFail();

        $this->assertNull(CompanyAssistantLocatorService::for($this->tenantApp, $this->company));

        $this->expectException(ValidationException::class);
        new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();
    }
}
