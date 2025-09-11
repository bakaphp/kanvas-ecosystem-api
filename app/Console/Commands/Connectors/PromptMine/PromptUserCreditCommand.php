<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\UserConfig;

class PromptUserCreditCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:prompts-user-credit {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Set user credit based on messages sent in the last 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->option('app_id'));
        $this->overwriteAppService($app);

        $userConfigs = UserConfig::query()->where('name', 'composer_ideas_used')
             ->where('value', '<>', 0)->get();

        foreach ($userConfigs as $userConfig) {
            $this->info('Resetting composer ideas for user id : ' . $userConfig->users_id);

            $messageCount = Message::getUserMessageCountInTimeFrameBuilder(
                userId: $userConfig->user->getId(),
                app: $app,
                hours: 24,
                messageTypesId: null,
                getChildrenCount: false,
                messageJsonFilters: ['type' => 'image-format']
            );

            if ($app->get('exclude-message-type-from-limit')) {
                $messageCount->whereNotIn('message_types_id', $app->get('exclude-message-type-from-limit'));
            }
            $messageCount = $messageCount->count() ?? 0;

            $this->info("Message count: $messageCount");
            $userConfig->user->set('composer_ideas_used', $messageCount, true);
        }
    }
}
