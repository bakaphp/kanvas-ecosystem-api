<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\DataTransferObjects\TravelQuoteData;
use Kanvas\Connectors\UniversalAssistance\Enums\TripTypeEnum;
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
    public function createLead(array $travelData, ?People $contactPerson = null): array
    {
        // Use DTO for better structure
        $travelQuote = TravelQuoteData::from([
            'originCountry' => $travelData['origin_country'] ?? 'ARG',
            'destination' => $travelData['destination'] ?? 'Europa',
            'startDate' => isset($travelData['start_date']) ? Carbon::parse($travelData['start_date']) : Carbon::now()->addDays(30),
            'endDate' => isset($travelData['end_date']) ? Carbon::parse($travelData['end_date']) : Carbon::now()->addDays(37),
            'passengerCount' => $travelData['passenger_count'] ?? 1,
            'passengerAges' => $travelData['passenger_ages'] ?? [30],
            'leadId' => $travelData['lead_id'] ?? null,
            'tripType' => $travelData['trip_type'] ?? TripTypeEnum::SINGLE_TRIP->value,
            'agreementId' => $travelData['agreement_id'] ?? null,
            'brochure' => $travelData['brochure'] ?? 'N',
            'familyPack' => $travelData['family_pack'] ?? null,
            'quoteCount' => $travelData['quote_count'] ?? 1,
        ]);

        $this->validateTravelData($travelQuote);

        $leadData = [
            'IdLead' => $travelQuote->leadId,
            'CantCotizaciones' => $travelQuote->quoteCount,
            'Convenio' => $travelQuote->agreementId,
            'Folleto' => $travelQuote->brochure,
            'PaisOrigen' => $travelQuote->originCountry,
            'Destino' => $travelQuote->destination,
            'TipoViaje' => $travelQuote->tripType,
            'FechaInicio' => $travelQuote->startDate->format('m/d/Y'),
            'FechaFin' => $travelQuote->endDate->format('m/d/Y'),
            'CantidadPasajeros' => $travelQuote->passengerCount,
            'PackFamiliar' => $travelQuote->familyPack,
        ];

        // Add passenger ages
        if (isset($travelQuote->passengerAges) && is_array($travelQuote->passengerAges)) {
            foreach ($travelQuote->passengerAges as $index => $age) {
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
    protected function validateTravelData(TravelQuoteData $travelData): void
    {
        $errors = [];

        if ($travelData->startDate <= Carbon::now()) {
            $errors[] = 'Start date must be in the future';
        }

        if ($travelData->endDate <= $travelData->startDate) {
            $errors[] = 'End date must be after start date';
        }

        if ($travelData->passengerCount !== count($travelData->passengerAges)) {
            $errors[] = 'Passenger count must match number of ages provided';
        }

        if (empty($travelData->originCountry) || empty($travelData->destination)) {
            $errors[] = 'Origin country and destination are required';
        }

        if (! empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }
    }
}
