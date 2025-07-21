<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\CheckNuggetGenerationCountAction;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CheckNuggetGenerationCountActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $this->getCompany($app, $message->company);

        if (! $app->get('check-free-generation-count') || ! $app->get('free-generation-check-message-type') || $message->parent_id === null) {
            return [
                'result' => true,
                'message' => 'Free generation check is not enabled or message does not have a parent',
                'message_id' => $message->getId()
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($message) {
                $checkNuggetGeneration = (new CheckNuggetGenerationCountAction($message))->execute();

                return [
                    'message_id' => $message->getId(),
                    'message' => 'Nugget generation count check completed successfully',
                    'result' => $checkNuggetGeneration,
                ];
            },
            company: $company,
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
