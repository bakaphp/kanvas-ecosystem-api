<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\CreateInsurancePlanAction;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\Actions\UpdateInsurancePlanAction;
use Kanvas\Insurance\Actions\UpdateInsurerAction;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Models\InsuranceCoverage;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\InsurancePlanExclusion;
use Kanvas\Insurance\Models\InsurancePlanRequirement;
use Kanvas\Insurance\Models\Insurer;
use Tests\TestCase;

class InsuranceCatalogActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'insurance'];

    private function createInsurer(): Insurer
    {
        return new CreateInsurerAction(
            InsurerData::from(app(Apps::class), auth()->user()->getCurrentCompany(), ['name' => 'Acme Seguros ' . uniqid()])
        )->execute();
    }

    private function createPlan(Insurer $insurer): InsurancePlan
    {
        return new CreateInsurancePlanAction(
            InsurancePlanData::from($insurer, $this->planInput())
        )->execute();
    }

    private function planInput(): array
    {
        return [
            'name' => 'Full Plan',
            'type' => 'full',
            'base_price' => 1500.5,
            'validity_days' => 365,
            'deductible' => 10000,
            'sponsored' => true,
            'commission_rate' => 12.5,
            'recommended_score' => 80,
            'coverages' => [
                ['name' => 'Collision', 'limit_amount' => 500000],
                ['name' => 'Theft', 'description' => 'Total loss', 'sort_order' => 5],
            ],
            'exclusions' => [['description' => 'Racing']],
            'requirements' => [['name' => 'Inspection', 'condition' => 'Vehicle older than 5 years']],
        ];
    }

    public function testCreatesInsurerScopedToTenantWithSlug(): void
    {
        $insurer = $this->createInsurer();

        $this->assertSame(app(Apps::class)->getId(), $insurer->apps_id);
        $this->assertSame(auth()->user()->getCurrentCompany()->getId(), $insurer->companies_id);
        $this->assertSame(InsuranceCatalogStatusEnum::ACTIVE, $insurer->status);
        $this->assertStringStartsWith('acme-seguros-', $insurer->slug);
    }

    public function testDuplicateInsurerSlugIsRefused(): void
    {
        $insurer = $this->createInsurer();

        $this->expectException(ValidationException::class);

        new CreateInsurerAction(
            InsurerData::from(app(Apps::class), auth()->user()->getCurrentCompany(), ['name' => 'Other', 'slug' => $insurer->slug])
        )->execute();
    }

    public function testUpdatesInsurerKeepingSlugAndUnsentFields(): void
    {
        $insurer = $this->createInsurer();
        $slug = $insurer->slug;

        $updated = new UpdateInsurerAction(
            $insurer,
            InsurerData::forUpdate($insurer, ['name' => 'Renamed', 'status' => 'inactive']),
        )->execute();

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame($slug, $updated->slug);
        $this->assertSame(InsuranceCatalogStatusEnum::INACTIVE, $updated->fresh()->status);
    }

    public function testCreatesPlanWithNestedChildren(): void
    {
        $plan = $this->createPlan($this->createInsurer());

        $this->assertSame(InsuranceCoverageCategoryEnum::FULL, $plan->type);
        $this->assertTrue($plan->sponsored);
        $this->assertSame(80, $plan->recommended_score);
        $this->assertSame('full-plan', $plan->slug);
        $this->assertSame(['Collision', 'Theft'], $plan->coverages->pluck('name')->all());
        $this->assertSame(5, $plan->coverages->last()->sort_order);
        $this->assertCount(1, $plan->exclusions);
        $this->assertSame('Vehicle older than 5 years', $plan->requirements->first()->condition);
    }

    public function testUpdateReplacesChildrenOnlyWhenSent(): void
    {
        $plan = $this->createPlan($this->createInsurer());
        $oldCoverageId = $plan->coverages->first()->getId();

        $plan = new UpdateInsurancePlanAction(
            $plan,
            InsurancePlanData::forUpdate($plan, ['name' => 'Renamed', 'coverages' => [['name' => 'Fire']]]),
        )->execute();

        $this->assertSame('Renamed', $plan->name);
        $this->assertSame('full-plan', $plan->slug);
        $this->assertSame(['Fire'], $plan->coverages->pluck('name')->all());
        $this->assertCount(1, $plan->exclusions);
        $this->assertCount(1, $plan->requirements);
        $this->assertTrue((bool) InsuranceCoverage::query()->withoutGlobalScopes()->findOrFail($oldCoverageId)->is_deleted);
    }

    public function testUpdateCanClearChildrenWithEmptyArray(): void
    {
        $plan = $this->createPlan($this->createInsurer());

        $plan = new UpdateInsurancePlanAction(
            $plan,
            InsurancePlanData::forUpdate($plan, ['exclusions' => []]),
        )->execute();

        $this->assertCount(0, $plan->exclusions);
        $this->assertCount(2, $plan->coverages);
    }

    public function testDuplicatePlanSlugWithinInsurerIsRefused(): void
    {
        $insurer = $this->createInsurer();
        $this->createPlan($insurer);

        $this->expectException(ValidationException::class);

        $this->createPlan($insurer);
    }

    public function testDeletingPlanCascadesToItsChildren(): void
    {
        $plan = $this->createPlan($this->createInsurer());

        $plan->delete();

        $this->assertSame(0, InsuranceCoverage::query()->where('insurance_plans_id', $plan->getId())->count());
        $this->assertSame(0, InsurancePlanExclusion::query()->where('insurance_plans_id', $plan->getId())->count());
        $this->assertSame(0, InsurancePlanRequirement::query()->where('insurance_plans_id', $plan->getId())->count());
        $this->assertSame(
            2,
            InsuranceCoverage::query()->withoutGlobalScopes()->where('insurance_plans_id', $plan->getId())->where('is_deleted', 1)->count()
        );
    }

    public function testDeletingInsurerCascadesToPlansAndTheirChildren(): void
    {
        $insurer = $this->createInsurer();
        $plan = $this->createPlan($insurer);

        $insurer->delete();

        $this->assertSame(0, InsurancePlan::query()->where('insurers_id', $insurer->getId())->count());
        $this->assertSame(0, InsuranceCoverage::query()->where('insurance_plans_id', $plan->getId())->count());
        $this->assertSame(0, Insurer::query()->whereKey($insurer->getId())->count());
    }
}
