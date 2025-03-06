<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class KanvasSyncUserMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:sync-user-messages {app_id} {company_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync user messages table with messages from the people a user follows';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        //Get all messages from app, company and messase type
        $app_id = Apps::getById($this->argument('app_id'));
        $company_id = Companies::getById($this->argument('company_id'));
        $message_type_id = MessageType::getById($this->argument('message_type_id'));

        Message::query()
            ->where('apps_id', $app_id->getId())
            ->where('message_types_id', $message_type_id->getId())
            ->where('companies_id', $company_id->getId())
            ->where('is_deleted', 0)
            ->chunk(50, function ($messages) use ($app_id, $company_id) {
                foreach ($messages as $message) {
                    echo('-Working on message: ' . $message->getId() . PHP_EOL);
                    UsersAssociatedApps::where('apps_id', $app_id->getId())
                        ->where('companies_id', 0)
                        ->where('is_active', 1)
                        ->where('is_deleted', 0)
                        ->chunk(100, function ($users) use ($message, $app_id) {
                            foreach ($users as $user) {
                                if ($message->users_id === $user->users_id) {
                                    continue;
                                }

                                $userFollow = UsersFollows::where('users_id', $user->users_id)
                                    ->where('apps_id', $app_id->getId())
                                    ->where('entity_id', $message->users_id)
                                    ->where('entity_namespace', Users::class)
                                    ->where('is_deleted', 0)
                                    ->first();
                                if (! $userFollow) {
                                    continue;
                                }

                                $this->info('--Found user follow: ' . $user->users_id . ' with entity id: ' . $userFollow->entity_id . ' on message: ' . $message->getId() . PHP_EOL);

                                $userInteraction = UsersInteractions::fromApp($app_id)
                                    ->where('users_id', $user->users_id)
                                    ->where('interactions_id', 1642)
                                    ->where('entity_namespace', Message::class)
                                    ->where('entity_id', $message->getId())
                                    ->first();
                                if ($userInteraction) {
                                    $this->info('--Found user interaction: ' . $userInteraction->getId() . ' on message: ' . $message->getId() . 'from user: ' . $user->users_id . PHP_EOL);
                                }

                                UserMessage::updateOrCreate(
                                    [
                                        'users_id' => $user->users_id,
                                        'apps_id' => $app_id->getId(),
                                        'messages_id' => $message->getId(),
                                        'is_deleted' => 0,
                                    ],
                                    [
                                        'users_id' => $user->users_id,
                                        'apps_id' => $app_id->getId(),
                                        'is_liked' => $userInteraction ? 1 : 0,
                                        'messages_id' => $message->getId(),
                                        'is_deleted' => 0,
                                    ]
                                );

                                $this->info('---Added user message: ' . $user->users_id . ' - ' . $message->getId() . PHP_EOL);
                            }
                        });
                }
            });
    }
}
