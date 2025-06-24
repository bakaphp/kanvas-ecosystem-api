<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Notifications\FollowsRecommendationsPushNotication;
use Kanvas\Connectors\Recombee\Actions\GenerateWhoToFollowRecommendationsAction;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\UsersAssociatedApps;

class PushFollowRecommendationNotificationCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-send-follow-recommendations-push-notification {app_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send Push notification recommendations to follow other users with similar interests.';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::find((int) $this->argument('app_id'));
        $messageTypeId = (int) $this->argument('message_type_id');
        $messageType = MessageType::find($messageTypeId);
        $this->overwriteAppService($app);

        $notificationMessages = [
            "✨ Based on your interests, we think you'd like @username! Tap to view their profile.",
            "We found a creator you might like! @username creates AI prompts about [category] that match your interests.",
            "Heads up! @username created something you might like. (Others do!)",
            "You and @username have similar tastes! See their latest creation."
        ];

        $via = [
            NotificationChannelEnum::getNotificationChannelBySlug('push'),
        ];
        UsersAssociatedApps::fromApp($app)
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->chunk(100, function ($users) use ($app, $via, $notificationMessages, $messageType) {
                foreach ($users as $user) {
                    $recommendedUser = (new GenerateWhoToFollowRecommendationsAction($app))->execute($user);
                    if (empty($recommendedUser)) {
                        continue;
                    }
                    $randomRecommendedUser = $recommendedUser->random();
                    $userMessagesCategories = MessagesRepository::getUserAllMessagesTags(
                        $randomRecommendedUser,
                        Companies::find($randomRecommendedUser->defaultCompany()),
                        $app,
                        $messageType->getId(),
                    );
                    $randomRecommendedUserTag =  $userMessagesCategories[array_rand($userMessagesCategories)];
                    $dynamicMessage = $notificationMessages[array_rand($notificationMessages)];

                    if (str_contains($dynamicMessage, '[category]')) {
                        $dynamicMessage = str_replace('[category]', $randomRecommendedUserTag, $dynamicMessage);
                    }

                    $dynamicMessage = str_replace('@username', $randomRecommendedUser->displayname, $dynamicMessage);
                    $followsRecommendationsNotification = new FollowsRecommendationsPushNotication(
                        $user,
                        "Follow Recommendation",
                        $dynamicMessage,
                        $via,
                        [
                            'push_template' => 'push-follow-recommendation',
                        ]
                    );
                    $user->notify($followsRecommendationsNotification);
                }
            });

        return;
    }
}
