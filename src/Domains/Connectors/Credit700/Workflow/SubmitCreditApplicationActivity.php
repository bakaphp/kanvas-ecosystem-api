<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\Actions\SubmitCreditApplicationAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class SubmitCreditApplicationActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param Model<Message> $message
     */
    public function execute(Model $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::CREDIT700,
            additionalParams: $params,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams): array {
                $result = new SubmitCreditApplicationAction($message)->execute();

                return [
                    'message' => $result['success']
                        ? 'Credit application submitted to RouteOne successfully'
                        : 'RouteOne rejected the credit application',
                    'success' => $result['success'],
                    'entity' => $result['response'],
                ];
            },
            company: $message->company,
        );
    }
}
