<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class UniversalAssistanceService
{
    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
    }

    /**
     * Handle travel quote request
     */
    public function handleTravelQuote(array $travelData, ?People $contactPerson = null): array
    {
        $leadService = new LeadService($this->app, $this->order);

        try {
            $response = $leadService->createLead($travelData, $contactPerson);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'travel_quote_response' => $response,
                'travel_quote_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'travel_quote_error' => [
                    'error' => $e->getMessage(),
                    'data' => $travelData,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

            throw $e;
        }
    }

    /**
     * Handle voucher creation
     */
    public function handleVoucherCreation(array $voucherData, People $applicant): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->createVoucher($voucherData, $applicant);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_creation_response' => $response,
                'voucher_creation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_creation_error' => [
                    'error' => $e->getMessage(),
                    'data' => $voucherData,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

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

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_query_response' => $response,
                'voucher_query_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_query_error' => [
                    'error' => $e->getMessage(),
                    'data' => $queryParams,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

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

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'pdf_generation_response' => $response,
                'pdf_generation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'pdf_generation_error' => [
                    'error' => $e->getMessage(),
                    'voucher_number' => $voucherNumber,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

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

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'lead_cancellation_response' => $response,
                'lead_cancellation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'lead_cancellation_error' => [
                    'error' => $e->getMessage(),
                    'lead_id' => $leadId,
                    'reason_code' => $reasonCode,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

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
}
