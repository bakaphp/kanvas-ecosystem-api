<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\CMLink\Client;

class OrderService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Create an order.
     *
     * @param string $thirdOrderId Unique order ID from the client.
     * @param string $iccid ICCID of the target SIM card.
     * @param int $quantity Number of bundles to purchase.
     * @param int $isRefuel Indicates whether this is an add-on package (0 = yes, 1 = no).
     * @param string $dataBundleId ID of the data package to purchase.
     */
    public function createOrder(
        string $thirdOrderId,
        string $iccid,
        int $quantity,
        string $dataBundleId,
        string $activeDate,
        int $isRefuel = 1,
    ): array {
        return $this->client->post('/aep/APP_createOrder_SBO/v1', [
            'thirdOrderId' => $thirdOrderId,
            'ICCID' => $iccid,
            'quantity' => $quantity,
            'is_Refuel' => (string) $isRefuel,
            'includeCard' => 0, // Assuming 0 means no physical card
            'dataBundleId' => $dataBundleId,
            'sendLang' => 2,
            //'setActiveTime' => date('Ymd', strtotime($activeDate)),
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }

    /**
     * Refuel an order.
     *
     * @param string $thirdOrderId Unique order ID from the client.
     * @param string $iccid ICCID of the target SIM card.
     * @param int $quantity Number of bundles to purchase.
     * @param int $isRefuel Indicates whether this is an add-on package (0 = yes, 1 = no).
     * @param int $refuelingId Add-on package ID, it is required if is_Refuel is 0
     * @param string $dataBundleId ID of the data package to purchase.
     */
    public function refuelOrder(
        string $thirdOrderId,
        string $iccid,
        int $quantity,
        string $activeDate,
        string $refuelingId
    ): array {
        return $this->client->post('/aep/APP_createOrder_SBO/v1', [
            'thirdOrderId' => $thirdOrderId,
            'ICCID' => $iccid,
            'quantity' => $quantity,
            'is_Refuel' => '0',
            'refuelingId' => (string) $refuelingId,
            'includeCard' => 0, // Assuming 0 means no physical card
            'sendLang' => 2,
            //'setActiveTime' => date('Ymd', strtotime($activeDate)),
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }

    public function getOrderStatus(string|int $thirdOrderId): array
    {
        $response = $this->client->post('/aep/APP_getSubscriberAllQuota_SBO/v1', [
            'thirdOrderId' => $thirdOrderId,
            'accessToken' => $this->client->getAccessToken(),
        ]);

        // Check if 'quotaList' exists and is an array
        if (! isset($response['quotaList']) || ! is_array($response['quotaList'])) {
            return ['total' => 0];
        }

        // Loop through quotaList items
        foreach ($response['quotaList'] as $quotaItem) {
            // Check if subscriberQuota exists and is not empty
            if (! empty($quotaItem['subscriberQuota']) && isset($quotaItem['subscriberQuota']['qtabalance'])) {
                $totalConsumptionMB = $quotaItem['subscriberQuota']['qtabalance'];
                break;
            }
        }

        if (is_null($totalConsumptionMB)) {
            // If no subscriberQuota found, return total 0
            return ["total" => 0];
        }

        // Convert MB to required format (MB * 1,000,000 to match 500MB = 500000000)
        $totalConsumptionFormatted = (int) ($totalConsumptionMB * 1000000);

        return [
            'total' => $totalConsumptionFormatted,
            'original_response' => $response, // Keep original response for debugging
        ];
    }

    /**
     * Create an order and ensure activation.
     *
     * @param string $thirdOrderId Unique order ID from the client.
     * @param string $iccid ICCID of the target SIM card.
     * @param int $quantity Number of bundles to purchase.
     * @param int $isRefuel Indicates whether this is an add-on package (0 = yes, 1 = no).
     * @param string $dataBundleId ID of the data package to purchase.
     */
    public function createOrderWithActivation(
        string $thirdOrderId,
        string $iccid,
        int $quantity,
        string $dataBundleId,
        string $activeDate,
        int $isRefuel = 1
    ): array {
        $orderResponse = $this->createOrder(
            $thirdOrderId,
            $iccid,
            $quantity,
            $dataBundleId,
            $activeDate,
            $isRefuel
        );

        // Step 2: Activate the package immediately after order creation
        $planService = new PlanService($this->app, $this->company);
        $planService->activatePlan($dataBundleId, $iccid);

        return $orderResponse;
    }
}
