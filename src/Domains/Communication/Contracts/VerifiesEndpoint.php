<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Illuminate\Http\Request;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * For a vendor that proves the webhook URL is ours with a GET before it sends anything to it
 * (Meta's `hub.challenge`).
 */
interface VerifiesEndpoint
{
    /**
     * @return string|null the body to answer with, or null when the request is not a valid challenge
     */
    public function challenge(Request $request, ReceiverWebhook $receiver): ?string;
}
