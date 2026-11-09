<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Concerns\SkipsNonLeadEngagements;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Services\PeopleService;
use Kanvas\Connectors\SalesAssist\Actions\AttachTradeInPhotosAction;
use Kanvas\Connectors\VinSolution\Actions\PushCoBuyerAction;
use Kanvas\Connectors\VinSolution\Actions\PushCreditAppAction;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Actions\PushPurchaseVehicleAction;
use Kanvas\Connectors\VinSolution\Actions\PushTradeInAction;
use Kanvas\Connectors\VinSolution\Actions\PushVehicleOfInterest;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'VinSolution Push Lead Notes',
    description: 'Copies a message into the lead\'s notes in VinSolutions, so the CRM record shows the '
        . 'conversation. Writes a note only — it sends nothing to the customer. Pick the version '
        . 'matching the CRM this company runs; several connectors ship a near-identical step.',
    integration: IntegrationsEnum::VIN_SOLUTION,
)]
class PushLeadNotesActivity extends KanvasActivity
{
    use SkipsNonLeadEngagements;

    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        if ($skip = $this->skipNonLeadEngagement($message)) {
            return $skip;
        }

        $company = $message->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        $lead = $message->entity(Lead::class);

        if (! $lead instanceof Lead) {
            return $this->failWorkflow([
                'message_id' => $message->getId(),
                'error' => 'Message is not associated with a Lead',
            ]);
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            additionalParams: $params,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) use ($lead): array {
                if ($message->isLocked() || ! $message->isPublic()) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'message' => 'Message is locked or not public, skipping VinSolution note push.',
                    ]);
                }

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

        switch ($verb) {
            case ActionEnum::TRADE_WALK->value:
            case ActionEnum::PAYOFF_FORM->value:
            case ActionEnum::ADD_TRADE->value:
                // The note itself is already pushed by the caller; this adds the structured trade-in.
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    new AttachTradeInPhotosAction($message)->execute();
                    $results['tradeIn'] = new PushTradeInAction($lead, $message)->execute();
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
                $results['idVerification'] = $this->syncIdVerification($lead, $messageData);

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

    /**
     * Two producers write this verb with different shapes: the web form (status opened, address under
     * data.address) and the Intellicheck receiver (status submitted, the scan result under
     * data.form.private_data.result). Both update the person and then push it, because the note
     * push above carries no contact data.
     */
    public function syncIdVerification(Lead $lead, array $messageData): ?array
    {
        $status = $messageData['status'] ?? '';
        $people = $lead->people;

        if ($status === ActionStatusEnum::OPEN->value && ! empty($messageData['data']['address']['address'])) {
            PeopleService::updatePeopleAddressFromForm($people, $messageData['data']['address']);
            $lead->set('dont-run-id-verification-manual', 1);
            $this->pushPeople($people);

            return $messageData['data'];
        }

        $verification = $messageData['data']['form']['private_data']['result'] ?? [];
        if ($status === ActionStatusEnum::SUBMITTED->value && ! empty($verification['idcheck']['data']['address1'])) {
            PeopleService::updatePeopleInformation($people, $verification);
            $this->pushPeople($people);

            return $verification['idcheck']['data'];
        }

        return null;
    }

    protected function pushPeople(People $people): Contact
    {
        return new PushPeopleAction($people)->execute();
    }

    public function cleanEsignature(Lead $lead): void
    {
        $lead->set('prefill-sign-docs-' . time(), $lead->get('prefill-sign-docs'));
        $lead->set('actions_pdf_custom_form-' . time(), $lead->get('actions_pdf_custom_form'));

        $lead->del('prefill-sign-docs');
        $lead->del('actions_pdf_custom_form');
    }
}
