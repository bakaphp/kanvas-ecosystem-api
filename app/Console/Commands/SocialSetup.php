<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Support\Setup;
use Kanvas\Users\Models\Users;

class SocialSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'social:setup {userId} {companyId}';

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
        $company = Companies::getById((int) $this->argument('companyId'));
        $user = Users::getById((int) $this->argument('userId'));

        (new Setup(
            app(Apps::class),
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Company ' . $company->name . ' has been setup with social');
        $this->newLine();

        return;
    }
}
