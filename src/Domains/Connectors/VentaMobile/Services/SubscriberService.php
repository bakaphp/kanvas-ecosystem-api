<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\VentaMobile\Client;
use Kanvas\Exceptions\ValidationException;

class SubscriberService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Create a client.
     *
     * @param bool $savePersonalData Whether to save personal data
     * @param array $personalData Personal data if saving (lastname, name, patronymic, document_*, etc.)
     * @param string|null $phone Client's phone number
     * @param string|null $email Client's email
     * @param string|null $comment Comment about the client
     */
    public function createClient(
        bool $savePersonalData = false,
        array $personalData = [],
        ?string $phone = null,
        ?string $email = null,
        ?string $comment = null
    ): array {
        $data = [
            'abonentType' => 'physical',
        ];

        if ($savePersonalData && ! empty($personalData)) {
            $data['physical'] = $personalData;
        }

        if ($phone !== null) {
            $data['phone'] = $phone;
        }

        if ($email !== null) {
            $data['email'] = $email;
        }

        if ($comment !== null) {
            $data['COMMENT'] = $comment;
        }

        return $this->client->post('/clients', $data);
    }

    /**
     * Create a contract for a client.
     *
     * @param int $clientId The client ID
     * @param string $description Contract description
     * @param string $extIdent External identifier for the contract
     * @param string $currency The currency (EUR or USD)
     */
    public function createContract(
        int $clientId,
        ?string $description = null,
        ?string $extIdent = null,
        string $currency = 'EUR'
    ): array {
        $data = [
            'id_client_inst' => $clientId,
            'currency' => $currency,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        if ($extIdent !== null) {
            $data['v_ext_ident'] = $extIdent;
        }

        return $this->client->post('/contracts', $data);
    }

    /**
     * Create a service for a contract.
     *
     * @param int $contractId The contract ID
     * @param int $offerId The offer ID
     * @param string $imsi The IMSI of the SIM card
     * @param string $msisdn The phone number
     */
    public function createService(
        int $contractId,
        int $offerId,
        string $imsi,
        string $msisdn
    ): array {
        return $this->client->post('/services/new', [
            'id_contract_inst' => $contractId,
            'id_offer' => $offerId,
            'v_imsi' => $imsi,
            'v_msisdn' => $msisdn,
        ]);
    }

    /**
     * Create a complete subscriber (client, contract, service) in one step.
     *
     * @param int $offerId The offer ID
     * @param string $imsi The IMSI of the SIM card
     * @param string $msisdn The phone number
     */
    public function createCompleteSubscriber(
        int $offerId,
        string $imsi,
        string $msisdn
    ): array {
        // Use the simplified one-step method from the API docs section 4.2
        return $this->client->post('/services/new', [
            'id_offer' => $offerId,
            'v_imsi' => $imsi,
            'v_msisdn' => $msisdn,
        ]);
    }

    /**
     * Create a subscriber with personal data by creating client, contract, and service in separate steps.
     *
     * @param int $offerId The offer ID
     * @param string $imsi The IMSI of the SIM card
     * @param string $msisdn The phone number
     * @param array $personalData Personal data (lastname, name, etc.)
     * @param string|null $phone Client's phone number
     * @param string|null $email Client's email
     * @param string|null $comment Comment about the client
     * @param string|null $contractDescription Contract description
     * @param string|null $contractExtIdent External identifier for the contract
     * @param string $currency The currency (EUR or USD)
     */
    public function createSubscriberWithPersonalData(
        int $offerId,
        string $imsi,
        string $msisdn,
        array $personalData,
        ?string $phone = null,
        ?string $email = null,
        ?string $comment = null,
        ?string $contractDescription = null,
        ?string $contractExtIdent = null,
        string $currency = 'EUR'
    ): array {
        // Step 1: Create client
        $clientResult = $this->createClient(true, $personalData, $phone, $email, $comment);

        if (! isset($clientResult['ID_CLIENT_INST'])) {
            throw new ValidationException('Failed to create client: ' . json_encode($clientResult));
        }

        $clientId = $clientResult['ID_CLIENT_INST'];

        // Step 2: Create contract
        $contractResult = $this->createContract($clientId, $contractDescription, $contractExtIdent, $currency);

        if (! isset($contractResult['id_contract_inst'])) {
            throw new ValidationException('Failed to create contract: ' . json_encode($contractResult));
        }

        $contractId = $contractResult['id_contract_inst'];

        // Step 3: Create service
        $serviceResult = $this->createService($contractId, $offerId, $imsi, $msisdn);

        return [
            'client_id' => $clientId,
            'contract_id' => $contractId,
            'service_result' => $serviceResult,
        ];
    }

    /**
     * Get a client by ID.
     *
     * @param int $clientId The client ID
     */
    public function getClient(int $clientId): array
    {
        $clients = $this->client->get('/clients', [
            'ID_CLIENT_INST' => $clientId,
        ]);

        if (empty($clients)) {
            throw new ValidationException("No client found with ID: {$clientId}");
        }

        return $clients[0];
    }

    /**
     * Get a contract by ID.
     *
     * @param int $contractId The contract ID
     */
    public function getContract(int $contractId): array
    {
        $contracts = $this->client->get('/contracts', [
            'ID_CONTRACT_INST' => $contractId,
        ]);

        if (empty($contracts)) {
            throw new ValidationException("No contract found with ID: {$contractId}");
        }

        return $contracts[0];
    }

    /**
     * Get a service by ID.
     *
     * @param int $serviceId The service ID
     * @param bool $showBalance Whether to include balance information
     * @param bool $showAddService Whether to include additional services
     */
    public function getService(int $serviceId, bool $showBalance = false, bool $showAddService = false): array
    {
        $params = [
            'ID_SERVICE_INST' => $serviceId,
        ];

        if ($showBalance) {
            $params['show_balance'] = 1;
        }

        if ($showAddService) {
            $params['show_add_service'] = 1;
        }

        $services = $this->client->get('/services', $params);

        if (empty($services)) {
            throw new ValidationException("No service found with ID: {$serviceId}");
        }

        return $services[0];
    }

    /**
     * Update a service's MSISDN (phone number).
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param string $newMsisdn The new phone number
     */
    public function updateMsisdn(int $contractId, int $serviceId, string $newMsisdn): array
    {
        return $this->client->post('/services', [
            'id_contract_inst' => $contractId,
            'services_info' => [
                'id_service_inst' => $serviceId,
                'msisdn' => $newMsisdn,
            ],
        ]);
    }

    /**
     * Update a service's IMSI.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param string $newImsi The new IMSI
     */
    public function updateImsi(int $contractId, int $serviceId, string $newImsi): array
    {
        return $this->client->post('/services', [
            'id_contract_inst' => $contractId,
            'services_info' => [
                'id_service_inst' => $serviceId,
                'imsi' => $newImsi,
            ],
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
     * Unblock a service.
     *
     * @param int $contractId The contract ID
     * @param int $serviceId The service ID
     * @param int $blockingReason The reason for blocking
     */
    public function unblockService(int $contractId, int $serviceId, int $blockingReason): array
    {
        return $this->client->delete('/blocking', [
            'ID_CONTRACT_INST' => $contractId,
            'ID_SERVICE_INST' => $serviceId,
            'ID_BLOCKING_REASON' => $blockingReason,
        ]);
    }

    /**
     * Get available phone numbers.
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
     * Get available SIM cards/eSIMs.
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
     * Search for clients by various criteria.
     *
     * @param int|null $clientId Client ID
     * @param string|null $title Search by customer name
     * @param int|null $contractId Contract ID
     * @param int|null $serviceId Service ID
     * @param string|null $msisdn Search by phone number
     * @param string|null $imsi Search by IMSI
     * @param string|null $iccid Search by ICCID
     * @param int $maxRows Maximum number of results
     * @param int $offset Pagination offset
     */
    public function searchClients(
        ?int $clientId = null,
        ?string $title = null,
        ?int $contractId = null,
        ?int $serviceId = null,
        ?string $msisdn = null,
        ?string $imsi = null,
        ?string $iccid = null,
        int $maxRows = 20,
        int $offset = 0
    ): array {
        $params = [
            'max_rows' => $maxRows,
            'offset' => $offset,
        ];

        if ($clientId !== null) {
            $params['ID_CLIENT_INST'] = $clientId;
        }

        if ($title !== null) {
            $params['V_TITLE'] = $title;
        }

        if ($contractId !== null) {
            $params['ID_CONTRACT_INST'] = $contractId;
        }

        if ($serviceId !== null) {
            $params['ID_SERVICE_INST'] = $serviceId;
        }

        if ($msisdn !== null) {
            $params['V_MSISDN'] = $msisdn;
        }

        if ($imsi !== null) {
            $params['V_IMSI'] = $imsi;
        }

        if ($iccid !== null) {
            $params['V_ICCID'] = $iccid;
        }

        return $this->client->get('/clients', $params);
    }

    /**
     * Get extension details for a service.
     *
     * @param int $serviceId The service ID
     */
    public function getServiceExtensions(int $serviceId): array
    {
        return $this->client->get('/extension', [
            'ID_SERVICE_INST' => $serviceId,
        ]);
    }

    /**
     * Get detailed information about extensions purchased for a service.
     *
     * @param int|null $contractId Contract ID
     * @param int|null $serviceId Service ID
     * @param int|null $extensionId Extension ID
     * @param int|null $fromTimestamp From date in timestamp format
     * @param int|null $toTimestamp To date in timestamp format
     */
    public function getPurchasedExtensions(
        ?int $contractId = null,
        ?int $serviceId = null,
        ?int $extensionId = null,
        ?int $fromTimestamp = null,
        ?int $toTimestamp = null
    ): array {
        $params = [];

        if ($contractId !== null) {
            $params['id_contract_inst'] = $contractId;
        }

        if ($serviceId !== null) {
            $params['id_service_inst'] = $serviceId;
        }

        if ($extensionId !== null) {
            $params['id_extension'] = $extensionId;
        }

        if ($fromTimestamp !== null) {
            $params['dt_from'] = $fromTimestamp;
        }

        if ($toTimestamp !== null) {
            $params['dt_to'] = $toTimestamp;
        }

        return $this->client->get('/detalization/extension', $params);
    }

    /**
     * Get financial details for a contract.
     *
     * @param int $contractId Contract ID
     * @param int|null $fromTimestamp From date in timestamp format
     * @param int|null $toTimestamp To date in timestamp format
     * @param int|null $balanceId Balance ID to filter by
     */
    public function getFinancialDetails(
        int $contractId,
        ?int $fromTimestamp = null,
        ?int $toTimestamp = null,
        ?int $balanceId = null
    ): array {
        $params = [
            'id_contract_inst' => $contractId,
        ];

        if ($fromTimestamp !== null) {
            $params['dt_from'] = $fromTimestamp;
        }

        if ($toTimestamp !== null) {
            $params['dt_to'] = $toTimestamp;
        }

        if ($balanceId !== null) {
            $params['id_balance'] = $balanceId;
        }

        return $this->client->get('/detalization/fin', $params);
    }

    /**
     * Find available extensions for a tariff plan.
     *
     * @param int|null $tariffPlanId Tariff plan ID
     * @param int|null $balanceId Balance ID
     */
    public function findAvailableExtensions(?int $tariffPlanId = null, ?int $balanceId = null): array
    {
        $params = [];

        if ($tariffPlanId !== null) {
            $params['id_tariff_plan'] = $tariffPlanId;
        }

        if ($balanceId !== null) {
            $params['id_balance'] = $balanceId;
        }

        return $this->client->get('/dictionary/extension', $params);
    }

    /**
     * Get available offers.
     */
    public function getOffers(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'offer',
        ]);
    }

    /**
     * Get SIM card types.
     */
    public function getSimCardTypes(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'simType',
        ]);
    }

    /**
     * Get blocking reasons.
     */
    public function getBlockingReasons(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'blocking_reason',
        ]);
    }
}
