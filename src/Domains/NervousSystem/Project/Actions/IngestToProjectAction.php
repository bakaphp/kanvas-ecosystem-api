<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectIngest;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The single ingest funnel for a project's inbound context — transcript, email, or @mention. Each
 * lands as a typed message on the project's default channel (so it joins the unified feed the agent
 * reads), emits a `project.<type>.received` ledger event, and wakes the PM agent to act on it.
 *
 * Idempotent by content: re-submitting the SAME content to a project within DEDUP_WINDOW_HOURS does
 * NOT create a second message or wake the PM again — it returns the original message and flags the
 * call as a duplicate. Without this, re-POSTing a transcript spawns a fresh PM run that can produce
 * near-duplicate plans/tasks (the tool-level dedup only catches byte-identical titles).
 */
class IngestToProjectAction
{
    private const int DEDUP_WINDOW_HOURS = 24;

    private bool $wasDuplicate = false;

    /**
     * @param array<int, array<string, mixed>> $files
     */
    public function __construct(
        private readonly Project $project,
        private readonly ProjectIngestTypeEnum $type,
        private readonly string $content,
        private readonly ?Users $author = null,
        private readonly array $files = [],
        private readonly bool $wakeAgent = true,
    ) {
    }

    /**
     * True when execute() short-circuited on a duplicate submission (no new message, no wake).
     */
    public function wasDuplicate(): bool
    {
        return $this->wasDuplicate;
    }

    public function execute(): Message
    {
        $hash = sha1($this->type->value . '|' . trim($this->content));
        $cacheKey = 'ns:project:' . $this->project->getId() . ':ingest:' . $hash;

        // Fast path (Redis), then the durable ledger — so a re-submit is caught even if the cache was
        // flushed. Either hit → return the original message, don't wake the PM again.
        $priorMessageId = Cache::get($cacheKey)
            ?? ProjectIngest::query()
                ->where('project_id', $this->project->getId())
                ->where('content_hash', $hash)
                ->value('message_id');

        if ($priorMessageId !== null) {
            $prior = Message::find((int) $priorMessageId);
            if ($prior !== null) {
                $this->wasDuplicate = true;
                Cache::put($cacheKey, $prior->getId(), now()->addHours(self::DEDUP_WINDOW_HOURS));

                return $prior;
            }
        }

        $message = new PostProjectMessageAction(
            project: $this->project,
            verb: $this->type->messageVerb(),
            content: $this->content,
            author: $this->author,
            files: $this->files,
            extraPayload: ['ingest_type' => $this->type->value],
        )->execute();

        $this->recordIngest($hash, (int) $message->getId());
        Cache::put($cacheKey, $message->getId(), now()->addHours(self::DEDUP_WINDOW_HOURS));

        $this->project->emitLedgerEvent('project.' . $this->type->value . '.received', payload: [
            'message_id' => $message->getId(),
            'type' => $this->type->value,
            'channel_id' => $this->project->default_channel_id,
        ]);

        if ($this->wakeAgent) {
            WakeAgentForProjectJob::dispatch(
                $this->project,
                WakeAgentForProjectJob::REASON_INGEST,
                $this->content,
            );
        }

        return $message;
    }

    private function recordIngest(string $hash, int $messageId): void
    {
        // updateOrCreate so a stale ledger row whose message was deleted (re-ingest path) is refreshed
        // rather than colliding with the unique (project_id, content_hash) index.
        ProjectIngest::query()->updateOrCreate(
            [
                'project_id' => $this->project->getId(),
                'content_hash' => $hash,
            ],
            [
                'apps_id' => $this->project->apps_id,
                'companies_id' => $this->project->companies_id,
                'ingest_type' => $this->type->value,
                'message_id' => $messageId,
            ],
        );
    }
}
