<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesAgentMachine;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\SessionConfigBuilder;
use Kanvas\Connectors\OpenCode\Services\SessionContextBuilder;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Gives a session somewhere to run and an address to be reached at.
 *
 * Two modes, decided by configuration rather than by code:
 *
 *  - **attach** — `opencode_static_endpoint` points at a server that is already up. Nothing is created
 *    and nothing is destroyed. Local development, and the escape hatch when no machine is available.
 *  - **launch** — a machine is configured: prepare a worktree on the host, start a container there, and
 *    address it however that machine says it is reachable.
 *
 * The endpoint it computes is stored on the session. Everything afterwards reads that column instead of
 * re-deriving it, so a session keeps talking to the container it actually started.
 */
class ProvisionCodingSessionAction
{
    use ResolvesAgentMachine;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly ?CodingRepository $repository = null,
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $staticEndpoint = Str::trimToNull((string) $this->app->get(ConfigurationEnum::STATIC_ENDPOINT->value));

        if ($staticEndpoint !== null) {
            return $this->attachTo($staticEndpoint);
        }

        return $this->launchContainer();
    }

    private function attachTo(string $endpoint): AgentTaskSession
    {
        $this->session->endpoint = rtrim($endpoint, '/');
        $this->session->server_password = (string) $this->app->get(ConfigurationEnum::STATIC_PASSWORD->value);
        $this->session->status = HarnessStatusEnum::STARTING->value;
        $this->session->started_at ??= Carbon::now();
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        return $this->session;
    }

    /**
     * Uses the agent's own container and gives this task its own directory inside it.
     *
     * The container is per AGENT, not per task: starting one costs seconds a task should not pay, and a
     * box hosts many agents by port rather than many tasks by container. Isolation between tasks comes
     * from the worktree the session is pointed at.
     */
    private function launchContainer(): AgentTaskSession
    {
        $agent = $this->session->agent;

        if ($agent === null) {
            throw new ValidationException('This session has no agent, so there is no container to use.');
        }

        $machine = $this->resolveMachine();

        // Recorded BEFORE the container exists, not after provisioning succeeds. The reaper finds work
        // by looking at the machines sessions point at, so a crash between `docker run` and the save
        // below would otherwise strand a container on a machine nothing knows to sweep.
        $this->session->agent_machine_id = $machine->getId();
        $this->session->saveOrFail();

        $runtime = new EnsureAgentCodingContainerAction($agent, $machine, $this->app)->execute();

        $client = SshClient::fromMachine($machine);

        try {
            $workspace = $this->prepareWorkspace($client, $runtime['worktreeRoot']);
            $this->writeRuntimeConfig($client, $workspace);
        } finally {
            $client->disconnect();
        }

        $this->session->container_name = $runtime['container'];
        $this->session->server_password = $runtime['password'];
        $this->session->port = $runtime['port'];
        $this->session->endpoint = $runtime['endpoint'];
        // The path the CONTAINER sees. The host path and the mounted path differ, and the session is
        // created against the container's view.
        $this->session->session_data_path = '/workspaces/' . basename($workspace);
        $this->session->status = HarnessStatusEnum::STARTING->value;
        $this->session->started_at ??= Carbon::now();
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        return $this->session;
    }

    /**
     * A repository is optional: without one the session gets a bare directory, which is enough for a
     * task that only has to produce files. With one it gets a real checkout on its own branch.
     */
    private function prepareWorkspace(SshClient $client, string $root): string
    {
        if ($this->repository === null) {
            $workspace = $root . '/' . $this->session->uuid;
            $client->exec('mkdir -p ' . escapeshellarg($workspace), 60);
            // Not optional: opencode resolves a project config only inside a git repository. Without
            // this the config is present, visible on /config, and every turn fails with
            // "Model unavailable" — which points nowhere near the actual cause.
            $client->exec('git init -q ' . escapeshellarg($workspace) . ' 2>&1 || true', 60);

            $this->session->workspace_path = $workspace;
            $this->session->saveOrFail();

            return $workspace;
        }

        new PrepareSessionWorktreeAction(
            session: $this->session,
            client: $client,
            cloneUrl: $this->repository->cloneUrl,
            repoSlug: $this->repository->slug,
            baseBranch: $this->repository->baseBranch,
            branchPrefix: $this->repository->branchPrefix,
            root: rtrim((string) ($this->app->get(ConfigurationEnum::WORKSPACE_ROOT->value) ?? '/srv/kanvas'), '/'),
            worktreeRoot: $root,
        )->execute();

        return (string) $this->session->workspace_path;
    }

    /**
     * The config has to sit in the workspace: opencode only resolves a custom provider from a
     * project-level file, not from an environment variable or the global config.
     *
     * A file in the worktree would land in the agent's diff, so it goes into git's own local exclude
     * list — untracked and invisible to `git status`, without touching the repository's `.gitignore`.
     */
    private function writeRuntimeConfig(SshClient $client, string $workspace): void
    {
        $config = new SessionConfigBuilder($this->app, $this->repository, agent: $this->session->agent)->toJson();

        $client->writeFile($workspace . '/opencode.json', $config);

        $this->writeKanvasContext($client, $workspace);

        if ($this->repository === null) {
            return;
        }

        $this->excludeFromGit($client, $workspace);
    }

    /**
     * Keeps Kanvas's own files out of the agent's diff.
     *
     * `--absolute-git-dir`, not `--git-dir`: the latter answers `.git` relative to the repository, and
     * the command that consumes it runs in the SSH session's home directory, so the exclusions were
     * written somewhere harmless and the config and context files showed up in the diff — and would
     * have been pushed to the customer's repository.
     */
    private function excludeFromGit(SshClient $client, string $workspace): void
    {
        $gitDir = trim($client->exec(
            'git -C ' . escapeshellarg($workspace) . ' rev-parse --absolute-git-dir 2>/dev/null || true',
            30
        ));

        if ($gitDir === '' || ! str_starts_with($gitDir, '/')) {
            return;
        }

        $exclude = $gitDir . '/info/exclude';

        $client->exec('mkdir -p ' . escapeshellarg($gitDir . '/info'), 30);
        $client->exec(
            'grep -qxF ' . escapeshellarg('opencode.json') . ' ' . escapeshellarg($exclude) . ' 2>/dev/null'
            . ' || printf ' . escapeshellarg('opencode.json\n.kanvas/\n') . ' >> ' . escapeshellarg($exclude),
            30
        );
    }

    /**
     * Identity and prior learning, as files rather than prompt text: a file survives context
     * compaction and can be re-read by the agent, a system prompt cannot.
     *
     * Under `.kanvas/` on purpose — `AGENTS.md` belongs to the repository, and writing there would both
     * clobber its rules and appear in the diff.
     */
    private function writeKanvasContext(SshClient $client, string $workspace): void
    {
        $agent = $this->session->agent;

        if ($agent === null) {
            return;
        }

        $builder = new SessionContextBuilder($agent, $this->repository);

        $client->exec('mkdir -p ' . escapeshellarg($workspace . '/.kanvas'), 30);
        $client->writeFile($workspace . '/.kanvas/agent.md', $builder->agentDocument());

        $context = $builder->contextDocument();

        if ($context !== null) {
            $client->writeFile($workspace . '/.kanvas/context.md', $context);
        }
    }

    private function resolveMachine(): AgentMachine
    {
        $machine = $this->configuredMachine($this->session->agent) ?? AgentMachine::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('is_active', 1)
            ->first();

        if ($machine === null) {
            throw new ValidationException(
                'No active agent machine for this company, and no '
                . ConfigurationEnum::STATIC_ENDPOINT->value . ' configured to attach to.'
            );
        }

        return $machine;
    }
}
