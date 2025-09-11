<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Exception;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Actions\ProcessMessageTaskUpdatesAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\AddCoBuyerAction;
use Kanvas\Connectors\Elead\Actions\AddCreditAppAction;
use Kanvas\Connectors\Elead\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Elead\Actions\AddTradeInAction;
use Kanvas\Connectors\Elead\Actions\AddVehicleAction;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressDto;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class PushLeadNotesActivity extends KanvasActivity
{
    public $tries = 10;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $message->company;

        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    throw new Exception('Lead not found');
                }
                
                $syncLeadAction = new SyncLeadAction($lead);
                $eLeadOpportunity = $syncLeadAction->execute();

                // Add note to lead
                $addNoteAction = new AddNoteToLeadAction(
                    lead: $lead,
                    message: $message,
                );
                $note = $addNoteAction->execute($eLeadOpportunity);

                // Process task updates
                $processMessageTaskUpdatesAction = $this->setTaskEngagementStatus($message, $lead);

                // Handle action by verb
                $handlerResult = $this->handleActionByVerb(
                    message: $message,
                    lead: $lead,
                    eLeadOpportunity: $eLeadOpportunity
                );

                // Handle special cases for certain verbs
                $this->handleSpecialCases($message, $lead, $eLeadOpportunity);

                return [
                    'note' => $note,
                    'syncLead' => $eLeadOpportunity,
                    'handlerResult' => $handlerResult,
                    'taskUpdates' => $processMessageTaskUpdatesAction,
                    'message' => 'Elead integration completed successfully',
                ];
            },
            company: $company,
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
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $addTradeAction = new AddTradeInAction($lead);
                    $result = $addTradeAction->execute($messageData);
                }

                break;
            case ActionEnum::CO_SIGNER->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $addCreditApp = new AddCreditAppAction($lead);
                    $result = $addCreditApp->execute($messageData, true);

                    $addCoBuyer = new AddCoBuyerAction($lead);
                    $addCoBuyer->execute($messageData);

                    $lead->set('cobuyer_imported', 1);
                    $lead->set(CustomFieldEnum::CO_BUYER_APP_IMPORTER->value, [
                        'active' => 1,
                        'message' => 'Co-Buyer App Ready to be imported into eLead.',
                    ]);
                }

                break;
            case ActionEnum::VIEW_PRODUCT->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $addVehicle = new AddVehicleAction($lead);
                    $result = $addVehicle->execute($messageData);
                }

                break;
            case ActionEnum::CREDIT_APP->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $addCreditApp = new AddCreditAppAction($lead);
                    $result = $addCreditApp->execute($messageData);

                    $lead->set('creditapp_imported', 1);
                    $lead->set(CustomFieldEnum::CREDIT_APP_IMPORTER->value, [
                        'active' => 1,
                        'message' => 'Credit App Ready to be imported into eLead.',
                    ]);

                    if ($people = $lead->people) {
                        $this->updatePeopleAddress($people, $result, $messageData);

                        $people->firstname = $result['data']['firstname'] ?? '';
                        $people->middlename = $result['data']['middleName'] ?? '';
                        $people->lastname = $result['data']['lastname'] ?? '';
                        $people->saveOrFail();
                    }

                    // @todo: Implement workflow trigger for created message
                    // This would require integration with workflow service
                }

                break;
            case ActionEnum::GET_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    //$lead->set(CustomFieldEnum::GET_DOCS_IMPORTER->value, 1);
                }

                break;
            case ActionEnum::ESIGN_DOCS->value:
                if ($status === ActionStatusEnum::SUBMITTED->value) {
                    $this->cleanEsignatureDocs($lead);
                }

                break;
            default:
                # code...
                break;
        }

        return $result;
    }

    /**
     * Update people address information.
     */
    protected function updatePeopleAddress(People $people, array $result, array $messageData): void
    {
        $people->addDefaultAddress(AddressDto::from([
            'address' => $result['data']['street'],
            'city' => $result['data']['city'],
            'state' => $messageData['data']['form']['housing']['state']['code'] ?? '',
            'zip' => $result['data']['zip'],
        ]));

        if (isset($result['data']['previousAddressStreet']) && ! empty($result['data']['previousAddressStreet'])) {
            $previousHomeType = AddressType::getByName('Previous Home');

            $people->addAddress(AddressDto::from([
                'address' => $result['data']['previousAddressStreet'],
                'city' => $result['data']['previousAddressCity'],
                'state' => $messageData['data']['form']['housing']['previous_state']['code'] ?? '',
                'zip' => $result['data']['previousAddressZipCode'],
                'address_type_id' => $previousHomeType->getId(),
            ]));
        }
    }

    /**
     * Handle special cases for certain verbs and statuses.
     */
    protected function handleSpecialCases(Message $message, Lead $lead, mixed $eLeadOpportunity): void
    {
        $messageData = $message->getMessage();
        /** @var string $verb */
        $verb = $messageData['verb'] ?? '';
        /** @var string $status */
        $status = $messageData['status'] ?? '';
        $messageDataData = $messageData['data'] ?? [];

        // Handle credit app, co-signer, and get docs submission special cases
        $verbs = [ActionEnum::CO_SIGNER->value, ActionEnum::CREDIT_APP->value, ActionEnum::GET_DOCS->value];
        $isVerbInArray = in_array($verb, $verbs);
        $isStatusSubmitted = $status === ActionStatusEnum::SUBMITTED->value;
        $isGetDocsWithoutDriversLicense = $verb === ActionEnum::GET_DOCS->value && ! isset($messageDataData[3]); //3 is drivers license

        if ($isVerbInArray && $isStatusSubmitted && ! $isGetDocsWithoutDriversLicense) {
            /** @var string $text */
            $text = is_string($messageData['text'] ?? null) ? $messageData['text'] : '';
            $baseComment = $lead->getFullName() . ' ' . $text . ' is ready to be imported into eLead. ';

            if ($lead->get(CustomFieldEnum::LEAD_ID->value)) {
                $botLink = 'Please first open SalesAssist Extension and then click on the banner or this url https://salink.app/?openInSa=true&uuid=' . $lead->uuid . '&action=' . $verb . '&lDID=' . (string) $lead->get(CustomFieldEnum::LEAD_ID->value);
                $eLeadOpportunity->addComment($baseComment . $botLink);
            } else {
                $additionalComment = 'Please open the Chrome Extension to link and sync the opportunity first to view the banner.';
                $eLeadOpportunity->addComment($baseComment . $additionalComment);
            }
        }
    }

    /**
     * Clean e-signature documents custom fields.
     */
    protected function cleanEsignatureDocs(Lead $lead): void
    {
        // Archive old values with timestamp
        $timestamp = time();
        $lead->set('prefill-sign-docs-' . $timestamp, $lead->get('prefill-sign-docs'));
        $lead->set('actions_pdf_custom_form-' . $timestamp, $lead->get('actions_pdf_custom_form'));

        // Delete current values
        $lead->del('prefill-sign-docs');
        $lead->del('actions_pdf_custom_form');
    }

    /**
     * Set task engagement status.
     *
     * @throws Throwable
     */
    protected function setTaskEngagementStatus(Message $message, Lead $lead): array
    {
        try {
            return new ProcessMessageTaskUpdatesAction(
                message: $message,
                lead: $lead,
                user: $message->user,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            //throw new Exception('Failed to set task engagement status: ' . $e->getMessage());
        }
    }
}
