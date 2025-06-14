<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Notifications\ExpiringReservationPushNotification;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Souk\Orders\Models\Order;

class CheckExpiringOrders
{
    public function __construct(
        protected AppInterface $apps,
    ) {
    }

    public function execute(string $timeZonedNow, ?array $notifyIn = null, array $orderIds = []): Collection
    {
        $subQuery = Order::query()
            ->fromApp($this->apps)
            ->selectRaw("
                TIMESTAMPDIFF(
                MINUTE,
                ?,
                JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at'))
                )
                AS ends_in,
                id,
                metadata
            ", [$timeZonedNow])
            ->notDeleted()
            ->whereNotFulfilled()
            ->whereNotNull('metadata')
            ->whereRaw("JSON_VALID(metadata)")
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
            ->when($orderIds, function ($query) use ($orderIds) {
                $query->whereIn('id', $orderIds);
            })
            ->orderBy('id', 'desc');

        return $subQuery->get()->when($notifyIn, fn ($query) => $query->whereIn('ends_in', $notifyIn));
    }

    public function notify(Collection $orders, array $via = ['database']): void
    {
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $via
        );

        $completeOrders = Order::whereIn('id', $orders->pluck('id'))->get();

        $orders->each(function ($order) use ($endViaList, $completeOrders) {
            $message = $order->ends_in >= 5
                ? [
                    "title" => "Atención tu parqueo está por expirar",
                    "message" => "Tu tiempo de parqueo vence en {$order->ends_in} minutos, puedes extenderlo desde la app {$this->apps->name}."
                ]
                : [
                    "title" => "Último aviso: tu parqueo vencerá",
                    "message" => "Solo te quedan {$order->ends_in} minutos, Extiende tu tiempo ahora desde {$this->apps->name}."
                ];
            $completeOrder = $completeOrders->where('id', $order->id)->first();
            $newMessageNotification = new ExpiringReservationPushNotification(
                user: $completeOrder->user,
                entity: $completeOrder,
                message: $message['message'],
                title: $message['title'],
                via: $endViaList,
                templates: [
                    'email_template' => ConfigurationEnum::NOTIFICATION_EMAIL_TEMPLATE->value,
                    'push_template' => ConfigurationEnum::NOTIFICATION_PUSH_TEMPLATE->value,
                ],
            );
            $completeOrder->user->notify($newMessageNotification);
        });
    }
}
