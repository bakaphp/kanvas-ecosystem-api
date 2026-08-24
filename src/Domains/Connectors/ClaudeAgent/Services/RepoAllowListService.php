<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * The closed set of repositories an agent may touch.
 *
 * This is the security boundary, and it is deliberately not a prompt instruction: the agent only
 * ever names a **slug**, which is resolved against this list before anything is mounted or cloned.
 * A free-typed repository URL is never accepted from the model. The PAT that pairs with the list is
 * agent-scoped too, so the token and the repos it can reach stay in step.
 */
class RepoAllowListService
{
    /**
     * @param array<array-key, mixed> $repos
     * @return array<int, array<string, mixed>>
     */
    public static function validate(array $repos): array
    {
        if ($repos === []) {
            throw new ValidationException('At least one allowed repository is required');
        }

        $normalized = [];
        $seenSlugs = [];

        foreach ($repos as $repo) {
            if (! is_array($repo)) {
                throw new ValidationException('Each allowed repository must be an object');
            }

            $slug = trim((string) ($repo['slug'] ?? ''));
            $url = trim((string) ($repo['url'] ?? ''));

            if ($slug === '' || $url === '') {
                throw new ValidationException('Each allowed repository requires a slug and a url');
            }

            if (! preg_match('#^https://[^/]+/[^/]+/[^/]+#', $url)) {
                throw new ValidationException("Repository url for \"{$slug}\" must be an https git URL with owner and repo");
            }

            if (isset($seenSlugs[$slug])) {
                throw new ValidationException("Duplicate repository slug \"{$slug}\"");
            }
            $seenSlugs[$slug] = true;

            $entry = ['slug' => $slug, 'url' => $url];

            foreach (['base_branch', 'branch_prefix', 'rules'] as $optional) {
                if (isset($repo[$optional]) && $repo[$optional] !== '') {
                    $entry[$optional] = (string) $repo[$optional];
                }
            }

            if (isset($repo['protected_paths']) && is_array($repo['protected_paths'])) {
                $entry['protected_paths'] = array_values(array_map('strval', $repo['protected_paths']));
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * No company fallback: the token that pairs with this is agent-scoped, so the repos it may
     * touch are too.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forAgent(Agent $agent): array
    {
        $repos = $agent->get(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value);

        if (! is_array($repos)) {
            return [];
        }

        return array_values(array_filter($repos, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(Agent $agent, string $slug): array
    {
        foreach (self::forAgent($agent) as $repo) {
            if (($repo['slug'] ?? null) === $slug) {
                return $repo;
            }
        }

        throw new ValidationException("Repository \"{$slug}\" is not in this agent's allow-list");
    }

    public static function token(Agent $agent): ?string
    {
        return AgentSettingsService::get($agent, CustomFieldEnum::CLAUDE_GITHUB_TOKEN);
    }

    /**
     * Every allowed repo mounts, not one chosen per session — the allow-list *is* the grant, and a
     * conversational agent can't know up front which repo the next question is about.
     *
     * The token never enters the sandbox: Anthropic's git proxy injects it after the request leaves
     * the container. With no token we mount nothing, since a half-configured sandbox where every
     * clone fails is worse than a clear absence.
     *
     * @param list<string> $onlySlugs Restrict to these slugs; empty means the whole list.
     * @return list<array<string, mixed>>
     */
    public static function sessionResources(Agent $agent, array $onlySlugs = []): array
    {
        $token = self::token($agent);

        if ($token === null) {
            return [];
        }

        $resources = [];

        foreach (self::forAgent($agent) as $repo) {
            $slug = (string) ($repo['slug'] ?? '');
            $url = (string) ($repo['url'] ?? '');

            if ($slug === '' || $url === '') {
                continue;
            }

            if ($onlySlugs !== [] && ! in_array($slug, $onlySlugs, true)) {
                continue;
            }

            $resource = [
                'type' => 'github_repository',
                'url' => $url,
                'authorization_token' => $token,
                'mount_path' => '/workspace/' . $slug,
            ];

            $baseBranch = trim((string) ($repo['base_branch'] ?? ''));
            if ($baseBranch !== '') {
                $resource['checkout'] = ['type' => 'branch', 'name' => $baseBranch];
            }

            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * Weakest of the three tiers — the PAT scope and {@see resolve()} are the real boundaries — but
     * it is what tells the agent where each repo is mounted and what it must not touch.
     */
    public static function promptSection(Agent $agent): ?string
    {
        $repos = self::forAgent($agent);

        if ($repos === [] || self::token($agent) === null) {
            return null;
        }

        $lines = ['REPOSITORIES YOU MAY WORK IN:'];

        foreach ($repos as $repo) {
            $slug = (string) ($repo['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $line = "- {$slug} — mounted at /workspace/{$slug}";

            $baseBranch = trim((string) ($repo['base_branch'] ?? ''));
            if ($baseBranch !== '') {
                $line .= ", base branch {$baseBranch}";
            }

            $branchPrefix = trim((string) ($repo['branch_prefix'] ?? ''));
            if ($branchPrefix !== '') {
                $line .= ", branch your work as {$branchPrefix}*";
            }

            $lines[] = $line;

            $rules = trim((string) ($repo['rules'] ?? ''));
            if ($rules !== '') {
                $lines[] = "    rules: {$rules}";
            }

            $protected = $repo['protected_paths'] ?? [];
            if (is_array($protected) && $protected !== []) {
                $lines[] = '    never modify: ' . implode(', ', array_map('strval', $protected));
            }
        }

        $lines[] = 'You may only work in the repositories listed above. Never clone or fetch any other '
            . 'repository, and never ask for or handle credentials — git access is already configured.';

        // Left to itself the model reaches for `gh` or `curl` and gets a 401, because the git proxy
        // authenticates git wire traffic only. Naming the split here is what stops the user having
        // to say "use the github tools" in every request.
        if (AgentSettingsService::vaultId($agent) !== null) {
            $lines[] = 'Cloning, committing, branching and pushing work over plain git. Anything that is a '
                . 'GitHub API call — opening or reviewing a pull request, issues, comments — must go through '
                . 'your `github` tools. `gh` is not installed and curl against api.github.com is '
                . 'unauthenticated, so neither will work.';
        }

        return implode("\n", $lines);
    }
}
