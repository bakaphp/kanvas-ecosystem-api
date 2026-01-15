<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'error' => 'Message is not associated with a Lead',
                    ]);
                }

                /*   $syncLeadAction = new SyncLeadAction($lead);
                  $eLeadOpportunity = $syncLeadAction->execute(); */

                $isElead = $lead->company->get(CustomFieldEnum::COMPANY->value) !== null;
                $isVinSolutions = $lead->company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                $isDriveCentric = $lead->company->get(ConfigurationEnum::STORE_ID->value) !== null;
                $note = null;

                if ($isDriveCentric) {
                    $note = new AddCommentToDealAction($lead)->execute($message);
                }
                /*  // Process task updates
                 $processMessageTaskUpdatesAction = $this->setTaskEngagementStatus($message, $lead);

                 // Handle action by verb
                 $handlerResult = $this->handleActionByVerb(
                     message: $message,
                     lead: $lead,
                     eLeadOpportunity: $eLeadOpportunity
                 );

                 // Handle special cases for certain verbs
                 $this->handleSpecialCases($message, $lead, $eLeadOpportunity); */

                return [
                    'note' => $note,
                    //'handlerResult' => $handlerResult,
                    //'taskUpdates' => $processMessageTaskUpdatesAction,
                    'message' => 'Note added to Lead successfully',
                ];
            },
            company: $message->company,
        );
    }

    /**
     * Given the verb determine what action execute.
     */
    protected function handleActionByVerb(Message $message, Lead $lead, mixed $eLeadOpportunity): mixed
    {
        $messageData = $message->getMessage();
        $verb = $messageData['verb'] ?? '';
        $status = $messageData['status'] ?? '';
        $result = null;

        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                break;
            case ActionEnum::CO_SIGNER->value:
                break;
            case ActionEnum::VIEW_PRODUCT->value:
                break;
            case ActionEnum::CREDIT_APP->value:
                break;
            case ActionEnum::GET_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                }

                break;
            case ActionEnum::ESIGN_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                }

                break;
            default:
                # code...
                break;
        }

        return $result;
    }
}
