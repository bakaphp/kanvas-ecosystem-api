<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Users\Repositories\UserAppRepository;

class GenerateUserFollowingFeedCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-social:generate-user-following-feed {app_id} {company_id} {page_size} {clean_user_feed?}';

    protected $description = 'Generate a user message feed from users you are following';

    public function handle(): void
    {
        /** @var AppInterface $app */
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $pageSize = (int) ($this->argument('page_size') ?? 350);
        $cleanUserFeed = (bool) ($this->argument('clean_user_feed') ?? true);

        $this->info('Generating user message feed for app: ' . $app->getId());

        // Get total count for progress bar
        $totalUsers = UserAppRepository::getAllAppUsers($app)->count();
        $processedUsers = 0;
        $progress = $this->output->createProgressBar($totalUsers);
        $progress->start();

        UserAppRepository::getAllAppUsers($app)->chunk(100, function ($users) use (
            $app,
            $cleanUserFeed,
            $pageSize,
            &$processedUsers,
            $progress
        ) {
            foreach ($users as $user) {
                /** @var UserInterface $user */
                if ($cleanUserFeed) {
                    // Clean existing feed for this user
                    $totalCleanUp = UserMessage::fromApp($app)
                        ->where('users_id', $user->getId())
                        ->withTrashed()
                        ->where(function ($query) {
                            $query->where('is_liked', 0)
                                ->where('is_disliked', 0)
                                ->where('is_saved', 0)
                                ->where('is_purchased', 0)
                                ->where('is_shared', 0);
                        })
                        ->lockForUpdate()
                        ->forceDelete();

                    $this->info('Cleaned ' . $totalCleanUp . ' messages for user: ' . $user->getId());
                }

                $query = UserMessage::getFollowingFeed($user, $app);
                $messages = $query->take($pageSize)->orderBy('created_at', 'desc')->get();
                if ($messages->isEmpty()) {
                    $progress->advance();

                    continue;
                }
                // Create user messages for each message
                foreach ($messages as $message) {
                    try {
                        UserMessage::create([
                            'apps_id' => $app->getId(),
                            'messages_id' => $message->getId(),
                            'users_id' => $user->getId(),
                            'is_liked' => 0,
                            'is_disliked' => 0,
                            'is_saved' => 0,
                            'is_shared' => 0,
                            'is_purchased' => 0,
                            'is_reported' => 0,
                            'created_at' => $message->created_at,
                        ]);
                    } catch (UniqueConstraintViolationException $e) {
                    }
                }

                $processedUsers++;
                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine();
        $this->info('Successfully generated feed for ' . $processedUsers . ' users');
    }
}
