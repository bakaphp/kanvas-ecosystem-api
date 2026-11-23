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

    /** Learn & improve: the agent that reads the app's product data and errors and names what to improve. */
    case LEARN_AGENT = 'factory_learn_agent_id';

    /** Optional: the Amplitude project the Learn agent reads, written into its task. */
    case LEARN_AMPLITUDE_PROJECT = 'factory_learn_amplitude_project';

    /** Optional: the Sentry `organization/project` the Learn agent reads, written into its task. */
    case LEARN_SENTRY_PROJECT = 'factory_learn_sentry_project';

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

    /**
     * The Learn agent reads Amplitude and Sentry through its own tools in Kanvas, so it is an agent of the
     * project's tenant that does NOT work on the Mac: a factory agent has no data tools, only a checkout.
     */
    public static function learnAgentFor(Project $project): ?Agent
    {
        $id = (int) $project->get(self::LEARN_AGENT->value);

        if ($id <= 0) {
            return null;
        }

        $agent = Agent::query()
            ->where('id', $id)
            ->fromApp($project->app)
            ->fromCompany($project->company)
            ->notDeleted()
            ->where('is_active', 1)
            ->first();

        return $agent instanceof Agent && ! CodingChatSettingEnum::isEnabledFor($agent) ? $agent : null;
    }

    public static function learnSourceFor(Project $project, self $setting): ?string
    {
        return Str::trimToNull((string) $project->get($setting->value));
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
