<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Enums;

/**
 * Mirrors the AI Agent Architecture's `agent_types.provider` values.
 * `nervous_system_skills.frameworks` and `nervous_system_tools.frameworks`
 * JSON arrays must contain values from this enum.
 */
enum CapabilityFrameworkEnum: string
{
    case NEURON = 'neuron';
    case LARAVEL = 'laravel';
    case ADK = 'adk';
    case OPENCLAW = 'openclaw';
    case CLAUDE = 'claude';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
