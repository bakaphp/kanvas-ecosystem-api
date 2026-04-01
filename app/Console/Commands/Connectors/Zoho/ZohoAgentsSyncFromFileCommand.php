<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class ZohoAgentsSyncFromFileCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas:zoho-agents-sync-from-file {app_id} {company_id} {file}';

    /**
     * @var string|null
     */
    protected $description = 'Sync agents from a Zoho CSV export file, updating sponsor info and deactivating inactive agents';

    public function handle()
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $file = $this->argument('file');

        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();

        $totalRecords = count(iterator_to_array($csv->getRecords()));

        if ($totalRecords === 0) {
            $this->error('The provided file has no records.');

            return Command::FAILURE;
        }

        $this->info("Syncing {$totalRecords} agents from file...");

        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();
        $synced = 0;
        $deactivated = 0;

        foreach ($records as $record) {
            $email = $record['Email'];
            $isInactive = trim($record['Inactive?'] ?? '') === 'No Success';
            $sponsorMemberNumber = $record['Sponsor'] ?? null;
            $memberNumber = $record['Member Number'] ?? null;

            $user = Users::where('email', $email)->first();
            $progressBar->advance();

            if ($user && ($agent = Agent::where('users_id', $user->getId())->where('companies_id', $company->getId())->fromApp($app)->first())) {
                $synced++;

                $assignCompanyAction = new AssignCompanyAction(
                    $user,
                    $company->defaultBranch,
                    RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company),
                    $app
                );
                $assignCompanyAction->execute();

                $user->set('member_number_' . $company->getId(), $memberNumber);

                if ($isInactive) {
                    $this->deactivateAgent($agent, $user);
                    $deactivated++;
                } else {
                    $this->updateSponsor($agent, $sponsorMemberNumber, $app, $company);
                }

                continue;
            }

            try {
                $syncZohoAgent = new SyncZohoAgentAction(
                    $app,
                    $company,
                    $email
                );

                $agent = $syncZohoAgent->execute();

                if ($isInactive) {
                    $this->deactivateAgent($agent, Users::where('email', $email)->first());
                    $deactivated++;
                } else {
                    $this->updateSponsor($agent, $sponsorMemberNumber, $app, $company);
                }
            } catch (Exception $e) {
                Log::error('Error syncing Zoho agent: ' . $e->getMessage());

                continue;
            }

            $synced++;
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Sync completed: {$synced} synced, {$deactivated} deactivated.");

        return Command::SUCCESS;
    }

    protected function updateSponsor(Agent $agent, ?string $sponsorMemberNumber, Apps $app, Companies $company): void
    {
        if ($sponsorMemberNumber === null || $sponsorMemberNumber === '') {
            return;
        }

        $sponsorAgent = Agent::where('member_id', $sponsorMemberNumber)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', false)
            ->where('status_id', 1)
            ->first();

        if ($sponsorAgent) {
            $agent->sponsor_user_id = $sponsorAgent->users_id;
            $agent->saveOrFail();

            try {
                $zohoService = new ZohoService($app, $company);
                $zohoService->updateAgent($agent);
            } catch (Exception $e) {
                Log::error('Error pushing sponsor update to Zoho for agent ' . $agent->member_id . ': ' . $e->getMessage());
            }
        }
    }

    protected function deactivateAgent(Agent $agent, ?Users $user): void
    {
        $agent->status_id = 0;
        $agent->softDelete();

        if ($user) {
            $user->softDelete();
        }
    }
}
