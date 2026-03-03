<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows;

use Baka\Support\Str;
use Baka\Support\Url;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum as DriveCentricConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Services\LeadService as DriveCentricLeadService;
use Kanvas\Connectors\Elead\Entities\Lead as EleadLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum as VinSolutionConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Lead as VinSolutionLead;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class CreateSocialChannelActivity extends KanvasActivity
{
    public function execute(Contact $contact, Apps $app, array $params): array
    {
        $contactTypesAllowed = [
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::EMAIL->value,
        ];

        if (! in_array($contact->contacts_types_id, $contactTypesAllowed, true)) {
            return [
                'error' => 'Contact type not allowed for social channel creation',
            ];
        }

        if (empty($params['agent_id'])) {
            return [
                'error' => 'Agent ID is required to create social channel',
            ];
        }

        $company = $contact->people->company;

        return $this->executeIntegration(
            entity: $contact,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($contact, $app, $integrationCompany, $additionalParams) use ($params): array {
                //$lead = $contact->people->leads->first();
                $lead = LeadsRepository::getPeopleActiveLead($contact->people);

                if (! $lead) {
                    return $this->failWorkflow([
                        'error' => 'No lead associated with this contact',
                    ]);
                }

                $communicationChannel = match ($contact->contacts_types_id) {
                    ContactTypeEnum::CELLPHONE->value => 'sms',
                    ContactTypeEnum::EMAIL->value => 'email',
                    default => 'unknown',
                };

                if ($communicationChannel === 'unknown') {
                    return $this->failWorkflow([
                        'error' => 'Communication channel could not be determined',
                    ]);
                }

                $channel = $this->createChannelAndSession(
                    channelKey: $communicationChannel,
                    communicationChannel: $communicationChannel,
                    contact: $contact,
                    app: $app,
                    lead: $lead,
                    agentId: (int) $params['agent_id']
                );

                // Track if channel was newly created
                $isNewChannel = $channel->wasRecentlyCreated;

                // Set preferred channel to the first channel created for this lead
                if (! $lead->get(LeadsConfigurationEnum::PREFERRED_CHANNEL->value)) {
                    $lead->set(LeadsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
                }

                if (! empty($params['create_whatsapp'])) {
                    $whatsappChannel = $this->createChannelAndSession(
                        channelKey: 'whatsapp',//slug
                        communicationChannel: $communicationChannel,
                        contact: $contact,
                        app: $app,
                        lead: $lead,
                        agentId: (int) $params['agent_id']
                    );
                    $isNewChannel = $isNewChannel || $whatsappChannel->wasRecentlyCreated;
                    $channel = $whatsappChannel;
                }

                // Create note in CRM only if a new channel was created
                $crmNoteResult = $isNewChannel ? $this->createNoteInCrm($lead, $app) : null;

                return [
                    'success' => true,
                    'channel_id' => $channel->getId(),
                    'crm_note' => $crmNoteResult,
                ];
            },
            company: $company
        );
    }

    private function createChannelAndSession(
        string $channelKey,
        string $communicationChannel,
        Contact $contact,
        Apps $app,
        Lead $lead,
        int $agentId
    ): Channel {
        $contactValue = $contact->value;
        if ($communicationChannel === 'sms') {
            $contactValue = Str::normalizePhoneNumber($contact->value);
        }

        $channelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $channelKey,
                $contactValue
            ),
        ]);

        $channel = new CreateChannelAction($channelDto)->execute();

        $sessionDto = Session::from([
            'agent' => Agent::getById($agentId),
            'channel' => $channel,
            'app' => $app,
            'company' => $lead->company,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'user' => $lead->user->toArray(),
            'canal_id' => SessionChannelService::createCanalId(
                $communicationChannel,
                $contactValue
            ),
        ]);

        new CreateSessionAction($sessionDto)->execute();

        return $channel;
    }

    /**
     * Create a note in the CRM system if the company setting is enabled.
     * Detects which CRM the lead is from (DriveCentric, eLead, VinSolution, or DealerSocket).
     */
    private function createNoteInCrm(Lead $lead, Apps $app): ?array
    {
        $company = $lead->company;

        // Check if the company has the setting enabled
        if (! $company->get('create_note_in_social_channel')) {
            return null;
        }

        // Generate the AI chat link
        $aiChatLink = SessionChannelService::generateChannelLink($lead, $app);
        $note = $this->buildCrmNote($aiChatLink, $app);

        // Detect which CRM the lead is from and create the note
        $isElead = $company->get(EleadCustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(VinSolutionCustomFieldEnum::COMPANY->value) !== null;
        $isDriveCentric = $company->get(DriveCentricConfigurationEnum::STORE_ID->value) !== null;
        $isDealerSocket = $company->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_DEALER_ID->value) !== null;

        try {
            if ($isDriveCentric) {
                return $this->createDriveCentricNote($lead, $app, $note);
            }

            if ($isElead) {
                return $this->createEleadNote($lead, $app, $note);
            }

            if ($isVinSolutions) {
                return $this->createVinSolutionNote($lead, $app, $note);
            }

            if ($isDealerSocket) {
                return $this->createDealerSocketNote($lead, $app, $note);
            }
        } catch (Throwable $e) {
            return [
                'error' => 'Failed to create CRM note: ' . $e->getMessage(),
            ];
        }

        return [
            'error' => 'No CRM integration found for this company',
        ];
    }

    private function createDriveCentricNote(Lead $lead, Apps $app, string $note): array
    {
        $leadService = new DriveCentricLeadService($app, $lead->company);
        $dealData = $leadService->formatLeadForDriveCentric($lead);
        $dealData['comments'] = $note;
        $result = $leadService->upsertDeal($dealData);

        return [
            'crm' => 'drivecentric',
            'note' => $note,
            'result' => $result,
        ];
    }

    private function createEleadNote(Lead $lead, Apps $app, string $note): array
    {
        $opportunityId = $lead->get(EleadCustomFieldEnum::OPPORTUNITY_ID->value);

        if (! $opportunityId) {
            return [
                'crm' => 'elead',
                'error' => 'Lead does not have an eLead opportunity ID',
            ];
        }

        $eleadOpportunity = EleadLead::getById($app, $lead->company, (string) $opportunityId);
        $eleadOpportunity->addComment($note);

        return [
            'crm' => 'elead',
            'note' => $note,
        ];
    }

    private function createVinSolutionNote(Lead $lead, Apps $app, string $note): array
    {
        $vinCompanyId = $lead->company->get(VinSolutionConfigurationEnum::COMPANY->value);
        $vinLeadId = $lead->get(VinSolutionCustomFieldEnum::LEADS->value);

        if (! $vinLeadId) {
            return [
                'crm' => 'vinsolution',
                'error' => 'Lead does not have a VinSolution lead ID',
            ];
        }

        $vinUserId = $lead->user->get(
            VinSolutionConfigurationEnum::getUserKey($lead->company, $lead->user)
        );

        if (! $vinUserId) {
            return [
                'crm' => 'vinsolution',
                'error' => 'User not found in VinSolution',
            ];
        }

        $vinCompany = Dealer::getById($vinCompanyId, $app);
        $user = Dealer::getUser($vinCompany, $vinUserId, $app);
        $vinLead = VinSolutionLead::getById($vinCompany, $user, $vinLeadId);
        $vinLead->addNotes($vinCompany, $user, $note);

        return [
            'crm' => 'vinsolution',
            'note' => $note,
        ];
    }

    private function createDealerSocketNote(Lead $lead, Apps $app, string $note): array
    {
        $dealerSocketLeadId = $lead->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_LEAD_ID->value);

        if (! $dealerSocketLeadId) {
            return [
                'crm' => 'dealersocket',
                'error' => 'Lead does not have a DealerSocket lead ID',
            ];
        }

        $workNoteService = new DealerSocketWorkNoteService($app, $lead->company);
        $result = $workNoteService->addSimpleNote($lead, $note);

        return [
            'crm' => 'dealersocket',
            'note' => $note,
            'result' => $result,
        ];
    }

    /**
     * Build the CRM note with the AI chat link.
     */
    private function buildCrmNote(?string $aiChatLink, Apps $app): string
    {
        if ($aiChatLink === null) {
            return 'Open Messenger to Start Conversation.';
        }

        $shortUrl = Url::getShortUrl($aiChatLink, $app) . '?openInSa=true';

        return '<br />Open Messenger to Start Conversation here: <a href="' . $shortUrl . '" target="_blank">AI Chat Conversation</a>';
    }
}
