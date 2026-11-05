<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Runtime;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\PreparesHostDirectory;
use Kanvas\Connectors\OpenCode\Concerns\RunsCheckedSshCommands;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Kanvas\Connectors\OpenCode\Services\CodingModelResolver;
use Kanvas\Connectors\OpenCode\Services\CodingWorkspaceService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

/**
 * One long-lived container per agent, reused by every task that agent runs.
 *
 * Not one per task. A box hosts many agents by giving each its own container and port, and a task is
 * isolated by its **directory** rather than by its container: opencode takes a `location.directory`
 * per session, so two tasks on the same agent work in separate worktrees inside one runtime. That
 * keeps the agent's identity, its git setup and its warm runtime in one place, and removes a container
 * start from the front of every job.
 *
 * Every `docker` string the coding flow sends lives here. A `docker` call anywhere above this class is
 * a bug, because the host driver has to be able to replace it.
 */
class ContainerRuntimeDriver extends AbstractCodingRuntimeDriver
{
    use PreparesHostDirectory;
    use RunsCheckedSshCommands;

    /**
     * Named after the agent, which is what lets a second task find the running container instead of
     * starting a rival.
     */
    #[Override]
    public function handleFor(Agent $agent): string
    {
        return 'kanvas-coding-agent-' . $agent->getId();
    }

    /**
     * A container that died leaves its name behind and blocks the next start.
     *
     * `docker rm -f` returns before the daemon has finished releasing the name, so removing and then
     * immediately running fails with "container name is already in use" — a conflict against a
     * container that no longer appears in `docker ps -a` by the time anyone looks. Wait for the name
     * to actually come free rather than racing it.
     */
    #[Override]
    public function remove(string $handle, SshClient $client): bool
    {
        $filter = escapeshellarg('name=^' . $handle . '$');

        $client->exec('docker rm -f ' . escapeshellarg($handle) . ' 2>&1 || true', 120);

        for ($attempt = 0; $attempt < self::REMOVAL_ATTEMPTS; $attempt++) {
            if (trim($client->exec('docker ps -aq --filter ' . $filter, 30)) === '') {
                return true;
            }

            sleep($this->pollDelaySeconds());
        }

        return false;
    }

    /**
     * `{{.Image}}` is the tag the container was started with — or, once that tag has been rebuilt and
     * points at a different image, the bare id. Either way it differs from the current tag, which is
     * exactly what "stale" means.
     */
    #[Override]
    public function list(SshClient $client): array
    {
        $output = $client->exec(
            'docker ps -a --filter label=kanvas.agent --format '
            . escapeshellarg('{{.Names}}|{{.Label "kanvas.agent"}}|{{.Image}}'),
            60
        );

        $found = [];

        foreach (preg_split('/\r?\n/', trim((string) $output)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 3));

            if (count($parts) < 2 || ! ctype_digit($parts[1])) {
                continue;
            }

            $found[] = ['handle' => $parts[0], 'agentId' => (int) $parts[1], 'version' => $parts[2] ?? ''];
        }

