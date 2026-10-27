<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Activities\Concerns\PushesSubmittedMessageToZoho;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Zoho Push Deal Notes',
    description: 'Mirrors a submitted message on a DEAL into Zoho CRM. Today it only handles Get Docs: it '
        . 'attaches the uploaded documents to the message and uploads them to the Zoho deal and to the '
        . 'Zoho lead the deal came from. Writes to Zoho only — it sends nothing to the customer. For '
        . 'messages on a lead use Zoho Push Lead Notes.',
    integration: IntegrationsEnum::ZOHO,
)]
class PushDealNotesActivity extends KanvasActivity
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
                $deal = $message->entity(Deal::class);

                if (! $deal instanceof Deal) {
                    return $this->failWorkflow([
                        'error' => 'Message is not associated with a Deal',
                    ]);
                }

                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Zoho note push.',
                    ]);
                }

                $lead = $deal->lead;

                $handlerResult = $this->pushSubmittedMessage(
                    $message,
                    function () use ($deal, $lead) {
                        $zohoService = new ZohoService($deal->app, $deal->company);

                        return $this->zohoDealTarget($zohoService, $deal) + $this->zohoLeadTarget($zohoService, $lead);
                    }
                );

                return [
                    'handlerResult' => $handlerResult,
                    'taskUpdates' => $lead ? $this->processTaskUpdates($message, $lead) : null,
                    'message' => 'Message pushed to Zoho',
                ];
            },
            company: $message->company,
        );
    }
}
