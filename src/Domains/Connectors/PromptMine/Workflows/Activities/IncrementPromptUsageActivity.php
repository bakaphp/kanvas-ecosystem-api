<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class IncrementPromptUsageActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) {
                // $entity is the *Result Message* (Nugget) created from the Prompt.
                // We need to find the *Parent Prompt*.

                $parent = null;

                // 1. Check parent_id (Standard Reply/Thread)
                if ($entity->parent_id) {
                    $parent = Message::getById($entity->parent_id, $app);
                }
                // 2. Check remix_parent_id (Common in PromptMine for remixes)
                elseif (isset($entity->message['remix_parent_id'])) {
                    $parentId = (int) $entity->message['remix_parent_id'];
                    $parent = Message::getById($parentId, $app);
                }

                if (! $parent) {
                    // No parent found? Nothing to increment.
                    return [
                        'result' => false,
                        'message' => 'No parent prompt found to increment usage.',
                        'entity_id' => $entity->getId(),
                    ];
                }

                // Increment Usage Count
                // Using set() from HasCustomFields trait on the Parent Message
                $currentCount = (int) $parent->get('usage_count', 0);
                $parent->set('usage_count', $currentCount + 1);

                // Update Last Used At
                $parent->set('last_used_at', now()->toDateTimeString());

                return [
                    'result' => true,
                    'message' => 'Prompt usage incremented successfully.',
                    'parent_id' => $parent->getId(),
                    'new_count' => $currentCount + 1,
                    'last_used_at' => $parent->get('last_used_at'),
                ];
            },
            company: $company,
            integrationParams: $params,
        );
    }

    /**
     * Get the company for this workflow
     */
    protected function getCompany(AppInterface $app, Model $entity): Companies
    {
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);

            return $branch->company;
        } catch (ModelNotFoundException $e) {
            return $entity->company;
        }
    }
}
