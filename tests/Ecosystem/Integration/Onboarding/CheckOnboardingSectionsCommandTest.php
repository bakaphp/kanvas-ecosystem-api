<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Tests\TestCase;

final class CheckOnboardingSectionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testFailsNamingMissingTemplates(): void
    {
        $missing = 'Nobody Template ' . uniqid();
        $this->section(0, 'chk_global_' . uniqid(), $missing);

        $this->artisan('kanvas:onboarding:check-sections')
            ->expectsOutputToContain($missing)
            ->assertExitCode(1);
    }

    public function testChecksTheGivenAppOnTopOfAppZero(): void
    {
        $app = app(Apps::class);
        $onlyInApp = 'App Template ' . uniqid();
        $this->section($app->getId(), 'chk_app_' . uniqid(), $onlyInApp);

        $this->artisan('kanvas:onboarding:check-sections', ['app' => $app->getId()])
            ->expectsOutputToContain($onlyInApp)
            ->assertExitCode(1);
    }

    private function section(int $appsId, string $slug, string $sourceAgent): OnboardingSection
    {
        return OnboardingSection::query()->create([
            'apps_id' => $appsId,
            'slug' => $slug,
            'label' => $slug,
            'title' => $slug,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => 99,
            'is_active' => true,
            'config' => [
                'agents' => [
                    ['slug' => 'nobody', 'name' => 'Nobody', 'description' => 'x', 'source_agent' => $sourceAgent, 'agent_type' => 'Any', 'requirements' => []],
                ],
                'modules' => [],
                'requirements' => [],
                'capabilities' => [],
                'setup_steps' => [],
            ],
        ]);
    }
}
