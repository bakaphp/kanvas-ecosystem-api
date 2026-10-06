<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use Kanvas\Connectors\Movipass\Enums\RoadsideNotProceedReasonEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeField;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeQuestionnaire;
use Kanvas\Exceptions\ValidationException;

class RoadsideIntakeQuery
{
    public function get(mixed $rootValue, array $request): array
    {
        $requested = $request['service_type'] ?? null;

        if ($requested === null) {
            return array_map(
                fn (RoadsideServiceTypeEnum $service): array => $this->present($service),
                RoadsideServiceTypeEnum::cases(),
            );
        }

        $service = RoadsideServiceTypeEnum::tryFrom((string) $requested);

        if ($service === null) {
            throw new ValidationException(sprintf('Unknown roadside service type "%s"', $requested));
        }

        return [$this->present($service)];
    }

    public function notProceedReasons(mixed $rootValue, array $request): array
    {
        return array_map(static fn (RoadsideNotProceedReasonEnum $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], RoadsideNotProceedReasonEnum::cases());
    }

    private function present(RoadsideServiceTypeEnum $service): array
    {
        return [
            'service_type' => $service->value,
            'label' => $service->label(),
            'requires_photos' => RoadsideIntakeQuestionnaire::requiresPhotos($service),
            'general_fields' => $this->fields(RoadsideIntakeQuestionnaire::general()),
            'service_fields' => $this->fields(RoadsideIntakeQuestionnaire::forService($service)),
        ];
    }

    /**
     * @param array<int, RoadsideIntakeField> $fields
     */
    private function fields(array $fields): array
    {
        return array_map(static fn (RoadsideIntakeField $field): array => $field->toArray(), $fields);
    }
}
