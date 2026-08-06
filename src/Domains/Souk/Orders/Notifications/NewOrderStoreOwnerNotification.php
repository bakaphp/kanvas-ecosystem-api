<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;
use Override;

class NewOrderStoreOwnerNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
    ) {
        parent::__construct($order, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::NEW_ORDER_STORE_OWNER->value);
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }

    /**
     * The current template ships `$entity` (the order) + `$user` (the store owner), but older
     * per-tenant DB copies of `new-order-store-owner` still reference `$order`/`$admin`. Without
     * these aliases those stale templates fatal with "Undefined variable $admin" on the queue
     * (Sentry KANVAS-ECOSYSTEM-5GF); re-syncing would clobber tenant customizations, so we expose
     * both name pairs instead. `user` is only populated once via() runs, hence the null-safe fallback.
     */
    #[Override]
    public function getData(): array
    {
        $data = parent::getData();

        $data['order'] ??= $data['entity'] ?? null;
        $data['admin'] ??= $data['user'] ?? null;

        return $data;
    }
}
