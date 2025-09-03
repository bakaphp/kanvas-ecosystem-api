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
use Kanvas\Souk\Cart\Enums\AbandonCartTemplateEnum;
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

            // Use configurable templates with defaults
            $emailTemplate = $app->get(AbandonCartConfigEnum::EMAIL_TEMPLATE->value) ?? 'abandoned-cart-email';
            $pushTemplate = $app->get(AbandonCartConfigEnum::PUSH_TEMPLATE->value) ?? 'abandoned-cart-push';

            $discountCode = match ($intervalType) {
                'first' => $app->get(AbandonCartConfigEnum::FIRST_DISCOUNT_CODE->value) ?? null,
                'second' => $app->get(AbandonCartConfigEnum::SECOND_DISCOUNT_CODE->value) ?? null,
                'third' => $app->get(AbandonCartConfigEnum::THIRD_DISCOUNT_CODE->value) ?? null,
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

        // Map notification count to interval type for better logic
        $expectedNotificationCount = match ($intervalType) {
            'first' => 0,   // No notifications sent yet
            'second' => 1,  // First notification already sent
            'third' => 2,   // Second notification already sent
        };

        $abandonedCarts = Cart::with('user') // Cargar relación user
            ->where('apps_id', $app->getId())
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoffTime)
            ->whereNotNull('users_id')
            ->where('notification_count', '=', $expectedNotificationCount)
            ->get();

        $this->info("Found {$abandonedCarts->count()} carts abandoned for {$hoursAgo} hours with {$expectedNotificationCount} notifications sent");

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

            if (empty($user->email)) {
                $this->warn("Cart {$cart->id} - user has no email, skipping");
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

            $this->info("Sent {$intervalType} notification for cart {$cart->id} to email {$user->email}");
        } catch (\Exception $e) {
            Log::error("Failed to process abandoned cart {$cart->id}: " . $e->getMessage());
            $this->error("Error processing cart {$cart->id}: " . $e->getMessage());
        }
    }

    private function getNotificationData(Cart $cart, string $intervalType, array $config): array
    {
        // Detect language from app settings or default to 'en'
        $lang = $cart->app->metadata['language'] ?? 'en';
        $userName = $cart->user->firstname ?? $cart->user->email ?? 'Customer';

        // Get template texts using the enum
        $emailTitleKey = match ($intervalType) {
            'first' => 'email_first_title',
            'second' => 'email_second_title',
            'third' => 'email_third_title',
        };

        $emailMessageKey = match ($intervalType) {
            'first' => 'email_first_message',
            'second' => 'email_second_message',
            'third' => 'email_third_message',
        };

        $pushTitleKey = match ($intervalType) {
            'first' => 'push_first_title',
            'second' => 'push_second_title',
            'third' => 'push_third_title',
        };

        $pushMessageKey = match ($intervalType) {
            'first' => 'push_first_message',
            'second' => 'push_second_message',
            'third' => 'push_third_message',
        };

        // Determine message keys based on interval and discount availability
        $emailMessageKey = match ($intervalType) {
            'first' => 'email_first_message',
            'second' => $config['discount_code'] ? 'email_second_message' : 'email_second_message_no_discount',
            'third' => $config['discount_code'] ? 'email_third_message' : 'email_third_message_no_discount',
            default => 'email_first_message'
        };

        $pushMessageKey = match ($intervalType) {
            'first' => 'push_first_message',
            'second' => $config['discount_code'] ? 'push_second_message' : 'push_second_message_no_discount',
            'third' => $config['discount_code'] ? 'push_third_message' : 'push_third_message_no_discount',
            default => 'push_first_message'
        };

        // Build email message
        $emailMessage = AbandonCartTemplateEnum::get($emailMessageKey, $lang);
        if ($intervalType === 'first' || ! $config['discount_code']) {
            $emailMessage = sprintf($emailMessage, $userName);
        } else {
            $emailMessage = sprintf($emailMessage, $userName, $config['discount_code']);
        }

        // Build push message
        $pushMessage = AbandonCartTemplateEnum::get($pushMessageKey, $lang);
        if ($intervalType !== 'first' && $config['discount_code']) {
            $pushMessage = sprintf($pushMessage, $config['discount_code']);
        }        return [
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
            'user_name' => $userName,
            'language' => $lang,
            'email_template' => $config['email_template'],
            'push_template' => $config['push_template'],
            'urgency_level' => match ($intervalType) {
                'first' => 'low',
                'second' => 'medium',
                'third' => 'high',
                default => 'low'
            },
            'discount_code' => $config['discount_code'],
            // Template texts
            'email_title' => AbandonCartTemplateEnum::get($emailTitleKey, $lang),
            'email_message' => $emailMessage,
            'push_title' => AbandonCartTemplateEnum::get($pushTitleKey, $lang),
            'push_message' => $pushMessage,
            'complete_purchase_text' => AbandonCartTemplateEnum::get('complete_purchase', $lang),
            'continue_shopping_text' => AbandonCartTemplateEnum::get('continue_shopping', $lang),
            'cart_summary_text' => AbandonCartTemplateEnum::get('cart_summary', $lang),
            'item_text' => AbandonCartTemplateEnum::get('item', $lang),
            'quantity_text' => AbandonCartTemplateEnum::get('quantity', $lang),
            'price_text' => AbandonCartTemplateEnum::get('price', $lang),
            'total_text' => AbandonCartTemplateEnum::get('total', $lang),
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
                // Enum-rendered texts for template
                'email_title' => $notificationData['email_title'],
                'email_message' => $notificationData['email_message'],
                'push_title' => $notificationData['push_title'],
                'push_message' => $notificationData['push_message'],
                'complete_purchase_text' => $notificationData['complete_purchase_text'],
                'continue_shopping_text' => $notificationData['continue_shopping_text'],
                'cart_summary_text' => $notificationData['cart_summary_text'],
                'item_text' => $notificationData['item_text'],
                'quantity_text' => $notificationData['quantity_text'],
                'price_text' => $notificationData['price_text'],
                'total_text' => $notificationData['total_text'],
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
