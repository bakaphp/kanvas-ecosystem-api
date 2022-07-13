<?php

namespace Kanvas\Cli\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Apps\Actions\SetupAppsAction;

class AppSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'apps:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add default settings to new App';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $name = $this->ask("What's the name of the app?");
        $url = $this->ask("What's its url?");
        $description = $this->ask("Add some description of app");
        $domain = $this->ask("What's the domain of the app?");

        //If any question is empty, ask it again.
        if (empty($name) || empty($url) || empty($description) || empty($domain)) {
            $this->error('One or more question inputs are empty, please try again');
            return 0;
        }

        $ecosystemAuth = $this->confirm('Do you want authentication?', true);
        $paymentsActive = $this->confirm('Do you want payments enabled?', true);
        $isPublic = $this->confirm('Do you want want the app to be public?', true);

        $data = AppsPostData::fromConsole(
            [
            'name' => $name,
            'url' => $url,
            'description' => $description,
            'domain' => $domain,
            'is_actived' => 1,
            'ecosystem_auth' => (int)$ecosystemAuth,
            'payments_active' => (int)$paymentsActive,
            'is_public' => (int)$isPublic,
            'domain_based' => 0
            ]
        );

        $createApp = new CreateAppsAction($data);
        $app = $createApp->execute();

        $this->newLine();
        $this->info("App {$app->name} sucessfully created!");
        $this->newLine();

        return;
    }
}
