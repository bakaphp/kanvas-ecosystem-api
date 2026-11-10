<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

use Baka\Support\Str;
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

    public const string DEFAULT_FACTORY_DIR = 'Documents/Projects/opencode-factory';

    public static function isEnabledFor(Agent $agent): bool
    {
        return filter_var($agent->get(self::MODE->value), FILTER_VALIDATE_BOOLEAN);
    }

    public static function defaultRepoFor(Agent $agent): ?string
    {
        return Str::trimToNull((string) $agent->get(self::DEFAULT_REPO->value));
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
