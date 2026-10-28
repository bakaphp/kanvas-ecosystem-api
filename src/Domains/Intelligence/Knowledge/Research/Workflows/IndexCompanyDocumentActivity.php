<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Workflows;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\EntityFilesIndexer;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Documents uploaded during onboarding become company-wide knowledge for the internal agents. The
 * rule sits on the onboarding row, never on Companies: a Companies attach-file rule would index every
 * file ever attached to the company (logos, contracts, HR documents) into agent retrieval.
 */
#[WorkflowAction]
class IndexCompanyDocumentActivity extends KanvasActivity
{
    public const string SOURCE_TYPE = 'company_document';

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof CompanyOnboarding) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'attach-file entity is not a CompanyOnboarding row; nothing to index as company knowledge',
            ]);
        }

        $filesIndexer = new EntityFilesIndexer();
        $textFiles = $filesIndexer->indexableFiles($entity);

        if ($textFiles->isEmpty()) {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'No indexable text documents attached to the onboarding row',
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (CompanyOnboarding $row) use ($app, $filesIndexer, $textFiles): array {
                $chunks = $filesIndexer->index(
                    $app,
                    KnowledgeScope::forTenant($app->getId(), (int) $row->companies_id),
                    self::SOURCE_TYPE,
                    $textFiles,
                );

                return [
                    'success' => true,
                    'message' => "Indexed {$chunks} chunk(s) from {$textFiles->count()} document(s)",
                    'onboarding_id' => $row->getId(),
                ];
            },
            company: $params['company'] ?? Companies::getById((int) $entity->companies_id, $app),
        );
    }
}
