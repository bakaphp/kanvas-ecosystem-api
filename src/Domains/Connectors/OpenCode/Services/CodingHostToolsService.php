<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * What a host tool set means, in one place: the commands it opens, the reads it pre-answers, and how a
 * session's simulator is named.
 *
 * Keyed by tool set rather than by runtime so a Linux box that one day advertises `android` gets its
 * own list the same way. `brew`, `sudo`, `osascript`, `launchctl` and `curl` stay denied on purpose:
 * installs persist across sessions and agents, and the others are escapes from the box.
 *
 * Simulator devices are per macOS USER, not per `$HOME` — CoreSimulator ignores the environment and
 * keeps every device under the login user's own Library. So every agent on a Mac shares one device
 * list, and the only thing that tells one session's device from another's is its name.
 */
class CodingHostToolsService
{
    public const string XCODE = 'xcode';

    /** Where the agent keeps the logs and pid files of what it runs in the background. */
    public const string LOGS_DIRECTORY = '.kanvas/logs';

    private const array KNOWN = [self::XCODE];

    private const string SIMULATOR_PREFIX = 'Kanvas-';

    /** @var array<string, array<string, string>> */
    private const array BASH_RULES = [
        self::XCODE => [
            'xcodebuild *' => 'allow',
            'xcrun *' => 'allow',
            'swift *' => 'allow',
            'swiftc *' => 'allow',
            'fastlane *' => 'allow',
            'pod *' => 'allow',
            'xcbeautify *' => 'allow',
            // Xcode 27 renamed Simulator.app to Device Hub; the window is for a human watching the Mac.
            // The name has a space, so the agent quotes it — the pattern must match `"Device Hub"`,
            // `'Device Hub'` and `Device\ Hub` alike.
            'open -a *Device*Hub*' => 'allow',
            // Long-running work: the shell tool has a per-command time limit, so a packager or a build
            // goes to the background and the agent polls its log. `nohup` is opened only for the
            // toolchain already allowed — a bare `nohup *` would be a way around every deny above.
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
            // No kill of any shape: every session on the Mac runs as one user, so `pkill -f expo`
            // would stop a sibling session's packager too. The agent records what it started in
            // `LOGS_DIRECTORY/*.pid` and the reaper stops it once the session has ended.
        ],
    ];

    private const int PACKAGER_PORT_BASE = 8100;

    private const int PACKAGER_PORT_SPAN = 900;

    /**
     * Dotenv-style files inside the workspace: opencode asks before reading one, and a turn on a
     * machine the agent owns waited twenty minutes for that answer. Reads outside the workspace stay
     * governed by opencode's own `external_directory` rule.
     *
     * @var array<string, array<string, string>>
     */
    private const array READ_RULES = [
        self::XCODE => [
            '**/.env*' => 'allow',
            '**/.xcode.env*' => 'allow',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function forMachine(?AgentMachine $machine): array
    {
        $stored = $machine?->get(MachineCustomFieldEnum::HOST_TOOLS->value);

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (! is_array($stored)) {
            return [];
        }

        $tools = array_map(static fn (mixed $tool): string => strtolower(trim((string) $tool)), $stored);

        return array_values(array_unique(array_filter(
            $tools,
            static fn (string $tool): bool => in_array($tool, self::KNOWN, true)
        )));
    }

    /**
     * @param list<string> $hostTools
     */
    public static function hasXcode(array $hostTools): bool
    {
        return in_array(self::XCODE, $hostTools, true);
    }

    /**
     * @param list<string> $hostTools
     * @return array<string, string>
     */
    public static function bashRules(array $hostTools): array
    {
        return self::rulesFor(self::BASH_RULES, $hostTools);
    }

    /**
     * @param list<string> $hostTools
     * @return array<string, string>
     */
    public static function readRules(array $hostTools): array
    {
        return self::rulesFor(self::READ_RULES, $hostTools);
    }

    /**
     * The session's integer id, not a slice of its uuid: a uuid v7 opens with its millisecond
     * timestamp, so every session started in the same minute shares its first hex chars, and two
     * agents fanned out together would have named one device and shut each other down.
     */
    public static function simulatorNameFor(AgentTaskSession $session): string
    {
        return self::SIMULATOR_PREFIX . $session->getId();
    }

    /**
     * Two sessions on one Mac cannot share Metro's default 8081; 8100–8999 stays clear of the
     * machine's opencode port range and of the default simulator ports.
     */
    public static function packagerPortFor(AgentTaskSession $session): int
    {
        return self::PACKAGER_PORT_BASE + ($session->getId() % self::PACKAGER_PORT_SPAN);
    }

    /**
     * The session id a device name carries, or null for a device Kanvas did not name.
     */
    public static function sessionKeyOf(string $deviceName): ?string
    {
        if (preg_match('/^' . preg_quote(self::SIMULATOR_PREFIX, '/') . '(\d+)$/', $deviceName, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<string, array<string, string>> $table
     * @param list<string> $hostTools
     * @return array<string, string>
     */
    private static function rulesFor(array $table, array $hostTools): array
    {
        $rules = [];

        foreach ($hostTools as $tool) {
            $rules = [...$rules, ...($table[$tool] ?? [])];
        }

        return $rules;
    }
}
