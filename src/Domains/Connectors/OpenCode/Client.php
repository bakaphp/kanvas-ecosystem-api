<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;

/**
 * Thin typed wrapper over the opencode server API, verified against **2.0.16** via `GET /openapi.json`.
 *
 * v2 is not a compatible successor to 1.18.x and this client will not work against it. What moved:
 *
 * - A session is pinned to a directory at creation (`location.directory`). That single field is why
 *   one container can serve many tasks — each session resolves its own project config, and therefore
 *   its own provider and permissions, from its own worktree. 1.18.x had no equivalent.
 * - The prompt body is flat `{text}`; it was `{prompt: {text}}`.
 * - `/session/{id}/history`, the durable seq-numbered log, is **gone**. `/message` replaces it with
 *   cursor pagination, so resumable polling carries an opaque cursor instead of an integer.
 * - `/vcs/*` moved under `/api` and is now scoped by `location`, so a diff belongs to one task rather
 *   than to the whole container.
 * - Permission replies are `{decision}` at `/permission/{id}/reply`; there is no `/question` surface.
 *
 * Trust `/openapi.json`, not the docs site — `/doc` now serves the web UI.
 */
class Client
{
    public function __construct(
        private readonly HarnessTransport $transport,
    ) {
    }

    /**
     * @param array<string, string>|null $model providerID + id; the server otherwise picks its own
     *                                          default, which is a hosted model we did not choose
     * @return array<string, mixed>
     */
    public function createSession(?array $model = null, ?string $directory = null): array
    {
        $body = [];

        if ($model !== null) {
            $body['model'] = $model;
        }

        // Without this the session runs in the server's own working directory, silently sharing one
        // tree with every other task on the container — and reading that tree's config, not the
        // task's. It is the whole basis of per-task isolation.
        if ($directory !== null) {
            $body['location'] = ['directory' => $directory];
        }

        return $this->data($this->transport->request('POST', '/api/session', $body));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(string $sessionId): array
    {
        return $this->data($this->transport->request('GET', '/api/session/' . $sessionId));
    }

    /**
     * Returns as soon as the prompt is admitted — the turn runs on. `delivery` is absent for the first
     * prompt, "steer" to join the turn in flight, "queue" to wait for it.
     *
     * @return array<string, mixed>
     */
    public function prompt(string $sessionId, string $text, ?string $delivery = null): array
    {
        $body = ['text' => $text];

        if ($delivery !== null) {
            $body['delivery'] = $delivery;
        }

        return $this->data($this->transport->request('POST', '/api/session/' . $sessionId . '/prompt', $body, 60));
    }

    /**
     * @param array<string, string> $model
     * @return array<string, mixed>
     */
    public function setModel(string $sessionId, array $model): array
    {
        return $this->data(
            $this->transport->request('POST', '/api/session/' . $sessionId . '/model', ['model' => $model])
        );
    }

    /**
     * One page of the conversation, oldest first, resumable from `$cursor`.
     *
     * This is what the poller reads instead of the seq-numbered log v1 had. The cursor is opaque and
     * belongs to the server; store it, do not parse it.
     *
     * @return array{messages: list<array<string, mixed>>, cursor: string|null}
     */
    public function messages(string $sessionId, ?string $cursor = null, int $limit = 100): array
    {
        $query = ['order' => 'asc', 'limit' => $limit];

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $response = $this->transport->request(
            'GET',
            '/api/session/' . $sessionId . '/message?' . http_build_query($query)
        );

        $messages = $this->list($response);
        $next = $response['cursor']['next'] ?? null;

        return [
            'messages' => $messages,
            // A page that returned nothing must not advance the cursor, or a quiet tick would skip
            // whatever lands next.
            'cursor' => $messages !== [] && is_string($next) ? $next : $cursor,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingPermissions(string $sessionId): array
    {
        return $this->list($this->transport->request('GET', '/api/session/' . $sessionId . '/permission'));
    }

    /**
     * `$decision` is once|always|reject — the server rejects "allow". Answering twice returns
     * PermissionNotFoundError, which callers treat as already-decided rather than an error.
     */
    public function replyPermission(string $sessionId, string $permissionId, string $decision): void
    {
        $this->transport->request(
            'POST',
            '/api/session/' . $sessionId . '/permission/' . $permissionId . '/reply',
            ['decision' => $decision]
        );
    }

    /**
     * v2 replaced free-text questions with typed forms. They are still "the agent is blocked waiting
     * for a human", which is what the harness cares about.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForms(string $sessionId): array
    {
        return $this->list($this->transport->request('GET', '/api/session/' . $sessionId . '/form'));
    }

    /**
     * @param array<string, mixed> $answer keyed by field
     */
    public function replyForm(string $sessionId, string $formId, array $answer): void
    {
        $this->transport->request(
            'POST',
            '/api/session/' . $sessionId . '/form/' . $formId . '/reply',
            ['answer' => $answer]
        );
    }

    public function interrupt(string $sessionId): void
    {
        $this->transport->request('POST', '/api/session/' . $sessionId . '/interrupt', []);
    }

    /**
     * Everything this task changed, committed or not.
     *
     * `working` alone was wrong the moment a push committed the work: the tree goes clean and the diff
     * reported "no changes" for a job that had just pushed files, which reads as the agent having done
     * nothing. `branch` is the honest question — what does this branch have that its base does not —
     * and `working` still catches anything not yet committed.
     *
     * @return list<array<string, mixed>>
     */
    public function vcsChanges(string $directory): array
    {
        $byFile = [];

        foreach (['branch', 'working'] as $mode) {
            foreach ($this->list($this->vcsDiff($directory, $mode)) as $entry) {
                $file = $entry['file'] ?? null;

                if (is_string($file)) {
                    $byFile[$file] ??= $entry;
                }
            }
        }

        return array_values($byFile);
    }

    /**
     * The unified diff of everything this task changed.
     *
     * This is the evidence a human reviews and the only honest answer to "show me what changed" — the
     * agent's own account of its work is a claim, this is the artefact.
     */
    public function rawDiff(string $directory): string
    {
        $patches = [];

        foreach ($this->vcsChanges($directory) as $entry) {
            if (is_string($entry['patch'] ?? null)) {
                $patches[] = $entry['patch'];
            }
        }

        return implode("\n", $patches);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function vcsDiff(string $directory, string $mode): array
    {
        return $this->transport->request(
            'GET',
            '/api/vcs/diff?' . $this->locationQuery($directory) . '&mode=' . urlencode($mode)
        );
    }

    public function readFile(string $path): string
    {
        $response = $this->transport->request('GET', '/api/fs/read/' . ltrim($path, '/'));

        return is_string($response['content'] ?? null) ? $response['content'] : '';
    }

    public function health(): bool
    {
        // v2 has no /health; /info answers only once the server is actually serving.
        $response = $this->transport->request('GET', '/api/info', null, 10);

        return is_string($response['version'] ?? null);
    }

    private function locationQuery(string $directory): string
    {
        return http_build_query(['location' => ['directory' => $directory]]);
    }

    /**
     * @param array<string, mixed>|list<mixed> $response
     * @return array<string, mixed>
     */
    private function data(array $response): array
    {
        $data = $response['data'] ?? $response;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $response
     * @return list<array<string, mixed>>
     */
    private function list(array $response): array
    {
        $data = $response['data'] ?? $response;

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }
}
