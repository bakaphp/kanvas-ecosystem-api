<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Intelligence\Agents\Actions\EnsureAgentHasDedicatedUserAction;
use Kanvas\Intelligence\Agents\Actions\ProvisionAgentUserAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;
use Tests\Traits\MakesEmployees;

final class EnsureCompanyOperationsProjectCommandTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;
    use MakesEmployees;

    private const string COMMAND = 'kanvas:nervous-system:ensure-company-operations-project';

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain();
    }

    public function testCreatesTheProjectForACompanyThatHasABrain(): void
    {
        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertSame(1, $this->operationsBoards()->count());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->artisan(self::COMMAND, [...$this->scope(), '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->operationsBoards()->count());
    }

    public function testACompanyWithNoBrainIsSkippedAndNoAgentIsCreated(): void
    {
        $this->brain->is_active = false;
        $this->brain->saveOrFail();
        $before = $this->agentCount();

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertSame($before, $this->agentCount());
        $this->assertSame(0, $this->operationsBoards()->count());
    }

    public function testABrainSharingAHumansLoginGetsItsOwnUser(): void
    {
        $this->brain->user_id = $this->human->getId();
        $this->brain->saveOrFail();

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->brain->refresh();
        $this->assertNotSame($this->human->getId(), $this->brain->user_id);
        $this->assertSame($this->brain->user_id, $this->operationsBoards()->firstOrFail()->users_id);
    }

    public function testABrainThatAlreadyHasItsOwnUserKeepsIt(): void
    {
        $this->giveTheBrainAUserMadeForAnAgent();
        $before = $this->brain->user_id;

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertSame($before, $this->brain->refresh()->user_id);
    }

    /**
     * Not an administrator, and no other agent on it: a person's login all the same. Left there, the
     * brain treats that person's own messages as its own.
     */
    public function testABrainOnAnOrdinaryEmployeesLoginIsMovedOffIt(): void
    {
        $employee = $this->makeEmployee();
        $this->assertFalse($employee->isAdmin());
        $this->brain->user_id = $employee->getId();
        $this->brain->saveOrFail();

        $this->assertNotNull(new EnsureAgentHasDedicatedUserAction($this->brain->refresh())->sharedBecause());

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertNotSame($employee->getId(), $this->brain->refresh()->user_id);
    }

    public function testDryRunOnlyReportsTheSharedLogin(): void
    {
        $this->brain->user_id = $this->human->getId();
        $this->brain->saveOrFail();

        $this->artisan(self::COMMAND, [...$this->scope(), '--dry-run' => true])
            ->expectsOutputToContain('shares a login')
            ->assertSuccessful();

        $this->assertSame($this->human->getId(), $this->brain->refresh()->user_id);
    }

    public function testTwoBrainsWithTheSameNameDoNotEndUpOnOneUser(): void
    {
        $twin = $this->makeBrainAgent();
        $this->brain->user_id = $this->human->getId();
        $this->brain->saveOrFail();
        $twin->user_id = $this->human->getId();
        $twin->saveOrFail();

        $first = new EnsureAgentHasDedicatedUserAction($this->brain->refresh())->execute();
        $second = new EnsureAgentHasDedicatedUserAction($twin->refresh())->execute();

        $this->assertSame($this->brain->name, $twin->name);
        $this->assertNotSame($first->getId(), $second->getId());
        $this->assertNotSame($this->human->getId(), $first->getId());
    }

    public function testABrainOnTheCompanyAiUserIsMovedOffIt(): void
    {
        $shared = $this->company->getAiAgentUser();
        if ($shared === null) {
            $this->markTestSkipped('The test company has no shared AI agent user configured.');
        }
        $this->brain->user_id = $shared->getId();
        $this->brain->saveOrFail();

        $this->assertNotNull(new EnsureAgentHasDedicatedUserAction($this->brain->refresh())->sharedBecause());

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertNotSame($shared->getId(), $this->brain->refresh()->user_id);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->brain->user_id = $this->human->getId();
        $this->brain->saveOrFail();

        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();
        $afterFirst = $this->brain->refresh()->user_id;
        $this->artisan(self::COMMAND, $this->scope())->assertSuccessful();

        $this->assertSame($afterFirst, $this->brain->refresh()->user_id);
        $this->assertSame(1, $this->operationsBoards()->count());
    }

    private function giveTheBrainAUserMadeForAnAgent(): void
    {
        $this->brain->user_id = new ProvisionAgentUserAction(
            $this->tenantApp,
            $this->company,
            'Brain ' . fake()->unique()->lexify('?????'),
        )->execute()->getId();
        $this->brain->saveOrFail();
        $this->brain->refresh();
    }

    /**
     * @return array<string, int>
     */
    private function scope(): array
    {
        return [
            '--app' => $this->tenantApp->getId(),
            '--company' => $this->company->getId(),
        ];
    }

    private function agentCount(): int
    {
        return Agent::query()->fromApp($this->tenantApp)->fromCompany($this->company)->count();
    }
}
