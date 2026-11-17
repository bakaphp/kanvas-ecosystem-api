<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Actions\PruneKnowledgeDocumentAction;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

/**
 * Reads only the file's own columns: a file deleted outright reaches here rebuilt from its
 * attributes (TransientEntity), with no row left to load a relation from.
 */
#[WorkflowAction(
    name: 'Knowledge Prune Deleted File',
    description: 'Removes a deleted or detached file from the knowledge base. An app with no knowledge base is '
        . 'skipped (runs on the file deleted event)',
)]
class PruneDeletedFileKnowledgeActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Filesystem || empty($entity->uuid)) {
            return $this->skipWorkflow('Not a file');
        }

        if (! KnowledgeComponents::isAvailable($app)) {
            return $this->skipWorkflow('The app has no knowledge base');
        }

        new PruneKnowledgeDocumentAction($app, (int) $entity->companies_id, (string) $entity->uuid)->execute();

        return ['message' => 'Pruned from the knowledge base', 'uuid' => (string) $entity->uuid];
    }
}
