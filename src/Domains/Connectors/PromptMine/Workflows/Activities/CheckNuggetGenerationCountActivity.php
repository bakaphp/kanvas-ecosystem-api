<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\PromptMine\Actions\CheckNuggetGenerationCountAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CheckNuggetGenerationCountActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $app->get('check-free-generation-count') || ! $app->get('free-generation-check-message-type') || $message->parent_id === null) {
            return [
                'result' => true,
                'message' => 'Free generation check is not enabled or message does not have a parent',
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                $checkNuggetGeneration = (new CheckNuggetGenerationCountAction($message))->execute();

                return [
                    'message' => 'Nugget generation count check completed successfully',
                    'result' => $checkNuggetGeneration,
                ];
            },
            company: $message->company,
        );
    }
}
