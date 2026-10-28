<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\OnboardingSettingEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * A template is a companies_id = 0 agent that provisioning clones per company. The app's own
 * template wins; the platform template at apps_id = 0 is what a brand-new app has.
 */
final class OnboardingAgentTemplateService
{
    public function resolve(?Apps $app, string $name): ?Agent
    {
        if ($app !== null) {
            $own = $this->template((int) $app->getId(), $name);
            if ($own !== null) {
                return $own;
            }
        }

        return $this->template(0, $name);
    }

    public function setupAgentName(?Apps $app): string
    {
        $configured = $app?->get(OnboardingSettingEnum::SETUP_AGENT->value);

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : OnboardingSettingEnum::DEFAULT_SETUP_AGENT;
    }

    /**
     * @return list<string> every source_agent across the app's active sections, plus the setup agent, that resolves to nothing
     */
    public function missingFor(?Apps $app): array
    {
        $names = [$this->setupAgentName($app)];
        foreach (new OnboardingSectionsService()->forApp($app) as $section) {
            foreach ($section->config()->agents as $agent) {
                $names[] = (string) ($agent['source_agent'] ?? '');
            }
        }

        $missing = [];
        foreach (array_unique(array_filter($names)) as $name) {
            if ($this->resolve($app, $name) === null) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private function template(int $appsId, string $name): ?Agent
    {
        return Agent::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', 0)
            ->where('name', $name)
            ->notDeleted()
            ->orderBy('id')
            ->first();
    }
}
