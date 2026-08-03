<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Services;

/**
 * Assembles the pi.dev `systemPrompt`, immutable policy first. pi.dev *replaces* its default
 * instructions with whatever we send, so this is guidance, not enforcement — the real boundary is
 * the fine-grained GitHub token scope + branch protection (see connector plan, tier 1). Order:
 * locked policy, then per-repo rules of engagement, then the agent's own persona.
 */
class PromptBuilder
{
    private const string LOCKED_POLICY = <<<'TXT'
        You are an autonomous coding agent operating under strict rules of engagement:
        - Work on a NEW branch off the configured base branch. Never commit directly to the base branch.
        - Open a pull request for review. Never merge it yourself. Never force-push or rewrite history.
        - Touch only files relevant to the task. No unrelated refactors, formatting sweeps, or dependency bumps.
        - Never modify CI/CD workflows, deploy configs, .env files, or secrets unless the task explicitly asks for it.
        - Never print, log, or exfiltrate secrets, tokens, or credentials.
        - If the task is ambiguous or would be destructive, STOP and report what you found instead of guessing.
        - Obey the repository's own CLAUDE.md / CONTRIBUTING.md conventions where they exist.
        TXT;

    /**
     * @param array<string, mixed> $repo A resolved allow-list entry.
     */
    public static function build(array $repo, ?string $agentPersona = null): string
    {
        $sections = [self::LOCKED_POLICY];

        $repoRules = self::repoRules($repo);
        if ($repoRules !== '') {
            $sections[] = $repoRules;
        }

        if ($agentPersona !== null && trim($agentPersona) !== '') {
            $sections[] = "Your persona and additional instructions:\n" . trim($agentPersona);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param array<string, mixed> $repo
     */
    private static function repoRules(array $repo): string
    {
        $lines = [];

        if (isset($repo['base_branch']) && $repo['base_branch'] !== '') {
            $lines[] = 'Base branch: ' . (string) $repo['base_branch'];
        }

        if (isset($repo['branch_prefix']) && $repo['branch_prefix'] !== '') {
            $lines[] = 'Name your working branch with the prefix: ' . (string) $repo['branch_prefix'];
        }

        if (isset($repo['protected_paths']) && is_array($repo['protected_paths']) && $repo['protected_paths'] !== []) {
            $lines[] = 'Do NOT modify these paths unless the task explicitly names them: '
                . implode(', ', array_map('strval', $repo['protected_paths']));
        }

        if (isset($repo['rules']) && $repo['rules'] !== '') {
            $lines[] = (string) $repo['rules'];
        }

        return $lines === [] ? '' : "Rules of engagement for this repository:\n- " . implode("\n- ", $lines);
    }
}
