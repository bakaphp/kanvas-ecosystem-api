<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use JsonException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Enums\AppEnum;
use Kanvas\Guild\Leads\Actions\ConvertJsonTemplateToLeadStructureAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\SendRotationEmailsAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction]
class CreateLeadsFromReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $leadReceiver = $this->loadReceiver();
        $emailTemplate = $this->receiver->configuration['email_template'] ?? null;
        $userFlag = $this->receiver->configuration['flag'] ?? 'user';
        $showCustomFields = (bool) ($this->receiver->configuration['show_custom_fields'] ?? false);

        $attempt = $this->createAttempt($leadReceiver);
        $payload = $this->buildPayload($leadReceiver);
        [$user, $payload] = $this->resolveOwner($leadReceiver, $payload);

        $lead = $this->createLead(
            $leadReceiver,
            $user,
            $payload,
            $attempt
        );

        $this->afterLeadCreated(
            $lead,
            $leadReceiver,
            $payload
        );

        $sentEmail = $this->sendEmails(
            $lead,
            $leadReceiver,
            $user,
            $payload,
            $userFlag,
            $emailTemplate,
        );

        $this->fireWorkflows(
            $lead,
            $leadReceiver,
            $attempt,
            $payload
        );

        return $this->buildResponse(
            $leadReceiver,
            $lead,
            $sentEmail,
            $showCustomFields
        );
    }

    protected function getUserByMemberNumber(array $payload, Companies $company): ?Users
    {
        $keys = ['Member', 'member', 'Member_Id', 'member_id'];
        $memberNumber = null;

        foreach ($keys as $key) {
            if (isset($payload[$key])) {
                $memberNumber = $payload[$key];

                break;
            }
        }

        if (! $memberNumber) {
            return null;
        }

        try {
            $agent = Agent::getByMemberNumber($memberNumber, $company);

            /**
             * @var Users
             */
            return $agent->user;
        } catch (ModelNotFoundException $e) {
            return null;
        }
    }

    /**
    * Converts a double-escaped JSON string with a nested JSON structure into a PHP array.
    * This is particularly useful when dealing with nested JSON that has been double-encoded,
    * such as when a JSON string is used as a key in another JSON object.
    *
    * Example input:
    * {
    *   "{\"First_Name\":\"OttoIoqORO\",\"Last_Name\":\"TesterIoqORO\",\"Phone\":\"4079393463\",
    *   \"Email\":\"ottoIoqORO01242025202316@lendingtree_com\",\"Company\":\"LendingTree_AWE_Testing_Corp\",
    *   \"Street\":\"Not_Provided\",\"City\":\"Bat_Cave\",\"State\":\"NC\",\"Zip_Code\":\"28710\",
    *   \"Type_of_Incorporation\":\"CORPORATION\",\"Business_Founded\":\"7/1/2015\",\"Credit_Score\":\"Good\",
    *   \"SubID\":\"867347\",\"Other\":{\"QForm_Name\":\"6294JBZYPB\"},\"Amount_Requested\":10000,
    *   \"Annual_Revenue\":250000}": null
    * }
    */
    public function parseDoubleEncodedJsonToArray(array $doubleEscapedJson): array
    {
        $jsonString = array_key_first($doubleEscapedJson);
        $finalJson = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException('Failed to decode inner JSON: ' . json_last_error_msg());
        }

        return $finalJson;
    }

    private function loadReceiver(): LeadReceiver
    {
        return LeadReceiver::getByIdFromCompanyApp(
            $this->receiver->configuration['receiver_id'],
            $this->receiver->company,
            $this->receiver->app,
        );
    }

    private function resolveRealIp(): string
    {
        $ipAddresses = $this->webhookRequest->headers['x-real-ip'] ?? [];

        return is_array($ipAddresses) && ! empty($ipAddresses) ? reset($ipAddresses) : '127.0.0.1';
    }

    private function createAttempt(LeadReceiver $leadReceiver): LeadAttempt
    {
        return new CreateLeadAttemptAction(
            $this->webhookRequest->payload,
            $this->webhookRequest->headers,
            $this->receiver->company,
            $this->receiver->app,
            $this->resolveRealIp(),
            'RECEIVER ID: ' . $leadReceiver->getId(),
        )->execute();
    }

    private function buildPayload(LeadReceiver $leadReceiver): array
    {
        $payload = $this->webhookRequest->payload;

        if (isset($this->receiver->configuration['double_encoded_json'])) {
            $payload = $this->parseDoubleEncodedJsonToArray($payload);
        }

        if (! empty($leadReceiver->template) && is_array($leadReceiver->template)) {
            $payload = new ConvertJsonTemplateToLeadStructureAction(
                $leadReceiver->template,
                $payload,
            )->execute();
        }

        if (! empty($this->receiver->configuration['save_phone_as_cellphone'])) {
            $payload = $this->duplicatePhoneAsCellphone($payload);
        }

        return $this->applyReceiverDefaults($leadReceiver, $payload);
    }

    /**
     * Workaround for receivers whose source only sends a home phone (contact type PHONE)
     * when the lead really has a cellphone. When the `save_phone_as_cellphone` flag is set,
     * mirror every PHONE contact into an equivalent CELLPHONE contact so both exist.
     */
    private function duplicatePhoneAsCellphone(array $payload): array
    {
        $contacts = $payload['people']['contacts'] ?? null;

        if (! is_array($contacts) || empty($contacts)) {
            return $payload;
        }

        $existingCellphones = [];
        foreach ($contacts as $contact) {
            if (is_array($contact) && ($contact['contacts_types_id'] ?? null) === ContactTypeEnum::CELLPHONE->value) {
                $existingCellphones[(string) ($contact['value'] ?? '')] = true;
            }
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $value = (string) ($contact['value'] ?? '');
            $isPhone = ($contact['contacts_types_id'] ?? null) === ContactTypeEnum::PHONE->value;

            if (! $isPhone || $value === '' || isset($existingCellphones[$value])) {
                continue;
            }

            $contacts[] = [
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'value' => $value,
            ];
            $existingCellphones[$value] = true;
        }

        $payload['people']['contacts'] = $contacts;

        return $payload;
    }

    private function applyReceiverDefaults(LeadReceiver $leadReceiver, array $payload): array
    {
        $payload['branch_id'] = $leadReceiver->companies_branches_id;
        $payload['receiver_id'] = $leadReceiver->getId();

        $defaultStatus = $leadReceiver->app->get(AppEnum::APP_DEFAULT_RECEIVER_LEAD_STATUS->value);
        if ($defaultStatus) {
            $payload['status_id'] = $defaultStatus;
        }

        $payload['type_id'] = $payload['type_id'] ?? $leadReceiver->lead_types_id;
        $payload['source_id'] = $payload['source_id'] ?? $leadReceiver->leads_sources_id;

        return $payload;
    }

    /**
     * @return array{0: ?Users, 1: array}
     */
    private function resolveOwner(LeadReceiver $leadReceiver, array $payload): array
    {
        $user = $this->getUserByMemberNumber($payload, $this->receiver->company);

        if ($leadReceiver->rotation) {
            $user = $leadReceiver->rotation->getAgent();
            $payload['leads_owner_id'] = $user->getId();
        }

        return [$user, $payload];
    }

    private function createLead(
        LeadReceiver $leadReceiver,
        ?Users $user,
        array $payload,
        LeadAttempt $attempt,
    ): Lead {
        return new CreateLeadAction(
            LeadData::from(
                $user ?? $leadReceiver->user,
                $this->receiver->app,
                $payload,
            ),
            $attempt,
        )->execute();
    }

    private function sendEmails(
        Lead $lead,
        LeadReceiver $leadReceiver,
        ?Users $user,
        array $payload,
        string $userFlag,
        ?string $emailTemplate,
    ): array {
        if (! $user) {
            return ['no email sent'];
        }

        new SendRotationEmailsAction(
            $lead,
            $leadReceiver,
            $leadReceiver->rotation,
            $user,
        )->execute(
            $payload,
            $userFlag,
            $emailTemplate
        );

        return [
            'template' => $emailTemplate,
            'flag' => $userFlag,
            'payload' => $payload,
        ];
    }

    private function fireWorkflows(
        Lead $lead,
        LeadReceiver $leadReceiver,
        LeadAttempt $attempt,
        array $payload,
    ): void {
        $lead->fireWorkflow(
            WorkflowEnum::AFTER_RUNNING_RECEIVER->value,
            true,
            [
                'receiver' => $leadReceiver,
                'attempt' => $attempt,
            ],
        );

        if ($lead->company->isAIEnabled()) {
            $lead->fireWorkflow(
                WorkflowEnum::FAKE_CONTEXT->value,
                true,
                [
                    'app' => $lead->app,
                    'payload' => $payload,
                    'sub_source' => $this->receiver->configuration['sub_source'] ?? null,
                ],
            );
        }
    }

    private function buildResponse(
        LeadReceiver $leadReceiver,
        Lead $lead,
        array $sentEmail,
        bool $showCustomFields,
    ): array {
        return [
            'message' => 'Lead created successfully via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'lead_id' => $lead->getId(),
            'lead' => $lead->toArray(),
            'sent_email' => $sentEmail,
            'custom_fields' => $showCustomFields ? $lead->getAll() : [],
        ];
    }

    /**
    * Extension point invoked right after the lead is created, before owner/rotation emails.
    * No-op on the base receiver; subclasses (e.g. the confirmation receiver) override it.
    */
    protected function afterLeadCreated(
        Lead $lead,
        LeadReceiver $leadReceiver,
        array $payload
    ): void {
    }
}
