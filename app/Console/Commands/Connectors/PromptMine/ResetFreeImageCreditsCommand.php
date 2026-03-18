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
            'gemini-3.1-flash-image-preview' => 3,
        ],
    ];

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $subscribedUserIds = $this->getSubscribedUserIds($app);

        $processed = 0;

        UsersAssociatedApps::where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->whereNotIn('users_id', $subscribedUserIds)
            ->chunkById(100, function ($userApps) use (&$processed) {
                foreach ($userApps as $userApp) {
                    $user = Users::find($userApp->users_id);

                    if (! $user) {
                        continue;
                    }

                    $this->topUpFreeCredits($user);
                    $processed++;
                    $this->info("Reset free image credits for user ID: {$user->getId()}");
                }
            });

        $this->info("Done. Processed: {$processed}");
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

    /**
     * Top up free credits into order_credits (the key the consumption flow reads).
     *
     * - If user has purchased credits for a model, add free amount on top.
     * - If user has no purchased credits, cap at the free amount (no stacking day-over-day).
     * - Tracks last free grant in public_order_credits so we only top up the difference.
     */
    private function topUpFreeCredits(Users $user): void
    {
        /** @var array<string, array<string, int>> $orderCredits */
        $orderCredits = $user->get('order_credits', []);
        /** @var array<string, array<string, int>> $lastFreeGrant */
        $lastFreeGrant = $user->get('public_order_credits', []);
        $hasPurchased = $this->hasPurchasedCredits($orderCredits, $lastFreeGrant);

        foreach (self::FREE_CREDITS as $type => $models) {
            $orderCredits[$type] ??= [];

            foreach ($models as $model => $freeAmount) {
                $current = $orderCredits[$type][$model] ?? 0;
                $previousFree = $lastFreeGrant[$type][$model] ?? 0;

                if ($hasPurchased) {
                    // User bought credits — add the full free amount on top
                    $orderCredits[$type][$model] = $current + $freeAmount;
                } else {
                    // No purchases — top up to freeAmount, don't stack
                    $remainingFree = max(0, min($current, $previousFree));
                    $deficit = $freeAmount - $remainingFree;
                    $orderCredits[$type][$model] = $current + max(0, $deficit);
                }
            }
        }

        $user->set('order_credits', $orderCredits, true);
        $user->set('public_order_credits', self::FREE_CREDITS, false);
    }

    /**
     * @param array<string, array<string, int>> $orderCredits
     * @param array<string, array<string, int>> $lastFreeGrant
     */
    private function hasPurchasedCredits(
        array $orderCredits,
        array $lastFreeGrant
    ): bool {
        foreach (self::FREE_CREDITS as $type => $models) {
            foreach (array_keys($models) as $model) {
                $current = $orderCredits[$type][$model] ?? 0;
                $previousFree = $lastFreeGrant[$type][$model] ?? 0;

                // If current credits exceed what we last gave for free, they bought some
                if ($current > $previousFree) {
                    return true;
                }
            }
        }

        return false;
    }
}
