<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * One long-lived container per agent, reused by every task that agent runs.
 *
 * Not one per task. A box hosts many agents by giving each its own container and port, and a task is
 * isolated by its **directory** rather than by its container: opencode takes a `location.directory`
 * per session, so two tasks on the same agent work in separate worktrees inside one runtime. That
 * keeps the agent's identity, its git setup and its warm runtime in one place, and removes a container
 * start from the front of every job.
 *
 * Idempotent: the container name is derived from the agent, so this either finds it running or starts
 * it, and calling it before every dispatch is the normal path.
 */
class EnsureAgentCodingContainerAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AgentMachine $machine,
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array{container: string, endpoint: string, port: int|null, password: string, worktreeRoot: string}
     */
    /**
     * Serialised per agent, because this is exactly what two tasks starting together do.
     *
     * Both find no container, both remove the name, and both `docker run` — the loser gets "container
     * name is already in use" against a container the winner created a fraction of a second earlier,
     * and its task fails for a reason that has nothing to do with the task. Whoever waits here finds a
     * running container and reuses it, which is the intended path anyway.
     *
     * @return array{container: string, endpoint: string, port: int|null, password: string, worktreeRoot: string}
     */
    public function execute(): array
    {
        return Cache::lock('coding-container-agent-' . $this->agent->getId(), 300)
            ->block(120, fn (): array => $this->ensure());
    }

    /**
     * One container per agent, named after it — which is what lets a second task find the running one
     * instead of starting a rival. Anything that needs to reach an agent's container without a session
     * row to read `container_name` off asks here, so the rule has one definition.
     */
    public static function containerNameFor(Agent $agent): string
    {
        return 'kanvas-coding-agent-' . $agent->getId();
    }

    /**
     * @return array{container: string, endpoint: string, port: int|null, password: string, worktreeRoot: string}
     */
    private function ensure(): array
    {
        $container = self::containerNameFor($this->agent);
        $root = rtrim((string) ($this->app->get(ConfigurationEnum::WORKSPACE_ROOT->value) ?? '/srv/kanvas'), '/');
        $agentDir = $root . '/agents/' . $this->agent->getId();
        $worktreeRoot = $agentDir . '/worktrees';
        $client = SshClient::fromMachine($this->machine);

        try {
            if ($this->isRunningWithCurrentKey($client, $container)) {
                return [
                    'container' => $container,
                    'endpoint' => $this->endpoint($container),
                    'port' => $this->storedPort(),
                    'password' => $this->storedPassword(),
                    'worktreeRoot' => $worktreeRoot,
                ];
            }

            $password = bin2hex(random_bytes(16));
            $port = $this->networkMode()->needsPublishedPort() ? $this->allocatePort($client) : null;

            $this->prepareWorktreeRoot($client, $worktreeRoot);
            $client->exec('mkdir -p ' . escapeshellarg($worktreeRoot . '/.home'), 30);
            $this->installGitCredential($client, $root);

            $this->removeStaleContainer($client, $container);

            $result = $client->exec(
                $this->runCommand(
                    $container,
                    $worktreeRoot,
                    $password,
                    $port,
                    $this->hostIdentity($client)
                ) . ' 2>&1; echo "EXIT_CODE:$?"',
                300
            );

            if (! str_contains($result, 'EXIT_CODE:0')) {
                throw new ValidationException('Could not start the coding container: ' . trim($result));
            }

            $this->agent->set(AgentCustomFieldEnum::CONTAINER_PORT->value, (string) ($port ?? ''));
            $this->agent->set(AgentCustomFieldEnum::CONTAINER_PASSWORD->value, $password);

            return [
                'container' => $container,
                'endpoint' => $this->endpoint($container, $port),
                'port' => $port,
                'password' => $password,
                'worktreeRoot' => $worktreeRoot,
            ];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Everything under the workspace root belongs to the SSH user, and the container runs as that same
     * uid — see `hostIdentity()`.
     *
     * Creating the root itself may still need root, because a path like /srv is not usually writable.
     * That one step escalates only if the plain form fails; a customer's box is not ours to assume has
     * passwordless sudo, so when neither works this throws with the exact command their admin has to
     * run. The alternative is a container that starts happily and cannot write a single file, which
     * surfaces much later as an inexplicably empty diff.
     */
    private function prepareWorktreeRoot(SshClient $client, string $worktreeRoot): void
    {
        $path = escapeshellarg($worktreeRoot);
        $owner = escapeshellarg((string) $this->machine->ssh_user);

        $client->exec('mkdir -p ' . $path . ' 2>/dev/null || sudo -n mkdir -p ' . $path . ' 2>&1 || true', 60);
        $client->exec('chown -R ' . $owner . ' ' . $path . ' 2>/dev/null || sudo -n chown -R ' . $owner . ' ' . $path . ' 2>&1 || true', 120);

        $probe = trim($client->exec('test -w ' . $path . ' && echo WRITABLE || echo NO', 30));

        if ($probe !== 'WRITABLE') {
            throw new ValidationException(
                'The coding workspace on ' . $this->machine->name . ' is not usable: ' . $worktreeRoot
                . ' must exist and be writable by ' . $this->machine->ssh_user . '. Run on that host: '
                . 'sudo mkdir -p ' . $worktreeRoot . ' && sudo chown -R ' . $this->machine->ssh_user . ' ' . $worktreeRoot
            );
        }
    }

    /**
     * The uid the container runs as, which is deliberately the SSH user's rather than the image's own.
     *
     * Git runs on the host as the SSH user and the agent edits the same files from inside the
     * container, so a mismatch means one of them cannot write — and on a box where Kanvas is not root
     * there is no `chown` available to paper over it. Matching the uid removes the problem instead of
     * escalating around it, which is what makes a customer-hosted machine workable.
     *
     * HOME has to move with it: the image's own home belongs to `kanvasrun`, so opencode's store would
     * be unwritable. Pointing it inside the mount also means that store survives the container.
     */
    private function hostIdentity(SshClient $client): string
    {
        return trim($client->exec('echo "$(id -u):$(id -g)"', 30));
    }

    /**
     * A container that died leaves its name behind and blocks the next start.
     *
     * `docker rm -f` returns before the daemon has finished releasing the name, so removing and then
     * immediately running fails with "container name is already in use" — a conflict against a
     * container that no longer appears in `docker ps -a` by the time anyone looks. Wait for the name
     * to actually come free rather than racing it.
     */
    private function removeStaleContainer(SshClient $client, string $container): void
    {
        $filter = escapeshellarg('name=^' . $container . '$');

        $client->exec('docker rm -f ' . escapeshellarg($container) . ' 2>&1 || true', 120);

        for ($attempt = 0; $attempt < 15; $attempt++) {
            if (trim($client->exec('docker ps -aq --filter ' . $filter, 30)) === '') {
                return;
            }

            sleep(1);
        }

        throw new ValidationException(
            'The previous container named ' . $container . ' on ' . $this->machine->name
            . ' could not be removed, so a new one cannot take its name.'
        );
    }

    /**
     * Reuse the running container only if it holds the key the agent is configured with now.
     *
     * The key is passed at `docker run`, so a container started with an older one keeps using it for as
     * long as it lives — rotating a revoked key, or pointing an agent at a different key, would appear
     * to do nothing. Comparing a fingerprint carried as a label makes the change take effect on the
     * next task instead of whenever the container happens to be replaced.
     */
    private function isRunningWithCurrentKey(SshClient $client, string $container): bool
    {
        $output = trim($client->exec(
            'docker ps --filter ' . escapeshellarg('name=^' . $container . '$')
            . ' --format ' . escapeshellarg('{{.Names}}|{{.Label "kanvas.keyfp"}}'),
            60
        ));

        if (! str_starts_with($output, $container . '|')) {
            return false;
        }

        return trim(mb_substr($output, mb_strlen($container) + 1)) === $this->keyFingerprint();
    }

    /**
     * Identifies the key without storing or logging it — a label is readable by anyone who can run
     * `docker inspect` on that host.
     */
    private function keyFingerprint(): string
    {
        return mb_substr(hash('sha256', $this->resolveApiKey()), 0, 16);
    }

    /**
     * The agent's git identity, installed on the machine when its container comes up.
     *
     * Deliberately OUTSIDE the worktree root: that root is mounted into the container, and a credential
     * inside it would be readable by whatever repository the agent is working on.
     */
    private function installGitCredential(SshClient $client, string $root): void
    {
        $token = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($token === null) {
            return;
        }

        $path = $root . '/agents/' . $this->agent->getId() . '/git-credentials';

        $client->exec('mkdir -p ' . escapeshellarg(dirname($path)), 30);
        $client->writeFile($path, 'https://x-access-token:' . $token . '@github.com' . "\n");
        $client->exec('chmod 600 ' . escapeshellarg($path), 30);
    }

    private function runCommand(
        string $container,
        string $worktreeRoot,
        string $password,
        ?int $port,
        string $hostIdentity
    ): string {
        $apiKeyEnv = (string) ($this->app->get(ConfigurationEnum::PROVIDER_ENV_VAR->value) ?? 'OPENAI_API_KEY');
        $apiKey = $this->resolveApiKey();
        $image = Str::trimToNull((string) $this->app->get(ConfigurationEnum::IMAGE->value));

        if ($image === null) {
            throw new ValidationException('No opencode image configured (' . ConfigurationEnum::IMAGE->value . ')');
        }

        $parts = [
            'docker run -d --restart unless-stopped',
            '--name ' . escapeshellarg($container),
            '--label ' . escapeshellarg('kanvas.agent=' . $this->agent->getId()),
            '--label ' . escapeshellarg('kanvas.company=' . $this->agent->companies_id),
            '--label ' . escapeshellarg('kanvas.keyfp=' . $this->keyFingerprint()),
            '--cpus ' . escapeshellarg($this->setting(ConfigurationEnum::CONTAINER_CPUS, '2')),
            '--memory ' . escapeshellarg($this->setting(ConfigurationEnum::CONTAINER_MEMORY, '2g')),
            '--pids-limit 512',
            '--user ' . escapeshellarg($hostIdentity),
            '-e ' . escapeshellarg('OPENCODE_SERVER_PASSWORD=' . $password),
            '-e ' . escapeshellarg($apiKeyEnv . '=' . $apiKey),
            '-e ' . escapeshellarg('HOME=/workspaces/.home'),
            // The whole worktree root, so each task gets a directory inside one runtime.
            '-v ' . escapeshellarg($worktreeRoot . ':/workspaces'),
        ];

        if ($this->networkMode() === MachineNetworkModeEnum::SHARED_NETWORK && $this->machine->docker_network !== null) {
            $parts[] = '--network ' . escapeshellarg((string) $this->machine->docker_network);
        }

        if ($port !== null) {
            $parts[] = '-p ' . escapeshellarg($this->privateHost() . ':' . $port . ':4096');
        }

        $parts[] = escapeshellarg($image);

        return implode(' ', $parts);
    }

    private function endpoint(string $container, ?int $port = null): string
    {
        $port ??= $this->storedPort();

        return match ($this->networkMode()) {
            MachineNetworkModeEnum::SHARED_NETWORK => 'http://' . $container . ':4096',
            MachineNetworkModeEnum::PRIVATE_IP => 'http://' . $this->privateHost() . ':' . (int) $port,
            MachineNetworkModeEnum::SSH_EXEC => 'http://127.0.0.1:4096',
        };
    }

    /**
     * One box, many agents, one port each — so the allocator has to see what other agents on this
     * machine already hold, not just what deployments hold.
     */
    private function allocatePort(SshClient $client): int
    {
        $taken = [];

        foreach (preg_split('/\r?\n/', trim($client->exec('docker ps --format ' . escapeshellarg('{{.Ports}}'), 60))) ?: [] as $line) {
            if (preg_match_all('/:(\d+)->/', $line, $matches) > 0) {
                foreach ($matches[1] as $port) {
                    $taken[] = (int) $port;
                }
            }
        }

        for ($port = $this->machine->port_range_start; $port <= $this->machine->port_range_end; $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        throw new ValidationException('No free port on machine ' . $this->machine->name);
    }

    private function storedPort(): ?int
    {
        $port = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::CONTAINER_PORT->value));

        return $port === null ? null : (int) $port;
    }

    private function storedPassword(): string
    {
        return (string) $this->agent->get(AgentCustomFieldEnum::CONTAINER_PASSWORD->value);
    }

    /**
     * The provider key this agent's container runs with.
     *
     * Per agent before per tenant, for the same reason the git token and the machine are: an agent's
     * reach should be settable on the agent. One can run an expensive model while another runs a cheap
     * one, and a key's spend can be attributed to a single agent instead of pooled across the company.
     *
     * A missing named setting is an error rather than a fall-back to the tenant default. Quietly using
     * a different key than the one named would bill the wrong budget and send the tenant's code to a
     * provider account nobody chose — the same class of problem as a substituted model.
     */
    private function resolveApiKey(): string
    {
        $company = $this->agent->company;
        $own = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::PROVIDER_API_KEY->value));

        if ($own !== null) {
            return $own;
        }

        $named = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::PROVIDER_KEY_NAME->value));

        if ($named !== null) {
            $key = Str::trimToNull((string) ($company?->get($named) ?? $this->app->get($named)));

            if ($key === null) {
                throw new ValidationException(
                    'Agent ' . $this->agent->name . ' is set to use the provider key "' . $named
                    . '", but no such setting exists on its company or app.'
                );
            }

            return $key;
        }

        return (string) ($company?->get(ConfigurationEnum::PROVIDER_API_KEY->value)
            ?? $this->app->get(ConfigurationEnum::PROVIDER_API_KEY->value));
    }

    private function setting(ConfigurationEnum $key, string $default): string
    {
        return Str::trimToNull((string) $this->app->get($key->value)) ?? $default;
    }

    private function privateHost(): string
    {
        return Str::trimToNull($this->machine->private_host) ?? $this->machine->host;
    }

    private function networkMode(): MachineNetworkModeEnum
    {
        return MachineNetworkModeEnum::tryFrom((string) $this->machine->network_mode)
            ?? MachineNetworkModeEnum::SSH_EXEC;
    }
}
