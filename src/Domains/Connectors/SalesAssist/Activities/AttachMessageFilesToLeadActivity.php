<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Attach Message Files To Lead',
    description: 'Copies any files on a message onto the lead it belongs to, so attachments live with the '
        . 'record rather than only in the conversation. Moves files; sends nothing.',
)]
class AttachMessageFilesToLeadActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Message is not linked to a Lead entity',
                    ]);
                }

                $messageFiles = FilesystemEntitiesRepository::getFilesByEntity($message);

                if ($messageFiles->isEmpty()) {
                    return [
                        'result' => false,
                        'message' => 'No files attached to message',
                    ];
                }

                foreach ($messageFiles as $fileEntity) {
                    $lead->addFile($fileEntity->filesystem, $fileEntity->field_name);
                }

                return [
                    'result' => true,
                    'message' => 'Attached ' . $messageFiles->count() . ' file(s) to lead',
                    'lead_id' => $lead->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
