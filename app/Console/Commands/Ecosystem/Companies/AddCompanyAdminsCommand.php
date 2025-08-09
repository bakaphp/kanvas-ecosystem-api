<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Companies;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Actions\AddAdminsToCompanyAction;

class AddCompanyAdminsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-company:add-admins {app_id} {company_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Add admins to a company';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $companyId = $this->argument('company_id') ? $this->argument('company_id') : $this->ask("What's the companyId?");

        try {
            $company = Companies::getById($companyId);
        } catch (ModelNotFoundException $e) {
            $this->newLine();
            $this->error('Company not found');
            $this->newLine();

            return ;
        }

        $branch = $company->defaultBranch;
        $adminUser = $app->keys()->firstOrFail()->user;

        (new AddAdminsToCompanyAction($app, $adminUser, $company, $branch))->execute();
        return;
    }
}
