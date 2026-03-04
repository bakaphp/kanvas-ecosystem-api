<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

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
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Throwable;

class CreateCrmNoteAction
{
    public function __construct(
        protected Lead $lead,
        protected Apps $app
    ) {
    }

    /**
     * Create a note in the CRM system if the company setting is enabled.
     * Detects which CRM the lead is from (DriveCentric, eLead, VinSolution, or DealerSocket).
     */
    public function execute(): ?array
    {
        $company = $this->lead->company;

        // Check if the company has the setting enabled
        if (! $company->get('create_note_in_social_channel')) {
            return null;
        }

        // Generate the AI chat link
        $aiChatLink = SessionChannelService::generateChannelLink($this->lead, $this->app);
        $note = $this->buildCrmNote($aiChatLink);

        // Detect which CRM the lead is from and create the note
        $isElead = $company->get(EleadCustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(VinSolutionCustomFieldEnum::COMPANY->value) !== null;
        $isDriveCentric = $company->get(DriveCentricConfigurationEnum::STORE_ID->value) !== null;
        $isDealerSocket = $company->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_DEALER_ID->value) !== null;

        try {
            if ($isDriveCentric) {
                return $this->createDriveCentricNote($note);
            }

            if ($isElead) {
                return $this->createEleadNote($note);
            }

            if ($isVinSolutions) {
                return $this->createVinSolutionNote($note);
            }

            if ($isDealerSocket) {
                return $this->createDealerSocketNote($note);
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

    private function createDriveCentricNote(string $note): array
    {
        $leadService = new DriveCentricLeadService($this->app, $this->lead->company);
        $dealData = $leadService->formatLeadForDriveCentric($this->lead);
        $dealData['comments'] = $note;
        $result = $leadService->upsertDeal($dealData);

        return [
            'crm' => 'drivecentric',
            'note' => $note,
            'result' => $result,
        ];
    }

    private function createEleadNote(string $note): array
    {
        $opportunityId = $this->lead->get(EleadCustomFieldEnum::OPPORTUNITY_ID->value);

        if (! $opportunityId) {
            return [
                'crm' => 'elead',
                'error' => 'Lead does not have an eLead opportunity ID',
            ];
        }

        $eleadOpportunity = EleadLead::getById($this->app, $this->lead->company, (string) $opportunityId);
        $eleadOpportunity->addComment($note);

        return [
            'crm' => 'elead',
            'note' => $note,
        ];
    }

    private function createVinSolutionNote(string $note): array
    {
        $vinCompanyId = $this->lead->company->get(VinSolutionConfigurationEnum::COMPANY->value);
        $vinLeadId = $this->lead->get(VinSolutionCustomFieldEnum::LEADS->value);

        if (! $vinLeadId) {
            return [
                'crm' => 'vinsolution',
                'error' => 'Lead does not have a VinSolution lead ID',
            ];
        }

        $vinUserId = $this->lead->user->get(
            VinSolutionConfigurationEnum::getUserKey($this->lead->company, $this->lead->user)
        );

        if (! $vinUserId) {
            return [
                'crm' => 'vinsolution',
                'error' => 'User not found in VinSolution',
            ];
        }

        $vinCompany = Dealer::getById($vinCompanyId, $this->app);
        $user = Dealer::getUser($vinCompany, $vinUserId, $this->app);
        $vinLead = VinSolutionLead::getById($vinCompany, $user, $vinLeadId);
        $vinLead->addNotes($vinCompany, $user, $note);

        return [
            'crm' => 'vinsolution',
            'note' => $note,
        ];
    }

    private function createDealerSocketNote(string $note): array
    {
        $dealerSocketLeadId = $this->lead->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_LEAD_ID->value);

        if (! $dealerSocketLeadId) {
            return [
                'crm' => 'dealersocket',
                'error' => 'Lead does not have a DealerSocket lead ID',
            ];
        }

        $workNoteService = new DealerSocketWorkNoteService($this->app, $this->lead->company);
        $result = $workNoteService->addSimpleNote($this->lead, $note);

        return [
            'crm' => 'dealersocket',
            'note' => $note,
            'result' => $result,
        ];
    }

    /**
     * Build the CRM note with the AI chat link.
     */
    private function buildCrmNote(?string $aiChatLink): string
    {
        if ($aiChatLink === null) {
            return 'Open Messenger to Start Conversation.';
        }

        $shortUrl = Url::getShortUrl($aiChatLink, $this->app) . '?openInSa=true';

        return '<br />Open Messenger to Start Conversation here: <a href="' . $shortUrl . '" target="_blank">AI Chat Conversation</a>';
    }
}
