<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Exception;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

/**
 * Reynolds counterpart of VinSolution's PushLeadNotesActivity.
 *
 * Minimal v1: pushes the message content to Reynolds as a USL Note via
 * AddNoteToLeadAction. The ActionEngine verb-based branches VinSolution
 * runs (trade / co-signer / credit / VOI / purchase / esign) are
 * intentionally not wired here yet — they all degrade to unstructured
 * notes under SalesAssist anyway, and we want to first confirm plain
 * message delivery lands in R&R before layering that in.
 */
#[WorkflowAction]
class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_USERNAME->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value))
        ) {
            return ['error' => 'Reynolds credentials are not configured for this company'];
        }

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value))
        ) {
            return ['error' => 'Reynolds dealer/store/area not configured for this company'];
        }

        $lead = $message->entity();
        if (! $lead instanceof Lead) {
            throw new Exception('Lead not found');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::REYNOLDS,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) use ($lead): array {
                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Reynolds note push.',
                    ]);
                }

                $note = $this->extractNoteContent($message);
                if ($note === '') {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message content is empty, nothing to push.',
                    ]);
                }

                $taskUpdates = new ProcessMessageTaskUpdatesAction(
                    message: $message,
                    lead: $lead,
                    user: $message->user,
                )->execute();

                try {
                    $noteResult = new AddNoteToLeadAction($lead, $note)->execute();
                } catch (Throwable $e) {
                    return $this->failWorkflow([
                        'error' => 'Reynolds USL Note failed: ' . $e->getMessage(),
                    ]);
                }

                return [
                    'note' => $noteResult,
                    'message' => 'Reynolds note pushed successfully',
                    'taskUpdates' => $taskUpdates,
                ];
            },
            company: $company,
        );
    }

    private function extractNoteContent(Message $message): string
    {
        $content = $message->message['content'] ?? '';

        return is_string($content) ? trim($content) : '';
    }
}
