<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

class PlanTagsTest extends TestCase
{
    public function testCreatePlanRegistersSystemModule(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'System module registration',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $this->assertTrue(
            SystemModules::query()
                ->where('apps_id', $app->getId())
                ->where('model_name', Plan::class)
                ->exists(),
        );
    }

    public function testAddTagAttachesTagAndIsRetrievable(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Plan with tags',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $plan->addTag('urgent', $app, $user, $company);
        $plan->addTag('design-review', $app, $user, $company);

        $this->assertTrue($plan->hasTags());
        $names = $plan->tags()->pluck('name')->all();
        $this->assertContains('urgent', $names);
        $this->assertContains('design-review', $names);
    }

    public function testAddTagsBulkAttachesAll(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Bulk tags',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $plan->addTags(['marketing', 'q2-launch', 'high-priority'], $app, $user, $company);

        $this->assertSame(3, $plan->tags()->count());
    }
}
