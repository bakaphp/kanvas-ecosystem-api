<?php

return [
    // Rechazos
    'PROCESSOR_DECLINED' => 'Rechazo general de la tarjeta. El banco emisor no proporcionó información adicional.',
    'UNAUTHORIZED_CARD' => 'Tarjeta inactiva o no autorizada para transacciones sin presencia de tarjeta.',
    'GENERAL_DECLINE' => 'El banco emisor rechazó el pago. Por favor, intenta con otro método de pago.',
    'PAYMENT_REFUSED' => 'El banco emisor rechazó el pago. Por favor, intenta con otro método de pago.',
    'CONTACT_PROCESSOR' => 'El banco emisor no pudo aprobar el pago. Contacta a tu banco o intenta con otro método de pago.',
    'DECLINED' => 'El banco emisor rechazó el pago. Por favor, intenta con otro método de pago.',
    'AVS_FAILED' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'DECISION_PROFILE_REJECT' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'SCORE_EXCEEDS_THRESHOLD' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'RISK_DECLINED' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'BLACKLISTED_CUSTOMER' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'CUSTOMER_WATCHLIST_MATCH' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'ADDRESS_COUNTRY_WATCHLIST_MATCH' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'EMAIL_COUNTRY_WATCHLIST_MATCH' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'IP_COUNTRY_WATCHLIST_MATCH' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'SUSPENDED_ACCOUNT' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'PENDING_REVIEW' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'PARTIAL_AUTHORIZATION' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'CONSUMER_AUTHENTICATION_FAILED' => 'No pudimos validar tu identidad con el banco emisor. Por favor, intenta de nuevo o utiliza otra tarjeta.',
    'CONSUMER_AUTHENTICATION_REQUIRED' => 'Tu banco requiere una validación adicional para completar el pago. Por favor, intenta de nuevo.',

    // Fondos y límites
    'INSUFFICIENT_FUND' => 'Fondos insuficientes en la cuenta.',
    'EXCEEDS_CREDIT_LIMIT' => 'La tarjeta ha alcanzado el límite de crédito.',
    'DEBIT_CARD_USEAGE_EXCEEDD_LIMIT' => 'Se excedió el límite de uso de la tarjeta de débito.',

    // Problemas con la tarjeta
    'EXPIRED_CARD' => 'Tarjeta vencida. También puede recibir este error si la fecha de vencimiento proporcionada no coincide con la que el banco emisor tiene registrada.',
    'STOLEN_LOST_CARD' => 'Tarjeta robada o extraviada.',
    'INVALID_ACCOUNT' => 'Número de cuenta inválido.',
    'INVALID_CVN' => 'Número de verificación de tarjeta (CVN) inválido.',
    'CV_FAILED' => 'El código de seguridad de la tarjeta (CVN) es inválido.',
    'CVN_NOT_MATCH' => 'El código de seguridad de la tarjeta (CVN) no coincide.',
    'INVALID_CARD' => 'La tarjeta no es válida.',
    'CARD_TYPE_NOT_ACCEPTED' => 'El tipo de tarjeta no es aceptado.',
    'INVALID_CARD_TYPE' => 'El tipo de tarjeta no es válido o no es aceptado.',

    // PIN
    'ALLOWABLE_PIN_RETRIES_EXCEEDED' => 'Se excedió el número permitido de intentos de ingreso de PIN.',

    // Errores del procesador
    'PROCESSOR_ERROR' => 'Error del procesador de pagos. El emisor no está operativo o hay un problema del sistema.',
    'PROCESSOR_TIMEOUT' => 'El banco emisor tardó demasiado en responder. Por favor, intenta de nuevo.',
    'PROCESSOR_UNAVAILABLE' => 'El procesador de pagos no está disponible en este momento. Por favor, intenta de nuevo más tarde.',
    'SERVER_TIMEOUT' => 'El procesador de pagos tardó demasiado en responder. Por favor, intenta de nuevo.',
    'SERVICE_TIMEOUT' => 'El procesador de pagos tardó demasiado en responder. Por favor, intenta de nuevo.',
    'ISSUER_UNAVAILABLE' => 'El banco emisor no está disponible en este momento. Por favor, intenta de nuevo más tarde.',
    'SYSTEM_ERROR' => 'Ocurrió un error al procesar el pago. Por favor, intenta de nuevo más tarde.',
    'DUPLICATE_REQUEST' => 'Ya recibimos una solicitud de pago idéntica. Espera unos minutos antes de intentar de nuevo.',
    'INVALID_AMOUNT' => 'El monto del pago no es válido.',
    'INVALID_DATA' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'MISSING_FIELD' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'INVALID_MERCHANT_CONFIGURATION' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'INVALID_REQUEST' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',
    'UNKNOWN_AUTHORIZATION_STATUS' => 'No pudimos procesar el pago. Por favor, intenta con otro método de pago.',

    'card_velocity_exceeded' => 'No pudimos procesar el pago en este momento. Si el problema persiste, contacta a soporte.',

    // Respaldo
    'default' => 'El pago no pudo ser procesado. Por favor, intente de nuevo o utilice otro método de pago.',
];
