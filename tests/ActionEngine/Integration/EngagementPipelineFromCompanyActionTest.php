<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineAction;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\Pipeline as PipelineData;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\BuildsSalesAppFixtures;

/**
 * Serial: Setup writes the global `companies_id = 0` actions row every process shares.
 */
#[Group('serial')]
final class EngagementPipelineFromCompanyActionTest extends TestCase
{
    use BuildsSalesAppFixtures;
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

    public function testUsesTheCompanyActionPipelineWhenNoneMatchesTheActionSlug(): void
    {
        $companyAction = $this->makeSalesApp(
            $this->makeSalesAppAction('Video'),
            $this->company,
            isActive: true
        );

        // Slugged from the display name, as CreateCompanyActionAction does, so it differs from the action slug.
        $pipeline = new CreatePipelineAction(
            data: new PipelineData(name: 'Video Link ' . Str::lower(Str::random(8))),
            user: auth()->user(),
            app: $this->currentApp,
            companiesId: $this->company->getId(),
        )->execute();
        $companyAction->pipelines_id = $pipeline->getId();
        $companyAction->saveOrFail();

        $engagement = $this->createSentEngagement($companyAction);

        $this->assertSame($pipeline->getId(), (int) $engagement->stage->pipelines_id);
        $this->assertSame(ActionStatusEnum::SENT->value, $engagement->stage->slug);
        $this->assertNotEmpty($engagement->message->message['action_link']);
    }

    public function testStillFailsWhenTheCompanyActionHasNoPipelineEither(): void
    {
        $companyAction = $this->makeSalesApp(
            $this->makeSalesAppAction('Video'),
            $this->company,
            isActive: true
        );

        $this->expectException(ModelNotFoundException::class);

        $this->createSentEngagement($companyAction);
    }

    private function createSentEngagement(CompanyAction $companyAction): Engagement
    {
        return new CreateEngagementAction(
            new EngagementData(
                app: $this->currentApp,
                company: $this->company,
                user: auth()->user(),
                lead: $this->lead,
                action: $companyAction->action->slug,
                requestId: (string) Str::uuid(),
                source: 'video',
                status: ActionStatusEnum::SENT,
            )
        )->execute();
    }
}
