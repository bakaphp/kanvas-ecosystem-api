<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\TipoViajeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class LeadService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
        $this->client = new Client($app, $order->company);
    }

    /**
     * Create a new lead for travel quote
     */
    public function createLead(array $travelData, People $contactPerson = null): array
    {
        $this->validateTravelData($travelData);

        $leadData = [
            'IdLead' => $travelData['lead_id'] ?? null, // If null, creates new lead
            'CantCotizaciones' => $travelData['quote_count'] ?? 1,
            'Convenio' => $travelData['agreement_id'] ?? null,
            'Folleto' => $travelData['brochure'] ?? 'N',
            'PaisOrigen' => $travelData['origin_country'],
            'Destino' => $travelData['destination'],
            'TipoViaje' => $travelData['trip_type'] ?? TipoViajeEnum::UN_VIAJE->value,
            'FechaInicio' => Carbon::parse($travelData['start_date'])->format('m/d/Y'),
            'FechaFin' => Carbon::parse($travelData['end_date'])->format('m/d/Y'),
            'CantidadPasajeros' => $travelData['passenger_count'],
            'PackFamiliar' => $travelData['family_pack'] ?? null,
        ];

        // Add passenger ages
        if (isset($travelData['passenger_ages']) && is_array($travelData['passenger_ages'])) {
            foreach ($travelData['passenger_ages'] as $index => $age) {
                $leadData['Edad' . ($index + 1)] = (string) $age;
            }
        }

        // Add contact information if provided
        if ($contactPerson) {
            $leadData['ApellidoContacto'] = $contactPerson->lastname;
            $leadData['NombreContacto'] = $contactPerson->firstname;
            $leadData['TelefonoContacto'] = $contactPerson->getCustomField('phone')?->value ?? '';
            $leadData['EmailContacto'] = $contactPerson->email ?? '';
        }

        return $this->client->createOrUpdateLead($leadData);
    }

    /**
     * Update an existing lead
     */
    public function updateLead(string $leadId, array $updateData): array
    {
        $updateData['IdLead'] = $leadId;
        
        return $this->client->createOrUpdateLead($updateData);
    }

    /**
     * Cancel a lead
     */
    public function cancelLead(string $leadId, string $reasonCode = 'Venta Online'): array
    {
        return $this->client->cancelLead($leadId, $reasonCode);
    }

    /**
     * Validate travel data
     */
    protected function validateTravelData(array $travelData): void
    {
        $requiredFields = [
            'origin_country',
            'destination',
            'start_date',
            'end_date',
            'passenger_count',
            'passenger_ages'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($travelData[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        // Validate dates
        $startDate = Carbon::parse($travelData['start_date']);
        $endDate = Carbon::parse($travelData['end_date']);

        if ($endDate->lte($startDate)) {
            throw new ValidationException('End date must be after start date');
        }

        // Validate passenger count matches ages
        if (count($travelData['passenger_ages']) !== (int) $travelData['passenger_count']) {
            throw new ValidationException('Passenger count must match number of ages provided');
        }

        // Validate at least one passenger age
        if (empty($travelData['passenger_ages'][0])) {
            throw new ValidationException('At least Edad1 (first passenger age) is required');
        }
    }
}
