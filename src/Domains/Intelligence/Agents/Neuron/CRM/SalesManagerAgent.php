<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddOrganizationNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddPeopleToCampaignTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddPersonNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ConvertLeadToDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadCampaignTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePeopleCampaignTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\DeleteDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\DeleteLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindDealsBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsByTraitsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPeopleBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetAssistedSalesAttributionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetBatchHistoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetDealRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetMessageUsageReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetSalesSummaryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ImportPeopleListTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LinkPersonToOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListOrganizationPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListStaleLeadsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ManagePersonContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MarkPersonDoNotContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MergeLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MergePeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadLeadActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadOrganizationActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadPersonActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReassignDealOwnerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReassignLeadOwnerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchDealsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendBatchMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetOrganizationAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetOrganizationCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UnlinkPersonFromOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadDescriptionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UploadFileToLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\CancelLeadFollowUpPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\GetLeadFollowUpPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\SetLeadFollowUpEnabledTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\SetLeadFollowUpPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\UploadFileToMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\CreateTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\GetTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\ListTemplatesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\UpdateTemplateTool;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\Users\Models\Users;
use Override;

/**
 * The manager-facing sales copilot ("Polly"/"Sally" per tenant). An internal-teammate agent
 * (inherits SystemUserAgent's identity + cross-channel memory) whose job is desk management:
 * report on team messaging usage and pipeline, find segments of leads by trait, and run
 * reviewable batch outreach. It is NOT the customer-facing SalesAgent — it talks to managers,
 * with full company-wide recall.
 *
 * The type is functional and global; each app spins up its own branded instance (Polly/Sally)
 * from it with its own dedicated user + persona.
 */
#[AgentTypeDefinition(
    name: 'Sales Manager Agent',
    description: 'Manager-facing sales copilot: Engage usage reporting, lead trait finding, batch outreach, pipeline analytics, and the CRM records themselves (leads, people, deals, organizations).',
    provider: 'neuron',
    soul: 'You are a sales-desk manager\'s copilot. You report on team messaging and pipeline health, find segments of '
        . 'leads that share traits, and run batch SMS/email outreach. You NEVER send a batch from the initial request: '
        . 'you first show the interpreted filters and the recipient list, let the manager remove anyone and edit the '
        . 'message, and only send after explicit confirmation. You always exclude opted-out and do-not-contact leads. '
        . 'You also keep the CRM: you create and update leads, people, deals and organizations on request, and you '
        . 'confirm with the manager before deleting or merging anything, because those cannot be undone from the chat.',
    outputFormat: 'Plain text. Use compact tables for report figures and recipient lists; short paragraphs otherwise.',
    role: 'Sales Manager Copilot',
)]
class SalesManagerAgent extends SystemUserAgent
{
    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        $app = $this->app;
        $company = $this->company;
        $agent = $this->agent;
        $user = $this->user;

        if ($app === null || $company === null || $agent === null || $user === null) {
            return [];
        }

        $core = $this->identityTools();

        $core[] = new GetMessageUsageReportTool()->withContext($app, $company, $user);
        $core[] = new GetLeadAnalyticsTool()->withContext($app, $company, $user);
        $core[] = new GetSalesSummaryTool()->withContext($app, $company, $user);
        $core[] = new GetAssistedSalesAttributionTool()->withContext($app, $company, $user);
        $core[] = new SearchLeadsTool()->withContext($app, $company, $user);
        $core[] = new LeadRefTool()->withContext($app, $company, $user);
        $core[] = new FindLeadsByTraitsTool()->withContext($app, $company, $user);
        $core[] = new GetBatchHistoryTool()->withContext($app, $company, $user);
        $core[] = new ReassignLeadOwnerTool()->withContext($app, $company, $user);
        $core[] = new SetLeadStatusTool()->withContext($app, $company, $user);
        $core[] = new AddLeadNoteTool()->withContext($app, $company, $user);
        $core[] = new TagLeadTool()->withContext($app, $company, $user);
        $core[] = new FindPersonTool()->withContext($app, $company, $user);
        $core[] = new FindPeopleBulkTool()->withContext($app, $company, $user);
        $core[] = new ListPeopleTool()->withContext($app, $company, $user);
        $core[] = new GetPersonTool()->withContext($app, $company, $user);
        $core[] = new AddPersonNoteTool()->withContext($app, $company, $user);
        $core[] = new AddOrganizationNoteTool()->withContext($app, $company, $user);
        $core[] = new ReadLeadActivityTool()->withContext($app, $company, $user, $agent);
        $core[] = new ReadPersonActivityTool()->withContext($app, $company, $user, $agent);
        $core[] = new ReadOrganizationActivityTool()->withContext($app, $company, $user, $agent);
        $core[] = new UploadFileToLeadTool()->withContext($app, $company, $user);
        $core[] = new UploadFileToMessageTool()->withContext($app, $company, $user);
        $core[] = new SendBatchMessageTool()->withContext($app, $company, $user)->forRequestingUser($user);
        $core[] = new ImportPeopleListTool()->withContext($app, $company, $user)->forRequestingUser($user);
        $core[] = new CreateLeadCampaignTool()->withContext($app, $company, $user)->forRequestingUser($user);
        $core[] = new CreatePeopleCampaignTool()->withContext($app, $company, $user)->forRequestingUser($user);
        $core[] = new AddPeopleToCampaignTool()->withContext($app, $company, $user)->forRequestingUser($user);
        $core[] = new CreateTemplateTool()->withContext($app, $company, $user);
        $core[] = new ListTemplatesTool()->withContext($app, $company, $user);
        $core[] = new GetTemplateTool()->withContext($app, $company, $user);
        $core[] = new UpdateTemplateTool()->withContext($app, $company, $user);

