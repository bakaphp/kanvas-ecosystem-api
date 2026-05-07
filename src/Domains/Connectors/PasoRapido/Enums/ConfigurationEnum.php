<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'PASO_RAPIDO_BASE_URL';
    case APP_TOKEN = 'PASO_RAPIDO_APP_TOKEN';
    case CLIENT_ID = 'PASO_RAPIDO_CLIENT_ID';
    case SECRET = 'PASO_RAPIDO_SECRET';
    case VERIFY_MAX_ATTEMPTS = 'PASO_RAPIDO_VERIFY_MAX_ATTEMPTS';
    case VERIFY_MAX_DAILY = 'PASO_RAPIDO_VERIFY_MAX_DAILY';
    case VERIFY_SEQUENTIAL_THRESHOLD = 'PASO_RAPIDO_VERIFY_SEQUENTIAL_THRESHOLD';
    case VERIFY_TAG_ATTRIBUTE_SLUG = 'PASO_RAPIDO_VERIFY_TAG_ATTRIBUTE_SLUG';
    case VERIFY_REQUIRE_VERIFIED_ACCOUNT = 'PASO_RAPIDO_VERIFY_REQUIRE_VERIFIED_ACCOUNT';
    case VERIFY_IP_MAX_DAILY = 'PASO_RAPIDO_VERIFY_IP_MAX_DAILY';
    case VERIFY_IP_MAX_USERS = 'PASO_RAPIDO_VERIFY_IP_MAX_USERS';

    case AUTHORIZATION_PATH = '/api/v1/RdVial/generarAutorizacion';
    case VERIFY_PATH = '/api/v1/RdVial/Verificar';
    case CONFIRM_PAYMENT_PATH = '/api/v1/RdVial/confirmarPago';
    case VERIFY_PAYMENT_PATH = '/api/v1/RdVial/ValidarPago';
    case CANCEL_PAYMENT_PATH = '/api/v1/RdVial/cancelarPago';
}
