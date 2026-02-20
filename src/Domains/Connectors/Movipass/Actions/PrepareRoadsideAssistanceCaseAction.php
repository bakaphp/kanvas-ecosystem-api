<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
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

        if ($providerId === null && $providerName === '') {
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
