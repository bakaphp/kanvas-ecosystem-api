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
     * Block a service.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param int $blockingReason The reason for blocking
     * @param int|null $startDate Start date in timestamp format
     * @param int|null $stopDate Stop date in timestamp format
     */
    public function blockService(
        int $contractId,
        int $serviceId,
        int $blockingReason,
        ?int $startDate = null,
        ?int $stopDate = null
    ): array {
        $data = [
            'ID_CONTRACT_INST' => $contractId,
            'ID_SERVICE_INST' => $serviceId,
            'ID_BLOCKING_REASON' => $blockingReason,
        ];

        if ($startDate !== null) {
            $data['DT_START'] = $startDate;
        }

        if ($stopDate !== null) {
            $data['DT_STOP'] = $stopDate;
        }

        return $this->client->post('/blocking', $data);
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
     * Activate an extension with custom values (data amount and validity period).
     *
     * @param int $serviceId The service ID
     * @param int $extensionId The extension ID
     * @param int|null $dataAmount Custom data amount in bytes
     * @param int|null $periodLengthType Type of period length
     * @param int|null $periodCount Number of periods
     */
    public function activateExtensionWithCustomValues(
        int $serviceId,
        int $extensionId,
        ?int $dataAmount = null,
        ?int $periodLengthType = null,
        ?int $periodCount = null
    ): array {
        $data = [
            'ID_SERVICE_INST' => $serviceId,
            'V_STATUS' => 'M', // Manual purchase
            'ID_EXTENSION' => $extensionId,
        ];

        if ($dataAmount !== null) {
            $data['VALUE'] = $dataAmount;
        }

        if ($periodLengthType !== null) {
            $data['PERIOD_LENGTH_TYPE'] = $periodLengthType;
        }

        if ($periodCount !== null) {
            $data['PERIOD_COUNT'] = $periodCount;
        }

        return $this->client->post('/extension', $data);
    }

    /**
     * Delete an extension (package) from a service.
     * Note: An extension can only be deleted if there have been no consumptions for it.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param int $extensionInstanceId The extension instance ID
     */
    public function deleteExtension(int $contractId, int $serviceId, int $extensionInstanceId): array
    {
        return $this->client->delete('/extension', [
            'id_contract_inst' => $contractId,
            'id_service_inst' => $serviceId,
            'id_extension_inst' => $extensionInstanceId,
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
     *
     * Using explicit nullable type with PHP 8 syntax for parameters
     */
    public function getFinancialDetails(int $contractId, ?int $fromTimestamp = null, ?int $toTimestamp = null): array
    {
        $params = [
            'id_contract_inst' => $contractId,
        ];

        if ($fromTimestamp !== null) {
            $params['dt_from'] = $fromTimestamp;
        }

        if ($toTimestamp !== null) {
            $params['dt_to'] = $toTimestamp;
        }

        return $this->client->get('/detalization/fin', $params);
    }

    /**
     * Get a list of available SIM cards/eSIMs.
     *
     * @param int $simType The SIM card type ID (required)
     * @param string $status Status of the SIM card ('free' or 'busy')
     * @param int $daysInFree Number of days after which released IMSI is available
     * @param string|null $imsiFilter Filter by IMSI (can use % as wildcard)
     * @param string|null $iccidFilter Filter by ICCID (can use % as wildcard)
     * @param int $maxRows Maximum number of results
     * @param int $offset Pagination offset
     */
    public function getAvailableSimCards(
        int $simType,
        string $status = 'free',
        int $daysInFree = 90,
        ?string $imsiFilter = null,
        ?string $iccidFilter = null,
        int $maxRows = 20,
        int $offset = 0
    ): array {
        $params = [
            'sim_type' => $simType,
            'status' => $status,
            'day_in_free' => $daysInFree,
            'max_rows' => $maxRows,
            'offset' => $offset,
        ];

        if ($imsiFilter !== null) {
            $params['IMSI'] = $imsiFilter;
        }

        if ($iccidFilter !== null) {
            $params['ICCID'] = $iccidFilter;
        }

        return $this->client->get('/get/sim', $params);
    }

    /**
     * Get a list of available phone numbers (MSISDNs).
     *
     * @param string $status Status of the number ('free', 'busy', or 'sump')
     * @param string|null $phoneFilter Filter by phone number (can use % as wildcard)
     * @param int $maxRows Maximum number of results
     * @param int $offset Pagination offset
     */
    public function getAvailablePhoneNumbers(
        string $status = 'free',
        ?string $phoneFilter = null,
        int $maxRows = 20,
        int $offset = 0
    ): array {
        $params = [
            'status' => $status,
            'max_rows' => $maxRows,
            'offset' => $offset,
        ];

        if ($phoneFilter !== null) {
            $params['phone'] = $phoneFilter;
        }

        return $this->client->get('/get/msisdn', $params);
    }

    /**
     * Create a client, contract, and service in one step.
     *
     * @param int $offerId The offer ID
     * @param string $imsi The IMSI of the SIM card
     * @param string $msisdn The phone number
     */
    public function createSubscriber(
        int $offerId,
        string $imsi,
        string $msisdn
    ): array {
        return $this->client->post('/services/new', [
            'id_offer' => $offerId,
            'v_imsi' => $imsi,
            'v_msisdn' => $msisdn,
        ]);
    }

    /**
     * Update a service - can be used to replace MSISDN or IMSI.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param string|null $newMsisdn New phone number (if changing)
     * @param string|null $newImsi New IMSI (if changing)
     */
    public function updateService(
        int $contractId,
        int $serviceId,
        ?string $newMsisdn = null,
        ?string $newImsi = null
    ): array {
        $serviceInfo = [
            'id_service_inst' => $serviceId,
        ];

        if ($newMsisdn !== null) {
            $serviceInfo['msisdn'] = $newMsisdn;
        }

        if ($newImsi !== null) {
            $serviceInfo['imsi'] = $newImsi;
        }

        return $this->client->post('/services', [
            'id_contract_inst' => $contractId,
            'services_info' => $serviceInfo,
        ]);
    }

    /**
     * Delete a service.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     */
    public function deleteService(int $contractId, int $serviceId): array
    {
        return $this->client->delete('/services', [
            'ID_CONTRACT_INST' => $contractId,
            'ID_SERVICE_INST' => $serviceId,
        ]);
    }
}
