<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\CodingHostToolsService;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;

/**
 * The one place that knows what a host tool set means: which commands it opens, which reads it
 * pre-answers, and how a session's simulator is named so the reaper can tell it apart later.
 */
class CodingHostToolsServiceTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testHostToolsNormaliseFromStringArrayAndNull(): void
    {
        $machine = $this->createAgentMachine(runtime: 'host');

        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, '["Xcode ", "android"]');
        $this->assertSame(['xcode'], CodingHostToolsService::forMachine($machine));

        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, ['xcode', 'xcode']);
        $this->assertSame(['xcode'], CodingHostToolsService::forMachine($machine));

        $this->assertSame([], CodingHostToolsService::forMachine($this->createAgentMachine(runtime: 'host')));
        $this->assertSame([], CodingHostToolsService::forMachine(null));
    }

    public function testXcodeBashRulesAreExactlyTheAllowList(): void
    {
        $this->assertSame([
            'xcodebuild *' => 'allow',
            'xcrun *' => 'allow',
            'swift *' => 'allow',
            'swiftc *' => 'allow',
            'fastlane *' => 'allow',
            'pod *' => 'allow',
            'xcbeautify *' => 'allow',
            'open -a *Device*Hub*' => 'allow',
            'nohup npx *' => 'allow',
            'nohup npm *' => 'allow',
            'nohup node *' => 'allow',
            'nohup xcodebuild *' => 'allow',
            'nohup xcrun *' => 'allow',
            'nohup pod *' => 'allow',
            'nohup fastlane *' => 'allow',
            'tail *' => 'allow',
            'sleep *' => 'allow',
            'mkdir -p .kanvas/*' => 'allow',
        ], CodingHostToolsService::bashRules(['xcode']));

        // Every agent on a Mac runs as one user: a kill grant of any shape reaches sibling sessions.
        foreach (array_keys(CodingHostToolsService::bashRules(['xcode'])) as $rule) {
            $this->assertStringNotContainsString('kill', $rule);
        }

        $this->assertSame([], CodingHostToolsService::bashRules([]));
    }

    public function testXcodeReadRulesPreAnswerDotenvInsideTheWorkspace(): void
    {
        $this->assertSame([
            '**/.env*' => 'allow',
            '**/.xcode.env*' => 'allow',
        ], CodingHostToolsService::readRules(['xcode']));

        $this->assertSame([], CodingHostToolsService::readRules([]));
    }

    public function testThePackagerPortIsPerSessionAndOutsideTheRuntimeRange(): void
    {
        $session = new AgentTaskSession();
        $session->id = 4514;

        $this->assertSame(8114, CodingHostToolsService::packagerPortFor($session));

        $session->id = 900;
        $this->assertSame(8100, CodingHostToolsService::packagerPortFor($session));
    }

    public function testSimulatorNameIsTheSessionId(): void
    {
        $session = new AgentTaskSession();
        $session->id = 4514;

        $this->assertSame('Kanvas-4514', CodingHostToolsService::simulatorNameFor($session));
        $this->assertSame('4514', CodingHostToolsService::sessionKeyOf('Kanvas-4514'));
        $this->assertNull(CodingHostToolsService::sessionKeyOf('Kanvas-spike'));
        $this->assertNull(CodingHostToolsService::sessionKeyOf('iPhone 17'));
        $this->assertNull(CodingHostToolsService::sessionKeyOf('Kanvas-4514-x'));
        $this->assertNull(CodingHostToolsService::sessionKeyOf('Kanvas-01a1a8a5'));
    }

    /**
     * A uuid v7 starts with its millisecond timestamp, so its first hex chars are shared by every
     * session started in the same minute — two agents fanned out together would have named one device
     * and shut each other down.
     */
    public function testTwoSessionsStartedTogetherGetDifferentDevices(): void
    {
        $first = $this->createTaskSession();
        $second = $this->createTaskSession();

        $this->assertNotSame(
            CodingHostToolsService::simulatorNameFor($first),
            CodingHostToolsService::simulatorNameFor($second)
        );
    }

    /**
     * opencode turns `*` into "anything"; the agent writes the app name quoted or escaped, and the
     * first live run was refused on `open -a "Device Hub"` because the rule said `Device Hub*`.
     */
    public function testTheDeviceHubRuleMatchesTheQuotedForms(): void
    {
        $pattern = array_find_key(CodingHostToolsService::bashRules(['xcode']), static fn (string $v, string $k): bool => str_contains($k, 'Device'));
        $this->assertNotNull($pattern);
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';

        foreach ([
            'open -a "Device Hub" --args -CurrentDeviceUDID 6486A438',
            "open -a 'Device Hub'",
            'open -a Device\\ Hub',
            'open -a DeviceHub',
        ] as $command) {
            $this->assertMatchesRegularExpression($regex, $command, $command);
        }

        $this->assertDoesNotMatchRegularExpression($regex, 'open -a Terminal');
    }
}
