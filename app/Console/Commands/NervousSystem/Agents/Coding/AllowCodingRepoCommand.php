<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Github\Client as GitHubClient;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Adds, removes and shows the repositories one agent is allowed to work on.
 *
 * The allow-list is the boundary: an agent can only ever clone and push to something on it, so adding
 * an entry is a privilege grant and deliberately NOT something the agent can do for itself. This is how
 * a human does it, instead of hand-editing JSON in a custom field and finding out it was malformed when
 * a job fails hours later.
 */
class AllowCodingRepoCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:allow-repo
        {--agent= : Agent id}
        {--url= : Clone URL, or owner/name for GitHub}
        {--slug= : Short name the agent uses; derived from the URL when omitted}
        {--base-branch= : Branch to work from; read from GitHub when omitted, else main}
        {--branch-prefix=agent/ : Prefix for the branch each task pushes}
        {--protected=* : A path the agent may never change, repeatable}
        {--rules= : Repository-specific instructions given to every session}
        {--remove : Remove the repository named by --slug or --url}
        {--list : Show the list and change nothing}';

    protected $description = 'Manage the repositories a coding agent is allowed to work on.';

    public function handle(): int
    {
        /** @var Agent|null $agent */
        $agent = Agent::query()->where('id', (int) $this->option('agent'))->first();

        if ($agent === null) {
            $this->error('Pass --agent with a valid agent id.');

            return self::FAILURE;
        }

        $this->overwriteAppService($agent->app);

        if ($this->option('list')) {
            return $this->show($agent);
        }

        return $this->option('remove') ? $this->remove($agent) : $this->add($agent);
    }

    private function add(Agent $agent): int
    {
        $url = $this->normaliseUrl((string) $this->option('url'));

        if ($url === null) {
            $this->error('Pass --url with a clone URL or owner/name.');

            return self::FAILURE;
        }

        $slug = Str::trimToNull((string) $this->option('slug')) ?? $this->slugFrom($url);
        $reachable = $this->checkReachable($agent, $url);

        if ($reachable === false) {
            // Refusing rather than warning: an entry the token cannot reach produces a clone failure
            // minutes later inside a queued job, which is a far worse place to discover a typo.
            $this->error('The agent\'s git token cannot reach ' . $url . '.');
            $this->line('  Either the repository does not exist, or the token does not cover it.');
            $this->line('  Fix one of those, or pass --slug to add it anyway once you are sure.');

            return self::FAILURE;
        }

        $entries = $this->entries($agent);
        $entries = array_values(array_filter(
            $entries,
            static fn (array $e): bool => strcasecmp((string) ($e['slug'] ?? ''), $slug) !== 0
        ));

        $entries[] = array_filter([
            'slug' => $slug,
            'url' => $url,
            'base_branch' => Str::trimToNull((string) $this->option('base-branch'))
                ?? $this->defaultBranch($agent, $url)
                ?? 'main',
            'branch_prefix' => (string) $this->option('branch-prefix'),
            'rules' => Str::trimToNull((string) $this->option('rules')),
            'protected_paths' => array_values(array_filter((array) $this->option('protected'))),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);

        $agent->set(AgentCustomFieldEnum::ALLOWED_REPOS->value, json_encode($entries));

        $this->info('Added "' . $slug . '" to ' . $agent->name . '.');

        return $this->show($agent);
    }

    private function remove(Agent $agent): int
    {
        $needle = Str::trimToNull((string) $this->option('slug'))
            ?? Str::trimToNull((string) $this->option('url'));

        if ($needle === null) {
            $this->error('Pass --slug or --url to say what to remove.');

            return self::FAILURE;
        }

        $target = new RepoAllowListService($agent)->resolve($needle);

        if ($target === null) {
            $this->error('"' . $needle . '" is not on this agent\'s list.');

            return self::FAILURE;
        }

        $entries = array_values(array_filter(
            $this->entries($agent),
            static fn (array $e): bool => strcasecmp((string) ($e['slug'] ?? ''), $target->slug) !== 0
        ));

        $agent->set(AgentCustomFieldEnum::ALLOWED_REPOS->value, json_encode($entries));

        $this->info('Removed "' . $target->slug . '" from ' . $agent->name . '.');

        return $this->show($agent);
    }

    private function show(Agent $agent): int
    {
        $repositories = new RepoAllowListService($agent)->all();

        $this->newLine();
        $this->line('<info>' . $agent->name . '</info> may work on:');

        if ($repositories === []) {
            $this->warn('  nothing — every coding task will be refused');

            return self::SUCCESS;
        }

        foreach ($repositories as $repository) {
            $this->line('  <comment>' . $repository->slug . '</comment>  ' . $repository->cloneUrl);
            $this->line('     base ' . $repository->baseBranch . ' · branches ' . $repository->branchPrefix . '{task}'
                . ($repository->protectedPaths === []
                    ? ''
                    : ' · protected ' . implode(', ', $repository->protectedPaths)));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(Agent $agent): array
    {
        $raw = $agent->get(AgentCustomFieldEnum::ALLOWED_REPOS->value);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /**
     * Accepts `owner/name` as shorthand for GitHub, because that is how people refer to a repository.
     */
    private function normaliseUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $url) === 1) {
            return 'https://github.com/' . $url . '.git';
        }

        return $url;
    }

    private function slugFrom(string $url): string
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);

        return Str::slug(preg_replace('/\.git$/', '', $name) ?? $name);
    }

    /**
     * @return bool|null null when we cannot tell — a non-GitHub remote, or no token to ask with
     */
    private function checkReachable(Agent $agent, string $url): ?bool
    {
        $repo = $this->gitHubRepo($url);
        $token = Str::trimToNull((string) $agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($repo === null || $token === null) {
            return null;
        }

        try {
            // Fixed host, so the URL is not attacker-steerable; only the path segment comes from input.
            return Http::withToken($token)
                ->timeout(15)
                ->get('https://api.github.com/repos/' . $repo)
                ->successful();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function defaultBranch(Agent $agent, string $url): ?string
    {
        $repo = $this->gitHubRepo($url);
        $token = Str::trimToNull((string) $agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($repo === null || $token === null) {
            return null;
        }

        try {
            $branch = Http::withToken($token)
                ->timeout(15)
                ->get('https://api.github.com/repos/' . $repo)
                ->json('default_branch');

            return is_string($branch) ? $branch : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function gitHubRepo(string $url): ?string
    {
        try {
            return GitHubClient::normalizeRepository($url);
        } catch (ValidationException $e) {
            return null;
        }
    }
}
