<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Claims\Actions\AcquireEntityClaimAction;
use Kanvas\NervousSystem\Claims\Actions\ReleaseEntityClaimAction;
use Tests\TestCase;

class EntityClaimTest extends TestCase
{
    private function makeAgent(?int $companyId = null): Agent
    {
        $app = app(Apps::class);
        $companyId ??= auth()->user()->getCurrentCompany()->getId();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($companyId)
            ->create();
    }

    public function testAcquireReturnsClaimAndEmitsAcquiredEvent(): void
    {
        $agent = $this->makeAgent();
        $subject = $this->makeAgent();

        $claim = new AcquireEntityClaimAction($subject, $agent, reason: 'test-work')->execute();

        $this->assertNotNull($claim);
        $this->assertSame($agent->getId(), $claim->agent_id);
        $this->assertTrue($claim->expires_at->isFuture());

        $this->assertDatabaseHas('nervous_system_entity_claims', [
            'uuid' => $claim->uuid,
            'entity_namespace' => Agent::class,
            'entity_id' => $subject->getId(),
            'agent_id' => $agent->getId(),
        ], 'intelligence');

        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'claim.acquired',
            'actor_type' => 'Agent',
            'actor_id' => $agent->getId(),
            'source_entity_type' => Agent::class,
            'source_entity_id' => $subject->getId(),
        ], 'intelligence');
    }

    public function testSecondAgentIsDeferredWhileClaimHeld(): void
    {
        $subject = $this->makeAgent();
        $holderA = $this->makeAgent();
        $holderB = $this->makeAgent();

        $first = new AcquireEntityClaimAction($subject, $holderA)->execute();
        $second = new AcquireEntityClaimAction($subject, $holderB)->execute();

        $this->assertNotNull($first);
        $this->assertNull($second, 'A live claim held by another agent must defer the second acquirer');
    }

    public function testSameAgentReacquireRenewsInsteadOfDeferring(): void
    {
        $subject = $this->makeAgent();
        $agent = $this->makeAgent();

        $first = new AcquireEntityClaimAction($subject, $agent, ttlSeconds: 30)->execute();
        $this->assertNotNull($first);

        $renewed = new AcquireEntityClaimAction($subject, $agent, ttlSeconds: 120)->execute();

        $this->assertNotNull($renewed, 'The same holder re-acquiring must renew, not defer');
        $this->assertSame($first->getKey(), $renewed->getKey(), 'Renew keeps the same claim row');
    }

    public function testReleaseFreesTheClaimAndEmitsReleasedEvent(): void
    {
        $subject = $this->makeAgent();
        $holderA = $this->makeAgent();
        $holderB = $this->makeAgent();

        $claim = new AcquireEntityClaimAction($subject, $holderA)->execute();
        $this->assertNotNull($claim);
        $claimUuid = $claim->uuid;

        new ReleaseEntityClaimAction($claim)->execute();

        $this->assertDatabaseMissing('nervous_system_entity_claims', [
            'uuid' => $claimUuid,
        ], 'intelligence');

        $this->assertDatabaseHas('nervous_system_events', [
            'event_type' => 'claim.released',
            'actor_type' => 'Agent',
            'actor_id' => $holderA->getId(),
            'source_entity_id' => $subject->getId(),
        ], 'intelligence');

        $reacquire = new AcquireEntityClaimAction($subject, $holderB)->execute();
        $this->assertNotNull($reacquire, 'After release the entity is free for another agent to claim');
    }

    public function testExpiredClaimIsReacquiredByAnotherAgent(): void
    {
        $subject = $this->makeAgent();
        $holderA = $this->makeAgent();
        $holderB = $this->makeAgent();

        $claim = new AcquireEntityClaimAction($subject, $holderA)->execute();
        $this->assertNotNull($claim);

        // Simulate the holder's claim aging past its TTL.
        $claim->expires_at = Carbon::now()->subMinute();
        $claim->save();

        $stolen = new AcquireEntityClaimAction($subject, $holderB)->execute();

        $this->assertNotNull($stolen, 'An expired claim must not block a new acquirer');
        $this->assertSame($holderB->getId(), $stolen->agent_id);
    }

    public function testClaimIsScopedPerTenant(): void
    {
        $subject = $this->makeAgent();
        $company2 = Companies::factory()->create();

        $agentCompany1 = $this->makeAgent();
        $agentCompany2 = $this->makeAgent($company2->getId());

        $claim1 = new AcquireEntityClaimAction($subject, $agentCompany1)->execute();
        $claim2 = new AcquireEntityClaimAction($subject, $agentCompany2)->execute();

        $this->assertNotNull($claim1);
        $this->assertNotNull($claim2, 'The same entity under a different company is a distinct claim slot');
        $this->assertNotSame($claim1->companies_id, $claim2->companies_id);
    }
}
