<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Reads the connector's agent-scoped settings.
 *
 * Exists for one rule: **blank counts as absent.** Clearing a field in the settings UI writes an
 * empty string rather than deleting the row, so a bare `?? null` check treats a cleared token as
 * configured — which is how an agent ends up with a vault id of `''` and an MCP server it can never
 * authenticate.
 */
class AgentSettingsService
{
    /**
     * Scalars are stringified first: a custom field round-trips through JSON, so a value written as
     * an int (the agent version) reads back as one and would otherwise be dropped as "not a string".
     */
    public static function get(Agent $agent, CustomFieldEnum $field): ?string
    {
        $value = $agent->get($field->value);

        return is_scalar($value) ? self::trimmed((string) $value) : null;
    }

    /** The same rule for anything else that can arrive blank — an agent's own soul, role, format. */
    public static function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function vaultId(Agent $agent): ?string
    {
        return self::get($agent, CustomFieldEnum::CLAUDE_VAULT_ID);
    }
}
