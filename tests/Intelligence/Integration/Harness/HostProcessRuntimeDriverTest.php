<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Runtime\HostProcessRuntimeDriver;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeVersionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\Intelligence\Integration\Harness\Concerns\MocksCodingApp;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\Intelligence\Integration\Harness\Fakes\ImpatientHostProcessRuntimeDriver;
use Tests\TestCase;

/**
 * One launchd job per agent on a Mac. Every `launchctl`, `plutil`, `nc` and `curl` the driver sends
 * is pinned here against a fake host, the way the container tests pin the docker strings.
 */
class HostProcessRuntimeDriverTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;
    use MocksCodingApp;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string HOME = '/Users/kanvasrun';

    public function testALoadedJobOnTheCurrentVersionAndKeyIsReused(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->version() . '|' . $this->fingerprint($agent) . '|LOADED');

        $runtime = $this->driver()->ensure($agent, $ssh);

        $this->assertSame($this->handle($agent), $runtime->handle);
        $this->assertSame('pw-old', $runtime->password);
        $this->assertSame(4101, $runtime->port);
        $this->assertSame('http://127.0.0.1:4101', $runtime->endpoint);
        $this->assertSame(MachineRuntimeEnum::HOST, $runtime->runtime);
        $this->assertFalse($this->bootstrapped($ssh));
        $this->assertSame([], $ssh->files);
    }

    public function testAPlistWithoutALoadedJobIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->version() . '|' . $this->fingerprint($agent) . '|GONE');

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->bootstrapped($ssh));
        $this->assertContains(
            'launchctl bootout gui/501/' . $this->handle($agent) . ' 2>&1 || true; rm -f ' . escapeshellarg($this->plist($agent)),
            $ssh->commands
        );
    }

    public function testAJobWithAnOlderKeyFingerprintIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->version() . '|0000000000000000|LOADED');

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->bootstrapped($ssh));
    }

    public function testAJobOnAnOlderVersionIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('2.0.16|' . $this->fingerprint($agent) . '|LOADED');

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->bootstrapped($ssh));
    }

    public function testAMissingPlistStartsAJob(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING');

        $runtime = $this->driver()->ensure($agent, $ssh);

        $password = (string) $agent->get(AgentCustomFieldEnum::CONTAINER_PASSWORD->value);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $password);
        $this->assertSame($password, $runtime->password);
        $this->assertSame(4101, $runtime->port);
        $this->assertSame(4101, (int) $agent->get(AgentCustomFieldEnum::CONTAINER_PORT->value));
        $this->assertSame('http://127.0.0.1:4101', $runtime->endpoint);
        $this->assertSame($this->expectedPlist($agent, $password), $ssh->files[$this->plist($agent)]);
        $this->assertContains('chmod 600 ' . escapeshellarg($this->plist($agent)), $ssh->commands);
        $this->assertContains(
            'launchctl bootstrap gui/501 ' . escapeshellarg($this->plist($agent)) . ' 2>&1; echo "EXIT_CODE:$?"',
            $ssh->commands
        );
        $this->assertContains(
            'curl -s -o /dev/null -w \'%{http_code}\' --max-time 2 -u ' . escapeshellarg('opencode:' . $password)
            . ' http://127.0.0.1:4101/api/info',
            $ssh->commands
        );
    }

    public function testTheCapRefusesANewJob(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING', $this->othersAtCap());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('2-agent cap');

        try {
            $this->driver()->ensure($agent, $ssh);
        } finally {
            $this->assertFalse($this->bootstrapped($ssh));
        }
    }

    public function testAnAgentsOwnJobIsReusedAtTheCap(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh(
            $this->version() . '|' . $this->fingerprint($agent) . '|LOADED',
            $this->othersAtCap() . "\n" . $this->handle($agent) . '|' . $this->version()
        );

        $runtime = $this->driver()->ensure($agent, $ssh);

        $this->assertSame($this->handle($agent), $runtime->handle);
        $this->assertFalse($this->bootstrapped($ssh));
    }

    public function testAMissingBinaryNamesTheInstallCommand(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING', overrides: ['test -x' => 'NO']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('kanvas:coding:build-image --app=');

        $this->driver()->ensure($agent, $ssh);
    }

    public function testAFailedBootstrapRemovesThePlistAndNamesTheError(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING', overrides: [
            'launchctl bootstrap' => "Bootstrap failed: 5: Input/output error\nEXIT_CODE:5",
        ]);

        try {
            $this->driver()->ensure($agent, $ssh);
            $this->fail('A failed bootstrap must throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Input/output error', $e->getMessage());
            $this->assertSame('rm -f ' . escapeshellarg($this->plist($agent)), end($ssh->commands));
        }
    }

    public function testAJobThatNeverAnswersIsRemovedWithTheLogPath(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING', overrides: ['curl -s -o /dev/null' => '000']);

        try {
            $this->driver()->ensure($agent, $ssh);
            $this->fail('A job that never answers must throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opencode.err.log', $e->getMessage());
            // Once to clear the name before the launch, once to tear the dead job down after it.
            $this->assertCount(2, $ssh->commandsStartingWith('launchctl bootout'));
        }
    }

    /**
     * `nc` only sees a port once something listens on it, and a job takes seconds to get there; the
     * reservation is what keeps two agents starting together off the same port in between.
     */
    public function testAPortReservedByAnotherAgentIsSkipped(): void
    {
        $agent = $this->codingAgent();
        $machine = $this->machine();
        Cache::add('coding-runtime-port-' . $machine->getId() . '-4101', 999999, 120);
        $ssh = $this->ssh('MISSING');

        $runtime = $this->driver($machine)->ensure($agent, $ssh);

        $this->assertSame(4102, $runtime->port);
    }

    /**
     * Loaded is not listening: a job crash-looping under KeepAlive (binary gone, port stolen after a
     * reboot) answers `launchctl print` and nothing else, and must not be reused forever.
     */
    public function testALoadedJobThatDoesNotAnswerIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh(
            $this->version() . '|' . $this->fingerprint($agent) . '|LOADED',
            overrides: ['curl -s -o /dev/null' => '000']
        );

        try {
            $this->driver()->ensure($agent, $ssh);
        } catch (ValidationException) {
            // The replacement's own probe fails on the same fake answer; the bootstrap is the point.
        }

        $this->assertTrue($this->bootstrapped($ssh));
    }

    public function testAPlistThatCannotBeWrittenIsAnError(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING');
        $ssh->writable = false;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not write');

        try {
            $this->driver()->ensure($agent, $ssh);
        } finally {
            $this->assertFalse($this->bootstrapped($ssh));
        }
    }

    public function testNoFreePortIsAnError(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('MISSING', overrides: ['nc -z' => 'TAKEN']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No free port');

        $this->driver()->ensure($agent, $ssh);
    }

    public function testRemoveBootsOutAndDeletesThePlist(): void
    {
        $ssh = $this->ssh('MISSING');

        $this->assertTrue($this->driver()->remove('com.kanvas.coding-agent-7', $ssh));
        $this->assertSame(
            'launchctl bootout gui/501/com.kanvas.coding-agent-7 2>&1 || true; rm -f '
            . escapeshellarg(self::HOME . '/Library/LaunchAgents/com.kanvas.coding-agent-7.plist'),
            $ssh->commands[1]
        );
    }

    public function testRemoveReportsAJobThatWillNotGo(): void
    {
        $ssh = $this->ssh('MISSING', overrides: ['launchctl print gui/501/com.kanvas' => 'LOADED']);

        $this->assertFalse($this->driver()->remove('com.kanvas.coding-agent-7', $ssh));
    }

    public function testListParsesEveryPlist(): void
    {
        $ssh = $this->ssh('MISSING', "com.kanvas.coding-agent-7|2.2.5\ncom.kanvas.coding-agent-x|2.2.5\ncom.kanvas.coding-agent-9|");

        $this->assertSame(
            [
                ['handle' => 'com.kanvas.coding-agent-7', 'agentId' => 7, 'version' => '2.2.5'],
                ['handle' => 'com.kanvas.coding-agent-9', 'agentId' => 9, 'version' => ''],
            ],
            $this->driver()->list($ssh)
        );
    }

    /**
     * `prewarm()` only ever installs the Dockerfile's version, so a tag naming another one must not
     * make the replacer treat every job as stale.
     */
    public function testVersionForIsTheDockerfilesVersionWhateverTheTagSays(): void
    {
        $this->assertSame($this->version(), $this->driver()->versionFor('kanvas/opencode:0.0.1'));
    }

    public function testPrewarmInstallsTheVerifiedBinary(): void
    {
        $binary = '/srv/kanvas/bin/opencode-' . $this->version();
        $ssh = $this->ssh('MISSING', overrides: [
            'mkdir -p ' => 'EXIT_CODE:0',
            'shasum -a 256 ' => 'abc123  ' . $binary,
        ]);
        $machine = $this->savedMachine();

        $this->assertSame($binary, $this->driver($machine)->prewarm($this->app(), $ssh));

        $install = $ssh->commandStartingWith('mkdir -p ');
        $this->assertStringContainsString(CodingRuntimeVersionService::archiveUrl($this->version()), $install);
        $this->assertStringContainsString(CodingRuntimeVersionService::checksumsUrl($this->version()), $install);
        $this->assertStringContainsString('shasum -a 256 -c -', $install);
        $this->assertStringContainsString('mv x/opencode ' . escapeshellarg($binary), $install);
        $this->assertSame('sha256:abc123', $machine->get(MachineCustomFieldEnum::IMAGE_DIGEST->value));
    }

    public function testAChecksumMismatchInstallsNothing(): void
    {
        $ssh = $this->ssh('MISSING', overrides: ['mkdir -p ' => "opencode-darwin-arm64.zip: FAILED\nEXIT_CODE:1"]);
        $machine = $this->savedMachine();

        try {
            $this->driver($machine)->prewarm($this->app(), $ssh);
            $this->fail('A failed checksum must throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Could not install', $e->getMessage());
            $this->assertSame('', $ssh->commandStartingWith('shasum -a 256 \''));
            $this->assertNull($machine->get(MachineCustomFieldEnum::IMAGE_DIGEST->value));
        }
    }

    public function testHostProblemsNameEachMissingPrerequisite(): void
    {
        $problems = $this->driver()->hostProblems($this->app(), $this->readyMacSsh([
            'test -x' => 'NO',
            'launchctl print gui/501 ' => 'NO',
            'PATH=' => 'node npm ',
        ]));

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('kanvas:coding:build-image --app=', $problems[0]);
        $this->assertStringContainsString('auto-login', $problems[1]);
        $this->assertSame('Missing on mac: node npm — brew install node npm as kanvasrun.', $problems[2]);
    }

    public function testHostProblemsAreEmptyOnAReadyMac(): void
    {
        $this->assertSame([], $this->driver($this->xcodeMachine())->hostProblems($this->app(), $this->readyMacSsh([
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcode-select' => 'OK',
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices available' => '12',
        ])));
    }

    public function testAMissingXcodeIsNamedWithTheFix(): void
    {
        $ssh = $this->readyMacSsh([
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcode-select' => 'NO',
        ]);

        $problems = $this->driver($this->xcodeMachine())->hostProblems($this->app(), $ssh);

        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Xcode is not installed on mac', $problems[0]);
        $this->assertStringContainsString('accept the licence', $problems[0]);
        $this->assertStringContainsString('as kanvasrun', $problems[0]);
        $this->assertSame('', $ssh->commandStartingWith('PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl'));
    }

    public function testAMissingSimulatorRuntimeIsNamed(): void
    {
        $problems = $this->driver($this->xcodeMachine())->hostProblems($this->app(), $this->readyMacSsh([
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcode-select' => 'OK',
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices available' => '0',
        ]));

        $this->assertSame(['No iOS simulator runtime on mac — in Xcode, Settings → Components, download one.'], $problems);
    }

    public function testXcodeRowsAreSkippedWhenNotAdvertised(): void
    {
        $ssh = $this->readyMacSsh();

        $this->assertSame([], $this->driver()->hostProblems($this->app(), $ssh));
        $this->assertSame('', $ssh->commandStartingWith('PATH=' . HostProcessRuntimeDriver::PATH . '; xcode-select'));
    }

    public function testAnIntelMacIsRefused(): void
    {
        $problems = $this->driver()->hostProblems($this->app(), $this->readyMacSsh(['uname -m' => 'x86_64']));

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('only Apple silicon', $problems[0]);
    }

    public function testOnlyLogsOverTheLimitAreTruncated(): void
    {
        $ssh = $this->ssh('MISSING', overrides: ['find ' => '/srv/kanvas/agents/7/log/opencode.err.log']);

        $this->assertSame(['/srv/kanvas/agents/7/log/opencode.err.log'], $this->driver()->housekeep($this->app(), $ssh));
        $this->assertStringContainsString('-size +50M', $ssh->commandStartingWith('find '));
    }

    public function testOnlyDevicesOfEndedSessionsAreDeleted(): void
    {
        $machine = $this->xcodeMachine();
        $running = $this->sessionOn($machine, HarnessStatusEnum::RUNNING);
        $waiting = $this->sessionOn($machine, HarnessStatusEnum::AWAITING_ANSWER);
        $ended = $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->ssh('MISSING', overrides: [
            'find ' => '',
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices -j' => json_encode(['devices' => [
                'com.apple.CoreSimulator.SimRuntime.iOS-27-2' => [
                    ['name' => 'Kanvas-' . $running->getId(), 'udid' => 'A'],
                    ['name' => 'Kanvas-' . $waiting->getId(), 'udid' => 'B'],
                    ['name' => 'Kanvas-' . $ended->getId(), 'udid' => 'C'],
                    ['name' => 'iPhone 17', 'udid' => 'D'],
                ],
            ]]),
        ]);

        $reclaimed = $this->driver($machine)->housekeep($this->app(), $ssh);

        $this->assertSame(['simulator Kanvas-' . $ended->getId() . ' (C)'], $reclaimed);
        $deletes = array_values(array_filter($ssh->commands, static fn (string $c): bool => str_contains($c, 'simctl delete')));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString("xcrun simctl shutdown 'C'", $deletes[0]);
        $this->assertStringContainsString("xcrun simctl delete 'C'", $deletes[0]);
    }

    public function testUnparseableOrForeignDeviceListsReclaimNothing(): void
    {
        $machine = $this->xcodeMachine();

        foreach (['', 'not json', json_encode(['devices' => ['rt' => [['name' => 'iPhone 17', 'udid' => 'D']]]])] as $listing) {
            $ssh = $this->ssh('MISSING', overrides: [
                'find ' => '',
                'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices -j' => $listing,
            ]);

            $this->assertSame([], $this->driver($machine)->housekeep($this->app(), $ssh));
            $this->assertSame('', $ssh->commandStartingWith('PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl shutdown'));
        }
    }

    /**
     * A project folder's own mtime never moves on a build — Xcode writes under `Build/` — so the
     * decision is the newest FILE inside it, the shared `*.noindex` caches are skipped, and a folder
     * is reported only once `rm` succeeded.
     */
    public function testTheDerivedDataSweepDecidesByNewestFileAndSkipsSharedCaches(): void
    {
        $machine = $this->xcodeMachine();
        $derived = self::HOME . '/Library/Developer/Xcode/DerivedData';
        $ssh = $this->ssh('MISSING', overrides: [
            'find ' => '',
            'find ' . escapeshellarg($derived) => $derived . '/Quorum-cinwrpqodhkosicixhihypkhdhlt',
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices -j' => '',
        ]);

        $reclaimed = $this->driver($machine)->housekeep($this->app(), $ssh);

        $this->assertSame(['derived-data Quorum-cinwrpqodhkosicixhihypkhdhlt'], $reclaimed);
        $sweep = $ssh->commandStartingWith('find ' . escapeshellarg($derived));
        $this->assertStringContainsString("-mindepth 1 -maxdepth 1 -type d -name '*-*'", $sweep);
        $this->assertStringContainsString('-type f -mtime -7 -print -quit', $sweep);
        $this->assertStringContainsString('rm -rf "$1" && echo "$1"', $sweep);
        $this->assertStringNotContainsString('-mtime +7', $sweep);
    }

    /**
     * No kill grant for the agent — every session on the Mac is one user. What a session started in
     * the background is recorded in `.kanvas/logs/*.pid` and stopped here once the session has ended.
     */
    public function testBackgroundProcessesOfEndedSessionsAreStopped(): void
    {
        $machine = $this->xcodeMachine();
        $live = $this->sessionOn($machine, HarnessStatusEnum::RUNNING);
        $ended = $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $root = '/srv/kanvas/agents/7/worktrees/';
        $live->workspace_path = $root . 'live';
        $live->saveQuietly();
        $ended->workspace_path = $root . 'ended';
        $ended->saveQuietly();
        $ssh = $this->ssh('MISSING', overrides: [
            'find ' => '',
            'PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun simctl list devices -j' => '',
            'for f in ' . escapeshellarg($root . 'ended/.kanvas/logs') => '4321',
        ]);

        $reclaimed = $this->driver($machine)->housekeep($this->app(), $ssh);

        $this->assertSame(['process 4321 (' . $ended->uuid . ')'], $reclaimed);
        $this->assertSame('', $ssh->commandStartingWith('for f in ' . escapeshellarg($root . 'live/.kanvas/logs')));
        $stop = $ssh->commandStartingWith('for f in ' . escapeshellarg($root . 'ended/.kanvas/logs'));
        $this->assertStringContainsString('grep -q opencode', $stop);
        $this->assertStringContainsString('kill "$p"', $stop);
    }

    public function testExtraDiskPathsNameXcodeCachesOnlyWhenAdvertised(): void
    {
        $ssh = $this->ssh('MISSING');

        $this->assertSame([
            'xcode' => [
                self::HOME . '/Library/Developer/Xcode/DerivedData',
                self::HOME . '/Library/Developer/CoreSimulator',
            ],
        ], $this->driver($this->xcodeMachine())->extraDiskPaths($ssh));
        $this->assertSame([], $this->driver()->extraDiskPaths($ssh));
    }

    public function testTheSweepIsSkippedWithoutXcode(): void
    {
        $ssh = $this->ssh('MISSING', overrides: ['find ' => '']);

        $this->assertSame([], $this->driver()->housekeep($this->app(), $ssh));
        $this->assertSame('', $ssh->commandStartingWith('PATH=' . HostProcessRuntimeDriver::PATH . '; xcrun'));
    }

    private function savedMachine(): AgentMachine
    {
        return $this->createAgentMachine(name: 'mac', runtime: 'host');
    }

    private function machine(): AgentMachine
    {
        $machine = $this->savedMachine();
        $machine->max_agents = 2;
        $machine->port_range_start = 4100;
        $machine->port_range_end = 4102;
        $machine->saveOrFail();

        return $machine;
    }

    private function sessionOn(AgentMachine $machine, HarnessStatusEnum $status): AgentTaskSession
    {
        return $this->createTaskSession($status, ['agent_machine_id' => $machine->getId()]);
    }

    private function xcodeMachine(): AgentMachine
    {
        $machine = $this->savedMachine();
        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, ['xcode']);

        return $machine;
    }

    private function driver(?AgentMachine $machine = null): HostProcessRuntimeDriver
    {
        return new ImpatientHostProcessRuntimeDriver($machine ?? $this->machine());
    }

    private function codingAgent(): Agent
    {
        return $this->agent([
            AgentCustomFieldEnum::CONTAINER_PASSWORD->value => 'pw-old',
            AgentCustomFieldEnum::CONTAINER_PORT->value => '4101',
        ]);
    }

    private function handle(Agent $agent): string
    {
        return 'com.kanvas.coding-agent-' . $agent->getId();
    }

    private function plist(Agent $agent): string
    {
        return self::HOME . '/Library/LaunchAgents/' . $this->handle($agent) . '.plist';
    }

    private function version(): string
    {
        return CodingRuntimeVersionService::upstreamVersion();
    }

    private function othersAtCap(): string
    {
        return 'com.kanvas.coding-agent-1|' . $this->version() . "\ncom.kanvas.coding-agent-2|" . $this->version();
    }

    /**
     * A Mac that passes every host check, for the tests that take one row away.
     *
     * @param array<string, string> $overrides
     */
    private function readyMacSsh(array $overrides = []): FakeCodingSshClient
    {
        return $this->ssh('MISSING', overrides: [
            'uname -m' => 'arm64',
            'launchctl print gui/501 ' => 'OK',
            'PATH=' => '',
            ...$overrides,
        ]);
    }

    private function ssh(string $reuse, string $listing = '', array $overrides = []): FakeCodingSshClient
    {
        return new FakeCodingSshClient([
            'echo "$HOME|' => self::HOME . '|501|kanvasrun',
            'test -f ' => $reuse,
            'test -x' => 'OK',
            'test -w' => 'WRITABLE',
            'launchctl print gui/501/com.kanvas' => 'GONE',
            'launchctl bootstrap' => 'EXIT_CODE:0',
            'nc -z 127.0.0.1 4100' => 'TAKEN',
            'nc -z' => 'FREE',
            'curl -s -o /dev/null' => '200',
            'for f in' => $listing,
            ...$overrides,
        ]);
    }

    private function bootstrapped(FakeCodingSshClient $ssh): bool
    {
        return $ssh->commandStartingWith('launchctl bootstrap') !== '';
    }

    private function expectedPlist(Agent $agent, string $password): string
    {
        $agentDir = '/srv/kanvas/agents/' . $agent->getId();
        $worktrees = $agentDir . '/worktrees';

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
            '<plist version="1.0">',
            '<dict>',
            '    <key>Label</key><string>' . $this->handle($agent) . '</string>',
            '    <key>ProgramArguments</key>',
            '    <array>',
            '        <string>/srv/kanvas/bin/opencode-' . $this->version() . '</string><string>serve</string>',
            '        <string>--hostname</string><string>127.0.0.1</string>',
            '        <string>--port</string><string>4101</string>',
            '        <string>--print-logs</string>',
            '        <string>--log-level</string><string>error</string>',
            '    </array>',
            '    <key>WorkingDirectory</key><string>' . $worktrees . '</string>',
            '    <key>EnvironmentVariables</key>',
            '    <dict>',
            '        <key>HOME</key><string>' . $worktrees . '/.home</string>',
            '        <key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>',
            '        <key>LANG</key><string>en_US.UTF-8</string>',
            '        <key>USER</key><string>kanvasrun</string>',
            '        <key>LOGNAME</key><string>kanvasrun</string>',
            '        <key>SHELL</key><string>/bin/zsh</string>',
            '        <key>TMPDIR</key><string>' . $agentDir . '/tmp</string>',
            '        <key>OPENCODE_SERVER_PASSWORD</key><string>' . $password . '</string>',
            '        <key>OPENAI_API_KEY</key><string>sk-app-openai</string>',
            '        <key>KANVAS_VERSION</key><string>' . $this->version() . '</string>',
            '        <key>KANVAS_KEYFP</key><string>' . $this->fingerprint($agent) . '</string>',
            '    </dict>',
            '    <key>KeepAlive</key><true/>',
            '    <key>RunAtLoad</key><true/>',
            '    <key>StandardOutPath</key><string>' . $agentDir . '/log/opencode.out.log</string>',
            '    <key>StandardErrorPath</key><string>' . $agentDir . '/log/opencode.err.log</string>',
            '</dict>',
            '</plist>',
            '',
        ]);
    }
}
