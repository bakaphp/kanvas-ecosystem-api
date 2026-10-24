<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Concerns;

use Exception;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDefinedInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;

trait ResolvesEchoPayMerchant
{
    protected function getMerchantCredentials(Order $order): array
    {
        $isMultiMerchant = $this->app->get('portal_multy_merchant') === 1;
        $orderTypeName = $order->orderType?->name;

        if ($isMultiMerchant && $orderTypeName) {
            return [
                'id' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_ID'),
                'key' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_KEY'),
                'secretKey' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_SECRET'),
            ];
        }

        return [
            'id' => $this->app->get('ECHO_PAY_MERCHANT_ID') ?? '',
            'key' => $this->app->get('ECHO_PAY_MERCHANT_KEY') ?? '',
            'secretKey' => $this->app->get('ECHO_PAY_MERCHANT_SECRET') ?? '',
        ];
    }

    protected function setupMerchantAuthentication(Payments $payment, Order $order, bool $includeDetails = false): MerchantDetail
    {
        $credentials = $this->getMerchantCredentials($order);

        if (! $credentials['id']) {
            $orderTypeName = $order->orderType?->name;
            $isMultiMerchant = $this->app->get('portal_multy_merchant') === 1;

            if ($isMultiMerchant && $orderTypeName) {
                throw new Exception("Missing merchant credentials for order type '{$orderTypeName}'. Please configure {$orderTypeName}_ECHO_PAY_MERCHANT_ID, {$orderTypeName}_ECHO_PAY_MERCHANT_KEY, and {$orderTypeName}_ECHO_PAY_MERCHANT_SECRET.");
            } else {
                throw new Exception('Missing default merchant credentials. Please configure ECHO_PAY_MERCHANT_ID, ECHO_PAY_MERCHANT_KEY, and ECHO_PAY_MERCHANT_SECRET.');
            }
        }

        return MerchantDetail::from([
            ...$credentials,
            ...($includeDetails
                ? ['merchantDefinedInformation' => new MerchantDefinedInformation(
                    category: MerchantCategoryEnum::RETAIL,
                    cardIdentifier: $credentials['id'],
                    platform: MerchantPlatformEnum::MOBILE,
                    customerId: 'user_' . $payment->user->id,
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: (string) ($payment->user->get('driver_license') ?? ''),
                )]
                : []),
        ]);
    }
}
