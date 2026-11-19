<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Agent custom fields for a coding chat: the agent's chat becomes a remote terminal for the opencode
 * factory on its coding machine (`CODING_MACHINE_ID`), the same checkout, persona and rules `akira`
 * uses there. The factory owns all of that; Kanvas only relays messages and turns.
 */
enum CodingChatSettingEnum: string
{
    case MODE = 'CODING_CHAT_MODE';

    /** Where `scripts/serve.sh` lives on the machine, relative to the SSH user's home or absolute. */
    case FACTORY_DIR = 'CODING_CHAT_FACTORY_DIR';

    /** The checkout a message opens when the conversation has none yet; `/repo <App>` switches away. */
    case DEFAULT_REPO = 'CODING_CHAT_DEFAULT_REPO';

    /**
     * Workflow task runs at once on the agent's Mac. Each has its own worktree, but the simulator, the
     * emulator and Metro's port are one per machine, so one by default; raise it for code-only work.
     */
    case MAX_TASK_RUNS = 'CODING_CHAT_MAX_TASK_RUNS';

    /**
     * For an agent whose chat stays with its own model (an orchestrator): its tasks that declare a
     * `Repo:` run in the factory under its name. It keeps planning and assigning in Kanvas, where those
     * tools exist, and builds on the Mac, where `gh` and the checkouts are.
     */
    case FACTORY_TASKS = 'CODING_FACTORY_TASKS';

    public const string DEFAULT_FACTORY_DIR = 'Documents/Projects/opencode-factory';

    public static function isEnabledFor(Agent $agent): bool
    {
        return filter_var($agent->get(self::MODE->value), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether this task is worked in the factory: every task of a chat-mode agent, and the tasks of a
     * factory-tasks agent that name their checkout.
     */
    public static function runsTaskInFactory(Agent $agent, string $taskText): bool
    {
        if (self::isEnabledFor($agent)) {
            return true;
        }

        if (! filter_var($agent->get(self::FACTORY_TASKS->value), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Two different Repo lines still mean the factory: the worker blocks the task with the reason,
        // where a throw here would only fail the job that hands it over.
        try {
            return TaskDirectives::repo($taskText) !== null;
        } catch (ValidationException) {
            return true;
        }
    }

    /**
     * The company's agents that work on its factory machine. Read on every turn of an internal agent to
     * decide whether it gets `ask_repository`; the settings come from Redis, so a company's handful of
     * agents costs one query.
     *
     * @return Collection<int, Agent>
     */
    public static function factoryAgentsOf(AppInterface $app, CompanyInterface $company): Collection
    {
        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('is_active', 1)
            ->orderBy('id')
            ->get()
            ->filter(fn (Agent $agent): bool => self::isEnabledFor($agent))
            ->values();
    }

    /** The one whose default checkout this is, else the first: a question needs one reader, not a choice. */
    public static function factoryAgentFor(AppInterface $app, CompanyInterface $company, string $repo): ?Agent
    {
        $agents = self::factoryAgentsOf($app, $company);

        return $agents->first(fn (Agent $agent): bool => self::defaultRepoFor($agent) === $repo) ?? $agents->first();
    }

    /**
     * Why this agent cannot be handed this task, or null when it can. A task that declares a checkout is
     * worked in it, and the checkouts exist only on the factory machine: an agent that runs in Kanvas
     * would get the task, find nothing to read, and block it.
     */
    public static function repositoryRefusal(Agent $agent, string $taskText): ?string
    {
        try {
            $repo = TaskDirectives::repo($taskText);
        } catch (ValidationException) {
            return null;
        }

        if ($repo === null || self::runsTaskInFactory($agent, $taskText)) {
            return null;
        }

        $factory = self::factoryAgentsOf($agent->app, $agent->company)
            ->map(fn (Agent $candidate): string => $candidate->name . ' (agent_id ' . $candidate->getId() . ')')
            ->implode(', ');

        return sprintf(
            'It works in the repository "%s", which exists only on the factory machine, and %s runs in Kanvas '
                . 'where no repository can be read. %s An agent in Kanvas that only needs facts from the '
                . 'repository asks with ask_repository while working its own task.',
            $repo,
            $agent->name,
            $factory !== '' ? 'Give it to a factory agent: ' . $factory . '.' : 'This company has no factory agent to give it to.',
        );
    }

    public static function defaultRepoFor(Agent $agent): ?string
    {
        return Str::trimToNull((string) $agent->get(self::DEFAULT_REPO->value));
    }

    public static function maxTaskRunsFor(Agent $agent): int
    {
        return max(1, (int) $agent->get(self::MAX_TASK_RUNS->value));
    }

    public static function factoryDirFor(Agent $agent): string
    {
        return Str::trimToNull((string) $agent->get(self::FACTORY_DIR->value)) ?? self::DEFAULT_FACTORY_DIR;
    }

    /**
     * Who the factory agent is: this agent's own soul and instructions, never its type's. In chat mode
     * the agent IS the worker, so its own words describe the worker; a type's soul is the catalog's
     * orchestrator text ("you delegate coding work… and report back"), which once turned a worker into
     * something that described edits instead of making them (see `SessionContextBuilder::agentDocument`).
     * Null leaves the factory's own persona in place.
     */
    public static function personaFor(Agent $agent): ?string
    {
        return Str::trimToNull(implode("\n\n", array_filter([
            Str::trimToNull($agent->soul),
            Str::trimToNull($agent->instructions),
        ])));
    }
}
