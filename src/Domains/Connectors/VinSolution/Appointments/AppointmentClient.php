<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Appointments;

use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Exceptions\ValidationException;

/**
 * The CarDashboard appointment API. Not the PublicAPI Client: these calls take the session token from
 * SessionTokenService instead of the client_credentials one, and need no cookies.
 */
class AppointmentClient
{
    private const APPOINTMENT_URL = 'https://sales.vinsolutions.com/vin.api.crm.appointment/Sales';
    private const MEDIA_TYPE = 'application/vnd.coxauto.v2+json';

    public function __construct(
        protected GuzzleClient $client = new GuzzleClient(['timeout' => 30, 'connect_timeout' => 10]),
    ) {
    }

    /**
     * dealerId, UserId and CustomerId are all required, and limit is the literal "(page,size)" string.
     * Items read back under other names than the create payload: startTimeUtc/endTimeUtc, status
     * ACTIVE|CANCELLED, purpose "SALES_APPOINTMENT".
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAppointments(
        string $accessToken,
        int $dealerId,
        int $userId,
        int $customerId,
        int $pageNumber = 1,
        int $pageSize = 50,
    ): array {
        $response = $this->client->get(
            self::APPOINTMENT_URL,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => self::MEDIA_TYPE,
                ],
                'query' => [
                    'dealerId' => $dealerId,
                    'UserId' => $userId,
                    'CustomerId' => $customerId,
                    'limit' => '(' . $pageNumber . ',' . $pageSize . ')',
                ],
            ]
        );

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) && isset($data['items']) && is_array($data['items'])
            ? $data['items']
            : [];
    }

    public function createSalesAppointment(string $accessToken, AppointmentData $appointment): string
    {
        $response = $this->client->post(
            self::APPOINTMENT_URL,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => self::MEDIA_TYPE,
                    'Content-Type' => self::MEDIA_TYPE,
                ],
                'body' => json_encode($appointment->toApiPayload()),
            ]
        );

        $appointmentId = trim((string) $response->getBody());

        if ($appointmentId === '') {
            throw new ValidationException('VinSolutions did not return an appointment id');
        }

        return $appointmentId;
    }
}
