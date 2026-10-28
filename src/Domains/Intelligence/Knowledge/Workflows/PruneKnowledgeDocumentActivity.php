<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Workflows;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Research\Workflows\IndexCompanyDocumentActivity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class PruneKnowledgeDocumentActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Filesystem) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'deleted entity is not a Filesystem; nothing to prune',
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Filesystem $file) use ($app): array {
                // A file uuid is unique, so pruning both document kinds never touches another file.
                foreach ([IndexKnowledgeDocumentActivity::SOURCE_TYPE, IndexCompanyDocumentActivity::SOURCE_TYPE] as $sourceType) {
                    KnowledgeComponents::store($app)->deleteBySourceAcrossEntities(
                        $app->getId(),
                        $file->companies_id,
                        $sourceType,
                        $file->uuid,
                    );
                }

                return [
                    'success' => true,
                    'message' => "Pruned knowledge for deleted file {$file->uuid}",
                    'file_uuid' => $file->uuid,
                ];
            },
            company: $params['company'] ?? Companies::getById($entity->companies_id, $app),
        );
    }
}
