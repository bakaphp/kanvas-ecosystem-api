<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Approval;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
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
 * Reuses the same IngestToProjectAction the auto-forward path uses.
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

        new IngestToProjectAction(
            project: $project,
            type: $type,
            content: $content,
        )->execute();
    }
}
