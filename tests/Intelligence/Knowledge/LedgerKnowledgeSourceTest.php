<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class LedgerKnowledgeSourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testASavedMemoryIsKeptAsTheAgentWroteIt(): void
    {
        $event = $this->event('agent.knowledge.saved', [
            'title' => 'Acme prefers quarterly invoicing',
            'content' => 'Agreed with their CFO on 2026-09-01; monthly invoices get rejected.',
            'tags' => ['billing', 'acme'],
        ]);

        $documents = new LedgerKnowledgeSource()->build($event);

        $this->assertCount(1, $documents);
        $this->assertSame('ledger-memory-99', $documents[0]->id);
        $this->assertSame(
            "Acme prefers quarterly invoicing\nAgreed with their CFO on 2026-09-01; monthly invoices get rejected.\nTags: billing, acme",
            $documents[0]->content
        );
        $this->assertSame('memory', $documents[0]->metadata['source_type']);
        $this->assertSame(7, $documents[0]->metadata['agent_id']);
        $this->assertSame(Lead::class, $documents[0]->metadata['entity_type']);
        $this->assertSame(5, $documents[0]->metadata['entity_id']);
        $this->assertSame($event->occurred_at->timestamp, $documents[0]->metadata['created_at']);
    }

    public function testAnAllowlistedOutcomeBecomesOneReadableLine(): void
    {
        $event = $this->event('plan.approved', [
            'title' => "Migrate the billing cron to Laravel scheduler\nSecond line that must not be embedded",
            'status' => 'approved',
        ]);

        $documents = new LedgerKnowledgeSource()->build($event);

        $this->assertCount(1, $documents);
        $this->assertSame('ledger-ledger-99', $documents[0]->id);
        $this->assertSame('plan.approved: Migrate the billing cron to Laravel scheduler', $documents[0]->content);
        $this->assertSame('ledger', $documents[0]->metadata['source_type']);
    }

    public function testOrdinaryTelemetryIsNeverEmbedded(): void
    {
        $this->assertSame([], new LedgerKnowledgeSource()->build($this->event('lead.viewed', ['title' => 'x'])));
        $this->assertFalse(LedgerKnowledgeSource::wants('lead.viewed'));
        $this->assertFalse(LedgerKnowledgeSource::wants('plan.task.created'));
        $this->assertTrue(LedgerKnowledgeSource::wants('agent.decided.escalate'));
        $this->assertTrue(LedgerKnowledgeSource::wants('agent.knowledge.saved'));
    }

    /**
     * The allowlist names what the ledger really emits: these are the literals in ApprovePlanAction,
     * UpdateTaskStatusAction and LeadObserver.
     */
    public function testTheOutcomesTheLedgerEmitsAreWanted(): void
    {
        foreach (['plan.approved', 'plan.task.completed', 'lead.stage.changed', 'lead.follow_up.sent'] as $emitted) {
            $this->assertTrue(LedgerKnowledgeSource::wants($emitted), $emitted);
        }
    }

    /**
     * The real emitters' payloads carry ids, not text (`to_stage_id`, `status_to`, ...): the line comes
     * from the record the event happened to.
     */
    public function testARealOutcomeIsDescribedFromItsRecord(): void
    {
        $app = app(Apps::class);
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['title' => 'Acme renewal']);
        $stage = PipelineStage::query()->findOrFail($lead->pipeline_stage_id);

        $event = $this->event('lead.stage.changed', ['from_stage_id' => null, 'to_stage_id' => $stage->getId()], $lead);

        $documents = new LedgerKnowledgeSource()->build($event);

        $this->assertCount(1, $documents);
        $this->assertSame("lead.stage.changed: Acme renewal (moved to {$stage->name})", $documents[0]->content);
        $this->assertSame($lead->getId(), $documents[0]->metadata['entity_id']);
    }

    public function testAnOutcomeWhoseRecordIsGoneIsSkipped(): void
    {
        $this->assertSame([], new LedgerKnowledgeSource()->build($this->event('plan.task.completed', ['status_to' => 'done'], sourceId: 0)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $type, array $payload, ?Lead $lead = null, int $sourceId = 5): Event
    {
        $event = new Event();
        $event->id = 99;
        $event->apps_id = 1;
        $event->companies_id = 2;
        $event->event_type = $type;
        $event->payload = $payload;
        $event->actor_type = 'Agent';
        $event->actor_id = 7;
        $event->source_entity_type = Lead::class;
        $event->source_entity_id = $lead?->getId() ?? $sourceId;
        $event->occurred_at = Carbon::parse('2026-09-28 12:00:00');

        return $event;
    }
}
