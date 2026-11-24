<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class OnboardingModelsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testCreatesOnboardingRowWithJsonDataAndUuid(): void
    {
        $row = $this->row(['areas_selected' => []]);

        $fresh = CompanyOnboarding::query()->findOrFail($row->getId());

        $this->assertNotEmpty($fresh->uuid);
        $this->assertSame(OnboardingStatusEnum::STARTED->value, $fresh->status);
        $this->assertSame([], $fresh->dataGet('areas_selected'));
        $this->assertSame('fallback', $fresh->dataGet('missing.key', 'fallback'));
    }

    public function testDataSetWritesDotPathUnderLock(): void
    {
        $row = $this->row(['profile' => ['offering' => 'cars']]);

        $row->dataSet('provisioning.sally.status', 'ready');

        $fresh = CompanyOnboarding::query()->findOrFail($row->getId());
        $this->assertSame('ready', $fresh->dataGet('provisioning.sally.status'));
        $this->assertSame('cars', $fresh->dataGet('profile.offering'));
    }

    public function testUniquePerAppAndCompany(): void
    {
        $this->row();

        $this->expectException(QueryException::class);
        $this->row();
    }

    public function testSectionConfigRejectsUnknownMode(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/mode/');

        $this->section('accounts_payable', [
            'agents' => [],
            'modules' => [],
            'requirements' => [
                ['slug' => 'accounting_software', 'label' => 'Accounting', 'required' => true, 'shared' => true,
                    'options' => [['label' => 'QuickBooks', 'integration_id' => 1, 'mode' => 'teleport']]],
            ],
            'capabilities' => [],
            'setup_steps' => [],
        ]);
    }

    public function testSectionConfigRejectsUndeclaredRequirementSlug(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/nothing_declared/');

        $this->section('sales', [
            'agents' => [
                ['slug' => 'sally', 'name' => 'Sally', 'description' => 'Sales', 'source_agent' => 'Sally',
                    'agent_type' => 'Sales Agent', 'requirements' => ['nothing_declared']],
            ],
            'modules' => [],
            'requirements' => [],
            'capabilities' => [],
            'setup_steps' => [],
        ]);
    }

    public function testFromAppOrGlobalReturnsGlobalAndAppRows(): void
    {
        $app = app(Apps::class);
        $slug = 'scope_' . uniqid();

        $global = $this->section($slug . '_global', $this->emptyConfig(), appsId: 0);
        $mine = $this->section($slug . '_mine', $this->emptyConfig());
        $this->section($slug . '_other', $this->emptyConfig(), appsId: 999999);

        $ids = OnboardingSection::query()
            ->fromAppOrGlobal($app)
            ->where('slug', 'like', $slug . '%')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$global->getId(), $mine->getId()], $ids);
    }

    private function row(array $data = []): CompanyOnboarding
    {
        $user = auth()->user();

        return CompanyOnboarding::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'status' => OnboardingStatusEnum::STARTED->value,
            'current_step' => 'register',
            'data' => $data,
            'started_at' => now(),
        ]);
    }

    private function section(string $slug, array $config, ?int $appsId = null): OnboardingSection
    {
        return OnboardingSection::query()->create([
            'apps_id' => $appsId ?? app(Apps::class)->getId(),
            'slug' => $slug,
            'label' => 'Label ' . $slug,
            'title' => 'Title ' . $slug,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => 1,
            'is_active' => true,
            'config' => $config,
        ]);
    }

    private function emptyConfig(): array
    {
        return ['agents' => [], 'modules' => [], 'requirements' => [], 'capabilities' => [], 'setup_steps' => []];
    }

    public function testSectionConfigRejectsAnItemSlugWithADot(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/slug/');

        $this->section('dotted_' . uniqid(), [
            'agents' => [],
            'modules' => [['slug' => 'bad.slug', 'name' => 'Bad', 'description' => 'x']],
            'requirements' => [],
            'capabilities' => [],
            'setup_steps' => [],
        ]);
    }

    public function testDataSetLeavesTheInstanceCleanSoALaterSaveDoesNotRewriteTheJson(): void
    {
        $row = $this->row(['profile' => ['offering' => 'cars']]);

        $row->dataSet('provisioning.sally.status', 'ready');

        $this->assertFalse($row->isDirty('data'));
        $this->assertSame('ready', $row->dataGet('provisioning.sally.status'));
    }
}
