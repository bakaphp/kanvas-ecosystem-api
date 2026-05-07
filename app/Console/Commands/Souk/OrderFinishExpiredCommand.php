<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Actions\RecalculateSlotCapacityAction;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderExpirationNotification;

class OrderFinishExpiredCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-souk:order-finish-expired {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Finish expired orders';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->checkApps($appsId);
        } else {
            $appsIds = Settings::where([
                'name' => ConfigurationEnum::CHECK_EXPIRED_ORDERS->value,
                'value' => '1',
            ])->select('apps_id')->get()->pluck('apps_id');
            $this->info('Checking ' . $appsIds->count() . ' apps');
            foreach ($appsIds as $appsId) {
                $this->checkApps($appsId);
                $this->info('Checked ' . $appsId);
            }
        }
    }

    protected function finishOrdersExpiredOrder(Order $order): void
    {
        if (count($order->items) === 0) {
            $this->info('No items found for order ' . $order->id . ' for app ' . $order->app->name);

            return;
        }

        $order->fulfill();
        $order->transitionToStatus(
            $order->user,
            OrderStatusEnum::COMPLETED->value
        );

        new RecalculateSlotCapacityAction($order, $order->app)->execute();
        $this->sendExpiredOrderFinishedNotification($order);

        $this->info('Finished order ' . $order->id . ' for app ' . $order->app->name);
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $endTime = now()->toDateTimeString();
        $query = Order::fromApp($app)
        ->notDeleted()
        ->whereNotFulfilled()
        ->whereNotNull('metadata')
        ->whereRaw('JSON_VALID(metadata)')  // Add JSON validation
        ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
        ->orderBy('id', 'desc')
        ->with('items');

        $ordersInProgress = $query->get();
        $this->info('Found ' . $ordersInProgress->count() . ' orders in progress to finish for app ' . $app->name . ' at ' . $endTime);
        foreach ($ordersInProgress as $order) {
            $orderEndTime = $order->metadata['data']['end_at'];

            $timezone = $this->resolveOrderTimezone($order);
            $orderEndTime = Carbon::parse($orderEndTime, $timezone);

            if ($orderEndTime->isPast()) {
                $this->finishOrdersExpiredOrder($order);
            } else {
                $this->sendExpiringOrderNotification($order, $orderEndTime);
            }
        }
    }

    protected function resolveOrderTimezone(Order $order): string
    {
        $orderAppTimeZone = $order->app?->get('timezone');

        if (! empty($orderAppTimeZone)) {
            return $orderAppTimeZone;
        }

        $companyTimeZone = $order->company?->get('timezone') ?? $order->company?->timezone;

        if (! empty($companyTimeZone)) {
            return $companyTimeZone;
        }

        return config('app.timezone', 'UTC');
    }

    protected function sendExpiredOrderFinishedNotification(Order $order): void
    {
        if (! $this->shouldSendExpiredOrderFinishedNotification($order) || ! $order->user) {
            return;
        }

        $notification = new OrderExpirationNotification(
            $order,
            [
                'app' => $order->app,
                'company' => $order->company,
                'order' => $order,
                'title' => 'Your reservation has ended',
                'message' => 'Your reservation time has ended.',
                'metadata' => $order->toArray(),
                'message_owner_id' => $order->users_id,
                'message_id' => $order->getId(),
                'parent_message_id' => $order->getId(),
                'destination_id' => $order->getId(),
                'destination_type' => 'ORDER',
                'destination_event' => 'ORDER_EXPIRED_FINISHED',
            ],
            EmailTemplateEnum::ORDER_EXPIRED
        );

        $notification->setType(ConfigurationEnum::SEND_EXPIRED_ORDER_FINISHED_NOTIFICATION->value);
        $notification->channels = ['mail'];

        $order->user->notify($notification);
    }

    protected function sendExpiringOrderNotification(Order $order, Carbon $orderEndTime): void
    {
        $notifyMinutes = $this->getExpiringOrderNotificationMinutes($order);

        if (
            ! $this->shouldSendExpiringOrderNotification($order)
            || ! $order->user
            || $notifyMinutes <= 0
            || $this->expiringOrderNotificationWasSent($order)
        ) {
            return;
        }

        $now = Carbon::now($orderEndTime->timezone);

        if ($orderEndTime->greaterThan($now->copy()->addMinutes($notifyMinutes))) {
            return;
        }

        $notification = new OrderExpirationNotification(
            $order,
            [
                'app' => $order->app,
                'company' => $order->company,
                'order' => $order,
                'title' => 'Your reservation is ending soon',
                'message' => "Your reservation will end in {$notifyMinutes} minutes.",
                'metadata' => $order->toArray(),
                'message_owner_id' => $order->users_id,
                'message_id' => $order->getId(),
                'parent_message_id' => $order->getId(),
                'destination_id' => $order->getId(),
                'destination_type' => 'ORDER',
                'destination_event' => 'ORDER_EXPIRING_SOON',
            ],
            EmailTemplateEnum::ORDER_EXPIRING
        );

        $notification->setType(ConfigurationEnum::SEND_EXPIRING_ORDER_NOTIFICATION->value);
        $notification->channels = ['mail'];

        $order->user->notify($notification);
        $this->markExpiringOrderNotificationSent($order);
    }

    protected function shouldSendExpiredOrderFinishedNotification(Order $order): bool
    {
        $config = ConfigurationEnum::SEND_EXPIRED_ORDER_FINISHED_NOTIFICATION->value;

        return $this->enabled($order->app?->get($config))
            || $this->enabled($order->company?->get($config));
    }

    protected function shouldSendExpiringOrderNotification(Order $order): bool
    {
        $config = ConfigurationEnum::SEND_EXPIRING_ORDER_NOTIFICATION->value;

        return $this->enabled($order->app?->get($config))
            || $this->enabled($order->company?->get($config));
    }

    protected function getExpiringOrderNotificationMinutes(Order $order): int
    {
        $config = ConfigurationEnum::EXPIRING_ORDER_NOTIFICATION_MINUTES->value;
        $minutes = $order->app?->get($config) ?? $order->company?->get($config);

        return is_numeric($minutes) ? (int) $minutes : 0;
    }

    protected function expiringOrderNotificationWasSent(Order $order): bool
    {
        return (bool) ($order->metadata['data']['expiring_order_notification_sent_at'] ?? false);
    }

    protected function markExpiringOrderNotificationSent(Order $order): void
    {
        $order->metadata = [
            ...($order->metadata ?? []),
            'data' => [
                ...($order->metadata['data'] ?? []),
                'expiring_order_notification_sent_at' => now()->toIso8601String(),
            ],
        ];
        $order->saveQuietly();
    }

    protected function enabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
