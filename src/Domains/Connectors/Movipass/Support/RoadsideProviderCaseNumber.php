<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;

/**
 * The provider's case number is the only handle it gives us, and every later call (state, contact,
 * the poll) is addressed by it. It lives on a custom field so the order itself carries the link, and
 * is read out of the create response through here because the response key spelling is not
 * guaranteed.
 */
final class RoadsideProviderCaseNumber
{
    private const RESPONSE_KEYS = [
        'numeroAsistencia',
        'NumeroAsistencia',
        'numero_asistencia',
        'numero',
    ];

    public static function for(Model $entity): ?string
    {
        $number = $entity->get(CustomFieldEnum::ROADSIDE_PROVIDER_CASE_NUMBER->value);

        return is_string($number) || is_int($number) ? (string) $number : null;
    }

    public static function store(Model $entity, string $number): void
    {
        $entity->set(CustomFieldEnum::ROADSIDE_PROVIDER_CASE_NUMBER->value, $number);
    }

    public static function fromResponse(array $response): ?string
    {
        foreach (['data', 'result', 'asistencia'] as $wrapper) {
            if (is_array($response[$wrapper] ?? null)) {
                $response = [...$response, ...$response[$wrapper]];
            }
        }

        foreach (self::RESPONSE_KEYS as $key) {
            if (isset($response[$key]) && (is_string($response[$key]) || is_int($response[$key]))) {
                return (string) $response[$key];
            }
        }

        return null;
    }
}
