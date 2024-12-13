<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Enums\AppEnum;
use Kanvas\Guild\Leads\Actions\ConvertJsonTemplateToLeadStructureAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class CreateLeadsFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp($this->receiver->configuration['receiver_id'], $this->receiver->company, $this->receiver->app);
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
}
