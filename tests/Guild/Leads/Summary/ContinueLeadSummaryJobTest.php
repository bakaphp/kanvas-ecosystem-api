<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Jobs\ContinueLeadSummaryJob;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Mockery;
use Mockery\MockInterface;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\MakesLeadConversations;

final class ContinueLeadSummaryJobTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ContinueLeadSummaryJob::class]);
        Log::spy();
    }

    public function testAnAlreadyCoveredTargetNeverResolvesAProvider(): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'hello');
        LeadSummaryRepository::save($lead, new LeadSummaryState(through: $message->getId()));
        $job = $this->job($lead, $message->getId());
        $job->shouldNotReceive('summaryProvider');

        $job->handle();

        Bus::assertNothingDispatched();
        $job->assertNotReleased();
    }

    public function testAnExhaustedChainNeverResolvesAProvider(): void
    {
        $lead = $this->makeLead();
        $job = $this->job($lead, $this->leadMessage($lead, 'hello')->getId());
        $job->continuations = ContinueLeadSummaryJob::MAX_RUNS;
        $job->shouldNotReceive('summaryProvider');

        $job->handle();

        $this->assertStopped($job, 'chain_limit');
    }

    public function testFiveChunksDispatchOneSuccessorWithTheSameTargetAndChainProgress(): void
    {
        $lead = $this->makeLead();
        $messages = $this->largeMessages($lead);
        $provider = new ScriptedStructuredNeuronProvider(array_fill(0, 5, $this->summaryAnswer([])));
        $job = $this->job($lead, $messages[5]);
        $job->continuations = 2;
        $job->shouldReceive('summaryProvider')->once()->andReturn($provider);

        $job->handle();

        $this->assertCount(5, $provider->prompts);
        Bus::assertDispatchedTimes(ContinueLeadSummaryJob::class, 1);
        Bus::assertDispatched(ContinueLeadSummaryJob::class, static fn (ContinueLeadSummaryJob $next): bool =>
            $next->lead->is($lead)
            && $next->target === $messages[5]
            && $next->continuations === 3
            && $next->previousCoveredThrough === $messages[4]
            && $next->delay === 5);
        $job->assertNotReleased();
    }

    public function testTheLastAllowedRunDoesNotCreateAnotherJob(): void
    {
        $lead = $this->makeLead();
        $messages = $this->largeMessages($lead);
        $provider = new ScriptedStructuredNeuronProvider(array_fill(0, 5, $this->summaryAnswer([])));
        $job = $this->job($lead, $messages[5]);
        $job->continuations = ContinueLeadSummaryJob::MAX_RUNS - 1;
        $job->shouldReceive('summaryProvider')->once()->andReturn($provider);

        $job->handle();

        $this->assertCount(5, $provider->prompts);
        $this->assertStopped($job, 'chain_limit');
    }

    public function testARebuildThatLosesChainProgressStopsAfterOneChunk(): void
    {
        $lead = $this->makeLead();
        $messages = $this->largeMessages($lead);
        LeadSummaryRepository::save($lead, new LeadSummaryState(through: $messages[4], rebuildFrom: $messages[0]));
        $provider = new ScriptedStructuredNeuronProvider([$this->summaryAnswer([])]);
        $job = $this->job($lead, $messages[5]);
        $job->previousCoveredThrough = $messages[4];
        $job->continuations = 1;
        $job->shouldReceive('summaryProvider')->once()->andReturn($provider);

        $job->handle();

        $this->assertCount(1, $provider->prompts);
        $this->assertStopped($job, 'no_progress');
    }

    public function testReachingTheTargetStopsWithoutDispatching(): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'hello');
        $provider = new ScriptedStructuredNeuronProvider([$this->summaryAnswer([])]);
        $job = $this->job($lead, $message->getId());
        $job->shouldReceive('summaryProvider')->once()->andReturn($provider);

        $job->handle();

        $this->assertSame($message->getId(), LeadSummaryRepository::state($lead)->coveredThrough());
        Bus::assertNothingDispatched();
        $job->assertNotReleased();
    }

    public function testFailedOutputRetriesTheSameJobWithoutStartingAFreshChain(): void
    {
        $lead = $this->makeLead();
        $job = $this->job($lead, $this->leadMessage($lead, 'hello')->getId());
        $job->shouldReceive('summaryProvider')->once()->andReturn(new ScriptedStructuredNeuronProvider(['invalid', 'invalid']));

        $job->handle();

        $job->assertReleased(120);
        Bus::assertNothingDispatched();
    }

    private function job(Lead $lead, int $target): ContinueLeadSummaryJob&MockInterface
    {
        return Mockery::mock(ContinueLeadSummaryJob::class, [$lead, $this->makeAgentFor(auth()->user()), $target])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->withFakeQueueInteractions();
    }

    /** @return list<int> */
    private function largeMessages(Lead $lead): array
    {
        $ids = [];

        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->leadMessage($lead, str_repeat('conversation ', 2500))->getId();
        }

        return $ids;
    }

    private function assertStopped(ContinueLeadSummaryJob $job, string $reason): void
    {
        Bus::assertNothingDispatched();
        $job->assertNotReleased();
        Log::shouldHaveReceived('warning')->with(
            'Lead summary continuation stopped',
            Mockery::on(static fn (array $context): bool => $context['reason'] === $reason && $context['target'] === $job->target),
        )->once();
    }
}
