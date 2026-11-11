<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Runtime;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenCode\Concerns\PreparesHostDirectory;
use Kanvas\Connectors\OpenCode\Concerns\RunsCheckedSshCommands;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\CodingHostToolsService;
use Kanvas\Connectors\OpenCode\Services\CodingModelResolver;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeVersionService;
use Kanvas\Connectors\OpenCode\Services\CodingWorkspaceService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

/**
 * One opencode process per agent, run by launchd on a Mac the agent owns.
 *
 * The job is the container's twin: named after the agent, reused by every task, replaced when the
 * version or the key changes, removed when the last session leaves. launchd is what keeps it alive
 * across crashes and logins (`KeepAlive` + `RunAtLoad`), so `remove()` has to delete the plist as
 * well as boot the job out — a plist left on disk comes back at the next login.
 *
 * Everything runs as the SSH user in its own GUI session (`gui/<uid>`), which is the only launchd
 * domain where the Simulator and anything else that needs a window server will work.
 */
class HostProcessRuntimeDriver extends AbstractCodingRuntimeDriver
{
    use PreparesHostDirectory;
    use RunsCheckedSshCommands;

    public const string PATH = '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin';

    private const int BACKGROUND_SWEEP_BATCH = 50;

    /** Long enough to cover write → bootstrap → listening; `nc` takes over once the job is up. */
    private const int PORT_RESERVATION_SECONDS = 120;

    /** @var array{home: string, uid: int, user: string}|null */
    private ?array $identity = null;

    #[Override]
    public function handleFor(Agent $agent): string
    {
        return 'com.kanvas.coding-agent-' . $agent->getId();
    }

    #[Override]
    public function remove(string $handle, SshClient $client): bool
    {
        $uid = $this->identity($client)['uid'];

        $client->exec(
            'launchctl bootout gui/' . $uid . '/' . $handle . ' 2>&1 || true; rm -f ' . escapeshellarg($this->plistPath($client, $handle)),
            60
        );

        for ($attempt = 0; $attempt < self::REMOVAL_ATTEMPTS; $attempt++) {
            if (! $this->isLoaded($client, $handle)) {
                return true;
            }

            sleep($this->pollDelaySeconds());
        }

        return false;
    }

    /**
     * Disk is what `ensure()` wrote; a job on disk but not loaded is a problem `ensure()` fixes, not
     * a different state, so the listing reads the plists rather than `launchctl list`.
     */
    #[Override]
    public function list(SshClient $client): array
    {
        $directory = $this->identity($client)['home'] . '/Library/LaunchAgents';
        $output = $client->exec(
            'for f in ' . escapeshellarg($directory) . '/com.kanvas.coding-agent-*.plist; do [ -f "$f" ] || continue; '
            . 'echo "$(basename "$f" .plist)|$(plutil -extract EnvironmentVariables.KANVAS_VERSION raw -o - "$f" 2>/dev/null)"; done',
            60
        );

        $found = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            [$handle, $version] = array_pad(explode('|', trim($line), 2), 2, '');
            $id = substr($handle, strlen('com.kanvas.coding-agent-'));

            if (! str_starts_with($handle, 'com.kanvas.coding-agent-') || ! ctype_digit($id)) {
                continue;
            }

            $found[] = ['handle' => $handle, 'agentId' => (int) $id, 'version' => $version];
        }

