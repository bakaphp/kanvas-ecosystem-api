<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Social\Messages\Actions\DistributeMessagesToUsersAction;
use Kanvas\Social\Messages\Jobs\DistributeMessagesToUsersJob;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class DistributeMessageActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        try {
            $company = $app->getAppCompany();
        } catch (ModelNotFoundException $e) {
            $company = $message->company;
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                //DistributeMessagesToUsersJob::dispatch($message, $app, $params);

                $totalDelivery = new DistributeMessagesToUsersAction($message, $app)->execute();

                return [
                    'message' => 'Distributed message activity executed to ' . $totalDelivery . ' users',
                    'message_id' => $message->getId(),
                    'result' => true,
                ];
            },
            company: $company
        );
    }
}
