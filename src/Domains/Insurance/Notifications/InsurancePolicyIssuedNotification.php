<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Notifications;

use Kanvas\Insurance\Services\InsuranceCarnet;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;

class InsurancePolicyIssuedNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
        string $carnetUrl,
    ) {
        parent::__construct($order, [
            'app' => $order->app,
            'company' => $order->company,
        ]);

        $this->setType(InsuranceCarnet::EMAIL_TEMPLATE);
        $this->setTemplateName(InsuranceCarnet::EMAIL_TEMPLATE);
        $this->setData($data);
        $this->pathAttachment = [$carnetUrl];
        $this->channels = ['mail'];
    }
}
