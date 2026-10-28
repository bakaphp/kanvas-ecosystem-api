<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Actions\SelectOnboardingAreasAction;
use Kanvas\Companies\Onboarding\Actions\SkipOnboardingAction;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingPromptBlockService;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Tests\TestCase;

final class OnboardingPromptBlockTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence'];

    public function testBrainGetsOnboardingBlockWhileOpenAndNotAfter(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $slug = 'p' . substr(uniqid(), -6) . '_sales';

        OnboardingSection::query()->create([
            'apps_id' => $app->getId(),
            'slug' => $slug,
            'label' => 'Sales',
            'title' => 'Respond to my leads instantly',
            'description' => 'Reply to every conversation.',
            'is_available' => true,
            'display_order' => 1,
            'is_active' => true,
            'config' => ['agents' => [], 'modules' => [], 'requirements' => [], 'capabilities' => [], 'setup_steps' => ['Go live']],
        ]);

        $row = new OnboardingStateService()->forCompany($app, $company, auth()->user());
        new SelectOnboardingAreasAction($row, auth()->user(), [$slug], $slug)->execute();

        $block = OnboardingPromptBlockService::for($app, $company);
        $this->assertStringContainsString('## Onboarding', $block);
        $this->assertStringContainsString('Respond to my leads instantly', $block);
        $this->assertStringContainsString('only when the person confirms', $block);

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create(['name' => 'Ginny']);
        $handler = new CompanyBrainAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());
        $this->assertStringContainsString('## Onboarding', $handler->instructions());

        new SkipOnboardingAction($row->refresh(), auth()->user())->execute();
        OnboardingPromptBlockService::forget($app, $company);

        $this->assertSame('', OnboardingPromptBlockService::for($app, $company));
    }
}
