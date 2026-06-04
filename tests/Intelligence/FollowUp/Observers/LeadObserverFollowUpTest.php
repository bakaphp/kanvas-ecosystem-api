<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Observers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

/**
 * Verifies LeadObserver::updating fires the stage-change side effects
 * when pipeline_stage_id changes: state reset + ledger event emitted.
 * Thread message persistence is best-effort (action swallows errors) so
 * we don't assert on it directly here — covered via lower-level test
 * of WriteLeadStageChangeThreadMessageAction if needed.
 */
class LeadObserverFollowUpTest extends TestCase
{
    use DatabaseTransactions;

    public function testStageChangeResetsFollowUpStateAndEmitsLedgerEvent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Pipe',

            'is_default' => 0,

        ]);
        $stageA = PipelineStage::create(['pipelines_id' => $pipeline->getId(), 'name' => 'A', 'weight' => 1]);
        $stageB = PipelineStage::create(['pipelines_id' => $pipeline->getId(), 'name' => 'B', 'weight' => 2]);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stageA->getId(),

        ]);

        // Pre-populate follow_up_state so we can prove it was reset.
        $lead->set('follow_up_state', [
            'count' => 3,
            'channels_used' => ['whatsapp'],
            'last_at' => Carbon::now()->toIso8601String(),
            'exhausted_at' => Carbon::now()->toIso8601String(),
            'exhausted_reason' => 'max_retries',
        ]);
        $lead->refresh();
        $this->assertSame(3, $lead->getFollowUpStateCount());

        // Trigger stage change.
        $lead->pipeline_stage_id = $stageB->getId();
        $lead->save();

        $lead->refresh();

        // State should be reset.
        $this->assertSame(0, $lead->getFollowUpStateCount());
        $this->assertFalse($lead->isFollowUpExhausted());

        // Ledger event should be emitted.
        $event = Event::query()
            ->where('apps_id', $app->getId())
            ->where('event_type', 'lead.stage.changed')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($stageA->getId(), $event->payload['from_stage_id'] ?? null);
        $this->assertSame($stageB->getId(), $event->payload['to_stage_id'] ?? null);
    }
}
