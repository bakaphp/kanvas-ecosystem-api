<?php

return [
    // Rechazos
    'PROCESSOR_DECLINED' => 'Rechazo general de la tarjeta. El banco emisor no proporcionó información adicional.',
    'UNAUTHORIZED_CARD' => 'Tarjeta inactiva o no autorizada para transacciones sin presencia de tarjeta.',

    // Fondos y límites
    'INSUFFICIENT_FUND' => 'Fondos insuficientes en la cuenta.',
    'EXCEEDS_CREDIT_LIMIT' => 'La tarjeta ha alcanzado el límite de crédito.',

    // Problemas con la tarjeta
    'EXPIRED_CARD' => 'Tarjeta vencida. También puede recibir este error si la fecha de vencimiento proporcionada no coincide con la que el banco emisor tiene registrada.',
    'STOLEN_LOST_CARD' => 'Tarjeta robada o extraviada.',
    'INVALID_ACCOUNT' => 'Número de cuenta inválido.',
    'INVALID_CVN' => 'Número de verificación de tarjeta (CVN) inválido.',

    // PIN
    'ALLOWABLE_PIN_RETRIES_EXCEEDED' => 'Se excedió el número permitido de intentos de ingreso de PIN.',

    // Errores del procesador
    'PROCESSOR_ERROR' => 'Error del procesador de pagos. El emisor no está operativo o hay un problema del sistema.',

    // Respaldo
    'default' => 'El pago no pudo ser procesado. Por favor, intente de nuevo o utilice otro método de pago.',
];
