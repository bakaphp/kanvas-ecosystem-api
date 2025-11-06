<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\DealerSocket\LeadClient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;

use function Sentry\captureException;

use Throwable;

class DealerSocketLeadService
{
    protected LeadClient $leadClient;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
    ) {
        $this->leadClient = new LeadClient(app: $app, company: $company, region: $region);
    }

    /**
     * Save a lead to DealerSocket
     */
    public function saveLead(Lead $lead): array
    {
        try {
            $leadData = $this->mapLeadToArray($lead);

            $format = config('dealersocket.lead_format', 'star');

            $response = $format === 'adf'
                ? $this->leadClient->createSalesLeadADF($leadData)
                : $this->leadClient->createSalesLead($leadData);

            return $response;
        } catch (Throwable $e) {
            Log::error('Failed to create DealerSocket lead', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            captureException($e);

            throw $e;
        }
    }

    /**
     * Map Lead model to DealerSocket array format
     */
    protected function mapLeadToArray(Lead $lead): array
    {
        // Get customer info from People relationship
        $people = $lead->people;

        if (! $people) {
            throw new Exception('Lead must have a People relationship to create in DealerSocket');
        }

        return [
            'senderNameCode' => $this->getVendorName(),
            'serviceId' => $this->getServiceId($lead),
            'bodId' => $this->generateBodId($lead),
            'documentId' => $this->generateDocumentId($lead),

            'firstName' => $people->firstname,
            'lastName' => $people->lastname,
            'email' => $this->getEmailFromPeople($people),
            'phone' => $this->getPhoneFromPeople($people),
            'phoneType' => $this->getPhoneTypeFromPeople($people),
            'phoneTime' => 'Anytime',

            'leadInterestCode' => $this->mapLeadInterestCode($lead),
            'customerType' => 'Prospect',
            'contactMethod' => 'Phone',
            'leadSource' => $this->getLeadSource($lead),
            'customerComments' => $this->getCustomerComments($lead),
            'leadComments' => $this->getLeadComments($lead),

            'address' => $this->getAddressFromPeople($people),

            'interestedVehicle' => $this->getInterestedVehicle($lead),

            'salesPerson' => $this->getSalesPerson($lead),

            'description' => $lead->description ?? '',
        ];
    }

    /**
     * Get email from People model
     */
    protected function getEmailFromPeople(People $people): string
    {
        try {
            $emails = $people->getEmails();

            if ($emails->isEmpty()) {
                throw new Exception("People {$people->id} has no email address");
            }

            // Get first email
            return $emails->first()->value;
        } catch (Throwable $e) {
            Log::error('Failed to get email from People', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
            ]);

            throw new Exception("Customer must have an email address. People ID: {$people->id}");
        }
    }

    /**
     * Get phone from People model (OPTIONAL)
     */
    protected function getPhoneFromPeople(People $people): string
    {
        try {
            $phones = $people->getAllPhones();

            if ($phones->isEmpty()) {
                return ''; // Return empty string, DealerSocket will handle it
            }

            $phone = $phones->first()->value;

            return $this->formatPhone($phone);
        } catch (Throwable $e) {
            Log::warning('Failed to get phone from People', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Get phone type from People model
     */
    protected function getPhoneTypeFromPeople(People $people): string
    {
        try {
            $phones = $people->getAllPhones();

            if ($phones->isEmpty()) {
                return 'Cell Phone';
            }

            $firstPhone = $phones->first();
            $contactType = $firstPhone->type->name ?? 'Cell Phone';

            // Map contact type name to DealerSocket phone type
            return match (strtolower($contactType)) {
                'phone', 'work', 'business phone' => 'Business Phone',
                'cellphone', 'cell phone', 'mobile' => 'Cell Phone',
                'home', 'home phone' => 'Evening Phone',
                default => 'Cell Phone',
            };
        } catch (Throwable $e) {
            return 'Cell Phone';
        }
    }

    /**
     * Get address information from People model
     */
    protected function getAddressFromPeople(People $people): ?array
    {
        try {
            // Try to get default address first
            $address = $people->address()
                ->where('is_default', 1)
                ->where('is_deleted', 0)
                ->first();

            // If no default, get first available address
            if (! $address) {
                $address = $people->address()
                    ->where('is_deleted', 0)
                    ->first();
            }

            if (! $address) {
                return null;
            }

            $addressData = [
                'street' => $address->address ?? '',
                'city' => $address->city ?? '',
                'state' => $address->state ?? '',
                'zipCode' => $address->zip ?? '',
            ];

            // Add address_2 if exists
            if (! empty($address->address_2)) {
                $addressData['street'] = trim($addressData['street'] . ' ' . $address->address_2);
            }

            // Only return if at least one field is filled
            $hasData = array_filter($addressData);

            return ! empty($hasData) ? $addressData : null;
        } catch (Throwable $e) {
            Log::debug('Failed to get address from People', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get vendor name from config or company
     */
    protected function getVendorName(): string
    {
        return config('dealersocket.vendor_name', $this->company->name);
    }

    /**
     * Get service ID based on lead source
     */
    protected function getServiceId(Lead $lead): string
    {
        try {
            $source = $lead->source;

            return $source ? $source->name : 'WEB_LEAD';
        } catch (Throwable $e) {
            return 'WEB_LEAD';
        }
    }

    /**
     * Generate unique Business Object Document ID
     * Uses the Lead's UUID for consistency and tracking
     */
    protected function generateBodId(Lead $lead): string
    {
        return $lead->uuid;
    }

    /**
     * Generate unique Document ID
     * Format: DOC_{lead_uuid}
     */
    protected function generateDocumentId(Lead $lead): string
    {
        return 'DOC_' . $lead->uuid;
    }

    /**
     * Format phone number
     */
    protected function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        return $phone;
    }

    /**
     * Map phone type to DealerSocket channel code
     */
    protected function mapPhoneType(Lead $lead): string
    {
        // Try to get from custom field or default to Mobile
        try {
            $phoneType = $lead->get('phone_type');

            return match (strtolower($phoneType ?? '')) {
                'day phone', 'work', 'business' => 'Business Phone',
                'evening phone', 'home' => 'Evening Phone',
                'cell phone', 'mobile', 'cell' => 'Cell Phone',
                default => 'Cell Phone',
            };
        } catch (Throwable $e) {
            return 'Cell Phone';
        }
    }

    /**
     * Map lead interest code
     */
    protected function mapLeadInterestCode(Lead $lead): string
    {
        try {
            $type = $lead->type;
            $typeName = strtolower($type->name ?? '');

            return match (true) {
                str_contains($typeName, 'buy') => 'B',
                str_contains($typeName, 'lease') => 'L',
                str_contains($typeName, 'trade') => 'T',
                default => 'B',
            };
        } catch (Throwable $e) {
            return 'B';
        }
    }

    /**
     * Get lead source
     */
    protected function getLeadSource(Lead $lead): string
    {
        try {
            $source = $lead->source;

            return $source ? $source->name : 'Website';
        } catch (Throwable $e) {
            return 'Website';
        }
    }

    /**
     * Get customer comments
     */
    protected function getCustomerComments(Lead $lead): string
    {
        $comments = [];

        // Add description if available
        if ($lead->description) {
            $comments[] = $lead->description;
        }

        // Add any custom field comments
        try {
            $customComments = $lead->get('customer_comments');
            if ($customComments) {
                $comments[] = $customComments;
            }
        } catch (Throwable $e) {
            // Ignore
        }

        return implode("\n\n", $comments);
    }

    /**
     * Get lead comments (internal notes)
     */
    protected function getLeadComments(Lead $lead): string
    {
        $comments = [];

        // Add title as comment
        if ($lead->title) {
            $comments[] = 'Title: ' . $lead->title;
        }

        // Add status
        try {
            $status = $lead->status;
            if ($status) {
                $comments[] = 'Status: ' . $status->name;
            }
        } catch (Throwable $e) {
            // Ignore
        }

        // Add pipeline stage
        try {
            $stage = $lead->stage;
            if ($stage) {
                $comments[] = 'Pipeline Stage: ' . $stage->name;
            }
        } catch (Throwable $e) {
            // Ignore
        }

        return implode("\n", $comments);
    }

    /**
     * Get address information
     */
    protected function getAddress(Lead $lead): ?array
    {
        try {
            $people = $lead->people;

            if (! $people) {
                return null;
            }

            // Try to get address from people
            $address = [
                'street' => $people->get('address_1') ?? $people->get('street') ?? '',
                'city' => $people->get('city') ?? '',
                'state' => $people->get('state') ?? '',
                'zipCode' => $people->get('zipcode') ?? $people->get('zip') ?? '',
            ];

            // Only return if at least one field is filled
            $hasData = array_filter($address);

            return ! empty($hasData) ? $address : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get interested vehicle information (Optional but Recommended)
     */
    protected function getInterestedVehicle(Lead $lead): ?array
    {
        try {
            // Get vehicle_of_interest custom field
            $vehicleOfInterest = $lead->get('vehicle_of_interest');

            if (! $vehicleOfInterest) {
                return null;
            }

            // If it's a JSON string, decode it
            if (is_string($vehicleOfInterest)) {
                $vehicleOfInterest = json_decode($vehicleOfInterest, true);
            }

            // If it's not an array after decoding, return null
            if (! is_array($vehicleOfInterest)) {
                return null;
            }

            // Extract vehicle data
            $year = $vehicleOfInterest['year'] ?? $vehicleOfInterest['model_year'] ?? null;
            $make = $vehicleOfInterest['make'] ?? $vehicleOfInterest['manufacturer'] ?? null;
            $model = $vehicleOfInterest['model'] ?? null;
            $status = $vehicleOfInterest['status'] ?? $vehicleOfInterest['condition'] ?? 'New';
            $vin = $vehicleOfInterest['vin'] ?? null;
            $stock = $vehicleOfInterest['stock'] ?? $vehicleOfInterest['stock_number'] ?? null;

            if (! $year && ! $make && ! $model) {
                return null;
            }

            $vehicle = [
                'year' => $year ?: date('Y'),
                'make' => $make ?: 'Unknown',
                'model' => $model ?: 'Unknown',
                'status' => $status, // 'New' or 'Used'
            ];

            // Add optional fields only if they exist
            if ($vin) {
                $vehicle['vin'] = $vin;
            }

            if ($stock) {
                $vehicle['stock'] = $stock;
            }

            return $vehicle;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get sales person name
     */
    protected function getSalesPerson(Lead $lead): string
    {
        try {
            $owner = $lead->owner;

            if (! $owner) {
                return '';
            }

            return trim($owner->firstname . ' ' . $owner->lastname);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Update a lead in DealerSocket
     */
    public function updateLead(Lead $lead, string $dealerSocketLeadId): array
    {
        // TODO: Implement update logic when UpdateEventCommand is ready
        throw new Exception('Update lead functionality not yet implemented');
    }
}
