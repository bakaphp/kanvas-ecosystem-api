<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Exception;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushCoBuyerAction;
use Kanvas\Connectors\VinSolution\Actions\PushCreditAppAction;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Actions\PushPurchaseVehicleAction;
use Kanvas\Connectors\VinSolution\Actions\PushVehicleOfInterest;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        $lead = $message->entity();

        if (! $lead) {
            throw new Exception('Lead not found');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($lead) {
                $leadNoteAction = new PushNoteToLeadAction(
                    lead: $lead,
                    message: $message,
                );

                $processMessageTaskUpdatesAction = new ProcessMessageTaskUpdatesAction(
                    message: $message,
                    lead: $lead,
                    user: $message->user,
                )->execute();

                $results = $this->processVinSolutionMessage($message, $lead);

                return [
                    'note' => $leadNoteAction->execute(),
                    'results' => $results,
                    'message' => 'VinSolution integration completed successfully',
                    'taskUpdates' => $processMessageTaskUpdatesAction,
                ];
            },
            company: $company,
        );
    }

    protected function processVinSolutionMessage(Message $message, Lead $lead): array
    {
        $messageData = $message->getMessage();
        $verb = $messageData['verb'] ?? '';
        $status = $messageData['status'] ?? '';
        $results = [];

        // Process based on action type and status
        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $tradeInAction = new PushNoteToLeadAction($lead, $message);
                    $results['tradeIn'] = $tradeInAction->execute();
                }

                break;
            case ActionEnum::CO_SIGNER->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $coBuyerAction = new PushCoBuyerAction($lead, $message);
                    $results['coBuyer'] = $coBuyerAction->execute();
                }

                break;
            case ActionEnum::CREDIT_APP->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $creditAppAction = new PushCreditAppAction($lead->people, $message);
                    $results['creditApp'] = $creditAppAction->execute();
                }

                break;
            case ActionEnum::ID_VERIFICATION->value:
                if ($status === ActionStatusEnum::OPEN->value && ! empty($messageData['data']['address']['address'])) {
                    $results['idVerification'] = $this->idVerificationUpdatePeople($lead->people, $messageData);
                    $lead->set('dont-run-id-verification-manual', 1);
                }

                break;
            case ActionEnum::VIEW_PRODUCT->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $vehicleInterestAction = new PushVehicleOfInterest($lead, $message);
                    $results['vehicleInterest'] = $vehicleInterestAction->execute();
                }

                break;
            case ActionEnum::PURCHASE_VEHICLE->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $purchaseVehicleAction = new PushPurchaseVehicleAction($lead, $message);
                    $results['purchaseVehicle'] = $purchaseVehicleAction->execute();

                    // Send email notification if configured
                    if ($lead->company->get('send-esign-notification-link')) {
                        //$this->sendEsignNotification($lead, $messageData);
                    }
                }

                break;
            case ActionEnum::ESIGN_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $this->cleanEsignature($lead);
                    $results['esignCleaned'] = true;
                }

                break;
            default:
                $results['action'] = 'No specific action for verb: ' . $verb;

                break;
        }

        return [
            'success' => true,
            'results' => $results,
            'message' => 'VinSolution integration completed successfully',
        ];
    }

    public function idVerificationUpdatePeople(People $people, array $message): array
    {
        $people->addDefaultAddress(Address::from([
            'address' => $message['data']['address']['address'],
            'city' => $message['data']['address']['city'],
            'state' => $message['data']['address']['state'],
            'zip' => $message['data']['address']['zipcode'],
        ]));

        return $message['data'];
    }

    public function cleanEsignature(Lead $lead): void
    {
        $lead->set('prefill-sign-docs-' . time(), $lead->get('prefill-sign-docs'));
        $lead->set('actions_pdf_custom_form-' . time(), $lead->get('actions_pdf_custom_form'));

        $lead->del('prefill-sign-docs');
        $lead->del('actions_pdf_custom_form');
    }
}
