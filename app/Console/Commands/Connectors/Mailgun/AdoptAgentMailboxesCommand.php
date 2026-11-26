<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Communication\Connections\Actions\EnsureProviderIntegrationAction;
use Kanvas\Communication\Connections\Actions\RefreshAgentConnectionsAction;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Intelligence\Agents\Models\Agent;

class AdoptAgentMailboxesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mailgun:adopt-agent-mailboxes {app_id? : one app; every app when omitted} {--dry-run}';

    protected $description = 'One-off for the move to Communication: gives every company that already has an agent mailbox the Mailgun integration its connections hang off, then reconciles them. Safe to run again.';

    public function handle(): int
    {
        $appId = $this->argument('app_id');

        $companies = Agent::getByCustomFieldBuilderTransactionSafe(
            name: CustomFieldEnum::MAILBOX_ADDRESS->value,
            value: null,
            useCompanyFilter: false
        )
            ->when($appId !== null, fn (Builder $query) => $query->fromApp((int) $appId))
            // A `companies_id = 0` agent is an onboarding template: there is no company to reconcile.
            ->where('companies_id', '>', 0)
            ->notDeleted()
            ->orderBy('apps_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Agent $agent): string => $agent->apps_id . ':' . $agent->companies_id);

        $adopted = 0;

        foreach ($companies as $agents) {
            $adopted += (int) $this->adopt($agents);
        }

        $this->info(sprintf('%d of %d companies with agent mailboxes reconciled.', $adopted, $companies->count()));

        return self::SUCCESS;
    }

    /**
     * Reconciling reads every mailbox of the company, so it runs once for all of them.
     *
     * @param Collection<int, Agent> $agents the agents of one company that have a mailbox
     */
    private function adopt(Collection $agents): bool
    {
        /** @var Agent $agent */
        $agent = $agents->first();
        $this->overwriteAppService($agent->app);

        $company = sprintf(
            'app %d company %d (%d agent mailboxes)',
            $agent->apps_id,
            $agent->companies_id,
            $agents->count()
        );

        if ($this->option('dry-run')) {
            $this->line($company);

            return false;
        }

        $integration = new EnsureProviderIntegrationAction(
            $agent->app,
            $agent->company,
            $agent->user,
            MailgunHandler::class
        )->execute();

        if ($integration === null) {
            $this->warn($company . ': no Mailgun integration could be given; its mailboxes will not receive.');

            return false;
        }

        new RefreshAgentConnectionsAction($agent)->execute();

        return true;
    }
}
