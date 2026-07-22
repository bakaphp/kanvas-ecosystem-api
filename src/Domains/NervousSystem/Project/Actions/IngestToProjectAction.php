<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The single ingest funnel for a project's inbound context — transcript, email, or @mention. Each
 * lands as a typed message on the project's default channel (so it joins the unified feed the agent
 * reads), emits a `project.<type>.received` ledger event, and wakes the PM agent to act on it.
 */
class IngestToProjectAction
{
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

    public function execute(): Message
    {
        $message = new PostProjectMessageAction(
            project: $this->project,
            verb: $this->type->messageVerb(),
            content: $this->content,
            author: $this->author,
            files: $this->files,
            extraPayload: ['ingest_type' => $this->type->value],
        )->execute();

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
}
