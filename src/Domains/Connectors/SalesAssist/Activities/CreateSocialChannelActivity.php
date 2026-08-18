<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateCrmNoteAction;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelForContactAction;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'SalesAssist Create Contact Channel',
    description: 'Opens the messaging channel between an agent and a contact or lead, so there is somewhere '
        . 'for the conversation to live. Creates the channel only; it sends no message. Needs an agent, '
        . 'and does nothing without one.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'agent_id' => 'The agent the channel belongs to. Required — without it the step errors.',
    ],
)]
class CreateSocialChannelActivity extends KanvasActivity
{
    public function execute(
        Contact|Lead $entity,
        Apps $app,
        array $params
    ): array {
        if (empty($params['agent_id'])) {
            return [
                'error' => 'Agent ID is required to create social channel',
            ];
        }

        $company = $entity instanceof Lead ? $entity->company : $entity->people->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($entity, $app, $params): array {
                if ($entity instanceof Lead) {
                    return $this->executeForLead($entity, $app, $params);
                }

                return $this->executeForContact($entity, $app, $params);
            },
            company: $company
        );
    }

    private function executeForLead(Lead $lead, Apps $app, array $params): array
    {
        $results = [];
        $people = $lead->people;

        if (! $people instanceof People) {
            return [
                'error' => 'No people associated with this lead',
            ];
        }

        $contacts = $people->contacts;

        if ($contacts->isEmpty()) {
            return [
                'error' => 'No contacts found for this lead',
            ];
        }

        $hasNewChannel = false;
        foreach ($contacts as $contact) {
            $result = new CreateSocialChannelForContactAction(
                $contact,
                $app,
                $params,
                $lead
            )->execute();
            $results[] = $result;

            if (! empty($result['is_new_channel'])) {
                $hasNewChannel = true;
            }
        }

        $crmNoteResult = $hasNewChannel
            ? new CreateCrmNoteAction($lead, $app)->execute()
            : null;

        return [
            'success' => true,
            'results' => $results,
            'crm_note' => $crmNoteResult,
        ];
    }

    private function executeForContact(Contact $contact, Apps $app, array $params, ?Lead $leadOverride = null): array
    {
        return new CreateSocialChannelForContactAction(
            $contact,
            $app,
            $params,
            $leadOverride
        )->execute();
    }
}
