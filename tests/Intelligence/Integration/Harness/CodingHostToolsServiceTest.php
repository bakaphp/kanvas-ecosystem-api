<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

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

        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, '["xcode", "ASC"]');
        $this->assertSame(['xcode', 'asc'], CodingHostToolsService::forMachine($machine));

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

    /**
     * opencode takes the last matching rule, so the account-level subcommands are denied after the allow.
     */
    public function testAppStoreConnectOpensAscButNotItsWebOrAuthCommands(): void
    {
        $rules = CodingHostToolsService::bashRules(['asc']);

        $this->assertSame([
            'asc *' => 'allow',
            'asc web *' => 'deny',
            'asc * web *' => 'deny',
            'asc auth *' => 'deny',
            'asc * auth *' => 'deny',
        ], $rules);
        $this->assertSame('asc *', array_key_first($rules));

        $this->assertSame([], CodingHostToolsService::readRules(['asc']));
        $this->assertTrue(CodingHostToolsService::hasAppStoreConnect(['xcode', 'asc']));
        $this->assertFalse(CodingHostToolsService::hasAppStoreConnect(['xcode']));
        $this->assertArrayNotHasKey('asc *', CodingHostToolsService::bashRules(['xcode']));
    }

    /**
     * asc reads root flags before the command name, so `asc --debug auth login` must not slip past a
     * deny written for `asc auth *`. A command that only passes the word `auth` as an argument is
     * denied too; that is the safe side.
     */
    public function testAscAccountCommandsStayDeniedBehindRootFlags(): void
    {
        $rules = ['*' => 'deny', ...CodingHostToolsService::bashRules(['asc'])];

        foreach ([
            'asc auth',
            'asc web',
            'asc auth login --name MyKey',
            'asc web auth login --apple-id user@example.com',
            'asc --debug auth login',
            'asc --profile x web y',
            'asc --profile work auth',
            'asc --report=junit --debug web apps create --name App',
        ] as $command) {
            $this->assertSame('deny', $this->opencodeDecision($rules, $command), $command);
        }

        foreach ([
            'asc apps list',
            'asc --profile work apps list --output json',
            'asc webhooks list',
            'asc --debug webhooks list',
        ] as $command) {
            $this->assertSame('allow', $this->opencodeDecision($rules, $command), $command);
        }
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

    /**
     * opencode's `Wildcard.match` (2.2): `*` is any run of characters, spaces included and possibly
     * none, `?` is one character, and a trailing ` *` is optional, so `asc auth *` also covers a bare
     * `asc auth`. The last matching rule decides.
     *
     * @param array<string, string> $rules
     */
    private function opencodeDecision(array $rules, string $command): ?string
    {
        $decision = null;

        foreach ($rules as $pattern => $action) {
            $regex = str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/'));

            if (str_ends_with($regex, ' .*')) {
                $regex = substr($regex, 0, -3) . '( .*)?';
            }

            if (preg_match('/^' . $regex . '$/s', $command) === 1) {
                $decision = $action;
            }
        }

        return $decision;
    }
}
