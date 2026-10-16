<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

/**
 * Identity document of the referrer. Optional on a quote, but once the type is sent
 * the number becomes required — see QuoteRequest::make.
 */
enum DocumentoReferidorEnum: string
{
    case CEDULA = 'CED';
    case RNC = 'RNC';
    case PASAPORTE = 'PAS';
}
