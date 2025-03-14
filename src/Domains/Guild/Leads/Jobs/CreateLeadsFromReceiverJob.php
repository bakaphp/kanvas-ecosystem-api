<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use JsonException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Enums\AppEnum;
use Kanvas\Guild\Leads\Actions\ConvertJsonTemplateToLeadStructureAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class CreateLeadsFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp($this->receiver->configuration['receiver_id'], $this->receiver->company, $this->receiver->app);
        $emailTemplate = $this->receiver->configuration['email_template'] ?? null;

        $ipAddresses = $this->webhookRequest->headers['x-real-ip'] ?? [];
        $realIp = is_array($ipAddresses) && ! empty($ipAddresses) ? reset($ipAddresses) : '127.0.0.1';

        $leadAttempt = new CreateLeadAttemptAction(
            $this->webhookRequest->payload,
            $this->webhookRequest->headers,
            $this->receiver->company,
            $this->receiver->app,
            $realIp,
            'RECEIVER ID: ' . $leadReceiver->getId()
        );
        $attempt = $leadAttempt->execute();

        $payload = $this->webhookRequest->payload;

        if (isset($this->receiver->configuration['double_encoded_json'])) {
            $payload = $this->parseDoubleEncodedJsonToArray($payload);
        }

        $user = $this->getUserByMemberNumber($payload, $this->receiver->company);
        $payload['branch_id'] = $leadReceiver->companies_branches_id;

        if (! empty($leadReceiver->template) && is_array($leadReceiver->template)) {
            $parseTemplate = new ConvertJsonTemplateToLeadStructureAction(
                $leadReceiver->template,
                $payload
            );
            $payload = $parseTemplate->execute();
        }

        $payload['receiver_id'] = $leadReceiver->getId();

        if ($leadReceiver->app->get(AppEnum::APP_DEFAULT_RECEIVER_LEAD_STATUS->value)) {
            $payload['status_id'] = $leadReceiver->app->get(AppEnum::APP_DEFAULT_RECEIVER_LEAD_STATUS->value);
        }

        $payload['type_id'] = $payload['type_id'] ?? $leadReceiver->lead_types_id;
        $payload['source_id'] = $payload['source_id'] ?? $leadReceiver->leads_sources_id;

        //get lead owner by rotation
        if ($leadReceiver->rotation) {
            $leadOwner = $leadReceiver->rotation->getAgent();
            $payload['leads_owner_id'] = $leadOwner->getId();
            $user = $leadOwner;
        }

        $createLead = new CreateLeadAction(
            Lead::from(
                $user ?? $leadReceiver->user,
                $this->receiver->app,
                $payload
            ),
            $attempt
        );

        $lead = $createLead->execute();

        if ($emailTemplate) {
            $userTemplate = 'user-' . $emailTemplate;
            $leadTemplate = 'lead-' . $emailTemplate;
            $data = [
                'lead' => $lead,
                'receiver' => $leadReceiver,
                'product' => [],
            ];
            $this->sendEmail($user, $userTemplate, $user->email, $data);
            $this->sendEmail($lead, $leadTemplate, $lead->email, $data);
        }

        $lead->fireWorkflow(
            WorkflowEnum::AFTER_RUNNING_RECEIVER->value,
            true,
            [
                'receiver' => $leadReceiver,
                'attempt' => $attempt,
            ]
        );

        return [
            'message' => 'Lead created successfully via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'lead_id' => $lead->getId(),
            'lead' => $lead->toArray(),
        ];
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
        // Extract the first key which contains our actual JSON data
        $jsonString = array_key_first($doubleEscapedJson);

        // Second decode: Convert the inner JSON string to array
        $finalJson = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException('Failed to decode inner JSON: ' . json_last_error_msg());
        }

        return $finalJson;
    }


    /**
    * Send email to user or lead using a custom template
    */
    private function sendEmail(Model $entity, string $emailTemplateName, string $email, array $leadData): void
    {
        $notification = new Blank(
            $emailTemplateName,
            $leadData,
            ['mail'],
            $entity
        );
        // $notification->setSubject($emailSubject);
        Notification::route('mail', $email)->notify($notification);
    }
}
