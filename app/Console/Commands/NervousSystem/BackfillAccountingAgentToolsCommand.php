<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RebuildAgentToolInstructionsAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOverdueInvoicesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchBillsForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MatchInvoicesForPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryApAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryArAgingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDataFreshnessTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\TopLatePayersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyApPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\CreateArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\VoidApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\VoidArInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByCustomerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesByProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\SalesRevenueTool;
use Kanvas\NervousSystem\Capability\Actions\GrantToolToAgentAction;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Throwable;

/**
 * Grants every AP/AR agent its full hardcoded toolset in `nervous_system_agent_tools` +
 * `selectedTools()`, so the admin tool screen (which reads only that DB pivot, never the
 * PHP tools() array) reflects what the agent actually has instead of appearing empty.
 * Idempotent — safe to re-run after adding a new tool to either agent's tools() method.
 */
class BackfillAccountingAgentToolsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:backfill-accounting-agent-tools
        {--app= : Only this app id}
        {--dry-run : List what would be granted without writing}';

    protected $description = 'Backfill nervous_system_agent_tools + selectedTools for every AP/AR agent so the admin tool screen matches their hardcoded tools(). Run kanvas:nervous-system:sync-tools and kanvas:intelligence:sync-agent-types first.';

    /**
     * @var array<class-string, list<class-string>>
     */
    private const AGENT_TOOL_HANDLERS = [
        AccountsPayableAgent::class => [
            QueryDataFreshnessTool::class,
            QueryApAgingTool::class,
            ListOpenBillsTool::class,
            ListOpenPurchaseOrdersTool::class,
            FindPurchaseOrderTool::class,
            FindBillTool::class,
            FindVendorTool::class,
            MatchBillsForPaymentTool::class,
            CreateApBillTool::class,
            VoidApBillTool::class,
            ApplyApPaymentTool::class,
        ],
        AccountsReceivableAgent::class => [
            QueryDataFreshnessTool::class,
            QueryArAgingTool::class,
            ListOverdueInvoicesTool::class,
            TopLatePayersTool::class,
            FindInvoiceTool::class,
            FindCustomerTool::class,
            FindSalesOrderTool::class,
            ListOpenSalesOrdersTool::class,
            FindProductTool::class,
            CreateSampleOrderTool::class,
            SalesByCustomerTool::class,
            SalesByProductTool::class,
            SalesRevenueTool::class,
            MatchInvoicesForPaymentTool::class,
            CreateArInvoiceTool::class,
            VoidArInvoiceTool::class,
            ApplyArPaymentTool::class,
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $appFilter = $this->option('app') !== null ? (int) $this->option('app') : null;

        $granted = 0;
        $failed = 0;

        foreach (self::AGENT_TOOL_HANDLERS as $agentHandler => $toolHandlers) {
            $agentType = AgentType::query()
                ->where('handler', $agentHandler)
                ->where('apps_id', 0)
                ->first();

            if ($agentType === null) {
                $this->warn("Skipping {$agentHandler}: no global AgentType found (run kanvas:intelligence:sync-agent-types first).");

                continue;
            }

            $toolIds = Tool::query()
                ->whereIn('handler', $toolHandlers)
                ->where('apps_id', 0)
                ->pluck('id', 'handler');

            $missing = array_diff($toolHandlers, $toolIds->keys()->all());
            if ($missing !== []) {
                $this->warn("Skipping " . implode(', ', $missing) . ": no catalog row (run kanvas:nervous-system:sync-tools first).");
            }

            $agents = Agent::query()
                ->where('agent_type_id', $agentType->getId())
                ->notDeleted()
                ->when($appFilter !== null, fn ($query) => $query->where('apps_id', $appFilter))
                ->get();

            foreach ($agents as $agent) {
                if ($dryRun) {
                    $this->line(sprintf('  would grant %d tool(s) to agent %d (app %d)', $toolIds->count(), $agent->getId(), $agent->apps_id));

                    continue;
                }

                try {
                    $app = Apps::getById($agent->apps_id);
                    $this->overwriteAppService($app);

                    foreach ($toolIds as $toolId) {
                        $tool = Tool::find($toolId);
                        new GrantToolToAgentAction($agent, $tool)->execute();
                    }

                    $agent->selectedTools()->syncWithoutDetaching($toolIds->values()->all());
                    new RebuildAgentToolInstructionsAction($agent, $app)->execute();

                    $granted++;
                    $this->line(sprintf('  agent %d (app %d) → %d tool(s) granted', $agent->getId(), $agent->apps_id, $toolIds->count()));
                } catch (Throwable $e) {
                    $failed++;
                    $this->error(sprintf('  agent %d FAILED: %s', $agent->getId(), $e->getMessage()));
                }
            }
        }

        $this->info(sprintf('Accounting agent tool backfill: %d agent(s) granted, %d failed.', $granted, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
