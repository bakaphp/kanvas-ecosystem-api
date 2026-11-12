<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Webhooks;

use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob as CurrentProcessTwilioMessageStatusWebhookJob;

/**
 * @deprecated moved to {@see CurrentProcessTwilioMessageStatusWebhookJob}. Receivers saved before the move name this class in `actions.model_name`, and webhook calls already queued carry it.
 */
class ProcessTwilioMessageStatusWebhookJob extends CurrentProcessTwilioMessageStatusWebhookJob
{
}
