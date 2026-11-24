<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Concerns;

use Illuminate\Http\Request;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * For receivers whose sender can only hold a static secret (an export script, a payment provider's
 * plain webhook): `shared_secret` in the receiver configuration, sent back in `X-Webhook-Secret`.
 * A receiver with no secret configured refuses everything — the uuid alone is not authentication for
 * a write that moves money on the books.
 */
trait AuthenticatesWithSharedSecret
{
    protected static function hasValidSharedSecret(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) (($receiver->configuration ?? [])['shared_secret'] ?? '');
        $sent = $request->header('X-Webhook-Secret');

        return $secret !== '' && is_string($sent) && hash_equals($secret, $sent);
    }
}
