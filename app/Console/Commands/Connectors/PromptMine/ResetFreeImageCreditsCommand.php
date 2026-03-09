<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class ResetFreeImageCreditsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:promptmine-reset-free-image-credits {app_id}';

    protected $description = 'Reset daily free image credits for unsubscribed users';

    private const array FREE_CREDITS = [
        'image' => [
            'fal-ai/kling-image/o3/text-to-image' => 3,
            'fal-ai/kling-image/v3/text-to-image' => 3,
        ],
    ];

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $subscribedUserIds = $this->getSubscribedUserIds($app);

        $processed = 0;
        $skipped = 0;

        UsersAssociatedApps::where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->whereNotIn('users_id', $subscribedUserIds)
            ->chunkById(100, function ($userApps) use (&$processed, &$skipped) {
                foreach ($userApps as $userApp) {
                    $user = Users::find($userApp->users_id);

                    if (! $user) {
                        continue;
                    }

                    if ($this->shouldSkipUser($user)) {
                        $skipped++;

                        continue;
                    }

                    $user->set('public_order_credits', self::FREE_CREDITS, true);
                    $processed++;
                    $this->info("Reset free image credits for user ID: {$user->getId()}");
                }
            });

        $this->info("Done. Processed: {$processed}, Skipped (has purchased credits): {$skipped}");
    }

    private function getSubscribedUserIds(Apps $app): array
    {
        return AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('companies_id', 0)
            ->where('is_deleted', 0)
            ->whereHas('subscriptions', function ($query) {
                $query->where('stripe_status', 'active')
                    ->orWhere('stripe_status', 'trialing');
            })
            ->pluck('users_id')
            ->toArray();
    }

    private function shouldSkipUser(Users $user): bool
    {
        $currentCredits = $user->get('public_order_credits', []);

        if (empty($currentCredits)) {
            return false;
        }

        foreach (self::FREE_CREDITS['image'] as $model => $freeAmount) {
            if (isset($currentCredits['image'][$model]) && $currentCredits['image'][$model] > $freeAmount) {
                return true;
            }
        }

        return false;
    }
}
