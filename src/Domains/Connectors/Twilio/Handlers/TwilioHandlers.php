<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Handlers;

use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers as CurrentTwilioHandlers;

/**
 * @deprecated moved to {@see CurrentTwilioHandlers}. Integration rows saved before the move name this class in `integrations.handler`.
 */
class TwilioHandlers extends CurrentTwilioHandlers
{
}
