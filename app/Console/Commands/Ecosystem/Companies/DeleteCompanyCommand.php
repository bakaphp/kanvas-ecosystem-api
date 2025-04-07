<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Companies;

use Illuminate\Console\Command;
use Kanvas\Companies\Actions\DeleteCompaniesAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;

class DeleteCompanyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-company:delete';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Delete a user company';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $companyId = $this->ask("What's the companyId?");

        try {
            $company = Companies::getById($companyId);
        } catch (ModelNotFoundException $e) {
            $this->newLine();
            $this->error('Company not found');
            $this->newLine();

            return ;
        }

        $delete = $this->confirm('ARE YOU SURE, this will softdelete all company records?', true);

        if (! $delete) {
            $this->newLine();
            $this->info('Company ' . $company->name . ' has not been deleted');
            $this->newLine();

            return;
        }

        $user = $company->user()->firstOrFail();

        $deleteCompany = new DeleteCompaniesAction($user);
        $deleteCompany->execute($company->getId());

        $this->newLine();
        $this->info('Company ' . $company->name . ' deleted');
        $this->newLine();

        return;
    }
}
