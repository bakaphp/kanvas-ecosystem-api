<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting\Concerns;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CheckAccountingSetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConfigureEcfRangeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListEcfRangesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RunAccountingSetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetReminderCadenceTool;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;
use NeuronAI\Tools\ToolInterface;
use Throwable;

/**
 * The AP/AR agents know their own readiness (plan §13.4 G): one generated line in the prompt, and no
 * tool whose dependency is missing, so the agent never finds out halfway through a task.
 */
trait AppliesAccountingReadiness
{
    /** Tools that only work with the app's Gmail connected. */
    private const array GMAIL_TOOLS = [
        'list_emails',
        'read_email_details',
        'download_attachment',
        'mark_email_as_read',
        'gmail_reply_to_thread',
    ];

    /** Tools that only work with an e-CF provider. */
    private const array ECF_TOOLS = ['check_rnc'];

    /**
     * e-NCF ranges are a company/DGII fact, not a provider setting, so they are loaded for any Dominican
     * company, before or after its provider is connected.
     */
    private const array DOMINICAN_TOOLS = ['configure_ecf_range', 'list_ecf_ranges'];

    /**
     * The tools that finish accounting setup from a conversation. The writes load only with a person at
     * the keyboard: this agent may be reading an outside sender's email, and the tools check that person
     * is an admin.
     *
     * @return list<object>
     */
    protected function accountingSetupTools(bool $runSetup = false, bool $reminderCadence = false): array
    {
        $tools = $this->addToolContext([
            new CheckAccountingSetupTool(),
            new ListEcfRangesTool(),
        ]);

        $person = $this->personAsking();
        if ($person === null || $this->app === null || $this->company === null) {
            return $tools;
        }

        $tools[] = new ConfigureEcfRangeTool()->withContext($this->app, $this->company, $person);
        if ($runSetup) {
            $tools[] = new RunAccountingSetupTool()->withContext($this->app, $this->company, $person);
        }
        if ($reminderCadence) {
            $tools[] = new SetReminderCadenceTool()->withContext($this->app, $this->company, $person);
        }

        return $tools;
    }

    /**
     * @param list<object> $tools
     * @return list<object>
     */
    protected function withoutUnavailableAccountingTools(array $tools): array
    {
        if ($this->app === null || $this->company === null) {
            return $tools;
        }

        $readiness = new AccountingReadinessService();
        $unavailable = [
            ...($readiness->gmailConnected($this->app) ? [] : self::GMAIL_TOOLS),
            ...($readiness->issuesEcf($this->company) ? [] : self::ECF_TOOLS),
            ...($readiness->isDominican($this->company) ? [] : self::DOMINICAN_TOOLS),
        ];

        return array_values(array_filter(
            $tools,
            fn (object $tool): bool => ! $tool instanceof ToolInterface || ! in_array($tool->getName(), $unavailable, true),
        ));
    }

    protected function accountingReadinessLine(): string
    {
        if ($this->app === null || $this->company === null) {
            return '';
        }

        try {
            return new AccountingReadinessService()->promptLine($this->app, $this->company);
        } catch (Throwable $e) {
            // A broken probe must not cost the turn; the agent works without its setup line.
            report($e);

            return '';
        }
    }
}
