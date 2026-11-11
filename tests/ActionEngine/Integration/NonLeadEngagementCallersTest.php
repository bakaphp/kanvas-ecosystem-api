<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Workflow\PushLeadNotesActivity as EleadPushLeadNotesActivity;
use Kanvas\Connectors\Reynolds\Activities\PushLeadNotesActivity as ReynoldsPushLeadNotesActivity;
use Kanvas\Connectors\SalesAssist\Activities\PushLeadNotesActivity as SalesAssistPushLeadNotesActivity;
use Kanvas\Connectors\VinSolution\Workflow\PushLeadNotesActivity as VinSolutionPushLeadNotesActivity;
use Kanvas\Guild\Leads\Filters\EngagementLeadFilter;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every lead-only consumer of engagements must skip one whose reference is a quote, invoice, ...
 * The rows are inserted raw: these callers only read entity_type, so the alias needs no registration.
 */
final class NonLeadEngagementCallersTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Apps $currentApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    /**
     * @param class-string<KanvasActivity> $activityClass
     */
    #[DataProvider('leadNoteActivities')]
    public function testLeadNoteActivitiesSkipANonLeadEngagementMessage(string $activityClass): void
    {
        $message = $this->message();
        $this->insertEngagement(
            $message,
            slug: 'view-invoice',
            entityType: 'scribe_invoice',
            leadId: null
        );

        $result = new $activityClass(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )
            ->execute($message->fresh(), $this->currentApp, []);

        $this->assertStringContainsString('not a lead; skipping', $result['message'] ?? '');
        $this->assertArrayNotHasKey('error', $result);
    }

    public static function leadNoteActivities(): array
    {
        return [
            'reynolds' => [ReynoldsPushLeadNotesActivity::class],
            'elead' => [EleadPushLeadNotesActivity::class],
            'vinsolution' => [VinSolutionPushLeadNotesActivity::class],
            'salesassist (drivecentric, dealersocket)' => [SalesAssistPushLeadNotesActivity::class],
        ];
    }

    public function testEngagementLeadFilterIgnoresNonLeadEngagements(): void
    {
        $slug = 'test-filter-' . Str::lower(Str::random(8));
        $lead = Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->company->getId())
            ->create();

        $this->insertEngagement(
            $this->message(),
            slug: $slug,
            entityType: 'lead',
            leadId: $lead->getId()
        );
        $this->insertEngagement(
            $this->message(),
            slug: $slug,
            entityType: 'scribe_invoice',
            leadId: null
        );

        $result = new EngagementLeadFilter()->resolve(
            $this->currentApp,
            $this->company,
            $slug,
            'started'
        );

        $this->assertSame([$lead->getId()], $result['lead_ids']);
        $this->assertSame(1, $result['matching_engagements']);
    }

    private function message(): Message
    {
        return Message::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['message' => ['verb' => 'view-invoice', 'status' => 'sent']]);
    }

    private function insertEngagement(
        Message $message,
        string $slug,
        string $entityType,
        ?int $leadId
    ): void {
        DB::connection('action_engine')->table('engagements')->insert([
            'uuid' => Str::uuid()->toString(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->currentApp->getId(),
            'users_id' => auth()->user()->getId(),
            'companies_actions_id' => 0,
            'message_id' => $message->getId(),
            'leads_id' => $leadId,
            'people_id' => null,
            'entity_type' => $entityType,
            'entity_id' => $leadId ?? 1,
            'pipelines_stages_id' => 0,
            'entity_uuid' => Str::uuid()->toString(),
            'slug' => $slug,
            'created_at' => now(),
            'is_deleted' => 0,
        ]);
    }
}
