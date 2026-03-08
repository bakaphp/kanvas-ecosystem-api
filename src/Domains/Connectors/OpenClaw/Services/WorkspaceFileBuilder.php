<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Kanvas\Intelligence\Agents\Models\Agent;

class WorkspaceFileBuilder
{
    public static function buildSoulMd(Agent $agent): string
    {
        $soul = $agent->soul;

        if ($soul === null) {
            $background = $agent->role['background'] ?? [];
            $soul = is_array($background) ? implode("\n", $background) : (string) $background;
        }

        $content = "# SOUL\n\n" . $soul;

        if ($agent->output_format !== null) {
            $content .= "\n\n## Output Format\n\n" . $agent->output_format;
        }

        return $content;
    }

    public static function buildAgentsMd(Agent $agent): string
    {
        $instructions = $agent->instructions;

        if ($instructions === null) {
            $steps = $agent->role['steps'] ?? [];
            $instructions = is_array($steps) ? implode("\n", $steps) : (string) $steps;
        }

        return "# AGENTS\n\n" . $instructions;
    }

    public static function buildIdentityMd(Agent $agent): string
    {
        $identity = $agent->identity ?? [];
        $name = $identity['name'] ?? $agent->name;
        $emoji = $identity['emoji'] ?? '';
        $vibe = $identity['vibe'] ?? '';
        $creature = $identity['creature'] ?? 'AI assistant';

        $content = "# IDENTITY\n\n";
        $content .= "**Name:** {$name}\n";
        $content .= "**Creature:** {$creature}\n";
        $content .= "**Vibe:** {$vibe}\n";
        $content .= "**Emoji:** {$emoji}\n";

        if (isset($identity['avatar'])) {
            $content .= "**Avatar:** {$identity['avatar']}\n";
        }

        return $content;
    }

    public static function buildUserMd(Agent $agent): string
    {
        if ($agent->user_context === null) {
            return '';
        }

        return "# USER\n\n" . $agent->user_context;
    }

    public static function buildToolsMd(Agent $agent): string
    {
        if ($agent->tools_config === null) {
            return '';
        }

        return "# TOOLS\n\n" . $agent->tools_config;
    }

    /**
     * @return array<string, string>
     */
    public static function buildAll(Agent $agent): array
    {
        $files = [
            'SOUL.md' => self::buildSoulMd($agent),
            'AGENTS.md' => self::buildAgentsMd($agent),
            'IDENTITY.md' => self::buildIdentityMd($agent),
        ];

        $userMd = self::buildUserMd($agent);
        if ($userMd !== '') {
            $files['USER.md'] = $userMd;
        }

        $toolsMd = self::buildToolsMd($agent);
        if ($toolsMd !== '') {
            $files['TOOLS.md'] = $toolsMd;
        }

        return $files;
    }
}
