<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'PASO_RAPIDO_BASE_URL';
    case APP_TOKEN = 'PASO_RAPIDO_APP_TOKEN';
    case CLIENT_ID = 'PASO_RAPIDO_CLIENT_ID';
    case SECRET = 'PASO_RAPIDO_SECRET';

    case AUTHORIZATION_PATH = '/api/v1/RdVial/generarAutorizacion';
    case VERIFY_PATH = '/api/v1/RdVial/Verificar';
    case CONFIRM_PAYMENT_PATH = '/api/v1/RdVial/confirmarPago';
    case VERIFY_PAYMENT_PATH = '/api/v1/RdVial/ValidarPago';
    case CANCEL_PAYMENT_PATH = '/api/v1/RdVial/cancelarPago';
}
