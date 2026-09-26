<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Support\Str;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Github\Client as GitHubClient;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Everything the coding agents need from GitHub that git itself cannot answer: pull requests, review
 * comments, check results, and reading a repository before any job exists.
 *
 * Scoped to one repository and authenticated with **one agent's** token, so the caller cannot reach
 * past what that agent may touch. `Connectors\Github\Client` is the app-wide client for releases and
 * webhooks; this is the per-agent one, and it borrows that client's URL normalising rather than
 * carrying a second copy.
 *
 * **Failures are soft, by design.** Everything here happens after a push has already landed, so the
 * work is safe: a missing `Pull requests: write` permission or an unreachable API is worth reporting
 * and never worth turning a successful push into a failure.
 */
class GitHubRepositoryService
{
    public function __construct(
        private readonly string $token,
        private readonly string $remoteUrl,
    ) {
    }

    /**
     * @return array{url: string, number: int}|array{error: string}|null null when this is not GitHub
     */
    public function openFor(string $branch, string $baseBranch, string $title, string $body): ?array
    {
        $repo = $this->repo();

        if ($repo === null) {
            return null;
        }

        // A branch cannot be a pull request against itself. The case is real: the first push into an
        // empty repository becomes its default branch, so the next job's base IS this branch.
        if (strcasecmp($branch, $baseBranch) === 0) {
            return ['error' => 'the branch is the repository\'s base branch, so there is nothing to merge into'];
        }

        $existing = $this->existingFor($repo, $branch);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(20)
                ->post('https://api.github.com/repos/' . $repo . '/pulls', [
                    'title' => Str::limit($title, 72),
                    'head' => $branch,
                    'base' => $baseBranch,
                    'body' => $body,
                    'draft' => true,
                ]);
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['error' => (string) ($response->json('message') ?? 'HTTP ' . $response->status())];
        }

