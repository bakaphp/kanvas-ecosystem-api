<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Users\Models\Users;

class KanvasAppSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:setup';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Kanvas App';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->ask("What's the name of the app?");
        $url = $this->ask("What's its url?");
        $description = $this->ask('Add some description of app');
        $domain = $this->ask("What's the domain of the app?");
        $ownerEmail = $this->ask('Wow is the owner of this app (email)?');

        $user = Users::getByEmail($ownerEmail);

        //If any question is empty, ask it again.
        if (empty($name) || empty($url) || empty($description) || empty($domain)) {
            $this->error('One or more question inputs are empty, please try again');

            return 0;
        }

        $ecosystemAuth = $this->confirm('Do you required shared ecosystem authentication?', true);
        $paymentsActive = $this->confirm('Do you want payments enabled?', true);
        $isPublic = $this->confirm('Do you want want the app to be public?', true);

        $data = AppInput::from(
            [
                'name' => $name,
                'url' => $url,
                'description' => $description,
                'domain' => $domain,
                'is_actived' => 1,
                'ecosystem_auth' => (int)$ecosystemAuth,
                'payments_active' => (int)$paymentsActive,
                'is_public' => (int)$isPublic,
                'domain_based' => 0,
            ]
        );

        $createApp = new CreateAppsAction($data, $user);
        $app = $createApp->execute();

        $this->newLine();
        $this->info("App {$app->name} successfully created! : Api Key " . $app->key);
        $this->newLine();

        return;
    }
}
