<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\ResolveEngagementStagePositionAction;
use Kanvas\ActionEngine\Engagements\Enums\EngagementStagePositionEnum;
use Kanvas\ActionEngine\Engagements\Jobs\NotifyEngagementPipelineStageJob;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class EngagementPipelineStageNotificationTest extends TestCase
{
    public function testResolvesFirstAndLastStageByWeightAndNullForMiddle(): void
    {
        Queue::fake();

        [$engagement, $first, $middle, $last] = $this->buildEngagementWithStages();

        $this->assertSame(
            EngagementStagePositionEnum::FIRST,
            new ResolveEngagementStagePositionAction($engagement)->execute()
        );

        $this->moveToStage($engagement, $middle);
        $this->assertNull(new ResolveEngagementStagePositionAction($engagement)->execute());

        $this->moveToStage($engagement, $last);
        $this->assertSame(
            EngagementStagePositionEnum::LAST,
            new ResolveEngagementStagePositionAction($engagement)->execute()
        );
    }

    public function testObserverDispatchesJobOnStageChange(): void
    {
        Queue::fake();

        [$engagement, , $middle] = $this->buildEngagementWithStages();

        Queue::assertPushed(NotifyEngagementPipelineStageJob::class);

        Queue::fake();
        $this->moveToStage($engagement, $middle);
        Queue::assertPushed(NotifyEngagementPipelineStageJob::class);
    }

    /**
     * @return array{0: Engagement, 1: PipelineStage, 2: PipelineStage, 3: PipelineStage}
     */
    private function buildEngagementWithStages(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $pipeline = new Pipeline();
        $pipeline->apps_id = $app->getId();
        $pipeline->companies_id = $company->getId();
        $pipeline->users_id = $user->getId();
        $pipeline->name = 'Test Pipeline';
        $pipeline->slug = 'test-pipeline-' . Str::random(6);
        $pipeline->saveOrFail();

        $first = $this->makeStage($pipeline->getId(), 'first', 1);
        $middle = $this->makeStage($pipeline->getId(), 'middle', 2);
        $last = $this->makeStage($pipeline->getId(), 'last', 3);

        $engagement = new Engagement();
        $engagement->apps_id = $app->getId();
        $engagement->companies_id = $company->getId();
        $engagement->users_id = $user->getId();
        $engagement->companies_actions_id = 0;
        $engagement->message_id = 0;
        $engagement->leads_id = 0;
        $engagement->pipelines_stages_id = $first->getId();
        $engagement->entity_uuid = (string) Str::uuid();
        $engagement->slug = 'test-engagement-' . Str::random(6);
        $engagement->saveOrFail();

        return [$engagement, $first, $middle, $last];
    }

    private function moveToStage(Engagement $engagement, PipelineStage $stage): void
    {
        $engagement->pipelines_stages_id = $stage->getId();
        $engagement->saveOrFail();
        $engagement->refresh();
    }

    private function makeStage(int $pipelineId, string $slug, float $weight): PipelineStage
    {
        $stage = new PipelineStage();
        $stage->pipelines_id = $pipelineId;
        $stage->name = ucfirst($slug) . ' Stage';
        $stage->slug = $slug . '-' . Str::random(6);
        $stage->weight = $weight;
        $stage->saveOrFail();

        return $stage;
    }
}