        return [
            'url' => (string) $response->json('html_url'),
            'number' => (int) $response->json('number'),
        ];
    }

    /**
     * A retried task pushes to the same branch, and GitHub answers a duplicate with a 422 that reads
     * like a failure. Finding the open one first makes a retry idempotent.
     *
     * @return array{url: string, number: int}|null
     */
    private function existingFor(string $repo, string $branch): ?array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(20)
                ->get('https://api.github.com/repos/' . $repo . '/pulls', [
                    'head' => explode('/', $repo)[0] . ':' . $branch,
                    'state' => 'open',
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $first = $response->successful() ? ($response->json()[0] ?? null) : null;

        return is_array($first)
            ? ['url' => (string) $first['html_url'], 'number' => (int) $first['number']]
            : null;
    }

    /**
     * What a reviewer said, and whether the checks passed.
     *
     * Both halves matter: review comments are the instruction, CI is the fact. An agent given only the
     * comments will happily "address the feedback" on a branch whose tests are red and report success.
     *
     * @return array{state: string, checks: string, behind_base: int, failed_checks: list<array{name: string, conclusion: string, summary: string}>, comments: list<array{author: string, body: string, file: string|null}>}|null
     */
    public function feedbackFor(int $number): ?array
    {
        $repo = $this->repo();

        if ($repo === null) {
            return null;
        }

        $pr = $this->get('/repos/' . $repo . '/pulls/' . $number);

        if ($pr === null) {
            return null;
        }

        $sha = (string) ($pr['head']['sha'] ?? '');
        $checks = $sha === '' ? null : $this->get('/repos/' . $repo . '/commits/' . $sha . '/status');

        return [
            'state' => (string) ($pr['merged_at'] ?? null) !== '' && $pr['merged_at'] !== null
                ? 'merged'
                : (string) ($pr['state'] ?? 'unknown'),
            'checks' => (string) ($checks['state'] ?? 'unknown'),
            'behind_base' => (int) ($pr['behind_by'] ?? 0),
            // Named and quoted, because "failure" on its own cannot be acted on.
            'failed_checks' => $this->failedChecks($sha),
            'comments' => [
                // Review comments hang off the diff; issue comments are the conversation. A reviewer
                // uses whichever is to hand, so reading one and not the other loses half the feedback.
                ...$this->commentsFrom('/repos/' . $repo . '/pulls/' . $number . '/comments'),
                ...$this->commentsFrom('/repos/' . $repo . '/issues/' . $number . '/comments'),
            ],
        ];
    }

    /**
     * @return list<array{author: string, body: string, file: string|null}>
     */
    private function commentsFrom(string $path): array
    {
        $rows = $this->get($path . '?per_page=100');
        $comments = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['body'] ?? null)) {
                continue;
            }

            $comments[] = [
                'author' => (string) ($row['user']['login'] ?? 'unknown'),
                'body' => $row['body'],
                'file' => is_string($row['path'] ?? null) ? $row['path'] : null,
            ];
        }

        return $comments;
    }

    /**
     * Searches file CONTENT, not names.
     *
     * Listing paths answers "is there a file called this"; almost every real question is "where is this
     * handled", which a filename cannot answer. Without it the agent guesses a path, reads the wrong
     * file, and writes a brief against code that does not exist.
     *
     * @return list<array{file: string, url: string}>|null
     */
    public function searchCode(string $query): ?array
    {
        $repo = $this->repo();

        if ($repo === null) {
            return null;
        }

        $found = $this->get('/search/code?q=' . urlencode($query . ' repo:' . $repo) . '&per_page=30');

        if (! is_array($found) || ! is_array($found['items'] ?? null)) {
            return null;
        }

        $hits = [];

        foreach ($found['items'] as $item) {
            if (is_array($item) && is_string($item['path'] ?? null)) {
                $hits[] = ['file' => $item['path'], 'url' => (string) ($item['html_url'] ?? '')];
            }
        }

        return $hits;
    }

    /**
     * The checks that FAILED, with whatever they said about it.
     *
     * A bare "failure" is not actionable — the agent can see the build is red and has no way to learn
     * why, so "fix the failing test" is impossible. The summary is usually the assertion message.
     *
     * @return list<array{name: string, conclusion: string, summary: string}>
     */
    public function failedChecks(string $sha): array
    {
        $repo = $this->repo();

        if ($repo === null || $sha === '') {
            return [];
        }

        $runs = $this->get('/repos/' . $repo . '/commits/' . $sha . '/check-runs?per_page=50');
        $failed = [];

        foreach (is_array($runs['check_runs'] ?? null) ? $runs['check_runs'] : [] as $run) {
            $conclusion = is_array($run) ? (string) ($run['conclusion'] ?? '') : '';

            if (! in_array($conclusion, ['failure', 'timed_out', 'cancelled', 'action_required'], true)) {
                continue;
            }

            $failed[] = [
                'name' => (string) ($run['name'] ?? 'unnamed check'),
                'conclusion' => $conclusion,
                'summary' => mb_substr(trim(
                    (string) ($run['output']['title'] ?? '') . "\n" . (string) ($run['output']['summary'] ?? '')
                ), 0, 2000),
            ];
        }

        return $failed;
    }

    /**
     * Merges the base branch into the pull request's branch.
     *
     * A pull request left open while the trunk moves goes behind and eventually conflicts, and nothing
     * else here can refresh it — so a branch that collected review feedback becomes unmergeable and the
     * only way out is a human doing it by hand.
     *
     * @return array{updated: bool, error?: string}
     */
    public function syncBranch(int $number): array
    {
        $repo = $this->repo();

        if ($repo === null) {
            return ['updated' => false, 'error' => 'not a GitHub repository'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(30)
                ->put('https://api.github.com/repos/' . $repo . '/pulls/' . $number . '/update-branch', []);
        } catch (Throwable $e) {
            report($e);

            return ['updated' => false, 'error' => $e->getMessage()];
        }

        // 422 is what GitHub answers when the branch is already current, which is not a failure.
        if ($response->status() === 422) {
            return ['updated' => false, 'error' => 'already up to date, or the branch conflicts and needs a human'];
        }

        return $response->successful()
            ? ['updated' => true]
            : ['updated' => false, 'error' => (string) ($response->json('message') ?? 'HTTP ' . $response->status())];
    }

    /**
     * What is already in flight, so a second job is not started over the top of the first.
     *
     * @return list<array{number: int, title: string, branch: string, url: string, draft: bool}>
     */
    public function openPullRequests(): array
    {
        $repo = $this->repo();
        $rows = $repo === null ? null : $this->get('/repos/' . $repo . '/pulls?state=open&per_page=50');
        $open = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $open[] = [
                'number' => (int) ($row['number'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'branch' => (string) ($row['head']['ref'] ?? ''),
                'url' => (string) ($row['html_url'] ?? ''),
                'draft' => (bool) ($row['draft'] ?? false),
            ];
        }

        return $open;
    }

    /**
     * Answers the reviewer in their own thread, so the conversation stays where the review is rather
     * than in a chat window they cannot see.
     */
    public function comment(int $number, string $body): bool
    {
        $repo = $this->repo();

        if ($repo === null) {
            return false;
        }

        try {
            return Http::withToken($this->token)
                ->timeout(20)
                ->post('https://api.github.com/repos/' . $repo . '/issues/' . $number . '/comments', [
                    'body' => $body,
                ])
                ->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * One file at a ref, so a brief can be written against what the code actually says.
     *
     * Reads from GitHub rather than a container, because the useful moment is BEFORE a job exists —
     * there is no workspace to look in yet.
     */
    public function readFile(string $path, ?string $ref = null): ?string
    {
        $repo = $this->repo();

        if ($repo === null) {
            return null;
        }

        $query = $ref === null ? '' : '?ref=' . urlencode($ref);
        $file = $this->get('/repos/' . $repo . '/contents/' . ltrim($path, '/') . $query);

        if (! is_array($file) || ($file['type'] ?? null) !== 'file' || ! is_string($file['content'] ?? null)) {
            return null;
        }

        return (string) base64_decode(str_replace("\n", '', $file['content']), true);
    }

    /**
     * Every path at a ref. A flat list beats a directory walk here: the agent is orienting itself, and
     * one call it can scan is cheaper than a conversation of them.
     *
     * @return list<string>|null
     */
    public function listFiles(?string $ref = null): ?array
    {
        $repo = $this->repo();

        if ($repo === null) {
            return null;
        }

        $tree = $this->get('/repos/' . $repo . '/git/trees/' . urlencode($ref ?? 'HEAD') . '?recursive=1');

        if (! is_array($tree) || ! is_array($tree['tree'] ?? null)) {
            return null;
        }

        $paths = [];

        foreach ($tree['tree'] as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null)) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function get(string $path): ?array
    {
        try {
            $response = Http::withToken($this->token)->timeout(20)->get('https://api.github.com' . $path);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /**
     * `owner/name`, via the connector that already solves this.
     *
     * A local regex anchored at the end of the URL rejected every address-bar paste — `/tree/main`,
     * `/pull/3`, `?tab=readme` — which is precisely the form people have in hand.
     */
    private function repo(): ?string
    {
        try {
            return GitHubClient::normalizeRepository($this->remoteUrl);
        } catch (ValidationException $e) {
            return null;
        }
    }
}
