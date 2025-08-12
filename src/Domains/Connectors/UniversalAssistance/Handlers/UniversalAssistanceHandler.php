<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Handlers;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\UniversalAssistance\Services\LeadService;
use Kanvas\Connectors\UniversalAssistance\Services\VoucherService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class UniversalAssistanceHandler
{
    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
    }

    /**
     * Handle travel insurance quote creation
     */
    public function handleTravelQuote(array $travelData, ?People $contactPerson = null): array
    {
        $leadService = new LeadService($this->app, $this->order);

        try {
            $response = $leadService->createLead($travelData, $contactPerson);

            // Log the response
            $this->logResponse('travel_quote_created', $response);

            return $response;
        } catch (\Exception $e) {
            $this->logError('travel_quote_error', $e->getMessage(), $travelData);
            throw $e;
        }
    }

    /**
     * Handle voucher creation after successful payment
     */
    public function handleVoucherCreation(array $voucherData, People $applicant): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->createVoucher($voucherData, $applicant);

            // Log the response
            $this->logResponse('voucher_created', $response);

            return $response;
        } catch (\Exception $e) {
            $this->logError('voucher_creation_error', $e->getMessage(), $voucherData);
            throw $e;
        }
    }

    /**
     * Handle voucher query
     */
    public function handleVoucherQuery(array $queryParams): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->queryVoucher($queryParams);

            // Log the response
            $this->logResponse('voucher_queried', $response);

            return $response;
        } catch (\Exception $e) {
            $this->logError('voucher_query_error', $e->getMessage(), $queryParams);
            throw $e;
        }
    }

    /**
     * Handle PDF generation
     */
    public function handlePdfGeneration(string $voucherNumber): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->generateVoucherPdf($voucherNumber);

            // Log the response
            $this->logResponse('pdf_generated', $response);

            return $response;
        } catch (\Exception $e) {
            $this->logError('pdf_generation_error', $e->getMessage(), ['voucher_number' => $voucherNumber]);
            throw $e;
        }
    }

    /**
     * Handle lead cancellation
     */
    public function handleLeadCancellation(string $leadId, string $reasonCode = 'Venta Online'): array
    {
        $leadService = new LeadService($this->app, $this->order);

        try {
            $response = $leadService->cancelLead($leadId, $reasonCode);

            // Log the response
            $this->logResponse('lead_cancelled', $response);

            return $response;
        } catch (\Exception $e) {
            $this->logError('lead_cancellation_error', $e->getMessage(), ['lead_id' => $leadId, 'reason' => $reasonCode]);
            throw $e;
        }
    }

    /**
     * Process order for Universal Assistance integration
     */
    public function processOrder(): array
    {
        // Get order metadata
        $orderMetadata = $this->order->metadata ?? [];

        if (! isset($orderMetadata['universal_assistance'])) {
            throw new ValidationException('Universal Assistance data not found in order metadata');
        }

        $uaData = $orderMetadata['universal_assistance'];
        $results = [];

        // Step 1: Create travel quote if needed
        if (isset($uaData['travel_data'])) {
            $contactPerson = $this->order->peoples()->first();
            $results['quote'] = $this->handleTravelQuote($uaData['travel_data'], $contactPerson);
        }

        // Step 2: Create voucher after successful quote
        if (isset($uaData['voucher_data']) && ! empty($results['quote'])) {
            $applicant = $this->order->peoples()->first();
            if (! $applicant) {
                throw new ValidationException('No applicant found for voucher creation');
            }

            $results['voucher'] = $this->handleVoucherCreation($uaData['voucher_data'], $applicant);
        }

        return $results;
    }

    /**
     * Log successful response
     */
    protected function logResponse(string $action, array $response): void
    {
        Message::from([
            'message' => "Universal Assistance {$action}: " . json_encode($response),
            'system_modules_id' => $this->order->system_modules_id,
            'apps_id' => $this->order->app->getId(),
            'companies_id' => $this->order->company->getId(),
            'users_id' => $this->order->users_id,
            'message_types_id' => 1, // Success type
        ]);
    }

    /**
     * Log error
     */
    protected function logError(string $action, string $error, array $context = []): void
    {
        Message::from([
            'message' => "Universal Assistance {$action} error: {$error}. Context: " . json_encode($context),
            'system_modules_id' => $this->order->system_modules_id,
            'apps_id' => $this->order->app->getId(),
            'companies_id' => $this->order->company->getId(),
            'users_id' => $this->order->users_id,
            'message_types_id' => 2, // Error type
        ]);
    }
}
