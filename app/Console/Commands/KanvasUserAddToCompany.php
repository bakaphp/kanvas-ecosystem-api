<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Repositories\UsersRepository;

class KanvasUserAddToCompany extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:users {email} {branch_id}';

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
        $email = $this->argument('email');
        $branchId = $this->argument('branch_id');
        $branch = CompaniesBranches::find($branchId);

        $assignCompanyAction = new AssignCompanyAction(UsersRepository::getByEmail($email), $branch);
        $assignCompanyAction->execute();
    }
}
