<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateAIAssistChannelAction;
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
    description: 'Opens the messaging channel between an agent and a contact, lead or person, so there is somewhere '
        . 'for the conversation to live. Creates the channel only; it sends no message. Needs an agent, '
        . 'and does nothing without one.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'agent_id' => 'The agent the channel belongs to. Required — without it the step errors.',
        'ai_assist_agent_id' => 'Optional. Agent for the lead or person AI Assist channel when AI Assist is enabled; '
            . 'falls back to the company ai_assist_agent setting, then to agent_id.',
    ],
)]
class CreateSocialChannelActivity extends KanvasActivity
{
    public function execute(
        Contact|Lead|People $entity,
        Apps $app,
        array $params
    ): array {
        if (empty($params['agent_id'])) {
            return [
                'error' => 'Agent ID is required to create social channel',
            ];
        }

        $company = $entity instanceof Contact ? $entity->people->company : $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($entity, $app, $params): array {
                if ($entity instanceof Lead) {
                    return $this->executeForLead($entity, $app, $params);
                }

                if ($entity instanceof People) {
                    return $this->executeForPeople($entity, $app, $params);
                }

                return $this->executeForContact($entity, $app, $params);
            },
            company: $company
        );
    }

    private function executeForLead(Lead $lead, Apps $app, array $params): array
    {
        $people = $lead->people;

        if (! $people instanceof People) {
            return [
                'error' => 'No people associated with this lead',
            ];
        }

        if ($people->contacts->isEmpty()) {
            return [
                'error' => 'No contacts found for this lead',
            ];
        }

        $results = $this->createChannels($lead, $app, $params);
        $hasNewChannel = array_any($results, fn (array $result): bool => ! empty($result['is_new_channel']));

        $crmNoteResult = $hasNewChannel
            ? new CreateCrmNoteAction($lead, $app)->execute()
            : null;

        return [
            'success' => true,
            'results' => $results,
            'crm_note' => $crmNoteResult,
        ];
    }

    /**
     * Channels hang off the person, not a lead, so there is no CRM lead to leave a note on.
     */
    private function executeForPeople(People $people, Apps $app, array $params): array
    {
        if ($people->contacts->isEmpty()) {
            return [
                'error' => 'No contacts found for this person',
            ];
        }

        return [
            'success' => true,
            'results' => $this->createChannels($people, $app, $params),
            'crm_note' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function createChannels(Lead|People $owner, Apps $app, array $params): array
    {
        $people = $owner instanceof People ? $owner : $owner->people;

        $results = [];
        foreach ($people->contacts as $contact) {
            $results[] = new CreateSocialChannelForContactAction(
                $contact,
                $app,
                $params,
                $owner
            )->execute();
        }

        CreateAIAssistChannelAction::ifEnabled(
            $owner,
            $app,
            $params,
            (int) $params['agent_id']
        )?->execute();

        return $results;
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
