<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingAgentTemplateService;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

final class OnboardingSectionsServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence'];

    public function testAppRowOverridesGlobalBySlug(): void
    {
        $app = app(Apps::class);
        $slug = 'ovr_' . uniqid();

        $this->section($slug, 'Global title', appsId: 0, order: 5);
        $this->section($slug, 'App title', order: 5);

        $sections = new OnboardingSectionsService()->forApp($app)->filter(
            fn (OnboardingSection $section): bool => $section->slug === $slug,
        );

        $this->assertCount(1, $sections);
        $this->assertSame('App title', $sections->first()->title);
    }

    public function testRequirementDefinitionsFirstDeclarationWins(): void
    {
        $app = app(Apps::class);
        $slug = 'req_' . uniqid();

        $this->section($slug . '_a', 'A', order: 1, config: $this->configWithRequirement($slug, 'First label'));
        $this->section($slug . '_b', 'B', order: 2, config: $this->configWithRequirement($slug, 'Second label'));

        $definitions = new OnboardingSectionsService()->requirementDefinitions($app);

        $this->assertSame('First label', $definitions[$slug]['label']);
    }

    public function testMissingTemplatesNamesEveryUnresolvedSourceAgent(): void
    {
        $app = app(Apps::class);
        $this->section('missing_' . uniqid(), 'Missing', config: [
            'agents' => [
                ['slug' => 'nobody', 'name' => 'Nobody', 'description' => 'x', 'source_agent' => 'Nobody Template ' . uniqid(), 'agent_type' => 'Sales Agent', 'requirements' => []],
            ],
            'modules' => [],
            'requirements' => [],
            'capabilities' => [],
            'setup_steps' => [],
        ]);

        $missing = new OnboardingAgentTemplateService()->missingFor($app);

        $this->assertContains('Ginny', $missing);
        $this->assertNotEmpty(array_filter($missing, fn (string $name): bool => str_starts_with($name, 'Nobody Template')));
    }

    public function testResolveFallsBackToAppZero(): void
    {
        $app = app(Apps::class);
        $name = 'Template ' . uniqid();
        $service = new OnboardingAgentTemplateService();

        $this->assertNull($service->resolve($app, $name));

        $global = Agent::factory()->withAppId(0)->withCompanyId(0)->create(['name' => $name]);
        $this->assertSame($global->getId(), $service->resolve($app, $name)?->getId());

        $mine = Agent::factory()->withAppId($app->getId())->withCompanyId(0)->create(['name' => $name]);
        $this->assertSame($mine->getId(), $service->resolve($app, $name)?->getId());
    }

    private function section(
        string $slug,
        string $title,
        ?int $appsId = null,
        int $order = 1,
        ?array $config = null,
    ): OnboardingSection {
        return OnboardingSection::query()->create([
            'apps_id' => $appsId ?? app(Apps::class)->getId(),
            'slug' => $slug,
            'label' => 'Label',
            'title' => $title,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => $order,
            'is_active' => true,
            'config' => $config ?? ['agents' => [], 'modules' => [], 'requirements' => [], 'capabilities' => [], 'setup_steps' => []],
        ]);
    }

    private function configWithRequirement(string $slug, string $label): array
    {
        return [
            'agents' => [],
            'modules' => [],
            'requirements' => [
                ['slug' => $slug, 'label' => $label, 'required' => true, 'shared' => true,
                    'options' => [['label' => 'Kanvas', 'mode' => 'kanvas_provided']]],
            ],
            'capabilities' => [],
            'setup_steps' => [],
        ];
    }
}
