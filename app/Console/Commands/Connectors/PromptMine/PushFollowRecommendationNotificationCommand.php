<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Notifications\FollowsRecommendationsPushNotication;
use Kanvas\Connectors\Recombee\Actions\GenerateWhoToFollowRecommendationsAction;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Users\Models\UsersAssociatedApps;


class PushFollowRecommendationNotificationCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:promptmine-send-follow-recommendations-push-notification {app_id}';

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
        $this->overwriteAppService($app);

        $notificationMessages = [
            "✨ Based on your interests, we think you'd like @username! Tap to view their profile.",
            "We found a creator you might like! @username creates AI prompts about [category] that match your interests.",
            // "Want more inspiration like [prompt title]? Follow @username who creates similar content."
        ];

        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );

        UsersAssociatedApps::fromApp($app)
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->chunk(100, function ($users) use ($app, $endViaList, $notificationMessages) {
                foreach ($users as $user) {
                    $recommendedUser = (new GenerateWhoToFollowRecommendationsAction($app))->execute($user);
                    if ($recommendedUser->isEmpty()) {
                        continue;
                    }
                    $randomRecommendedUser = $recommendedUser->random();
                    $dynamicMessage = str_replace('@username', $randomRecommendedUser->displayname, $notificationMessages[array_rand($notificationMessages)]);
                    $followsRecommendationsNotification = new FollowsRecommendationsPushNotication(
                        $user,
                        $dynamicMessage,
                        "Follow Recommendation",
                        $endViaList,
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
