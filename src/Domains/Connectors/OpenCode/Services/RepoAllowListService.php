<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Support\Str;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Github\Client as GitHubClient;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Turns whatever someone called a repository into one the agent can actually work on.
 *
 * **The agent's reach is its token's reach.** The permission lives in the git token, where GitHub can
 * enforce it, rather than in a list here that would only ever be a second, staler copy of the same
 * decision. Scope the token and you have scoped the agent.
 *
 * The configured list is per-repository *settings* — base branch, rules, protected paths — for
 * repositories someone has thought about. Missing from it is not a refusal.
 *
 * What does not move: the container never holds the credential. Git runs on the host, so an agent that
 * is talked into wanting a different repository still has nothing to do it with.
 */
class RepoAllowListService
{
    /**
     * Applied to a repository nobody configured. Not a policy decision so much as the absence of one:
     * these are the paths where a wrong change is expensive and a right one is rarely urgent.
     *
     * @var list<string>
     */
    private const array DEFAULT_PROTECTED_PATHS = ['.github/', '.env', '.circleci/', 'Jenkinsfile'];

    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * @return list<CodingRepository>
     */
    public function all(): array
    {
        $raw = $this->agent->get(AgentCustomFieldEnum::ALLOWED_REPOS->value);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $repositories = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $repository = CodingRepository::fromAllowListEntry($entry);

            if ($repository !== null) {
                $repositories[] = $repository;
            }
        }

        return $repositories;
    }

    /**
     * Finds the CONFIGURED entry for a repository, however it was written: slug, clone URL, or
     * `owner/name`. Returns null for one nobody configured — see `resolveOrFail`, which then asks the
     * token.
     */
    public function resolve(string $identifier): ?CodingRepository
    {
        $needle = $this->normalise($identifier);

        if ($needle === '') {
            return null;
        }

        // A GitHub URL reduces to owner/name first, so a link copied while viewing a file or a pull
        // request still matches the plain clone URL on the list.
        $repo = $this->gitHubRepo($identifier);

        foreach ($this->all() as $repository) {
            if (strcasecmp($repository->slug, trim($identifier)) === 0) {
                return $repository;
            }

            $url = $this->normalise($repository->cloneUrl);

            // `owner/name` also matches, so a person can paste the half of the URL they remember.
            if ($url === $needle || str_ends_with($url, '/' . $needle)) {
                return $repository;
            }

            if ($repo !== null && $url === $this->normalise('github.com/' . $repo)) {
                return $repository;
            }
        }

        return null;
    }

    /**
     * Reduces the ways of writing one repository to a single comparable form: scheme, credentials,
     * `.git`, trailing slashes and case all vary between what GitHub shows, what SSH uses and what
     * someone pastes from the address bar.
     */
    private function normalise(string $value): string
    {
        $value = trim($value);

        // `git@github.com:owner/name.git` — the one common form that is not a URL.
        $value = preg_replace('#^[^@/]+@([^:]+):#', '$1/', $value) ?? $value;
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value) ?? $value;
        // Credentials embedded in a URL are not part of its identity.
        $value = preg_replace('#^[^/@]*@#', '', $value) ?? $value;

        $value = mb_strtolower(rtrim($value, '/'));

        if (str_ends_with($value, '.git')) {
            $value = mb_substr($value, 0, -4);
        }

        return $value;
    }

    /**
     * The agent's reach is its **token's** reach. This resolves anything that token can actually get to.
     *
     * The configured list is settings, not a gate: an entry there supplies the base branch, the rules
     * and the protected paths for a repository someone has thought about. A repository not on it still
     * works, on defaults, as long as the token opens it — because a person who pastes a URL has already
     * decided, and asking them to write it down again teaches nothing and blocks the obvious case.
     *
     * The refusal that remains is the honest one: the token cannot open it. That is a fact about access,
     * not a policy we invented, and it is the same answer they would get from `git clone`.
     */
    public function resolveOrFail(string $identifier): CodingRepository
    {
        $repository = $this->resolve($identifier) ?? $this->discover($identifier);

        if ($repository === null) {
            throw new ValidationException(
                'Cannot reach "' . $identifier . '" with this agent\'s git token — it does not exist, is '
                . 'not covered by the token, or is not a git URL. Nothing was started.'
            );
        }

        return $repository;
    }

    /**
     * Builds a repository from what GitHub says about it, for a URL nobody configured.
     *
     * Defaults are deliberately conservative where it matters: the base branch is whatever the
     * repository actually uses rather than an assumed `main`, and the protected paths are applied even
     * though no one asked for them — CI config and secrets are not things an agent should be changing
     * on a repository that has had no thought put into it.
     */
    private function discover(string $identifier): ?CodingRepository
    {
        $repo = $this->gitHubRepo($identifier);
        $token = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($repo === null || $token === null) {
            return null;
        }

        try {
            // Fixed host; only the path comes from input, and a miss is a refusal rather than a fetch.
            $response = Http::withToken($token)
                ->timeout(15)
                ->get('https://api.github.com/repos/' . $repo);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return new CodingRepository(
            slug: (string) ($response->json('name') ?? basename($repo)),
            cloneUrl: (string) ($response->json('clone_url') ?? 'https://github.com/' . $repo . '.git'),
            baseBranch: (string) ($response->json('default_branch') ?? 'main'),
            protectedPaths: self::DEFAULT_PROTECTED_PATHS,
        );
    }

    /**
     * `owner/name`, via the connector that already solves this — including the address-bar forms a
     * regex anchored at the end of the URL silently rejects.
     */
    private function gitHubRepo(string $identifier): ?string
    {
        try {
            return GitHubClient::normalizeRepository($identifier);
        } catch (ValidationException $e) {
            return null;
        }
    }
}
