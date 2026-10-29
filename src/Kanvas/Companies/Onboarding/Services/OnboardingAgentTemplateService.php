<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\OnboardingSettingEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;

/**
 * A catalog agent is built from a template (a companies_id = 0 agent row cloned per company, the
 * app's own before the platform one at apps_id = 0) or, when no template row exists, from its agent
 * type. Types are keyed by handler class: the type *name* differs between environments.
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

    public function resolveType(?Apps $app, string $handler): ?AgentType
    {
        if ($handler === '') {
            return null;
        }

        return AgentType::query()
            ->where('handler', $handler)
            ->whereIn('apps_id', [0, $app?->getId() ?? 0])
            ->where('is_active', 1)
            ->notDeleted()
            ->orderByDesc('apps_id')
            ->first();
    }

    public function setupAgentName(?Apps $app): string
    {
        $configured = $app?->get(OnboardingSettingEnum::SETUP_AGENT->value);

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : OnboardingSettingEnum::DEFAULT_SETUP_AGENT;
    }

    /**
     * The implicit first item of every provisioning run, in the same shape as a catalog agent.
     *
     * @return array<string, mixed>
     */
    public function setupAgentItem(?Apps $app): array
    {
        $name = $this->setupAgentName($app);

        return [
            'slug' => 'ginny',
            'kind' => 'agent',
            'name' => $name,
            'source_agent' => $name,
            'agent_type' => OnboardingSettingEnum::DEFAULT_SETUP_AGENT_TYPE,
            'requirements' => [],
        ];
    }

    /**
     * @return list<string> every catalog agent, plus the setup agent, with neither a template row nor an agent type
     */
    public function missingFor(?Apps $app): array
    {
        $agents = [$this->setupAgentItem($app)];
        foreach (new OnboardingSectionsService()->forApp($app) as $section) {
            foreach ($section->config()->agents as $agent) {
                $agents[] = $agent;
            }
        }

        $missing = [];
        foreach ($agents as $agent) {
            $name = (string) ($agent['source_agent'] ?? '');
            if ($name === '' || in_array($name, $missing, true)) {
                continue;
            }
            if ($this->resolve($app, $name) === null && $this->resolveType($app, (string) ($agent['agent_type'] ?? '')) === null) {
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