        return $found;
    }

    /**
     * The official darwin-arm64 release, verified against the checksum file the release publishes —
     * or, for a release without one, the sha recorded beside the version in
     * `CodingRuntimeVersionService`. Installed side by side under its version so an upgrade never
     * replaces the binary a loaded job is running.
     */
    #[Override]
    public function prewarm(AppInterface $app, SshClient $client): string
    {
        $root = CodingWorkspaceService::rootFor($app);
        $version = CodingRuntimeVersionService::upstreamVersion();
        $binary = CodingRuntimeVersionService::hostBinary($root, $version);
        $staging = $root . '/tmp/opencode-' . $version;
        $archive = CodingRuntimeVersionService::ARCHIVE;
        $recorded = CodingRuntimeVersionService::HOST_ARCHIVE_SHA256[$version] ?? null;
        $fallback = $recorded === null
            ? 'false'
            : 'printf ' . escapeshellarg('%s  ' . $archive . "\n") . ' ' . escapeshellarg($recorded) . ' > ' . CodingRuntimeVersionService::CHECKSUMS;

        $this->runChecked(
            $client,
            'mkdir -p ' . escapeshellarg($root . '/bin') . ' ' . escapeshellarg($staging)
            . ' && { cd ' . escapeshellarg($staging)
            . ' && curl -fsSL -o ' . $archive . ' ' . escapeshellarg(CodingRuntimeVersionService::archiveUrl($version))
            . ' && (curl -fsSL -o ' . CodingRuntimeVersionService::CHECKSUMS . ' '
            . escapeshellarg(CodingRuntimeVersionService::checksumsUrl($version)) . ' || ' . $fallback . ')'
            . ' && grep ' . escapeshellarg(' ' . $archive . '$') . ' ' . CodingRuntimeVersionService::CHECKSUMS . ' | shasum -a 256 -c -'
            . ' && rm -rf x && mkdir x && unzip -q -o ' . $archive . ' -d x'
            . ' && mv x/opencode ' . escapeshellarg($binary) . ' && chmod 755 ' . escapeshellarg($binary)
            . ' && cd / && rm -rf ' . escapeshellarg($staging)
            . '; }',
            'install opencode ' . $version . ' on ' . $this->machine->name,
            900
        );

        $digest = strtok(trim($client->exec('shasum -a 256 ' . escapeshellarg($binary), 60)), ' ');
        $this->machine->set(MachineCustomFieldEnum::IMAGE_DIGEST->value, 'sha256:' . $digest);

        return $binary;
    }

    #[Override]
    public function hostProblems(AppInterface $app, SshClient $client): array
    {
        $identity = $this->identity($client);
        $version = CodingRuntimeVersionService::upstreamVersion();
        $binary = CodingRuntimeVersionService::hostBinary(CodingWorkspaceService::rootFor($app), $version);
        $problems = [];

        $arch = trim($client->exec('uname -m', 30));

        if ($arch !== 'arm64') {
            $problems[] = $this->machine->name . ' is ' . $arch . '; only Apple silicon (arm64) is supported.';
        }

        if (trim($client->exec('test -x ' . escapeshellarg($binary) . ' && echo OK || echo NO', 30)) !== 'OK') {
            $problems[] = 'opencode ' . $version . ' is not installed on ' . $this->machine->name . $this->installHint($app);
        }

        if (trim($client->exec('launchctl print gui/' . $identity['uid'] . ' >/dev/null 2>&1 && echo OK || echo NO', 30)) !== 'OK') {
            $problems[] = 'No GUI session for ' . $identity['user'] . ' on ' . $this->machine->name
                . ' — enable auto-login for the runtime user (FileVault must be off) and log it in.';
        }

        // Non-interactive SSH has no Homebrew on its PATH, so the check names the one the job will run with.
        $missing = trim($client->exec(
            'PATH=' . self::PATH . '; for t in git node npm curl unzip shasum; do command -v $t >/dev/null 2>&1 || printf \'%s \' $t; done',
            30
        ));

        if ($missing !== '') {
            $problems[] = 'Missing on ' . $this->machine->name . ': ' . $missing . ' — brew install ' . $missing
                . ' as ' . $identity['user'] . '.';
        }

        if ($this->advertisesXcode()) {
            $problems = [...$problems, ...$this->xcodeProblems($client, $identity['user'])];
        }

        return $problems;
    }

    /**
     * The licence is not a row of its own: unaccepted, the device listing fails and the second row
     * reports it.
     *
     * @return list<string>
     */
    private function xcodeProblems(SshClient $client, string $user): array
    {
        if (trim($client->exec('PATH=' . self::PATH . '; xcode-select -p >/dev/null 2>&1 && echo OK || echo NO', 30)) !== 'OK') {
            return [
                'Xcode is not installed on ' . $this->machine->name . ' — install Xcode, accept the licence '
                . '(sudo xcodebuild -license accept) as ' . $user . ', and download an iOS simulator runtime.',
            ];
        }

        $devices = (int) trim($client->exec(
            'PATH=' . self::PATH . '; xcrun simctl list devices available -j 2>/dev/null | grep -c \'"udid"\'',
            60
        ));

        if ($devices === 0) {
            return ['No iOS simulator runtime on ' . $this->machine->name . ' — in Xcode, Settings → Components, download one.'];
        }

        return [];
    }

    private function advertisesXcode(): bool
    {
        return CodingHostToolsService::hasXcode(CodingHostToolsService::forMachine($this->machine));
    }

    /**
     * The Dockerfile's version, whatever the tag says: it is the only one `prewarm()` installs, so
     * honouring a different `--image` here would mark every job stale and replace it with its twin.
     */
    #[Override]
    public function versionFor(string $imageTag): string
    {
        return CodingRuntimeVersionService::upstreamVersion();
    }

    /**
     * launchd does not rotate its own logs. Truncated in place rather than removed, because the job
     * keeps the file open and would carry on writing into an unlinked inode.
     */
    #[Override]
    public function housekeep(AppInterface $app, SshClient $client): array
    {
        $output = $client->exec(
            'find ' . escapeshellarg(CodingWorkspaceService::rootFor($app) . '/agents') . ' -path \'*/log/opencode.*.log\' -size +50M -print'
            . ' -exec sh -c \': > "$1"\' _ {} \; 2>/dev/null',
            120
        );

        $reclaimed = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])));

        if ($this->advertisesXcode()) {
            $reclaimed = [
                ...$reclaimed,
                ...$this->sweepSimulators($client),
                ...$this->sweepDerivedData($client),
                ...$this->stopBackgroundProcesses($client),
            ];
        }

        return $reclaimed;
    }

    /**
     * Xcode's caches live in the login user's Library — CoreSimulator ignores `$HOME` and DerivedData
     * follows it — so they are outside every agent directory the reaper measures.
     */
    #[Override]
    public function extraDiskPaths(SshClient $client): array
    {
        if (! $this->advertisesXcode()) {
            return [];
        }

        $home = $this->identity($client)['home'];

        return [
            'xcode' => [
                $home . '/Library/Developer/Xcode/DerivedData',
                $home . '/Library/Developer/CoreSimulator',
            ],
        ];
    }

    /**
     * DerivedData is a build cache: a project folder nobody built for a week costs a cold build next
     * time and nothing else. The decision is the newest FILE inside the folder — a build writes under
     * `Build/`, so the folder's own mtime never moves — the shared `*.noindex` caches at the same level
     * are skipped, and a folder is reported only once `rm` succeeded. Simulator runtimes are never
     * touched; reinstalling one is a human step.
     *
     * @return list<string>
     */
    private function sweepDerivedData(SshClient $client): array
    {
        $derived = $this->identity($client)['home'] . '/Library/Developer/Xcode/DerivedData';
        $output = $client->exec(
            'find ' . escapeshellarg($derived) . " -mindepth 1 -maxdepth 1 -type d -name '*-*'"
            . ' -exec sh -c \'[ -z "$(find "$1" -type f -mtime -7 -print -quit)" ] && rm -rf "$1" && echo "$1"\' _ {} \; 2>/dev/null',
            300
        );

        return array_map(
            static fn (string $path): string => 'derived-data ' . basename($path),
            array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])))
        );
    }

    /**
     * The agent has no kill grant — every session on the Mac is one user — so what it started in
     * the background is recorded in `LOGS_DIRECTORY/*.pid` and stopped here once its session ended.
     * The opencode server itself is never a candidate, whatever a pid file says.
     *
     * @return list<string>
     */
    private function stopBackgroundProcesses(SshClient $client): array
    {
        $ended = AgentTaskSession::query()
            ->onMachine($this->machine->getId(), HarnessEnum::OPENCODE)
            ->whereIn('status', HarnessStatusEnum::terminalValues())
            ->whereNull('workspace_reaped_at')
            ->whereNotNull('workspace_path')
            ->orderByDesc('id')
            ->limit(self::BACKGROUND_SWEEP_BATCH)
            ->get(['uuid', 'workspace_path']);

        $stopped = [];

        foreach ($ended as $session) {
            $logs = escapeshellarg($session->workspace_path . '/' . CodingHostToolsService::LOGS_DIRECTORY);
            $output = $client->exec(
                'for f in ' . $logs . '/*.pid; do [ -f "$f" ] || continue; p=$(cat "$f"); rm -f "$f";'
                . ' case "$p" in \'\'|*[!0-9]*) continue;; esac;'
                . ' ps -o command= -p "$p" 2>/dev/null | grep -q opencode && continue;'
                . ' kill "$p" 2>/dev/null && echo "$p"; done',
                60
            );

            foreach (array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])) as $pid) {
                $stopped[] = 'process ' . $pid . ' (' . $session->uuid . ')';
            }
        }

        return $stopped;
    }

    /**
     * Simulator devices are per macOS user, so every agent on the box shares one list and a device
     * is only known to be ours by its name. A `Kanvas-{session id}` device stays while that session
     * is live on this machine; anything else so named belongs to a session that ended and is shut
     * down and deleted.
     *
     * @return list<string>
     */
    private function sweepSimulators(SshClient $client): array
    {
        $listing = json_decode($client->exec('PATH=' . self::PATH . '; xcrun simctl list devices -j 2>/dev/null', 60), true);

        if (! is_array($listing) || ! is_array($listing['devices'] ?? null)) {
            return [];
        }

        $devices = [];

        foreach ($listing['devices'] as $runtimeDevices) {
            foreach (is_array($runtimeDevices) ? $runtimeDevices : [] as $device) {
                $key = CodingHostToolsService::sessionKeyOf((string) ($device['name'] ?? ''));

                if ($key !== null && isset($device['udid'])) {
                    $devices[] = ['name' => (string) $device['name'], 'udid' => (string) $device['udid'], 'key' => $key];
                }
            }
        }

        if ($devices === []) {
            return [];
        }

        $live = AgentTaskSession::query()
            ->onMachine($this->machine->getId(), HarnessEnum::OPENCODE)
            ->live()
            ->pluck('id')
            ->map(static fn (int|string $id): string => (string) $id)
            ->all();

        $reclaimed = [];

        foreach ($devices as $device) {
            if (in_array($device['key'], $live, true)) {
                continue;
            }

            $udid = escapeshellarg($device['udid']);
            $client->exec(
                'PATH=' . self::PATH . '; xcrun simctl shutdown ' . $udid . ' >/dev/null 2>&1; xcrun simctl delete ' . $udid . ' 2>&1 || true',
                120
            );
            $reclaimed[] = 'simulator ' . $device['name'] . ' (' . $device['udid'] . ')';
        }

        return $reclaimed;
    }

    protected function probeAttempts(): int
    {
        return 30;
    }

    #[Override]
    protected function ensureUnlocked(Agent $agent, SshClient $client): RuntimeHandle
    {
        $app = $agent->app;
        $handle = $this->handleFor($agent);
        $plist = $this->plistPath($client, $handle);
        $root = CodingWorkspaceService::rootFor($app);
        $agentDir = $root . '/agents/' . $agent->getId();
        $worktreeRoot = $agentDir . '/worktrees';
        $version = CodingRuntimeVersionService::upstreamVersion();
        $binary = CodingRuntimeVersionService::hostBinary($root, $version);
        $resolver = new CodingModelResolver($app, $agent);
        $fingerprint = $resolver->keyFingerprint();
        $storedPort = $this->storedPort($agent);
        $storedPassword = $this->storedPassword($agent);

        if ($storedPort !== null
            && $storedPassword !== ''
            && $this->isCurrentAndLoaded(
                $client,
                $handle,
                $plist,
                $version,
                $fingerprint
            )
            && $this->answers(
                $client,
                $storedPort,
                $storedPassword,
                attempts: 1
            )
        ) {
            return $this->handle(
                $handle,
                $storedPort,
                $storedPassword,
                $worktreeRoot
            );
        }

        $this->assertCapacityFor($agent, $client);

        $this->prepareHostDirectory($client, $worktreeRoot, $this->machine->name);
        $client->exec(
            'mkdir -p ' . escapeshellarg($worktreeRoot . '/.home') . ' ' . escapeshellarg($agentDir . '/log')
            . ' ' . escapeshellarg($agentDir . '/tmp'),
            30
        );

        if (trim($client->exec('test -x ' . escapeshellarg($binary) . ' && echo OK || echo NO', 30)) !== 'OK') {
            throw new ValidationException(
                'opencode ' . $version . ' is not installed on ' . $this->machine->name . $this->installHint($app)
            );
        }

        $this->removeOrFail($handle, $client);

        $password = bin2hex(random_bytes(16));
        $port = $this->allocatePort($agent, $client);
        $identity = $this->identity($client);

        $written = $client->writeFile($plist, $this->plist([
            'handle' => $handle,
            'binary' => $binary,
            'port' => $port,
            'worktreeRoot' => $worktreeRoot,
            'agentDir' => $agentDir,
            'user' => $identity['user'],
            'password' => $password,
            'envVar' => $resolver->envVar(),
            'apiKey' => $resolver->apiKey(),
            'version' => $version,
            'fingerprint' => $fingerprint,
        ]));

        if (! $written) {
            throw new ValidationException(
                'Kanvas could not write the launchd plist ' . $plist . ' on ' . $this->machine->name . '.'
            );
        }

        $client->exec('chmod 600 ' . escapeshellarg($plist), 30);

        try {
            $this->runChecked(
                $client,
                'launchctl bootstrap gui/' . $identity['uid'] . ' ' . escapeshellarg($plist),
                'start opencode for agent ' . $agent->name . ' on ' . $this->machine->name,
                60
            );
        } catch (ValidationException $e) {
            $client->exec('rm -f ' . escapeshellarg($plist), 30);

            throw $e;
        }

        if (! $this->answers(
            $client,
            $port,
            $password,
            $this->probeAttempts()
        )) {
            $this->remove($handle, $client);

            throw new ValidationException(
                'opencode did not answer on port ' . $port . ' of ' . $this->machine->name . ' after '
                . $this->probeAttempts() . ' attempts; see ' . $agentDir . '/log/opencode.err.log'
            );
        }

        $agent->set(AgentCustomFieldEnum::CONTAINER_PORT->value, (string) $port);
        $agent->set(AgentCustomFieldEnum::CONTAINER_PASSWORD->value, $password);

        return $this->handle(
            $handle,
            $port,
            $password,
            $worktreeRoot
        );
    }

    /**
     * Reuse the loaded job only if its plist carries the version and key fingerprint the agent is
     * configured with now — the same rule the container driver applies to its labels — AND launchd
     * still holds it. A plist on disk whose job is gone (a reboot before auto-login) is replaced; so
     * is a job that is loaded but does not answer, which the caller checks next.
     */
    private function isCurrentAndLoaded(
        SshClient $client,
        string $handle,
        string $plist,
        string $version,
        string $fingerprint
    ): bool {
        $quoted = escapeshellarg($plist);
        // The missing-key error goes to stderr on current macOS and to stdout on older ones; either
        // way the field never equals the expected value, so both read as "replace".
        $answer = trim($client->exec(
            'test -f ' . $quoted . ' && echo "$(plutil -extract EnvironmentVariables.KANVAS_VERSION raw -o - ' . $quoted
            . ' 2>/dev/null)|$(plutil -extract EnvironmentVariables.KANVAS_KEYFP raw -o - ' . $quoted
            . ' 2>/dev/null)|$(launchctl print gui/' . $this->identity($client)['uid'] . '/' . $handle
            . ' >/dev/null 2>&1 && echo LOADED || echo GONE)" || echo MISSING',
            60
        ));

        return $answer === $version . '|' . $fingerprint . '|LOADED';
    }

    private function isLoaded(SshClient $client, string $handle): bool
    {
        return trim($client->exec(
            'launchctl print gui/' . $this->identity($client)['uid'] . '/' . $handle . ' >/dev/null 2>&1 && echo LOADED || echo GONE',
            30
        )) === 'LOADED';
    }

    /**
     * `nc` only sees a port once something listens on it, and a job takes seconds to get there, so a
     * free port is also reserved in the cache for the agent that is about to bind it. Two agents
     * starting together on one Mac therefore never pick the same port; the machine lock covers the
     * probe, the reservation covers the bind.
     */
    private function allocatePort(Agent $agent, SshClient $client): int
    {
        return Cache::lock('coding-runtime-machine-' . $this->machine->getId(), 60)->block(30, function () use ($agent, $client): int {
            for ($port = $this->machine->port_range_start; $port <= $this->machine->port_range_end; $port++) {
                $reservation = 'coding-runtime-port-' . $this->machine->getId() . '-' . $port;

                if (! Cache::add($reservation, $agent->getId(), self::PORT_RESERVATION_SECONDS) && Cache::get($reservation) !== $agent->getId()) {
                    continue;
                }

                $answer = trim($client->exec('nc -z 127.0.0.1 ' . $port . ' >/dev/null 2>&1 && echo TAKEN || echo FREE', 30));

                if ($answer === 'FREE') {
                    return $port;
                }

                Cache::forget($reservation);
            }

            throw new ValidationException('No free port on machine ' . $this->machine->name);
        });
    }

    /**
     * A port taken by something else crash-loops under `KeepAlive`, and a bootstrap on a still-loaded
     * label fails; probing turns both into a launch failure with a reason instead of a sweep ten
     * minutes later.
     */
    private function answers(
        SshClient $client,
        int $port,
        string $password,
        int $attempts
    ): bool {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                sleep($this->pollDelaySeconds());
            }

            $status = trim($client->exec(
                'curl -s -o /dev/null -w \'%{http_code}\' --max-time 2 -u ' . escapeshellarg('opencode:' . $password)
                . ' http://127.0.0.1:' . $port . '/api/info',
                10
            ));

            if ($status === '200') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{handle: string, binary: string, port: int, worktreeRoot: string, agentDir: string, user: string, password: string, envVar: string, apiKey: string, version: string, fingerprint: string} $args
     */
    private function plist(array $args): string
    {
        $x = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
            '<plist version="1.0">',
            '<dict>',
            '    <key>Label</key><string>' . $x($args['handle']) . '</string>',
            '    <key>ProgramArguments</key>',
            '    <array>',
            '        <string>' . $x($args['binary']) . '</string><string>serve</string>',
            '        <string>--hostname</string><string>127.0.0.1</string>',
            '        <string>--port</string><string>' . $args['port'] . '</string>',
            '        <string>--print-logs</string>',
            '        <string>--log-level</string><string>error</string>',
            '    </array>',
            '    <key>WorkingDirectory</key><string>' . $x($args['worktreeRoot']) . '</string>',
            '    <key>EnvironmentVariables</key>',
            '    <dict>',
            '        <key>HOME</key><string>' . $x($args['worktreeRoot']) . '/.home</string>',
            '        <key>PATH</key><string>' . self::PATH . '</string>',
            '        <key>LANG</key><string>en_US.UTF-8</string>',
            '        <key>USER</key><string>' . $x($args['user']) . '</string>',
            '        <key>LOGNAME</key><string>' . $x($args['user']) . '</string>',
            '        <key>SHELL</key><string>/bin/zsh</string>',
            '        <key>TMPDIR</key><string>' . $x($args['agentDir']) . '/tmp</string>',
            '        <key>OPENCODE_SERVER_PASSWORD</key><string>' . $x($args['password']) . '</string>',
            '        <key>' . $x($args['envVar']) . '</key><string>' . $x($args['apiKey']) . '</string>',
            '        <key>KANVAS_VERSION</key><string>' . $x($args['version']) . '</string>',
            '        <key>KANVAS_KEYFP</key><string>' . $x($args['fingerprint']) . '</string>',
            '    </dict>',
            '    <key>KeepAlive</key><true/>',
            '    <key>RunAtLoad</key><true/>',
            '    <key>StandardOutPath</key><string>' . $x($args['agentDir']) . '/log/opencode.out.log</string>',
            '    <key>StandardErrorPath</key><string>' . $x($args['agentDir']) . '/log/opencode.err.log</string>',
            '</dict>',
            '</plist>',
            '',
        ]);
    }

    private function handle(
        string $handle,
        int $port,
        string $password,
        string $worktreeRoot
    ): RuntimeHandle {
        return new RuntimeHandle(
            runtime: MachineRuntimeEnum::HOST,
            handle: $handle,
            endpoint: 'http://127.0.0.1:' . $port,
            port: $port,
            password: $password,
            worktreeRoot: $worktreeRoot,
        );
    }

    private function plistPath(SshClient $client, string $handle): string
    {
        return $this->identity($client)['home'] . '/Library/LaunchAgents/' . $handle . '.plist';
    }

    /**
     * Read once per driver, which is once per machine: the home and uid are what `gui/<uid>` and the
     * plist path are built from, and they do not change between two commands of one run.
     *
     * @return array{home: string, uid: int, user: string}
     */
    private function identity(SshClient $client): array
    {
        if ($this->identity === null) {
            [$home, $uid, $user] = array_pad(explode('|', trim($client->exec('echo "$HOME|$(id -u)|$(whoami)"', 30)), 3), 3, '');

            if ($home === '' || ! ctype_digit($uid)) {
                throw new ValidationException('Could not read the SSH user\'s home and uid on ' . $this->machine->name . '.');
            }

            $this->identity = ['home' => rtrim($home, '/'), 'uid' => (int) $uid, 'user' => $user];
        }

        return $this->identity;
    }
}
