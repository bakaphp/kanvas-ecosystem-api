<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Workflows;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction]
class IndexKnowledgeDocumentActivity extends KanvasActivity
{
    /** sourceType stamped on every agent-uploaded knowledge chunk (write + prune share this). */
    public const string SOURCE_TYPE = 'agent_document';

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Agent) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'attach-file entity is not an Agent; nothing to index as company knowledge',
            ]);
        }

        $extractor = new FileTextExtractor();
        $textFiles = $entity->files
            ->filter(fn (Filesystem $file): bool => $extractor->supports($file) && $this->isStaffUpload($file, $entity))
            ->values();

        if ($textFiles->isEmpty()) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'No indexable staff-uploaded text documents attached to agent',
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Agent $agent) use ($extractor, $textFiles): array {
                // Scope the docs to THIS agent (not company-wide) so an upload to one
                // agent isn't retrievable by another.
                $indexer = KnowledgeComponents::indexer($agent->app);
                $scope = KnowledgeScope::forModel($agent);
                $chunks = 0;

                foreach ($textFiles as $file) {
                    try {
                        $content = $extractor->extract($file);
                    } catch (Throwable) {
                        // A corrupt/unreadable upload is a business condition, not a
                        // system fault — skip this file, keep going, don't report.
                        continue;
                    }

                    if (trim($content) === '') {
                        continue;
                    }

                    $chunks += $indexer->indexDocument($scope, self::SOURCE_TYPE, $file->uuid, $content);
                }

                return [
                    'success' => true,
                    'message' => "Indexed {$chunks} chunk(s) from {$textFiles->count()} document(s)",
                    'agent_id' => $agent->getId(),
                ];
            },
            company: $params['company'] ?? $entity->company,
        );
    }

    /**
     * Defense-in-depth: only index files a real staff user attached, never the
     * agent's own AI user self-feeding knowledge. Customer files never reach an
     * Agent structurally (they land on Message/Lead), so this is a second guard.
     */
    private function isStaffUpload(Filesystem $file, Agent $agent): bool
    {
        $uploaderId = $file->users_id;

        return $uploaderId > 0 && $uploaderId !== (int) ($agent->user?->getId() ?? 0);
    }
}
