<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\Enums;

/**
 * Company settings a signal source reads. Owned here, not by the connector that writes them, so the
 * orchestrator never imports a connector.
 */
enum SignalSourceSettingEnum: string
{
    /** Read.ai's per-webhook signing key, base64, stored encrypted. */
    case READ_AI_SIGNING_KEY = 'read_ai_webhook_signing_key';
}
