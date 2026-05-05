<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Tests\TestCase;

class PlanFilesystemTest extends TestCase
{
    public function testCreatePlanAttachesFilesFromUrl(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $files = [
            ['url' => fake()->imageUrl(), 'name' => 'mockup-1.png'],
            ['url' => fake()->imageUrl(), 'name' => 'mockup-2.png'],
        ];

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Plan with files',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
                files: $files,
            ),
        )->execute();

        $this->assertSame(2, $plan->files()->count());
    }

    public function testUpdatePlanAttachesAdditionalFiles(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Will get more files',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
                files: [['url' => fake()->imageUrl(), 'name' => 'first.png']],
            ),
        )->execute();

        $this->assertSame(1, $plan->files()->count());

        new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                files: [['url' => fake()->imageUrl(), 'name' => 'second.png']],
            ),
        )->execute();

        $plan->refresh();
        $this->assertGreaterThanOrEqual(2, $plan->files()->count());
    }

    public function testGraphTypeNameReturnsNervousSystemPlan(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Graph type check',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $this->assertSame('NervousSystemPlan', $plan->getGraphTypeName());
    }
}
