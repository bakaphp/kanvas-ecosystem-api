<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Services;

use Kanvas\Connectors\PiDev\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

class RepoAllowListService
{
    /**
     * Normalize and validate an allow-list before it is stored on an agent. Each entry needs a
     * unique slug and an https git URL with host/owner/repo; optional rules-of-engagement fields
     * pass through untouched.
     *
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

            $entry = [
                'slug' => $slug,
                'url' => $url,
            ];

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
     * The agent's configured allow-list (agent custom field, no company fallback — the token that
     * pairs with it is agent-scoped, so the repos it may touch are too).
     *
     * @return array<array-key, mixed>
     */
    public static function forAgent(Agent $agent): array
    {
        $repos = $agent->get(CustomFieldEnum::PIDEV_ALLOWED_REPOS->value);

        return is_array($repos) ? $repos : [];
    }

    /**
     * Resolve an LLM-supplied slug to its concrete allow-list entry, or throw.
     *
     * @return array<string, mixed>
     */
    public static function resolve(Agent $agent, string $slug): array
    {
        foreach (self::forAgent($agent) as $repo) {
            if (is_array($repo) && ($repo['slug'] ?? null) === $slug) {
                /** @var array<string, mixed> $repo */
                return $repo;
            }
        }

        throw new ValidationException("Repository \"{$slug}\" is not in this agent's allow-list");
    }
}
