<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk\Cart;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Cart\Models\Cart;
use Kanvas\Users\Models\Users;
use Kanvas\Notifications\Templates\Blank;

/**
 * Cart Maintenance Command
 */
class CartMaintenanceCommand extends Command
{
    protected $signature = 'souk:cart-maintenance';
    protected $description = 'Maintain cart data synchronization with Redis and send notifications for abandoned carts at 1h, 24h, and 72h intervals';

    private array $notificationIntervals = [
        'first' => ['hours' => 1, 'sent_field' => 'first_notification_sent'],
        'second' => ['hours' => 24, 'sent_field' => 'second_notification_sent'], 
        'third' => ['hours' => 72, 'sent_field' => 'third_notification_sent'],
    ];

    public function handle(): int
    {
        $this->info('Starting cart maintenance and abandoned cart notification process...');

        $apps = Apps::all();

        foreach ($apps as $app) {
            $this->info("Processing carts for app: {$app->name} (ID: {$app->getId()})");
            
            // Sync cart data with Redis
            $this->syncCartDataWithRedis($app);
            
            // Process abandoned cart notifications
            $this->processAbandonedCartsForApp($app);
        }

        $this->info('Cart maintenance and notification process completed.');
        return 0;
    }

    private function syncCartDataWithRedis(Apps $app): void
    {
        $this->info("Syncing cart data with Redis for app: {$app->name}");
        
        // Get all active carts for this app
        $activeCarts = Cart::where('apps_id', $app->getId())
            ->whereNull('deleted_at')
            ->get();

        foreach ($activeCarts as $cart) {
            try {
                // Check if Redis data exists for this cart session
                $redisKey = "cart:{$cart->cart_session_id}";
                $redisData = Redis::get($redisKey);
                
                if ($redisData) {
                    // Update cart metadata with Redis data if needed
                    $redisCartData = json_decode($redisData, true);
                    $this->updateCartFromRedis($cart, $redisCartData);
                } else {
                    // Redis data is missing, cart might be truly abandoned
                    $this->handleMissingRedisData($cart);
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync cart {$cart->getId()} with Redis: " . $e->getMessage());
            }
        }
    }

    private function updateCartFromRedis(Cart $cart, array $redisData): void
    {
        $metadata = $cart->metadata ?? [];
        $metadata['last_redis_sync'] = Carbon::now()->toISOString();
        $metadata['redis_items_count'] = count($redisData['items'] ?? []);
        $metadata['redis_total'] = $redisData['total'] ?? 0;
        
        $cart->update(['metadata' => $metadata]);
    }

    private function handleMissingRedisData(Cart $cart): void
    {
        $metadata = $cart->metadata ?? [];
        $metadata['redis_missing_since'] = $metadata['redis_missing_since'] ?? Carbon::now()->toISOString();
        
        $cart->update(['metadata' => $metadata]);
    }

    private function processAbandonedCartsForApp(Apps $app): void
    {
        $this->info("Processing abandoned carts for app: {$app->name}");

        foreach ($this->notificationIntervals as $intervalType => $config) {
            $this->processCartsForInterval($app, $intervalType, $config);
        }
    }

    private function processCartsForInterval(Apps $app, string $intervalType, array $config): void
    {
        $hoursAgo = $config['hours'];
        $sentField = $config['sent_field'];
        
        $cutoffTime = Carbon::now()->subHours($hoursAgo);
        
        $abandonedCarts = Cart::where('apps_id', $app->getId())
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoffTime)
            ->whereNotNull('users_id')
            ->whereNotNull('email')
            ->where(function ($query) use ($sentField) {
                $query->whereNull("metadata->{$sentField}")
                    ->orWhere("metadata->{$sentField}", false);
            })
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
            
            // Send push notification
            $this->sendPushNotification($user, $notificationData);
            
            // Send email notification  
            $this->sendEmailNotification($user, $cart, $notificationData);
            
            // Update cart metadata to mark notification as sent
            $this->markNotificationAsSent($cart, $config['sent_field'], $intervalType);
            
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
        ];

        // Generate interval-specific content
        return match($intervalType) {
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

    private function markNotificationAsSent(Cart $cart, string $sentField, string $intervalType): void
    {
        $metadata = $cart->metadata ?? [];
        $metadata[$sentField] = true;
        $metadata['last_notification_sent'] = Carbon::now()->toISOString();
        $metadata['last_notification_type'] = $intervalType;
        
        $cart->update(['metadata' => $metadata]);
    }
}
