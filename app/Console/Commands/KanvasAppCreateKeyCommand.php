<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class KanvasAppCreateKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:app-key {name} {app_id} {email}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new Kanvas App Key';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $appId = $this->argument('app_id');

        $app = Apps::getById($appId);
        $user = Users::getByEmail($email);

        UsersRepository::belongsToThisApp($user, $app);

        $appKey = (
            new CreateAppKeyAction(
                new AppKeyInput(
                    $name,
                    $app,
                    $user
                )
            )
        )->execute();

        $this->newLine();
        $this->info('App Key created successfully: ' . $appKey->client_id);
        $this->newLine();
        $this->info('Secret: ' . $appKey->client_secret_id);

        return;
    }
}
