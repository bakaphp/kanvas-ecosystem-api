<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Approval;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * Approve a routing decision the orchestrator wasn't confident enough to auto-run: forward the held
 * signal into the project a human confirmed. `context.project_id` is the human's choice (they may
 * redirect away from the suggested one); `context.content` + `context.ingest_type` are the held signal.
 * A signal stored as a file has that file attached to the approval message, named by
 * `context.filesystem_id`, with `context.body` as its summary. Reuses the same IngestToProjectAction
 * the auto-forward path uses.
 */
class ProjectRoutingApprovalHandler implements AgentApprovalHandler
{
    // Stable discriminator the frontend switches on to render the "route to project" approve UI.
    public const string KIND = 'project_routing';

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function approve(Message $message, array $context): void
    {
        $projectId = (int) ($context['project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new ValidationException('Select a project to route this signal to.');
        }

        $app = Apps::getById((int) $message->apps_id);
        $company = Companies::getById((int) $message->companies_id);

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp($projectId, $company, $app);

        $type = ProjectIngestTypeEnum::tryFrom((string) ($context['ingest_type'] ?? ''))
            ?? ProjectIngestTypeEnum::TRANSCRIPT;

        $content = (string) ($context['content'] ?? $message->contentText());
        if (trim($content) === '') {
            throw new ValidationException('Nothing to route — the held signal has no content.');
        }

        /** @var Filesystem|null $file */
        $file = $message->files()->whereKey((int) ($context['filesystem_id'] ?? 0))->first();

        new IngestToProjectAction(
            project: $project,
            type: $type,
            content: $content,
            body: $file !== null ? Str::trimToNull((string) ($context['body'] ?? '')) : null,
            storedFiles: $file !== null ? [$file] : [],
        )->execute();
    }
}
