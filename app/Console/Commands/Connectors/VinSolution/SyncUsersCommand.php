<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use App\Console\Commands\Connectors\VinSolution\Concerns\InteractsWithVinSolutionCompanies;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User as VinUser;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\Users;
use Throwable;

class SyncUsersCommand extends Command
{
    use InteractsWithVinSolutionCompanies;
    use KanvasJobsTrait;

    protected $signature = 'kanvas:vinsolution-sync-users
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every VinSolution-configured company for the app}
                            {--create-missing=1 : Create Kanvas users for VinSolution users with no matching email (1=yes, 0=map only)}
                            {--default-password=Kanvas1234! : Password for created users (no email is sent)}';

    protected $description = 'Sync VinSolution dealer users into Kanvas: match by email and store the VinSolution user id, creating missing users with a default password and no email.';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = $this->argument('company_ids');
        $companies = $this->resolveVinCompanies($app, is_string($companyIds) ? $companyIds : null);

        if ($companies->isEmpty()) {
            $this->info('No VinSolution-configured companies to process.');

            return;
        }

        foreach ($companies as $company) {
            $this->processCompany($app, $company);
            $this->newLine();
        }

        $this->info('=== VinSolution user sync completed ===');
    }

    private function processCompany(Apps $app, Companies $company): void
    {
        $dealerId = $company->get(ConfigurationEnum::COMPANY->value);

        if (! $dealerId) {
            $this->error("Company {$company->getId()} does not have VinSolution configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} (dealer {$dealerId}) ===");

        try {
            $dealer = Dealer::getById((int) $dealerId, $app);
            $vinUsers = Dealer::getUsers($dealer, $app);
        } catch (Throwable $e) {
            $this->error('Failed to fetch VinSolution users: ' . $e->getMessage());

            return;
        }

        $createMissing = (bool) $this->option('create-missing');
        $matched = 0;
        $created = 0;
        $skipped = 0;

        foreach ($vinUsers as $vinUser) {
            if (empty($vinUser->email)) {
                $skipped++;

                continue;
            }

            try {
                $user = Users::getByEmail($vinUser->email);
            } catch (ModelNotFoundException) {
                if (! $createMissing) {
                    $this->warn("No Kanvas user for {$vinUser->email} — skipped (create-missing disabled)");
                    $skipped++;

                    continue;
                }

                try {
                    $user = $this->createUser($app, $company, $vinUser);
                    $created++;
                } catch (Throwable $e) {
                    $this->warn("Failed to create user {$vinUser->email}: " . $e->getMessage());
                    $skipped++;

                    continue;
                }
            }

            $user->set(
                ConfigurationEnum::getUserKey($company, $user),
                $vinUser->id
            );
            $matched++;
        }

        $this->info("Mapped: {$matched} | Created: {$created} | Skipped: {$skipped}");
    }

    /**
     * Create a Kanvas user for a VinSolution user with a default password and no
     * email — the REGISTERED workflow is disabled so no verification/welcome mail fires.
     */
    private function createUser(Apps $app, Companies $company, VinUser $vinUser): Users
    {
        /** @var CompaniesBranches $branch */
        $branch = $company->defaultBranch()->firstOrFail();

        $registerInput = RegisterInput::fromArray(
            [
                'firstname' => $vinUser->firstName,
                'lastname' => $vinUser->lastName,
                'displayname' => $vinUser->fullName ?: $vinUser->email,
                'email' => $vinUser->email,
                'password' => (string) $this->option('default-password'),
                'role_ids' => [RolesEnums::USER->value],
            ],
            $branch,
            $app
        );

        $action = new CreateUserAction($registerInput, $app);
        $action->disableWorkflow();

        return $action->execute();
    }
}
