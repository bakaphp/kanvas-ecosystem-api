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
use Kanvas\Souk\Cart\Enums\AbandonCartConfigEnum;
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
            'name' => AbandonCartConfigEnum::ENABLED->value,
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
        $intervals = [];

        foreach (['first', 'second', 'third'] as $intervalType) {
            $hours = match ($intervalType) {
                'first' => (int) ($app->get(AbandonCartConfigEnum::FIRST_HOURS->value) ?? 1),
                'second' => (int) ($app->get(AbandonCartConfigEnum::SECOND_HOURS->value) ?? 24),
                'third' => (int) ($app->get(AbandonCartConfigEnum::THIRD_HOURS->value) ?? 72),
            };

            $emailTemplate = match ($intervalType) {
                'first' => $app->get(AbandonCartConfigEnum::FIRST_EMAIL_TEMPLATE->value) ?? 'abandon-cart-first',
                'second' => $app->get(AbandonCartConfigEnum::SECOND_EMAIL_TEMPLATE->value) ?? 'abandon-cart-second',
                'third' => $app->get(AbandonCartConfigEnum::THIRD_EMAIL_TEMPLATE->value) ?? 'abandon-cart-third',
            };

            $pushTemplate = match ($intervalType) {
                'first' => $app->get(AbandonCartConfigEnum::FIRST_PUSH_TEMPLATE->value) ?? 'abandon-cart-push-first',
                'second' => $app->get(AbandonCartConfigEnum::SECOND_PUSH_TEMPLATE->value) ?? 'abandon-cart-push-second',
                'third' => $app->get(AbandonCartConfigEnum::THIRD_PUSH_TEMPLATE->value) ?? 'abandon-cart-push-third',
            };

            $discountCode = match ($intervalType) {
                'first' => $app->get(AbandonCartConfigEnum::FIRST_DISCOUNT_CODE->value),
                'second' => $app->get(AbandonCartConfigEnum::SECOND_DISCOUNT_CODE->value),
                'third' => $app->get(AbandonCartConfigEnum::THIRD_DISCOUNT_CODE->value),
            };

            $intervals[$intervalType] = [
                'hours' => $hours,
                'notification_count' => match ($intervalType) {
                    'first' => 1,
                    'second' => 2,
                    'third' => 3,
                },
                'email_template' => $emailTemplate,
                'push_template' => $pushTemplate,
                'discount_code' => $discountCode
            ];
        }

        return $intervals;
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

            if (!$user) {
                $this->warn("Cart {$cart->id} has no associated user, skipping");
                return;
            }

            // Mark cart as abandoned if it's the first notification
            if ($intervalType === 'first' && $cart->status === 'pending') {
                $cart->update(['status' => 'abandoned']);
            }

            $notificationData = $this->getNotificationData($cart, $intervalType, $config);

            // Send combined email and push notification
            $this->sendNotification($user, $cart, $notificationData);

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
        return [
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
            'cart_items' => $cart->items ?? [],
            'cart_conditions' => $cart->conditions ?? [],
            'app' => $cart->app,
            'user_name' => $cart->user->firstname ?? $cart->user->email ?? 'Customer',
            'email_template' => $config['email_template'],
            'push_template' => $config['push_template'],
            'urgency_level' => match ($intervalType) {
                'first' => 'low',
                'second' => 'medium',
                'third' => 'high',
                default => 'low'
            },
            'discount_code' => $config['discount_code'], // App-configurable discount code
        ];
    }

    private function sendNotification(Users $user, Cart $cart, array $notificationData): void
    {
        try {
            $emailTemplateName = $notificationData['email_template'];
            $pushTemplateName = $notificationData['push_template'];

            // Prepare data for both email and push notifications
            $notificationChannelData = [
                'user_name' => $user->firstname ?? $user->email,
                'cart_amount' => $notificationData['amount'],
                'currency' => $notificationData['currency'],
                'urgency_level' => $notificationData['urgency_level'],
                'cart_id' => $cart->id,
                'cart_uuid' => $cart->uuid,
                'items_count' => $notificationData['items_count'],
                'cart_items' => $notificationData['cart_items'],
                'cart_conditions' => $notificationData['cart_conditions'],
                'notification_type' => $notificationData['notification_type'],
                'abandoned_hours' => $notificationData['abandoned_hours'],
                'app' => $cart->app,
                'user' => $user,
                'cart' => $cart,
                'action' => 'view_cart',
                'type' => 'abandoned_cart',
                'discount_code' => $notificationData['discount_code'],
            ];

            // Create notification using Blank template with both email and push channels
            $notification = new Blank(
                $emailTemplateName,
                $notificationChannelData,
                ['mail', 'push'],
                $cart
            );

            // Set push template name for push notifications
            $notification->setPushTemplateName($pushTemplateName);

            // Send to user - templates will handle their own titles/subjects
            $user->notify($notification);

            $this->info("Notification sent for cart {$cart->uuid} (user: {$cart->users_id}) - {$notificationData['notification_type']} notification using email template: {$emailTemplateName}, push template: {$pushTemplateName}");
        } catch (\Exception $e) {
            $this->error("Failed to send notification for cart {$cart->uuid}: " . $e->getMessage());
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
