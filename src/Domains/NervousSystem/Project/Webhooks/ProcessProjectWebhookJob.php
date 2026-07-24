<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Webhooks;

use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * The single inbound endpoint for a project's external context. Every project provisions its own
 * `ReceiverWebhook` pointing at this job; providers (Fireflies/Otter transcripts, inbound email,
 * platform/Slack @mentions) POST to `/receiver/{uuid}`. The payload's `type` selects the ingest
 * kind; the normalized content is dropped onto the project's channel via `IngestToProjectAction`,
 * which emits the ledger event and wakes the PM.
 *
 * The receiver's `configuration.project_id` binds the endpoint to one project.
 */
#[WorkflowAction(name: 'Project Webhook Ingest')]
class ProcessProjectWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload ?? [];
        $configuration = $this->receiver->configuration ?? [];

        $projectId = (int) ($configuration['project_id'] ?? 0);
        if ($projectId === 0) {
            return ['status' => 'error', 'reason' => 'receiver not bound to a project'];
        }

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp($projectId, $this->receiver->company, $this->receiver->app);

        $type = ProjectIngestTypeEnum::tryFrom((string) ($payload['type'] ?? ''))
            ?? ProjectIngestTypeEnum::MENTION;

        $content = $this->extractContent($payload, $type);
        if ($content === '') {
            return ['status' => 'ignored', 'reason' => 'empty content'];
        }

        $ingest = new IngestToProjectAction(
            project: $project,
            type: $type,
            content: $content,
        );
        $message = $ingest->execute();

        return [
            'status' => $ingest->wasDuplicate() ? 'duplicate' : 'success',
            'type' => $type->value,
            'message_id' => $message->getId(),
        ];
    }

    /**
     * Pull the human text out of a provider payload — the key varies by source, so try the common
     * ones per ingest type before giving up.
     *
     * @param array<string, mixed> $payload
     */
    private function extractContent(array $payload, ProjectIngestTypeEnum $type): string
    {
        $keys = match ($type) {
            ProjectIngestTypeEnum::TRANSCRIPT => ['transcript', 'content', 'text', 'summary'],
            ProjectIngestTypeEnum::EMAIL => ['body', 'text', 'content', 'stripped-text'],
            ProjectIngestTypeEnum::MENTION => ['text', 'content', 'message'],
        };

        foreach ($keys as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return '';
    }
}
