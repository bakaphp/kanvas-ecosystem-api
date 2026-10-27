<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Activities\Concerns\PushesSubmittedMessageToZoho;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Zoho Push Lead Notes',
    description: 'Mirrors a submitted message on a LEAD into Zoho CRM. Today it only handles Get Docs: it '
        . 'attaches the uploaded documents to the message and uploads them to the Zoho lead and any '
        . 'Zoho deal linked to it. Writes to Zoho only — it sends nothing to the customer. For messages '
        . 'on a deal use Zoho Push Deal Notes.',
    integration: IntegrationsEnum::ZOHO,
)]
class PushLeadNotesActivity extends KanvasActivity
{
    use PushesSubmittedMessageToZoho;

    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::ZOHO,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity(Lead::class);

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'error' => 'Message is not associated with a Lead',
                    ]);
                }

                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Zoho note push.',
                    ]);
                }

                $handlerResult = $this->pushSubmittedMessage(
                    $message,
                    fn () => $this->zohoTargets(new ZohoService($lead->app, $lead->company), $lead)
                );

                return [
                    'handlerResult' => $handlerResult,
                    'taskUpdates' => $this->processTaskUpdates($message, $lead),
                    'message' => 'Message pushed to Zoho',
                ];
            },
            company: $message->company,
        );
    }

    protected function zohoTargets(ZohoService $zohoService, Lead $lead): array
    {
        $targets = $this->zohoLeadTarget($zohoService, $lead);

        foreach ($lead->deals as $deal) {
            $targets += $this->zohoDealTarget($zohoService, $deal);
        }

        return $targets;
    }
}
