<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Workflows;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Actions\IndexTenantKnowledgeAction;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeTextExtractor;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Fires on attach-file for an Agent: indexes the agent's text/PDF documents as
 * the company's shared knowledge base (tenant-scoped, entity_id = 0 via
 * IndexTenantKnowledgeAction), so every agent in the company can search them.
 *
 * The attach-file seam only carries app + company (not the specific file), so we
 * re-index all currently-attached indexable files; replace-by-source (keyed on
 * each file's uuid) makes that idempotent, and unchanged chunks hit the
 * embedder's cache. Guarded to Agent + staff uploads so a customer/agent file
 * (which structurally never reaches an Agent anyway) can never be promoted to
 * company-wide knowledge.
 */
#[WorkflowAction]
class IndexKnowledgeDocumentActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Agent) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'attach-file entity is not an Agent; nothing to index as company knowledge',
            ]);
        }

        $extractor = new KnowledgeTextExtractor();
        $textFiles = $entity->getFiles()
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
            integrationOperation: function (Agent $agent, $app, $integrationCompany, $additionalParams) use ($extractor, $textFiles): array {
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

                    $chunks += new IndexTenantKnowledgeAction(
                        app: $agent->app,
                        company: $agent->company,
                        sourceType: 'agent_document',
                        sourceName: $file->uuid,
                        content: $content,
                    )->execute();
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
        $uploaderId = (int) $file->users_id;

        return $uploaderId > 0 && $uploaderId !== (int) ($agent->user?->getId() ?? 0);
    }
}