        // A manager keeps the CRM itself: the full record set over leads, people, deals and
        // organizations. Deletes and merges carry their own admin gate in the tool.
        $core = [...$core, ...$this->crmRecordTools($app, $company, $user)];

        $core = [...$core, ...$this->followThroughTools($agent)];

        // A rep's own drip on a lead is a sales-manager capability, not teammate plumbing: any other
        // system-user agent gets these four from the catalog, by grant.
        $core = [...$core, ...$this->leadFollowUpPlanTools($agent, $this->requestingHuman() ?? $user)];

        return $this->mergeRegisteredTools(
            $core,
            $agent,
            CapabilityFrameworkEnum::NEURON,
        );
    }

    /**
     * @return list<object>
     */
    private function leadFollowUpPlanTools(Agent $agent, Users $human): array
    {
        return [
            new SetLeadFollowUpPlanTool()->withContext(
                $this->app,
                $this->company,
                $human,
                $agent,
            ),
            new GetLeadFollowUpPlanTool()->withContext(
                $this->app,
                $this->company,
                $human,
                $agent,
            ),
            new CancelLeadFollowUpPlanTool()->withContext(
                $this->app,
                $this->company,
                $human,
                $agent,
            ),
            new SetLeadFollowUpEnabledTool()->withContext(
                $this->app,
                $this->company,
                $human,
                $agent,
            ),
        ];
    }

    /**
     * Three of these take their context in the constructor rather than through withContext().
     *
     * @return list<object>
     */
    private function crmRecordTools(Apps $app, Companies $company, Users $user): array
    {
        $contextual = [
            new UpdateLeadTool(),
            new UpdateLeadDescriptionTool(),
            new SetLeadContactTool(),
            new SetLeadCustomFieldsTool(),
            new ConvertLeadToDealTool(),
            new MergeLeadTool(),
            new DeleteLeadTool(),
            new FindLeadsBulkTool(),
            new ListStaleLeadsTool(),
            new CreatePersonTool(),
            new UpdatePersonTool(),
            new ManagePersonContactTool(),
            new SetPersonAddressTool(),
            new SetPersonCustomFieldsTool(),
            new TagPersonTool(),
            new MarkPersonDoNotContactTool(),
            new MergePeopleTool(),
            new LinkPersonToOrganizationTool(),
            new UnlinkPersonFromOrganizationTool(),
            new DeleteDealTool(),
            new ReassignDealOwnerTool(),
            new GetDealRefTool(),
            new SearchDealsTool(),
            new FindDealsBulkTool(),
            new GetDealAnalyticsTool(),
            new CreateOrganizationTool(),
            new UpdateOrganizationTool(),
            new GetOrganizationTool(),
            new ListOrganizationPeopleTool(),
            new SetOrganizationAddressTool(),
            new SetOrganizationCustomFieldsTool(),
            new TagOrganizationTool(),
        ];

        return [
            new CreateLeadTool($app, $company, $user),
            new CreateDealTool($app, $company, $user),
            new UpdateDealTool($app, $company, $user),
            ...array_map(fn (object $tool): object => $tool->withContext($app, $company, $user), $contextual),
        ];
    }
}
