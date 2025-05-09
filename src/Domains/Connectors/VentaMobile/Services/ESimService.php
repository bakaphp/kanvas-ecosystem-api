<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\VentaMobile\Client;
use Kanvas\Exceptions\ValidationException;

class ESimService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get service info by ICCID.
     */
    public function getServiceByIccid(string $iccid): array
    {
        return $this->client->get('/services', [
            'V_ICCID' => $iccid,
        ]);
    }

    /**
     * Get available extensions (data plans).
     */
    public function getAvailableExtensions(?int $tariffPlanId = null): array
    {
        $params = [];
        if ($tariffPlanId) {
            $params['id_tariff_plan'] = $tariffPlanId;
        }

        return $this->client->get('/dictionary/extension', $params);
    }

    /**
     * Unblock a service if it's blocked.
     */
    public function unblockService(int $contractId, int $serviceId, int $blockingReason = 2000): array
    {
        return $this->client->delete('/blocking', [
            'ID_CONTRACT_INST' => $contractId,
            'ID_SERVICE_INST' => $serviceId,
            'ID_BLOCKING_REASON' => $blockingReason,
        ]);
    }

    /**
     * Activate an extension (data plan) for a service.
     */
    public function activateExtension(int $serviceId, int $extensionId): array
    {
        return $this->client->post('/extension', [
            'ID_SERVICE_INST' => $serviceId,
            'V_STATUS' => 'M', // Manual purchase
            'ID_EXTENSION' => $extensionId,
        ]);
    }

    /**
     * Check if a service is on the correct tariff plan for an extension.
     */
    protected function isServiceOnCorrectTariffPlan(array $service, int $extensionId): bool
    {
        // Get extension details to confirm tariff plan compatibility
        $extensions = $this->client->get('/get/dictionary', [
            'dict' => 'extension',
            'name' => (string) $extensionId,
        ]);

        if (empty($extensions)) {
            throw new ValidationException("Extension ID {$extensionId} not found");
        }

        // Get all tariff packets to check compatibility
        $tariffPackets = $this->client->get('/get/dictionary', [
            'dict' => 'tariffs_packets',
        ]);

        // Find which tariff plan the extension belongs to
        $tariffPlanId = null;
        foreach ($tariffPackets as $packet) {
            if (isset($packet['tariff_packets'])) {
                foreach ($packet['tariff_packets'] as $tp) {
                    if (isset($tp['ID_EXTENSION']) && $tp['ID_EXTENSION'] == $extensionId) {
                        $tariffPlanId = $packet['ID'];

                        break 2;
                    }
                }
            }
        }

        if (! $tariffPlanId) {
            throw new ValidationException("Could not determine tariff plan for extension ID {$extensionId}");
        }

        return $service['id_tariff_plan'] == $tariffPlanId;
    }

    /**
     * Buy and activate a data plan for a SIM card by ICCID.
     *
     * @param string $iccid The ICCID of the SIM card
     * @param int $extensionId The ID of the data plan/extension to activate
     * @param bool $forceUnblock Whether to force unblocking if the SIM is blocked
     * @param bool $skipTariffCheck Skip the tariff plan compatibility check
     */
    public function buyAndActivateDataPlan(
        string $iccid,
        int $extensionId,
        bool $forceUnblock = true,
        bool $skipTariffCheck = false
    ): array {
        // Step 1: Get service information by ICCID
        $serviceInfo = $this->getServiceByIccid($iccid);

        if (empty($serviceInfo)) {
            throw new ValidationException("No service found for ICCID: {$iccid}");
        }

        $service = $serviceInfo[0];
        $serviceId = $service['services_info']['id_service_inst'];
        $contractId = $service['id_contract_inst'];

        // Step 2: Check if the service is on the correct tariff plan
        if (! $skipTariffCheck && ! $this->isServiceOnCorrectTariffPlan($service, $extensionId)) {
            throw new ValidationException(
                'The service is not on the correct tariff plan for this extension. ' .
                "Service tariff plan: {$service['id_tariff_plan']}, Required for extension: {$extensionId}"
            );
        }

        // Step 3: Check if the service is blocked and unblock if necessary
        if (isset($service['services_info']['blockings_info']) &&
            ! empty($service['services_info']['blockings_info']) &&
            $forceUnblock) {
            $blocking = $service['services_info']['blockings_info'][0];
            $blockingReason = $blocking['ID_BLOCKING_REASON'];

            $this->unblockService($contractId, $serviceId, $blockingReason);
        }

        // Step 4: Activate the extension (data plan)
        $activationResult = $this->activateExtension($serviceId, $extensionId);

        return [
            'service_id' => $serviceId,
            'extension_id' => $extensionId,
            'activation_result' => $activationResult,
            'original_service' => $service,
        ];
    }

    /**
     * Get the consumption/balance of a service.
     */
    public function getServiceBalance(int $serviceId): array
    {
        $serviceDetails = $this->client->get('/services', [
            'ID_SERVICE_INST' => $serviceId,
            'show_balance' => 1,
        ]);

        if (empty($serviceDetails)) {
            throw new ValidationException("No service found with ID: {$serviceId}");
        }

        return $serviceDetails[0]['services_info']['balances'] ?? [];
    }

    /**
     * Get detailed information about extensions on a service.
     */
    public function getServiceExtensions(int $serviceId): array
    {
        return $this->client->get('/extension', [
            'ID_SERVICE_INST' => $serviceId,
        ]);
    }

    /**
     * Get detailed financial transactions for a contract.
     */
    public function getFinancialDetails(int $contractId, int $fromTimestamp = null, int $toTimestamp = null): array
    {
        $params = [
            'id_contract_inst' => $contractId,
        ];

        if ($fromTimestamp) {
            $params['dt_from'] = $fromTimestamp;
        }

        if ($toTimestamp) {
            $params['dt_to'] = $toTimestamp;
        }

        return $this->client->get('/detalization/fin', $params);
    }
}
