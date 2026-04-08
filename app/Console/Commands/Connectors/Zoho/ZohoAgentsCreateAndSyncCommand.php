<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Support\Random;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class ZohoAgentsCreateAndSyncCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:zoho-agents-create-sync {app_id} {company_id} {file}';

    protected $description = 'Create users from CSV if they don\'t exist, assign member numbers, and sync with Zoho if the agent exists there';

    public function handle(): int
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

        $this->info("Processing {$totalRecords} agents from file...");

        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        $created = 0;
        $existing = 0;
        $zohoSynced = 0;
        $errors = 0;

        foreach ($records as $record) {
            $email = trim($record['Email'] ?? '');
            $name = trim($record['Finance Agents Name'] ?? '');
            $sponsorMemberNumber = isset($record['Sponsor']) ? (string) $record['Sponsor'] : null;
            $isInactive = trim($record['Inactive?'] ?? '') === 'No Success';

            if (empty($email)) {
                $progressBar->advance();
                $errors++;
                Log::warning('Skipping row with empty email');

                continue;
            }

            try {
                $agentData = null;
                $needsZohoSync = false;

                $result = DB::transaction(function () use (
                    $app,
                    $company,
                    $email,
                    $name,
                    $isInactive,
                    &$created,
                    &$existing,
                    &$agentData,
                    &$needsZohoSync,
                ) {
                    $user = Users::where('email', $email)->lockForUpdate()->first();

                    if (! $user) {
                        $nameParts = explode(' ', $name ?: 'Unknown User');
                        $firstName = $nameParts[0];
                        $lastName = implode(' ', array_slice($nameParts, 1));

                        $user = new CreateUserAction(
                            new RegisterInput(
                                firstname: $firstName,
                                lastname: $lastName,
                                displayname: Random::generateDisplayNameFromEmail($email, $app),
                                email: $email,
                                password: Str::random(11),
                                branch: $company->defaultBranch,
                            ),
                            $app
                        )->execute();

                        $created++;
                    } else {
                        $existing++;
                    }

                    $assignCompanyAction = new AssignCompanyAction(
                        $user,
                        $company->defaultBranch,
                        RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company),
                        $app
                    );
                    $assignCompanyAction->execute();

                    $agent = Agent::where('users_id', $user->getId())
                        ->where('companies_id', $company->getId())
                        ->where('apps_id', $app->getId())
                        ->lockForUpdate()
                        ->first();

                    if (! $agent) {
                        $memberNumber = Agent::getNextAgentNumber($company);

                        $agentData = [
                            'apps_id' => $app->getId(),
                            'users_id' => $user->getId(),
                            'companies_id' => $company->getId(),
                            'name' => $name ?: $user->firstname . ' ' . $user->lastname,
                            'member_id' => $memberNumber,
                            'status_id' => $isInactive ? 0 : 1,
                        ];

                        $needsZohoSync = true;
                    }

                    return ['user' => $user, 'agent' => $agent];
                }, 5);

                /** @var Users $user */
                $user = $result['user'];
                /** @var ?Agent $agent */
                $agent = $result['agent'];

                if ($needsZohoSync && is_array($agentData)) {
                    $this->syncWithZoho(
                        $app,
                        $company,
                        $email,
                        $agentData,
                        $zohoSynced
                    );

                    $agent = Agent::create($agentData);
                }

                if ($agent instanceof Agent) {
                    $this->syncExistingAgentWithZoho($agent, $app, $company, $email, $zohoSynced);
                    $user->set('member_number_' . $company->getId(), $agent->member_id);

                    if ($isInactive) {
                        $agent->status_id = 0;
                        $agent->softDelete();
                    } elseif ($sponsorMemberNumber !== null && $sponsorMemberNumber !== '') {
                        $this->updateSponsor($agent, $sponsorMemberNumber, $app, $company);
                    }
                }
            } catch (Exception $e) {
                $errors++;
                Log::error('Error processing agent ' . $email . ': ' . $e->getMessage());
                $this->newLine();
                $this->warn('Error: ' . $email . ' - ' . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Done! Created: {$created}, Existing: {$existing}, Zoho synced: {$zohoSynced}, Errors: {$errors}");

        return Command::SUCCESS;
    }

    protected function syncWithZoho(
        Apps $app,
        Companies $company,
        string $email,
        array &$agentData,
        int &$zohoSynced
    ): void {
        try {
            $zohoService = new ZohoService($app, $company);
            $zohoRecord = $zohoService->getAgentByEmail($email);

            $agentData['users_linked_source_id'] = $zohoRecord->id;
            $agentData['owner_linked_source_id'] = $zohoRecord->Owner['id'] ?? null;
            $agentData['owner_id'] = $zohoRecord->Sponsor ?? null;

            if ($zohoRecord->Member_Number) {
                $existingWithMember = Agent::where('member_id', $zohoRecord->Member_Number)
                    ->where('companies_id', $company->getId())
                    ->where('apps_id', $app->getId())
                    ->first();

                if (! $existingWithMember) {
                    $agentData['member_id'] = $zohoRecord->Member_Number;
                }
            }

            $zohoSynced++;
        } catch (Exception $e) {
            Log::info('Agent not found in Zoho (' . $email . '), creating locally only: ' . $e->getMessage());
        }
    }

    protected function syncExistingAgentWithZoho(
        Agent $agent,
        Apps $app,
        Companies $company,
        string $email,
        int &$zohoSynced
    ): void {
        try {
            $zohoService = new ZohoService($app, $company);
            $zohoRecord = $zohoService->getAgentByEmail($email);

            $needsSave = false;

            if (! $agent->users_linked_source_id) {
                $agent->users_linked_source_id = $zohoRecord->id;
                $needsSave = true;
            }

            if (! $agent->owner_linked_source_id && isset($zohoRecord->Owner['id'])) {
                $agent->owner_linked_source_id = $zohoRecord->Owner['id'];
                $needsSave = true;
            }

            if (! $agent->owner_id && $zohoRecord->Sponsor) {
                $agent->owner_id = $zohoRecord->Sponsor;
                $needsSave = true;
            }

            if ($needsSave) {
                $agent->saveOrFail();
            }

            // Push member number to Zoho if the agent has one
            if ($agent->member_id !== '' && (string) $agent->users_linked_source_id !== '') {
                $zohoService->updateAgentMemberNumber($agent);
            }

            $zohoSynced++;
        } catch (Exception $e) {
            Log::warning('Could not sync existing agent with Zoho (' . $email . '): ' . $e->getMessage());
        }
    }

    protected function updateSponsor(Agent $agent, string $sponsorMemberNumber, Apps $app, Companies $company): void
    {
        $sponsorAgent = Agent::where('member_id', $sponsorMemberNumber)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', false)
            ->where('status_id', 1)
            ->first();

        if ($sponsorAgent) {
            $agent->sponsor_user_id = $sponsorAgent->users_id;
            $agent->saveOrFail();

            if ($agent->users_linked_source_id) {
                try {
                    $zohoService = new ZohoService($app, $company);
                    $zohoService->updateAgent($agent);
                } catch (Exception $e) {
                    Log::error('Error pushing sponsor update to Zoho for agent ' . $agent->member_id . ': ' . $e->getMessage());
                }
            }
        }
    }
}
