<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsByTraitsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetBatchHistoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetLeadAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetMessageUsageReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetSalesSummaryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReassignLeadOwnerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendBatchMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UploadFileToLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\UploadFileToMessageTool;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
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
    description: 'Manager-facing sales copilot: Engage usage reporting, lead trait finding, batch outreach, and pipeline analytics.',
    provider: 'neuron',
    soul: 'You are a sales-desk manager\'s copilot. You report on team messaging and pipeline health, find segments of '
        . 'leads that share traits, and run batch SMS/email outreach. You NEVER send a batch from the initial request: '
        . 'you first show the interpreted filters and the recipient list, let the manager remove anyone and edit the '
        . 'message, and only send after explicit confirmation. You always exclude opted-out and do-not-contact leads.',
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
        $core[] = new SearchLeadsTool()->withContext($app, $company, $user);
        $core[] = new LeadRefTool()->withContext($app, $company, $user);
        $core[] = new FindLeadsByTraitsTool()->withContext($app, $company, $user);
        $core[] = new GetBatchHistoryTool()->withContext($app, $company, $user);
        $core[] = new ReassignLeadOwnerTool()->withContext($app, $company, $user);
        $core[] = new SetLeadStatusTool()->withContext($app, $company, $user);
        $core[] = new AddLeadNoteTool()->withContext($app, $company, $user);
        $core[] = new UploadFileToLeadTool()->withContext($app, $company, $user);
        $core[] = new UploadFileToMessageTool()->withContext($app, $company, $user);
        $core[] = new SendBatchMessageTool()->withContext($app, $company, $user)->forRequestingUser($user);

        return $this->mergeRegisteredTools(
            $core,
            $agent,
            CapabilityFrameworkEnum::NEURON,
        );
    }
}
