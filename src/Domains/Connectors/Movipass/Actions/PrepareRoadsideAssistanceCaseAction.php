<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

class PrepareRoadsideAssistanceCaseAction
{
    public function execute(array $metadata, Users $user): array
    {
        $assistanceCase = $this->resolveAssistanceCasePayload($metadata);
        $service = trim((string) ($assistanceCase['service'] ?? ''));
        $providerId = $assistanceCase['provider_id'] ?? null;
        $providerName = trim((string) ($assistanceCase['provider_name'] ?? ''));
        $location = is_array($assistanceCase['location'] ?? null) ? $assistanceCase['location'] : [];

        if ($service === '') {
            throw new ValidationException('Roadside assistance service is required');
        }

        $mechanic = $this->resolveMechanic($assistanceCase);

        // provider_id/provider_name only required when no mechanic is pre-identified
        if ($mechanic === null && $providerId === null && $providerName === '') {
            throw new ValidationException('Roadside assistance provider is required');
        }

        if (empty($location)) {
            throw new ValidationException('Roadside assistance location is required');
        }

        $photos = $this->normalizePhotos($assistanceCase['photos'] ?? []);

        $caseData = [
            'case_id' => $assistanceCase['case_id'] ?? (string) Str::uuid(),
            'status' => MovipassOrderStatusEnum::REQUEST_SUBMITTED->value,
            'requested_at' => $assistanceCase['requested_at'] ?? Carbon::now()->toISOString(),
            'user' => [
                'id' => $user->getId(),
                'uuid' => $user->uuid,
                'email' => $user->email,
            ],
            'service' => $service,
            'location' => $location,
            'notes' => $assistanceCase['notes'] ?? null,
            'provider_id' => $providerId,
            'provider_name' => $providerName !== '' ? $providerName : null,
            'photos' => $photos,
            'mechanic' => $mechanic,
        ];

        return [
            ...$metadata,
            'assistance_case' => $caseData,
            'data' => [
                ...(is_array($metadata['data'] ?? null) ? $metadata['data'] : []),
                'assistance_case' => $caseData,
            ],
        ];
    }

    protected function resolveMechanic(array $assistanceCase): ?array
    {
        $mechanicData = $assistanceCase['mechanic'] ?? null;

        if (! is_array($mechanicData) || empty($mechanicData['user_id'])) {
            return null;
        }

        $userId = (int) $mechanicData['user_id'];

        try {
            $mechanic = Users::getById($userId);
        } catch (ModelNotFoundException) {
            throw new ValidationException("Mechanic user with ID {$userId} not found");
        }

        $lat = $mechanic->get(CustomFieldEnum::MECHANIC_LAT->value);
        $lng = $mechanic->get(CustomFieldEnum::MECHANIC_LNG->value);
        $profileLocation = $lat !== null && $lng !== null ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;

        $rawVehicleInfo = $mechanic->get(CustomFieldEnum::MECHANIC_VEHICLE_INFO->value);
        $vehicleInfo = is_array($rawVehicleInfo) ? $rawVehicleInfo : json_decode((string) ($rawVehicleInfo ?? ''), true);

        return [
            'user_id' => $mechanic->getId(),
            'uuid' => $mechanic->uuid,
            'name' => isset($mechanicData['name']) && $mechanicData['name'] !== ''
                ? $mechanicData['name']
                : trim($mechanic->firstname . ' ' . $mechanic->lastname),
            'phone' => $mechanicData['phone'] ?? $mechanic->phone_number ?? null,
            'email' => $mechanicData['email'] ?? $mechanic->email,
            'company_id' => $mechanicData['company_id'] ?? $mechanic->default_company,
            'company_name' => $mechanicData['company_name'] ?? $mechanic->getCurrentCompany()?->name ?? null,
            'location' => (is_array($mechanicData['location'] ?? null) ? $mechanicData['location'] : null) ?? $profileLocation,
            'vehicle_info' => (is_array($mechanicData['vehicle_info'] ?? null) ? $mechanicData['vehicle_info'] : null) ?? ($vehicleInfo ?: null),
        ];
    }

    protected function normalizePhotos(mixed $photos): array
    {
        if (! is_array($photos)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $photo): string {
            return is_string($photo) ? trim($photo) : '';
        }, $photos), static fn (string $photo): bool => $photo !== ''));
    }

    protected function resolveAssistanceCasePayload(array $metadata): array
    {
        if (is_array($metadata['assistance_case'] ?? null)) {
            return $metadata['assistance_case'];
        }

        if (is_array($metadata['data']['assistance_case'] ?? null)) {
            return $metadata['data']['assistance_case'];
        }

        return [];
    }
}