        return $found;
    }

    /**
     * Pull-only: a build can outlast an SSH call, so building stays in `kanvas:coding:build-image`.
     * Without a prewarm the first task on a fresh machine waits for a ~500 MB pull inside its own
     * launch, which reads to a user as a hung job.
     */
    #[Override]
    public function prewarm(AppInterface $app, SshClient $client): string
    {
        $image = CodingImageService::configuredFor($app);
        $this->runChecked(
            $client,
            'docker pull ' . escapeshellarg($image),
            'pull ' . $image,
            900
        );

        return $image;
    }

    #[Override]
    public function hostProblems(AppInterface $app, SshClient $client): array
    {
        $problems = [];
        $owner = trim($client->exec('whoami', 30));

        if (trim($client->exec('docker info >/dev/null 2>&1 && echo OK || echo NO', 60)) !== 'OK') {
            $problems[] = 'Docker is not usable by ' . $owner . ' on ' . $this->machine->name
                . ' — install it, or add that user to the docker group.';
        }

        $image = Str::trimToNull((string) $app->get(ConfigurationEnum::IMAGE->value));

        if ($image !== null && trim($client->exec('docker images -q ' . escapeshellarg($image), 60)) === '') {
            $problems[] = $image . ' is not on ' . $this->machine->name . $this->installHint($app);
        }

        return $problems;
    }

    #[Override]
    public function versionFor(string $imageTag): string
    {
        return $imageTag;
    }

    /**
     * Docker rotates its own logs and the reaper already removes idle containers; nothing is left.
     */
    #[Override]
    public function housekeep(AppInterface $app, SshClient $client): array
    {
        return [];
    }

    #[Override]
    public function extraDiskPaths(SshClient $client): array
    {
        return [];
    }

    #[Override]
    protected function ensureUnlocked(Agent $agent, SshClient $client): RuntimeHandle
    {
        $app = $agent->app;
        $handle = $this->handleFor($agent);
        $root = CodingWorkspaceService::rootFor($app);
        $worktreeRoot = $root . '/agents/' . $agent->getId() . '/worktrees';
        $image = CodingImageService::configuredFor($app);
        $resolver = new CodingModelResolver($app, $agent);

        $storedPort = $this->storedPort($agent);

        if ($this->isRunningWithCurrentKeyAndImage(
            $client,
            $handle,
            $resolver->keyFingerprint(),
            $image
        )) {
            return new RuntimeHandle(
                runtime: MachineRuntimeEnum::CONTAINER,
                handle: $handle,
                endpoint: $this->endpoint($handle, $storedPort),
                port: $storedPort,
                password: $this->storedPassword($agent),
                worktreeRoot: $worktreeRoot,
            );
        }

        $this->assertCapacityFor($agent, $client);

        $password = bin2hex(random_bytes(16));
        $port = $this->networkMode()->needsPublishedPort() ? $this->allocatePort($client) : null;

        $this->prepareHostDirectory($client, $worktreeRoot, $this->machine->name);
        $client->exec('mkdir -p ' . escapeshellarg($worktreeRoot . '/.home'), 30);

        $this->removeOrFail($handle, $client);
        $this->runChecked(
            $client,
            $this->runCommand(
                $agent,
                $resolver,
                $handle,
                $image,
                $worktreeRoot,
                $password,
                $port,
                $this->hostIdentity($client)
            ),
            'start the coding container',
            300
        );

        $agent->set(AgentCustomFieldEnum::CONTAINER_PORT->value, (string) ($port ?? ''));
        $agent->set(AgentCustomFieldEnum::CONTAINER_PASSWORD->value, $password);

        return new RuntimeHandle(
            runtime: MachineRuntimeEnum::CONTAINER,
            handle: $handle,
            endpoint: $this->endpoint($handle, $port),
            port: $port,
            password: $password,
            worktreeRoot: $worktreeRoot,
        );
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
     * Reuse the running container only if it holds the key the agent is configured with now AND runs
     * the image the app is configured with now.
     *
     * Both are fixed at `docker run`, so a container started earlier keeps them for as long as it lives
     * — rotating a revoked key, or upgrading the image, would appear to do nothing. Comparing here makes
     * either change take effect on the next task instead of whenever the container happens to die.
     * `{{.Image}}` shows the bare id once the tag has been rebuilt under it, so a same-tag rebuild also
     * rolls over.
     */
    private function isRunningWithCurrentKeyAndImage(
        SshClient $client,
        string $handle,
        string $fingerprint,
        string $image
    ): bool {
        $output = trim($client->exec(
            'docker ps --filter ' . escapeshellarg('name=^' . $handle . '$')
            . ' --format ' . escapeshellarg('{{.Names}}|{{.Label "kanvas.keyfp"}}|{{.Image}}'),
            60
        ));

        $parts = array_map('trim', explode('|', $output, 3));

        return count($parts) === 3
            && $parts[0] === $handle
            && $parts[1] === $fingerprint
            && $parts[2] === $image;
    }

    private function runCommand(
        Agent $agent,
        CodingModelResolver $resolver,
        string $handle,
        string $image,
        string $worktreeRoot,
        string $password,
        ?int $port,
        string $hostIdentity
    ): string {
        $app = $agent->app;

        $parts = [
            'docker run -d --restart unless-stopped',
            '--name ' . escapeshellarg($handle),
            '--label ' . escapeshellarg('kanvas.agent=' . $agent->getId()),
            '--label ' . escapeshellarg('kanvas.company=' . $agent->companies_id),
            '--label ' . escapeshellarg('kanvas.keyfp=' . $resolver->keyFingerprint()),
            '--cpus ' . escapeshellarg($this->setting($app, ConfigurationEnum::CONTAINER_CPUS, '2')),
            '--memory ' . escapeshellarg($this->setting($app, ConfigurationEnum::CONTAINER_MEMORY, '2g')),
            '--pids-limit 512',
            '--user ' . escapeshellarg($hostIdentity),
            '-e ' . escapeshellarg('OPENCODE_SERVER_PASSWORD=' . $password),
            '-e ' . escapeshellarg($resolver->envVar() . '=' . $resolver->apiKey()),
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

    private function endpoint(string $handle, ?int $port): string
    {
        return match ($this->networkMode()) {
            MachineNetworkModeEnum::SHARED_NETWORK => 'http://' . $handle . ':4096',
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

    private function setting(AppInterface $app, ConfigurationEnum $key, string $default): string
    {
        return Str::trimToNull((string) $app->get($key->value)) ?? $default;
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
