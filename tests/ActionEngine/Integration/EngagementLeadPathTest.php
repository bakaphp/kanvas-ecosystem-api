<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The lead path is the contract every existing caller depends on: these assertions were written and
 * run green against CreateEngagementAction before the polymorphic-reference refactor, and only the
 * dual-written entity_type / entity_id were added after it.
 *
 * Serial: Setup writes the global `companies_id = 0` actions row every process shares.
 */
#[Group('serial')]
final class EngagementLeadPathTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Apps $currentApp;
    private Companies $company;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        Queue::fake();

        $this->lead = Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->company->getId())
            ->create();
        $this->lead->companies_branches_id = $this->company->defaultBranch()->firstOrFail()->getId();
        $this->lead->saveOrFail();

        new Setup($this->currentApp, auth()->user(), $this->company)->run();
    }

    public function testLeadEngagementWritesTheSameRowsChannelAndMessageAsBefore(): void
    {
        $requestId = Str::uuid()->toString();

        $engagement = new CreateEngagementAction(
            EngagementData::from(
                $this->currentApp,
                $this->company,
                auth()->user(),
                $this->lead,
                [
                    'action' => ActionEnum::SYNC->value,
                    'request_id' => $requestId,
                    'source' => 'test',
                    'status' => ActionStatusEnum::SENT->value,
                    'data' => ['foo' => 'bar'],
                ]
            )
        )->execute();

        $this->assertSame($this->lead->getId(), (int) $engagement->leads_id);
        $this->assertSame(EngagementReferenceMap::LEAD, $engagement->entity_type);
        $this->assertSame($this->lead->getId(), (int) $engagement->entity_id);
        $this->assertSame($this->lead->people->getId(), (int) $engagement->people_id);
        $this->assertSame($requestId, $engagement->entity_uuid);
        $this->assertSame(ActionEnum::SYNC->value, $engagement->slug);
        $this->assertSame(ActionStatusEnum::SENT->value, $engagement->stage->slug);
        $this->assertSame((int) auth()->user()->getId(), (int) $engagement->users_id);

        $message = $engagement->message;
        $this->assertSame(Lead::class, $message->appModuleMessage->system_modules);
        $this->assertSame($this->lead->getId(), (int) $message->appModuleMessage->entity_id);
        $payload = $message->getMessage();
        $this->assertSame(ActionStatusEnum::SENT->value, $payload['status']);
        $this->assertSame($this->lead->people->uuid, $payload['contact_uuid'] ?? $payload['contactUuid'] ?? null);
        $this->assertNotEmpty($payload['action_link']);
        $this->assertStringContainsString('lead_id=' . $this->lead->uuid, $payload['link_full'] ?? $this->linkFullFromVisitor($requestId));

        $channel = Channel::query()
            ->where('slug', (string) $this->lead->uuid)
            ->where('apps_id', $this->currentApp->getId())
            ->firstOrFail();
        $this->assertSame(Lead::class, $channel->entity_namespace);
        $this->assertSame((string) $this->lead->getId(), (string) $channel->entity_id);
        $this->assertTrue($channel->messages()->where('messages.id', $message->getId())->exists());

        $visitor = CompanyActionVisitor::query()->where('visitors_id', $requestId)->firstOrFail();
        $this->assertSame((string) $this->lead->uuid, $visitor->leads_id);
        $this->assertSame($this->lead->people->uuid, $visitor->contacts_id);
        $this->assertSame((int) $engagement->companies_actions_id, (int) $visitor->companies_actions_id);

        $this->assertTrue(auth()->user()->isFollowing($this->lead));
    }

    private function linkFullFromVisitor(string $requestId): string
    {
        $visitor = CompanyActionVisitor::query()->where('visitors_id', $requestId)->firstOrFail();

        return http_build_query($visitor->request);
    }
}
