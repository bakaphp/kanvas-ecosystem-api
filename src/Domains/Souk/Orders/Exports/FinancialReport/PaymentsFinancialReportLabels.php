<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport;

use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;

final class PaymentsFinancialReportLabels
{
    public static function channel(PaymentChannelEnum $channel): string
    {
        return match ($channel) {
            PaymentChannelEnum::DIGITAL => 'Digital',
            PaymentChannelEnum::PHYSICAL => 'Físico',
        };
    }

    public static function channelFilter(?PaymentChannelEnum $channel): string
    {
        return $channel === null ? 'Todos' : self::channel($channel);
    }

    public static function summaryMethod(PaymentChannelEnum $channel): string
    {
        return match ($channel) {
            PaymentChannelEnum::DIGITAL => 'Tarjeta / aplicación (digital)',
            PaymentChannelEnum::PHYSICAL => 'Transferencia / depósito (físico)',
        };
    }
}
