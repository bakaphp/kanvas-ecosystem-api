<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\PushGetDocsLeadStatusAction;
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
        . 'attaches the uploaded documents to the message, uploads them to the Zoho lead and any '
        . 'Zoho deal linked to it, and, when the company sets ZOHO_GET_DOCS_LEAD_STATUS, moves the lead '
        . 'to that status in both Kanvas and Zoho. Writes to Zoho only — it sends nothing to the '
        . 'customer. For messages on a deal use Zoho Push Deal Notes.',
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

                // One client for the file push and the status write, built only if there is something to push.
                $zohoService = null;
                $resolveZohoService = function () use (&$zohoService, $lead): ZohoService {
                    return $zohoService ??= $this->zohoService($lead);
                };

                $handlerResult = $this->pushSubmittedMessage(
                    $message,
                    fn () => $this->zohoTargets($resolveZohoService(), $lead)
                );

                return [
                    'handlerResult' => $handlerResult,
                    'leadStatus' => ($handlerResult['files'] ?? 0) > 0
                        ? $this->pushGetDocsLeadStatus($lead, $resolveZohoService())
                        : null,
                    'taskUpdates' => $this->processTaskUpdates($message, $lead),
                    'message' => 'Message pushed to Zoho',
                ];
            },
            company: $message->company,
        );
    }

    protected function zohoService(Lead $lead): ZohoService
    {
        return new ZohoService($lead->app, $lead->company);
    }

    protected function zohoTargets(ZohoService $zohoService, Lead $lead): array
    {
        $targets = $this->zohoLeadTarget($zohoService, $lead);

        foreach ($lead->deals as $deal) {
            $targets += $this->zohoDealTarget($zohoService, $deal);
        }

        return $targets;
    }

    protected function pushGetDocsLeadStatus(Lead $lead, ZohoService $zohoService): array
    {
        return new PushGetDocsLeadStatusAction($lead, $zohoService->getLeadModule())->execute();
    }
}
