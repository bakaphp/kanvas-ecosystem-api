<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Who builds and who designs a factory project's apps, kept on the project (custom fields) so each
 * project names its own agents instead of the orchestrator's prompt carrying ids.
 */
enum FactoryProjectSettingEnum: string
{
    case BUILDER_AGENT = 'factory_builder_agent_id';
    case DESIGNER_AGENT = 'factory_designer_agent_id';
    case TEMPLATE_REPO = 'factory_template_repo';

    public const string DEFAULT_TEMPLATE = 'kanvas-mobile-skeleton';

    public static function builderFor(Project $project): ?Agent
    {
        return self::BUILDER_AGENT->factoryAgentOf($project);
    }

    /** Optional: without a designer the builder designs the ticket too. */
    public static function designerFor(Project $project): ?Agent
    {
        return self::DESIGNER_AGENT->factoryAgentOf($project);
    }

    public static function templateFor(Project $project): string
    {
        return Str::trimToNull((string) $project->get(self::TEMPLATE_REPO->value)) ?? self::DEFAULT_TEMPLATE;
    }

    /** Only an agent of the project's own tenant that works tasks on the Mac. */
    private function factoryAgentOf(Project $project): ?Agent
    {
        $id = (int) $project->get($this->value);

        if ($id <= 0) {
            return null;
        }

        // Models, not ids: given an id, fromCompany() scopes to the logged-in user's company, and a queue
        // worker running the orchestrator's turn has none.
        $agent = Agent::query()
            ->where('id', $id)
            ->fromApp($project->app)
            ->fromCompany($project->company)
            ->notDeleted()
            ->where('is_active', 1)
            ->first();

        return $agent instanceof Agent && CodingChatSettingEnum::runsTaskInFactory($agent, 'Repo: app')
            ? $agent
            : null;
    }
}
