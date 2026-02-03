<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\AddCoBuyerToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddCreditAppToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddTradeInToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddVehicleOfInterestToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\ProcessPurchaseVehicleAction;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

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
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'error' => 'Message is not associated with a Lead',
                    ]);
                }

                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping Elead note push.',
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

                    $handlerResult = $this->handleActionByVerbForDriveCentric($message, $lead);

                    try {
                        $handleCheckList = new ProcessMessageTaskUpdatesAction(
                            message: $message,
                            lead: $lead,
                            user: $message->user,
                        )->execute();
                    } catch (Throwable $e) {
                        $handleCheckList = $e->getMessage();
                    }
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
                    'handlerResult' => $handlerResult,
                    'taskUpdates' => $handleCheckList,
                    'message' => 'Note added to Lead successfully',
                ];
            },
            company: $message->company,
        );
    }

    protected function handleActionByVerbForDriveCentric(Message $message, Lead $lead): ?array
    {
        $messageData = $message->getMessage();
        $verb = $messageData['verb'] ?? '';
        $status = $messageData['status'] ?? '';
        $result = null;

        if ($status !== ActionStatusEnum::SUBMITTED->value) {
            return $result;
        }

        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                $result = new AddTradeInToDealAction($lead)->execute($message);

                break;
            case ActionEnum::CO_SIGNER->value:
                $formData = $messageData['data']['form'];
                $mobilePhone = $formData['personal']['mobile_number'] ?? '';
                $name = $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'] ?? '';
                $email = $formData['personal']['email'];
                $people = null;

                if (! empty($mobilePhone)) {
                    $people = People::findByPhoneOrCreate(
                        $mobilePhone,
                        $lead->company,
                        $lead->user,
                        $name,
                        $lead->app
                    );
                } elseif (! empty($email)) {
                    $people = People::findByEmailOrCreate(
                        $formData['personal']['email'],
                        $lead->company,
                        $lead->user,
                        $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'],
                        $lead->app
                    );
                }

                if (! $people) {
                    return null;
                }
                $lead->addCoBuyerParticipant($people);
                //new AddCoBuyerToDealAction($lead)->execute($people);
                new PushLeadAction($lead)->execute();
                $result = new AddCreditAppToDealAction($lead)->execute($message, $people);

                break;
            case ActionEnum::VIEW_PRODUCT->value:
                $result = new AddVehicleOfInterestToDealAction($lead)->execute($message);

                break;
            case ActionEnum::CREDIT_APP->value:
                $result = new AddCreditAppToDealAction($lead)->execute($message);

                break;
            case ActionEnum::GET_DOCS->value:
                break;
            case ActionEnum::PURCHASE_VEHICLE:
                $result = new ProcessPurchaseVehicleAction($lead)->execute($message);

                break;
            case ActionEnum::ESIGN_DOCS->value:
                //clean cart
                $lead->set('prefill-sign-docs-' . time(), $lead->get('prefill-sign-docs'));
                $lead->set('actions_pdf_custom_form-' . time(), $lead->get('actions_pdf_custom_form'));

                $lead->del('prefill-sign-docs');
                $lead->del('actions_pdf_custom_form');

                break;
            default:
                # code...
                break;
        }

        return $result;
    }
}
