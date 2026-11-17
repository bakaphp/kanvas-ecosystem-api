<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Actions\IndexAgentDocumentsAction;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Knowledge Index Agent Documents',
    description: 'Indexes the documents attached to an agent into its knowledge base. An app with no knowledge '
        . 'base is skipped (runs on the agent attach-file event)',
)]
class IndexAgentDocumentsActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Agent) {
            return $this->skipWorkflow('Not an agent');
        }

        if (! KnowledgeComponents::isAvailable($entity->app)) {
            return $this->skipWorkflow('The app has no knowledge base');
        }

        return new IndexAgentDocumentsAction($entity)->execute();
    }
}
