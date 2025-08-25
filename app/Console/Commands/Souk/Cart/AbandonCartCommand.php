<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk\Cart;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Souk\Cart\Enums\ConfigurationEnum;
use Kanvas\Souk\Cart\Models\Cart;
use Kanvas\Users\Models\Users;

/**
 * Abandon Cart Command
 */
class AbandonCartCommand extends Command
{
    protected $signature = 'souk:abandon-cart';
    protected $description = 'Process abandoned cart notifications at specified intervals by applications';

    public function handle(): int
    {
        $this->info('Starting abandoned cart notification process...');

        // Get all apps that have abandon cart notifications enabled
        $appsIds = Settings::where([
            'name' => ConfigurationEnum::ABANDON_CART_ENABLED->value,
            'value' => '1',
        ])->select('apps_id')->get()->pluck('apps_id');

        if ($appsIds->isEmpty()) {
            $this->info('No apps have abandon cart notifications enabled.');
            return 0;
        }

        $this->info('Processing ' . $appsIds->count() . ' apps with abandon cart enabled');

        foreach ($appsIds as $appId) {
            $app = Apps::getById($appId);
            $this->info("Processing abandoned carts for app: {$app->name} (ID: {$app->getId()})");

            // Process abandoned cart notifications
            $this->processAbandonedCartsForApp($app);
        }

        $this->info('Abandoned cart notification process completed.');
        return 0;
    }

    private function getNotificationIntervals(Apps $app): array
    {
        return [
            'first' => [
                'hours' => (int) ($app->get(ConfigurationEnum::ABANDON_CART_FIRST_NOTIFICATION_HOURS->value) ?? 1),
                'notification_count' => 1
            ],
            'second' => [
                'hours' => (int) ($app->get(ConfigurationEnum::ABANDON_CART_SECOND_NOTIFICATION_HOURS->value) ?? 24),
                'notification_count' => 2
            ],
            'third' => [
                'hours' => (int) ($app->get(ConfigurationEnum::ABANDON_CART_THIRD_NOTIFICATION_HOURS->value) ?? 72),
                'notification_count' => 3
            ],
        ];
    }

    private function processAbandonedCartsForApp(Apps $app): void
    {
        $this->info("Processing abandoned carts for app: {$app->name}");

        $notificationIntervals = $this->getNotificationIntervals($app);

        foreach ($notificationIntervals as $intervalType => $config) {
            $this->processCartsForInterval($app, $intervalType, $config);
        }
    }

    private function processCartsForInterval(Apps $app, string $intervalType, array $config): void
    {
        $hoursAgo = $config['hours'];
        $notificationCount = $config['notification_count'];

        $cutoffTime = Carbon::now()->subHours($hoursAgo);

        $abandonedCarts = Cart::where('apps_id', $app->getId())
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoffTime)
            ->whereNotNull('users_id')
            ->whereNotNull('email')
            ->where('notification_count', '<', $notificationCount)
            ->get();

        $this->info("Found {$abandonedCarts->count()} carts abandoned for {$hoursAgo} hours");

        foreach ($abandonedCarts as $cart) {
            $this->processAbandonedCart($cart, $intervalType, $config);
        }
    }

    private function processAbandonedCart(Cart $cart, string $intervalType, array $config): void
    {
        try {
            $user = $cart->user;

            if (! $user) {
                $this->warn("Cart {$cart->id} has no associated user, skipping");
                return;
            }

            // Mark cart as abandoned if it's the first notification
            if ($intervalType === 'first' && $cart->status === 'pending') {
                $cart->update(['status' => 'abandoned']);
            }

            $notificationData = $this->getNotificationData($cart, $intervalType, $config);

            // Send push notification
            $this->sendPushNotification($user, $notificationData);

            // Send email notification
            $this->sendEmailNotification($user, $cart, $notificationData);

            // Update cart metadata to mark notification as sent
            $this->markNotificationAsSent($cart, $config['notification_count'], $intervalType);

            $this->info("Sent {$intervalType} notification for cart {$cart->id} to user {$user->email}");

        } catch (\Exception $e) {
            Log::error("Failed to process abandoned cart {$cart->id}: " . $e->getMessage());
            $this->error("Error processing cart {$cart->id}: " . $e->getMessage());
        }
    }

    private function getNotificationData(Cart $cart, string $intervalType, array $config): array
    {
        $baseData = [
            'cart_id' => $cart->id,
            'cart_uuid' => $cart->uuid,
            'amount' => $cart->amount,
            'currency' => $cart->currency,
            'abandoned_hours' => $config['hours'],
            'notification_type' => $intervalType,
            'notification_count' => $config['notification_count'],
            'current_notification_count' => $cart->notification_count,
            'items_count' => count($cart->items ?? []),
            'session_id' => $cart->session_id,
        ];

        // Generate interval-specific content
        return match ($intervalType) {
            'first' => [
                // Empty for now - will be populated with custom templates
            ],
            'second' => [
                // Empty for now - will be populated with custom templates
            ],
            'third' => [
                // Empty for now - will be populated with custom templates
            ],
            default => $baseData
        };
    }

    private function sendPushNotification(Users $user, array $notificationData): void
    {
        try {
            $pushData = [
                'title' => $notificationData['title'],
                'body' => $notificationData['message'],
                'data' => [
                    'type' => 'abandoned_cart',
                    'cart_id' => $notificationData['cart_id'],
                    'cart_uuid' => $notificationData['cart_uuid'],
                    'discount_code' => $notificationData['discount_code'],
                    'action' => 'view_cart',
                ],
            ];

            // Create notification using Blank template
            $notification = new Blank(
                'abandoned_cart_push_' . $notificationData['notification_type'],
                $pushData,
                ['push'],
                $user
            );

            $user->notify($notification);

        } catch (\Exception $e) {
            Log::error("Failed to send push notification: " . $e->getMessage());
        }
    }

    private function sendEmailNotification(Users $user, Cart $cart, array $notificationData): void
    {
        try {
            $emailData = [
                'user_name' => $user->firstname ?? $user->email,
                'cart_amount' => $notificationData['amount'],
                'currency' => $notificationData['currency'],
                'message' => $notificationData['message'],
                'discount_code' => $notificationData['discount_code'],
                'urgency_level' => $notificationData['urgency_level'],
            ];

            // Create notification using Blank template
            $notification = new Blank(
                'abandoned_cart_' . $notificationData['notification_type'],
                $emailData,
                ['mail'],
                $cart
            );

            $notification->setSubject($notificationData['title']);
            Notification::route('mail', $user->email)->notify($notification);

        } catch (\Exception $e) {
            Log::error("Failed to send email notification: " . $e->getMessage());
        }
    }

    private function markNotificationAsSent(Cart $cart, int $notificationCount, string $intervalType): void
    {
        $metadata = $cart->metadata ?? [];
        $metadata['last_notification_sent'] = Carbon::now()->toISOString();
        $metadata['last_notification_type'] = $intervalType;

        $cart->update([
            'notification_count' => $notificationCount,
            'metadata' => $metadata
        ]);
    }
}
