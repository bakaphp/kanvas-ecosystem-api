<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Users\Actions\AssignCompanyAction;

class KanvasUserAddToCompany extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:users {email} {company_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add User to Company';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        //
        $email = $this->argument('email');
        $company_id = $this->argument('company_id');
        $assignCompanyAction = new AssignCompanyAction($email, $company_id);
        $assignCompanyAction->execute();
    }
}
