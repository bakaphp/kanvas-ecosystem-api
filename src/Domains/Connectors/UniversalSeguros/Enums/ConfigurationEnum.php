<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum ConfigurationEnum: string
{
    case ENVIRONMENT = 'universal_seguros_environment';
    case CLIENT_ID = 'universal_seguros_client_id';
    case CLIENT_SECRET = 'universal_seguros_client_secret';
    case SCOPES = 'universal_seguros_scopes';
    case VERIFY_SSL = 'universal_seguros_verify_ssl';

    public const BASE_SCOPES = 'unit.serviceplattform.externos unit.serviceplattform.cotizaciones unit.serviceplattform.polizas';

    /**
     * Emission is scoped per product, so the token must carry every product we sell
     * or that product dies at emit time — after the customer has paid. Derived from
     * ProductEnum so adding a product can't silently skip its scope.
     */
    public static function defaultScopes(): string
    {
        return implode(' ', [
            self::BASE_SCOPES,
            ...array_map(fn (ProductEnum $product): string => $product->emitScope(), ProductEnum::cases()),
        ]);
    }
}
