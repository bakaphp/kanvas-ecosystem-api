<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Giftea\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\Giftea\Services\QuizService;
use Kanvas\Connectors\Giftea\Services\RecombeeItemService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class PushQuizToItemActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 4;

    #[Override]
    public function execute(Model $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $message->company;

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::GIFTEA,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                $messageType = $params['message_type_id'] ?? null;

                if ($messageType !== null) {
                    if ($message->message_types_id !== (int) $messageType) {
                        $this->setWorkflowStatus(StatusEnum::FAILED);
                        return [
                            'result' => false,
                            'message' => 'Message type does not match the expected ' . $messageType . ' but found ' . $message->message_types_id,
                            'id' => $message->id,
                        ];
                    }
                }

                try {
                    $itemService = $params["service"] ?? new RecombeeItemService($app);
                    $QuizService = new QuizService($itemService);
      
                    $result = $QuizService->processQuizSubmission(
                        $message, 
                        (string) $message->users_id
                    );

                    $message->update([
                        'message' => [
                            ...$message->message,
                            'recoms' => $result
                        ]
                    ]);

                } catch (Throwable $e) {
                    return [
                        'result' => false,
                        'message' => $e->getMessage(),
                        'id' => $message->id,
                    ];
                }

                return [
                    'result' => $result,
                    'message' => $message->id,
                    'slug' => $message->slug ?? $message->uuid,
                ];
            },
            company: $company
        );
    }
}
