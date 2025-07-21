<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateCommunicationChannelAction;
use Kanvas\Intelligence\Agents\DataTransferObject\CommunicationChannel;

class CreateDefaultChannelCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:agent:create-default-channel {app_id}';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $app = Apps::getById($appId);
        $this->overwriteAppService($app);
        $this->info('Creating default communication channel for app: ' . $app->name);
        $channel = new CommunicationChannel(
            app: $app,
            name: 'Default Channel',
            description: 'Default communication channel for the Kanvas agent',
            handler: 'kanvas.agent.default',
            config: [],
            is_active: true,
            is_published: true
        );
        $action = new CreateCommunicationChannelAction($channel);
        $action->execute();
    }
}
