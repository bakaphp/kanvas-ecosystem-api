<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Kanvas\Insurance\DataTransferObject\PaymentReportResult;
use Kanvas\Souk\Orders\Models\Order;

/**
 * For insurers that let the ally collect and then report the transaction, rather
 * than routing the cardholder through the insurer's own gateway. Reporting never
 * moves money and never emits — it only records how the premium was settled.
 */
interface PaymentReportProviderInterface
{
    public function reportPayment(Order $order, InsurancePaymentReport $report): PaymentReportResult;

    public function invoicePolicy(Order $order, InsurancePaymentReport $report): PaymentReportResult;
}
