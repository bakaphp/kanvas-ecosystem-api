<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions;

use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Override;

/**
 * The flush for a text filed by the legacy Twilio webhook job, whose messages carry no
 * `communication` block to read the medium from.
 */
class FlushSmsBurstAction extends FlushInboundBurstAction
{
    #[Override]
    protected function medium(): ?string
    {
        return 'sms';
    }
}
