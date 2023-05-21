<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Support\Setup;
use Kanvas\Users\Models\Users;

class SocialSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-social:setup {user_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the social system';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        (new Setup(
            app(Apps::class),
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Social setup for Company ' . $company->name . ' completed successful');
        $this->newLine();

        return;
    }
}
