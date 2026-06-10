<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Observers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Full lifecycle coverage for LeadObserver — every hook the observer wires up.
 *
 * - creating(): default title / status / pipeline-stage / phone sanitization
 * - created():  default Social channel (+ optional AI-notes channel)
 * - updating(): stage change → follow-up state reset + ledger event + system
 *               thread message via WriteLeadStageChangeThreadMessageAction
 * - softDeleted(): related Social channels soft-deleted
 */
class LeadObserverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    private Apps $testApp;

    private Companies $company;

    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        /** @var Users $authedUser */
        $authedUser = auth()->user();
        $this->user = $authedUser;
        $this->company = $this->user->getCurrentCompany();

        // WriteLeadStageChangeThreadMessageAction resolves the AI agent user off
        // the company and the polymorphic Lead system module for the message link.
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
    }

    public function testCreatingDefaultsTitleFromFirstAndLastName(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'title' => '',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
            ]);

        $this->assertSame('Ada Lovelace', $lead->title);
    }

    public function testCreatingAssignsDefaultStatusWhenMissing(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create(['leads_status_id' => 0]);

        $this->assertGreaterThan(0, $lead->leads_status_id);
    }

    public function testCreatingSanitizesPhoneNumber(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create(['phone' => '+1 (829) 555-1234']);

        // Str::sanitizePhoneNumber strips formatting characters.
        $this->assertStringNotContainsString('(', $lead->phone);
        $this->assertStringNotContainsString(')', $lead->phone);
        $this->assertStringNotContainsString(' ', $lead->phone);
    }

    public function testCreatingAutoAssignsDefaultPipelineAndFirstStage(): void
    {
        // Fresh company so the only pipeline in scope is the one we create —
        // otherwise pre-seeded company pipelines could win the is_default sort.
        $company = Companies::factory()->create();

        $pipeline = Pipeline::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => 'Default Pipe',
            'is_default' => 1,
        ]);
        $firstStage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'New',
            'weight' => 1,
        ]);

        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $company->getId())
            ->create([
                'pipeline_id' => null,
                'pipeline_stage_id' => null,
            ]);

        $this->assertSame($pipeline->getId(), $lead->pipeline_id);
        $this->assertSame($firstStage->getId(), $lead->pipeline_stage_id);
    }

    public function testCreatedSpawnsDefaultSocialChannel(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create();

        // created() creates a DEFAULT channel slugged with the lead uuid, which
        // is exactly what systemNotes() resolves on.
        $channel = $lead->systemNotes()->first();
        $this->assertNotNull($channel, 'created() must spawn the default lead channel.');
        $this->assertSame(ChannelNameEnum::DEFAULT->value, $channel->name);
    }

    public function testCreatedSpawnsAiNotesChannelWhenCompanyOptsIn(): void
    {
        $this->company->set('enable_ai_notes_channel', true);

        try {
            // addCategory('ai-agent') on the notes channel checks user↔company
            // membership, so the lead owner must be the authed (associated) user.
            $lead = Lead::factory()
                ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
                ->withUserId($this->user->getId())
                ->create();

            $notesChannel = $lead->socialChannels()
                ->where('name', ChannelNameEnum::NOTES->value)
                ->first();
        } finally {
            $this->company->set('enable_ai_notes_channel', false);
        }

        $this->assertNotNull($notesChannel, 'AI-notes channel must exist when enable_ai_notes_channel is on.');
    }

    public function testUpdatingStageChangeResetsStateEmitsLedgerAndWritesThreadMessage(): void
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => 'Pipe',
            'is_default' => 0,
        ]);
        $stageA = PipelineStage::create(['pipelines_id' => $pipeline->getId(), 'name' => 'A', 'weight' => 1]);
        $stageB = PipelineStage::create(['pipelines_id' => $pipeline->getId(), 'name' => 'B', 'weight' => 2]);

        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
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

        $lead->pipeline_stage_id = $stageB->getId();
        $lead->save();

        $lead->refresh();

        // 1. Follow-up state reset.
        $this->assertSame(0, $lead->getFollowUpStateCount());
        $this->assertFalse($lead->isFollowUpExhausted());

        // 2. Ledger event emitted with from/to stage ids.
        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.stage.changed')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($stageA->getId(), $event->payload['from_stage_id'] ?? null);
        $this->assertSame($stageB->getId(), $event->payload['to_stage_id'] ?? null);

        // 3. System "Stage: A → B" message written to the lead's default thread
        //    and tagged stage-change (WriteLeadStageChangeThreadMessageAction).
        $channel = $lead->systemNotes()->first();
        $this->assertNotNull($channel, 'Default channel must exist for the stage-change message.');

        $messages = $channel->messages()->get();
        $stageMessage = $messages->first(
            fn ($m) => str_starts_with((string) ($m->message['content'] ?? ''), 'Stage: A → B')
        );
        $this->assertNotNull($stageMessage, 'A "Stage: A → B" system message must be written to the thread.');
        $this->assertTrue($stageMessage->hasTag(['stage-change']), 'Stage-change message must carry the stage-change tag.');
    }

    public function testSoftDeletedMarksRelatedSocialChannelsDeleted(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create();

        // created() spawned the default channel.
        $this->assertGreaterThan(0, $lead->socialChannels()->count());

        $lead->softDelete();

        $this->assertSame(0, $lead->socialChannels()->count(), 'Soft-deleting a lead must soft-delete its social channels.');
    }
}
