<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Concerns;

use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

trait PersistsEchoPayAuthState
{
    protected function rememberAuthTransactionId(Order $order, ConsumerAuthentication $consumerAuthentication): void
    {
        if (! $consumerAuthentication->authenticationTransactionId) {
            return;
        }

        if ($order->get(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value)) {
            return;
        }

        $order->set(CustomFieldEnum::ECHO_PAY_AUTH_TRANSACTION_ID->value, $consumerAuthentication->authenticationTransactionId);
    }

    protected function mapEnrollmentStatusToPaymentStatus(string $enrollmentStatus): ?string
    {
        return match ($enrollmentStatus) {
            EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            EchoPayStatusEnum::PENDING_AUTHENTICATION->value => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            EchoPayStatusEnum::AUTHENTICATION_FAILED->value => PaymentStatusEnum::FAILED->value,
            default => null,
        };
    }
}
