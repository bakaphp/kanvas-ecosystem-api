<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Baka\Support\Str;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Who builds, designs and learns for a factory project's apps, and what the Learn agent reads, kept on
 * the project (custom fields) so each project names its own agents instead of the orchestrator's prompt
 * carrying ids.
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
     * It also has to be able to take a task at all: `WakeAgentForTaskJob` drops one given to a machine
     * runtime or a CRM-type agent without a word, and the Learn would sit open for good.
     */
    public static function learnAgentFor(Project $project): ?Agent
    {
        $agent = self::LEARN_AGENT->activeAgentOf($project);

        return $agent !== null && ! CodingChatSettingEnum::isEnabledFor($agent) && $agent->canExecuteBoardWork()
            ? $agent
            : null;
    }

    /** A custom field is free text, so it is quoted into the task with its directives defused. */
    public static function learnSourceFor(Project $project, self $setting): ?string
    {
        $source = Str::trimToNull((string) $project->get($setting->value));

        return $source !== null ? TaskDirectives::neutralize($source) : null;
    }

    public static function templateFor(Project $project): string
    {
        return Str::trimToNull((string) $project->get(self::TEMPLATE_REPO->value)) ?? self::DEFAULT_TEMPLATE;
    }

    /** Returns only an active agent of the project's own tenant. */
    private function activeAgentOf(Project $project): ?Agent
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

        return $agent instanceof Agent ? $agent : null;
    }

    /** Only an agent of the project's own tenant that works tasks on the Mac. */
    private function factoryAgentOf(Project $project): ?Agent
    {
        $agent = $this->activeAgentOf($project);

        return $agent !== null && CodingChatSettingEnum::runsTaskInFactory($agent, 'Repo: app') ? $agent : null;
    }
}
