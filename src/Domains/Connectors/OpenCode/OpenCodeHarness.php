<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPermissionRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\PermissionDecisionEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Override;

class OpenCodeHarness implements CodingHarness
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    #[Override]
    public function name(): HarnessEnum
    {
        return HarnessEnum::OPENCODE;
    }

    #[Override]
    public function start(AgentTaskSession $session, HarnessPrompt $prompt): void
    {
        $model = $this->modelRef($session);
        $created = $this->client->createSession($model, $this->directory($session));
        $sessionId = (string) ($created['id'] ?? '');

        if ($sessionId === '') {
            throw new HarnessTransportException('opencode did not return a session id');
        }

        $session->external_session_id = $sessionId;
        $session->status = HarnessStatusEnum::RUNNING->value;
        $session->touchHeartbeat();
        $session->saveOrFail();

        // Belt and braces: the model is passed at creation AND set explicitly, because a session that
        // cannot resolve the configured provider silently falls back to the runtime's own hosted model
        // — which means sending tenant code somewhere nobody chose.
        if ($model !== null) {
            $this->client->setModel($sessionId, $model);
        }

        $this->client->prompt($sessionId, $prompt->toText());
    }

    #[Override]
    public function steer(AgentTaskSession $session, string $message): void
    {
        $this->client->prompt($this->sessionId($session), $message, 'steer');
    }

    #[Override]
    public function ask(AgentTaskSession $session, string $message): void
    {
        $this->client->prompt($this->sessionId($session), $message, 'queue');
    }

    #[Override]
    public function poll(AgentTaskSession $session): HarnessTick
    {
        $sessionId = $this->sessionId($session);

        // Deliberately read from the start, not from the stored cursor: usage and status are computed
        // over the WHOLE conversation, and a page starting mid-way would report a fraction of the
        // tokens as the total. The cursor is carried so the runtime can tell us where the page ended,
        // and `narrationSince` is what avoids repeating lines already posted.
        $page = $this->client->messages($sessionId);
        $permissions = $this->client->pendingPermissions($sessionId);
        $forms = $this->client->pendingForms($sessionId);

        $assistant = $this->ofType($page['messages'], 'assistant');
        $idle = $this->ofType($page['messages'], 'idle');
        $questions = $this->mapForms($forms);

        return new HarnessTick(
            status: $this->resolveStatus($assistant, $idle, $permissions, $questions),
            usage: $this->sumUsage($assistant),
            narration: $this->narrationSince($assistant, $session->last_message_at),
            permissions: $this->mapPermissions($permissions),
            questions: $questions,
            modelsObserved: $this->modelsObserved($assistant),
            cursor: $page['cursor'],
            lastMessageAt: $this->newestMessageAt($assistant),
            error: $this->lastError($idle),
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function ofType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $message): bool => ($message['type'] ?? '') === $type
        ));
    }

    #[Override]
    public function answerPermission(
        AgentTaskSession $session,
        string $permissionId,
        PermissionDecisionEnum $decision
    ): void {
        try {
            $this->client->replyPermission($this->sessionId($session), $permissionId, $decision->value);
        } catch (HarnessTransportException $e) {
            // Answering twice is PermissionNotFoundError. Someone else decided first, which is a race
            // we tolerate rather than an error worth surfacing.
            if (! str_contains($e->getMessage(), 'PermissionNotFound')) {
                throw $e;
            }
        }
    }

    /**
     * `$questionId` is a v2 form id. A single-field form takes the answer directly; a multi-field one
     * cannot be answered from one string, and guessing which field it belongs to would submit the wrong
     * value silently, so it refuses and names the fields instead.
     */
    #[Override]
    public function answerQuestion(AgentTaskSession $session, string $questionId, string $answer): void
    {
        $sessionId = $this->sessionId($session);
        $fields = [];

        foreach ($this->client->pendingForms($sessionId) as $form) {
            if (($form['id'] ?? null) === $questionId && is_array($form['fields'] ?? null)) {
                $fields = $form['fields'];

                break;
            }
        }

        $keys = array_values(array_filter(array_map(
            static fn (mixed $field): ?string => is_array($field) && is_string($field['key'] ?? null)
                ? $field['key']
                : null,
            $fields
        )));

        if (count($keys) !== 1) {
            throw new HarnessTransportException(
                'Form ' . $questionId . ' expects ' . count($keys) . ' fields ('
                . implode(', ', $keys) . '), so it cannot be answered with a single value.'
            );
        }

        $this->client->replyForm($sessionId, $questionId, [$keys[0] => $answer]);
    }

    #[Override]
    public function stop(AgentTaskSession $session): void
    {
        if ($session->external_session_id === null) {
            return;
        }

        $this->client->interrupt($session->external_session_id);
    }

    #[Override]
    public function diff(AgentTaskSession $session): HarnessDiff
    {
        $files = [];

        foreach ($this->client->vcsChanges($this->directory($session)) as $entry) {
            $file = $entry['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            $files[] = [
                'file' => $file,
                'additions' => (int) ($entry['additions'] ?? 0),
                'deletions' => (int) ($entry['deletions'] ?? 0),
                'status' => (string) ($entry['status'] ?? 'modified'),
            ];
        }

        return new HarnessDiff($files);
    }

    #[Override]
    public function patch(AgentTaskSession $session): string
    {
        return $this->client->rawDiff($this->directory($session));
    }

    #[Override]
    public function readFile(AgentTaskSession $session, string $path): string
    {
        return $this->client->readFile($path);
    }

    /**
     * @return array<string, string>|null
     */
    private function modelRef(AgentTaskSession $session): ?array
    {
        if ($session->provider === null || $session->model === null) {
            return null;
        }

        return ['providerID' => $session->provider, 'id' => $session->model];
    }

    /**
     * The directory this session works in, as the CONTAINER sees it.
     *
     * Everything task-scoped hangs off this: which project config resolves, and therefore which
     * provider and permissions apply, and which working tree a diff describes. Without it the runtime
     * falls back to its own working directory and every task on the container shares one tree.
     */
    private function directory(AgentTaskSession $session): ?string
    {
        $directory = $session->session_data_path;

        return $directory === null || $directory === '' ? null : $directory;
    }

    private function sessionId(AgentTaskSession $session): string
    {
        $sessionId = $session->external_session_id;

        if ($sessionId === null || $sessionId === '') {
            throw new HarnessTransportException('Session ' . $session->uuid . ' has no opencode session id');
        }

        return $sessionId;
    }

    /**
     * @param list<array<string, mixed>> $assistant
     * @param list<array<string, mixed>> $idle
     * @param list<array<string, mixed>> $permissions
     * @param list<string>               $questions   already mapped: an unreadable question must not
     *                                                park the session with nothing to answer
     */
    private function resolveStatus(
        array $assistant,
        array $idle,
        array $permissions,
        array $questions
    ): HarnessStatusEnum {
        if ($permissions !== []) {
            return HarnessStatusEnum::AWAITING_PERMISSION;
        }

        if ($questions !== []) {
            return HarnessStatusEnum::AWAITING_ANSWER;
        }

        if ($this->lastError($idle) !== null) {
            return HarnessStatusEnum::FAILED;
        }

        // v2 closes a turn with an explicit `idle` message, which is a far better signal than inferring
        // the end from every assistant message having completed — a turn that is between steps has
        // completed messages and is not finished.
        if ($idle !== []) {
            return HarnessStatusEnum::IDLE;
        }

        if ($assistant === []) {
            return HarnessStatusEnum::STARTING;
        }

        return HarnessStatusEnum::RUNNING;
    }

    /**
     * @param list<array<string, mixed>> $assistant
     */
    private function sumUsage(array $assistant): HarnessUsage
    {
        $usage = new HarnessUsage();

        foreach ($assistant as $message) {
            $tokens = $message['tokens'] ?? null;

            if (! is_array($tokens)) {
                continue;
            }

            $usage = $usage->plus(new HarnessUsage(
                inputTokens: (int) ($tokens['input'] ?? 0),
                outputTokens: (int) ($tokens['output'] ?? 0),
                cacheReadTokens: (int) ($tokens['cache']['read'] ?? 0),
                cacheWriteTokens: (int) ($tokens['cache']['write'] ?? 0),
                reasoningTokens: (int) ($tokens['reasoning'] ?? 0),
            ));
        }

        return $usage;
    }

    /**
     * @param list<array<string, mixed>> $assistant
     * @return list<string>
     */
    private function narrationSince(array $assistant, ?int $lastMessageAt): array
    {
        $ordered = $assistant;
        usort(
            $ordered,
            static fn (array $a, array $b): int => ((int) ($a['time']['created'] ?? 0)) <=> ((int) ($b['time']['created'] ?? 0))
        );

        $narration = [];

        foreach ($ordered as $message) {
            $createdAt = (int) ($message['time']['created'] ?? 0);

            if ($lastMessageAt !== null && $createdAt <= $lastMessageAt) {
                continue;
            }

            $text = $this->textOf($message);

            if ($text !== '') {
                $narration[] = $text;
            }
        }

        return $narration;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function textOf(array $message): string
    {
        $content = $message['content'] ?? [];

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param list<array<string, mixed>> $assistant
     */
    private function newestMessageAt(array $assistant): ?int
    {
        $newest = null;

        foreach ($assistant as $message) {
            $createdAt = (int) ($message['time']['created'] ?? 0);

            if ($createdAt > 0 && ($newest === null || $createdAt > $newest)) {
                $newest = $createdAt;
            }
        }

        return $newest;
    }

    /**
     * @param list<array<string, mixed>> $assistant
     * @return list<string>
     */
    private function modelsObserved(array $assistant): array
    {
        $models = [];

        foreach ($assistant as $message) {
            $model = $message['model']['id'] ?? null;

            if (is_string($model) && ! in_array($model, $models, true)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<HarnessPermissionRequest>
     */
    private function mapPermissions(array $permissions): array
    {
        $mapped = [];

        foreach ($permissions as $permission) {
            $id = $permission['id'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $resources = $permission['resources'] ?? [];

            $mapped[] = new HarnessPermissionRequest(
                id: $id,
                action: (string) ($permission['action'] ?? 'unknown'),
                resources: is_array($resources) ? array_values(array_filter($resources, 'is_string')) : [],
                callId: is_string($permission['source']['callID'] ?? null) ? $permission['source']['callID'] : null,
            );
        }

        return $mapped;
    }

    /**
     * A form is v2's "the agent is blocked on a human". Only the title and field titles are surfaced —
     * enough for a person to see what is being asked and answer it.
     *
     * @param list<array<string, mixed>> $forms
     * @return list<string>
     */
    private function mapForms(array $forms): array
    {
        $mapped = [];

        foreach ($forms as $form) {
            $title = $form['title'] ?? null;

            if (! is_string($title) || $title === '') {
                continue;
            }

            $fields = array_values(array_filter(
                array_map(
                    static fn (mixed $field): ?string => is_array($field) && is_string($field['title'] ?? null)
                        ? $field['title']
                        : null,
                    is_array($form['fields'] ?? null) ? $form['fields'] : []
                )
            ));

            $mapped[] = $fields === [] ? $title : $title . ' (' . implode(', ', $fields) . ')';
        }

        return $mapped;
    }

    /**
     * v2 ends every turn with an `idle` message carrying its outcome, so a failure is a fact on the
     * conversation rather than something inferred.
     *
     * It is still not the whole story: a provider that cannot be resolved never reaches the API at all
     * and appears only in the container log. Absence of an error here is not evidence of health — the
     * poller's wall clock is what catches the rest.
     *
     * @param list<array<string, mixed>> $idle
     */
    private function lastError(array $idle): ?string
    {
        $error = null;

        foreach ($idle as $message) {
            $outcome = $message['outcome'] ?? null;

            if (! is_string($outcome) || $outcome === 'succeeded') {
                continue;
            }

            $error = is_string($message['error']['message'] ?? null)
                ? $message['error']['message']
                : 'the run ended as "' . $outcome . '"';
        }

        return $error;
    }
}
